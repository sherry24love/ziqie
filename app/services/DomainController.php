<?php

namespace App\Http\Controllers;

use App\Helper\Helper;
use App\Http\Services\CloakServce;
use App\Http\Services\CloudFlareService;
use App\Jobs\UserExportJob;
use App\Models\DomainSale;
use App\Models\Export;
use App\Jobs\DomainSaleBillJob;
use App\Jobs\DomainBindJob;
use App\Jobs\RequestLog;
use App\Models\Domain;
use App\Models\DomainDns;
use App\Models\DomainSaleBill;
use App\Models\DomainLoadPage;
use App\Models\DomainOfferSku;
use App\Models\DomainPixel;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Annotations\Get;
use OpenApi\Annotations\MediaType;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\RequestBody;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use QL\Ext\AbsoluteUrl;
use QL\QueryList;
use App\Http\Services\DomainService;
use function Qiniu\waterImg;
use App\Http\Services\UnionOfferService;

class DomainController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    //

    /**
     * 域名列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $admin_user = Auth::guard('api')->user();
        $query = Domain::with([
            'user',
            'company' => function ($query) {
                return $query->select(['org_short_name', 'org_name', 'id']);
            }]);
        $sort = $request->input('sort');
        if (trim($sort) != '') {
            if (strtolower($sort) == 'id desc') {
                $query = $query->OrderBy('id', 'desc');
            } else {
                $query = $query->OrderBy('id', 'asc');
            }
        } else {
            $query = $query->OrderBy('id', 'desc');
        }

        if ($admin_user->id != SUPPER_ADMIN_ID) {
            // 如果这个用户有 公司信息 则需要按公司查询 ，否则按平台型管理员查看
            if ($admin_user->company_id) {
                $query->where('company_id', $admin_user->company_id);
            }
        } else {
            $company_id = $request->input('company_id');
            if ($company_id) {
                $query->where('company_id', $company_id);
            }
        }
        $pagesize = $request->input('pagesize');
        $trash = $request->input('trash', 0);
        if ($trash) {
            $query = $query->where('deleted_at', '>', 0);
        } else {
            $query = $query->where('deleted_at', 0);
        }
        $domain = $request->input('domain');
        if ($domain) {
            $query = $query->where('domain', $domain);
        }
        $expire_at = $request->input('expire_at');
        if ($expire_at) {
            $expire_at = strtotime($expire_at . ' 00:00:00');
            $query = $query->where('expire_at', '>=', $expire_at)->where('expire_at', '<', $expire_at + 86400);
        }
        $created_at = $request->input('created_at');
        if ($created_at) {
            $created_at = strtotime($created_at . ' 00:00:00');
            $query = $query->where('created_at', '>=', $created_at)->where('created_at', '<', $created_at + 86400);
        }
        $status = $request->input('status');
        if (trim($status) != '') {
            $query = $query->where('status', $status);
        }

        $result = $query->paginate($pagesize);
        return response()->json([
            'errcode' => 0,
            'data' => [
                'items' => $result->items(),
                'total' => $result->total()
            ]
        ]);

    }


    /**
     * 新增数据
     *
     * @param Request $request
     *
     * @return
     * @throws
     *
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'domain' => 'required',
            'expire_at' => 'required|date',
            'company_id' => 'required',
            'type_domain' => 'required',
        ], [
            'domain.required' => '请填写域名',
            'company_id.required' => '请填写公司',
            'expire_at.required' => '请填写过期时间',
            'type_domain.required' => '请选择域名类型',
        ]);
        $expire_at = $request->input('expire_at');
        $expire_at = strtotime($expire_at . ' 00:00:00');
        $companyId = $request->input('company_id');
        $domains = $request->input('domain');
        $domains = explode("\n", $domains);
        foreach ($domains as $key => $item) {
            $item = strtolower(trim($item));
            if ($item) {
                $domains[$key] = $item;
            } else {
                unset($domains[$key]);
            }
        }
        $exists = Domain::whereIn('domain', $domains)->select(['id', 'domain'])->get();
        $exists_domain = [];
        if ($exists->isNotEmpty()) {
            $exists = $exists->toArray();
            $exists_domain = array_column($exists, 'domain');
        }
        // 新增数据
        $inserts = [];
        $admin_user = Auth::guard('api')->user();
        $admin_user_id = data_get($admin_user, 'id');
        // 如果管理 有公司信息  则需要重置信息
        if ($admin_user_id != SUPPER_ADMIN_ID) {
            if ($admin_user->company_id) {
                $companyId = $admin_user->company_id;
            }
        }

        // 处理主机信息
        $domain_type = $request->input('type_domain');

        $host_type_opt = [
            'COPY_SITE' => 'COPY_SITE',
            'SUB_DOMAIN' => 'NO_DATA',
            'SUB_PATH' => 'NO_DATA',
        ];

        // 获取域名需要解析的地址
        $host_ip = config('global.cdn_server', []);
        $host_type = data_get($host_type_opt, $domain_type);
        $host = data_get($host_ip, $host_type, '');

        foreach ($domains as $item) {
            if (!$item) {
                continue;
            }
            if (in_array($item, $exists_domain)) {
                // 如果在已在的数据中
                continue;
            }
            $inserts[] = [
                'domain' => $item,
                'type_host' => $host_type,
                'host' => $host,
                'type_domain' => $domain_type,
                'status' => 'AI',
                'sale_status' => 'AI',
                'bind_times' => 0,
                'dns_num' => 0,
                'expire_at' => $expire_at,
                'quality_score' => 0,
                'user_id' => 0,
                'bind_at' => 0,
                'create_user_id' => $admin_user_id,
                'created_at' => time(),
                'updated_at' => 0,
                'deleted_at' => 0,
                'delete_user_id' => 0,
                'company_id' => $companyId
            ];
        }
        $line = 0;
        if (!empty($inserts)) {
            DB::table('domain')->insert($inserts);
            foreach ($inserts as $domain) {
                $line++;
                // 追个解析域名
                dispatch(new DomainBindJob(data_get($domain, 'domain')))->onQueue('domain');
            }
        }

        return response()->json([
            'errcode' => 0,
            'data' => compact('expire_at', 'exists'),
            'msg' => '导入成功' . $line
        ]);
    }


    /**
     * 更新数据
     *
     * @param $id
     * @param Request $request
     */
    public function update($id, Request $request)
    {

    }


    /**
     * 详情
     *
     * @param $id
     */
    public function show($id)
    {

    }


    /**
     * 删除数据
     *
     * @param $id
     * @param Request $request
     *
     * @return
     */
    public function destroy($id, Request $request)
    {
        $domain = Domain::findOrFail($id);
        $admin_user = $request->user();
        $domain->deleted_at = time();
        $domain->delete_user_id = data_get($admin_user, 'id');
        $domain->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '删除成功'
        ]);
    }


    /**
     * 还原域名
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function restore($id, Request $request)
    {
        $domain = Domain::findOrFail($id);
        $domain->deleted_at = 0;
        $domain->delete_user_id = 0;
        $domain->save();
        // TODO 删除解析记录
        return response()->json([
            'errcode' => 0,
            'msg' => '还原成功'
        ]);
    }

    /**
     * 校验域名
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(Request $request)
    {
        $domain = $request->input('domain');
        $domain = trim($domain);
        if (!$domain) {
            return response()->json([
                'errcode' => 10001,
                'msg' => '请填写域名'
            ]);
        }

        $domain = Domain::where('domain', $domain)->where('deleted_at', 0)->first();
        if (empty($domain)) {
            return response()->json([
                'errcode' => 40001,
                'msg' => '域名不存在或已经删除'
            ]);
        }
        if ($domain->expire_at < time()) {
            return response()->json([
                'errcode' => 40002,
                'msg' => '域名已经过期，请更换域名'
            ]);
        }
        if (false) {
            $user = Auth::guard('user')->user();
            if ($domain->user_id && $domain->user_id != $user->id) {
                return response()->json([
                    'errcode' => 10002,
                    'msg' => '域名已被其他人绑定'
                ]);
            }
        }
        return response()->json([
            'errcode' => 0,
            'data' => $domain
        ]);
    }

    /**
     * 绑定域名
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bind(Request $request)
    {
        $domain = $request->input('domain');
        $domain = trim($domain);
        if (!$domain) {
            return response()->json([
                'errcode' => 10001,
                'msg' => '请填写域名'
            ]);
        }
        $domain_info = Domain::where('domain', $domain)->where('status', 'ON')->where('deleted_at', 0)->first();
        if (empty($domain_info)) {
            return response()->json([
                'errcode' => 40001,
                'msg' => '域名不存在或已经被删除'
            ]);
        }
        if ($domain_info->expire_at < time()) {
            return response()->json([
                'errcode' => 40002,
                'msg' => '域名已经过期，请更换域名'
            ]);
        }
        $user = Auth::guard('user')->user();
        if (false) {
            if ($domain_info->user_id != 0) {
                if ($domain_info->user_id != $user->id) {
                    return response()->json([
                        'errcode' => 10002,
                        'msg' => '域名被他们占用'
                    ]);
                }

            }
        }
        if ($domain_info->user_id === 0) {
            $update = [
                'user_id' => $user->id,
                'bind_at' => time()
            ];
            $row = Domain::where('domain', $domain)->where('user_id', 0)->update($update);
            DomainDns::where('domain_id', $domain_info->id)->update([
                'user_id' => $user->id,
                'updated_at' => time()
            ]);
            if ($row) {
                // 更新子域名 都 是自己可用的域名
                return response()->json([
                    'errcode' => 0,
                    'msg' => '绑定成功'
                ]);
            } else {
                return response()->json([
                    'errcode' => 20002,
                    'msg' => '绑定失败'
                ]);
            }
        }
        return response()->json([
            'errcode' => 20001,
            'msg' => '域名已被其他人使用'
        ]);

    }

    /**
     * 当前域名的DNS解析记录
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function dns($id, Request $request)
    {
        $domain = Domain::findOrFail($id);
        $admin = Auth::guard('api')->user();
        if ($admin->id != SUPPER_ADMIN_ID) {
            if ($admin->company_id) {
                if ($domain->company_id != $admin->company_id) {
                    return response()->json([
                        'errcode' => 40003,
                        'msg' => '您没有查看权限'
                    ]);
                }
            }
        }
        $dns = DomainDns::with([
            'domain' => function ($query) {
                return $query->select([
                    'id', 'domain', 'type_domain', 'type_host', 'host'
                ]);
            }
        ])->where('domain_id', $id)->where('deleted_at', 0)
            ->orderBy('updated_at', 'desc')->get();
        return response()->json([
            'errcode' => 0,
            'data' => $dns
        ]);
    }


    public function dnsLog(Request $request)
    {
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with([
            'domain' => function ($query) {
                return $query->select([
                    'id', 'domain', 'type_domain', 'type_host', 'host'
                ]);
            }
        ])->where('deleted_at', 0)->where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')->get();
        return response()->json([
            'errcode' => 0,
            'data' => $dns
        ]);
    }


    /**
     * 域名 列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dnsList(Request $request)
    {
        $domain = $request->input('domain');
        if (!$domain) {
            return response()->json([
                'errcode' => 0,
                'data' => []
            ]);
        }
        $user = Auth::guard('user')->user();
        $domain_info = Domain::where('domain', $domain)->firstOrFail();
        // TODO 暂时不按自己的来查询
        //->where('user_id', $user->id)
        $dns = DomainDns::where('domain_id', $domain_info->id)
            ->where('deleted_at', 0)->select(['sub_domain', 'id'])->orderBy('id', 'asc')->get();
        return response()->json([
            'errcode' => 0,
            'data' => $dns
        ]);
    }

    /**
     * 域名列表 带分页
     * @param Request $request
     * @return JsonResponse
     */
    public function dnsListPaginate(Request $request)
    {
        $user = Auth::guard('user')->user();
        $pagesize = $request->input('pagesize', 10);
        $query = DomainDns::with([
            'domain' => function ($query) {
                return $query->select(['type_domain', 'id']);
            }
        ])->where('user_id', $user->id);
        $domain = $request->input('domain');
        $domain = explode('/', $domain);
        $length = count($domain);

        switch ($length) {
            case 1:
                $query->where('domain_full', 'like', "%{$domain[0]}%");
                break;
            case 2:
                $query->where('domain_full', 'like', "%{$domain[0]}%");
                $query->where('sub_domain', 'like', "{$domain[1]}%");
                break;
            case 3:
                $query->where('domain_full', 'like', "{$domain[2]}%");
                break;
            case 4:
                $query->where('domain_full', 'like', "{$domain[2]}%");
                $query->where('sub_domain', 'like', "{$domain[3]}%");
                break;
        }
        $mode = $request->input('display_mode');
        if ($mode) {
            $query->where('mode', $mode);
        }
        $status_auth = $request->input('auth_status');
        if ($status_auth) {
            $query->where('status');
        }
        $created_at = $request->input('created_at');
        if ($created_at) {
            $created_start = strtotime($created_at . " 00:00:00");
            $created_end = strtotime($created_at . ' 23:59:59');
            $query->where('created_at', ">=", $created_start);
            $query->where('created_at', "<=", $created_end);
        }

        $change_at = $request->input('change_mode_at');
        if ($change_at) {
            $change_start = strtotime($change_at . " 00:00:00");
            $change_end = strtotime($change_at . ' 23:59:59');
            $query->where('change_mode_at', ">=", $change_start);
            $query->where('change_mode_at', "<=", $change_end);
        }

        $dns = $query->where('deleted_at', 0)->orderBy('id', 'desc')
            ->paginate($pagesize);
        $items = collect($dns->items())->transform(function ($item) {
            $item->update_at_format = date("m/d H:i", $item->updated_at);
            $item->created_at = date("m/d H:i", $item->created_at);
            if ($item->change_mode_at) {
                $item->change_mode_at = date("m/d H:i", $item->change_mode_at);
            }
            $item->change_mode_at = "";
            return $item;
        });

        return response()->json([
            'errcode' => 0,
            'data' => [
                'total' => $dns->total(),
                'items' => $items
            ]
        ]);
    }


    /**
     * 更新状态
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus($id, Request $request)
    {
        $dns = DomainDns::findOrFail($id);
        $status = $request->input('status', 'AI');
        $dns->status = strtoupper($status);
        $dns->updated_at = time();
        $dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '更新成功',
            'data' => []
        ]);
    }


    /**
     * 创建子域名
     * @param Request $request
     * @return JsonResponse
     */
    public function createSubDomain(Request $request)
    {
        $domain_id = $request->input('domain_id');
        $sub_domain = $request->input('sub_domain');
        $domain_info = Domain::findOrFail($domain_id);
        // 检查域名 是不是绑定
        if (!$domain_info->zone_id) {
            return response()->json([
                'errcode' => 10003,
                'msg' => '请先绑定域名'
            ]);
        }
        // TODO 检查这个二级域名是不是存在
        $count = DomainDns::where('domain_id', $domain_id)->where('deleted_at', 0)->where('sub_domain', $sub_domain)->count();
        if ($count) {
            return response()->json([
                'errcode' => 20001,
                'msg' => '当前目录已经存在，请更换目录名称'
            ]);
        }
        $user = Auth::guard('user')->user();
        $dns = new DomainDns();
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $domain_full = $sub_domain . '.' . $domain_info->domain;
            $update = [
                'zone_id' => $domain_info->zone_id,
                'dns_id' => '',
                'host' => $domain_info->host,
                'type_host' => $domain_info->type_host,
                'user_id' => $user->id,
                'domain_id' => $domain_id,
                'domain_full' => $domain_full,
                'domain_main' => $domain_info->domain,
                'sub_domain' => $sub_domain,
                'make_path_at' => time(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $dns->fill($update);
            $dns->save();
        } else {
            // 去服务器创建二级域名
            $domain_full = 'www.' . $domain_info->domain;
            $update = [
                'zone_id' => $domain_info->zone_id,
                'dns_id' => '',
                'host' => $domain_info->host,
                'type_host' => $domain_info->type_host,
                'user_id' => $user->id,
                'domain_id' => $domain_id,
                'domain_full' => $domain_full,
                'domain_main' => $domain_info->domain,
                'sub_domain' => $sub_domain,
                'make_path_at' => time(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $dns->fill($update);
            $is_beta = $request->input('_beta') ? true : false;
            $dns->save();
            $domain_service = new DomainService($domain_info, $is_beta);
            $domain_service->setDns($dns);
            $result = $domain_service->create_path();
            if (true !== $result) {
                $dns->delete();
                return response()->json([
                    'errcode' => 30001,
                    'msg' => $result
                ]);
            }
        }
        return response()->json([
            'errcode' => 0,
            'data' => $dns
        ]);
    }

    public function setRedirectBak(Request $request)
    {
        $this->validate($request, [
            'proxy_url' => 'required',
            'sub_domain_id' => 'required',
            'proxy_method' => 'required',
        ], [
            'proxy_url.required' => '请输入301跳转地址',
            'sub_domain_id.required' => '请选择二级域名',
            'proxy_method.required' => '请选择站点模式',
        ]);
        $proxy_method = $request->input('proxy_method', 301);
        $proxy_site = $request->input('proxy_url');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::where('id', $domain_id)->firstOrFail();

        // 检查 spide的情况
        if ($proxy_method == 'spide') {
            $result = $this->testSpide($proxy_site);
            if (false === $result) {
                return response()->json([
                    'errcode' => 40001,
                    'msg' => '检测到当前站点采集速度太慢，请更换站点'
                ]);
            }
        }
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = $proxy_site;
        // 清理其他的不需要的文件
        $dns->mode = $dns->mode ? $dns->mode : 'SAFE';
        $dns->safe_generate = 'Y';
        $dns->user_id = $user->id;
        $dns->make_sp_at = time();
        $dns->updated_at = time();

        $domain_info = Domain::findOrFail($dns->domain_id);
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_info, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->redirect();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => '访问超时'
            ]);
        }
        // 保存
        $dns->save();
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        $this->synDomainAndDns($dns);
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }
    /**
     * 设置301 跳转
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setRedirect(Request $request)
    {
        $this->validate($request, [
            'proxy_url' => 'required',
            'sub_domain_id' => 'required',
            'proxy_method' => 'required',
        ], [
            'proxy_url.required' => '请输入301跳转地址',
            'sub_domain_id.required' => '请选择二级域名',
            'proxy_method.required' => '请选择站点模式',
        ]);
        $proxy_method = $request->input('proxy_method', 301);
        $proxy_site = $request->input('proxy_url');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::where('id', $domain_id)->firstOrFail();

        // 检查 spide的情况
        if ($proxy_method == 'spide') {
            $result = $this->testSpide($proxy_site);
            if (false === $result) {
                return response()->json([
                    'errcode' => 40001,
                    'msg' => '检测到当前站点采集速度太慢，请更换站点'
                ]);
            }
        }
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = $proxy_site;
        // 清理其他的不需要的文件
        $dns->mode = $dns->mode ? $dns->mode : 'SAFE';
        $dns->safe_generate = 'Y';
        $dns->user_id = $user->id;
        $dns->make_sp_at = time();
        $dns->updated_at = time();

        $domain_info = Domain::findOrFail($dns->domain_id);
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_info, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->redirect();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => '访问超时'
            ]);
        }
        // 保存
        $dns->save();
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }

    public function setSpide(Request $request)
    {
        $this->validate($request, [
            'proxy_url' => 'required',
            'sub_domain_id' => 'required',
            'proxy_method' => 'required',
        ], [
            'proxy_url.required' => '请输入抓取地址',
            'sub_domain_id.required' => '请选择二级域名',
            'proxy_method.required' => '请选择站点模式',
        ]);
        $proxy_method = $request->input('proxy_method', 'spide');
        $proxy_site = $request->input('proxy_url');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with('domain')->where('id', $domain_id)->firstOrFail();
        // 填充新的数据
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = $proxy_site;
        // 清理其他的不需要的文件
        $dns->mode = $dns->mode ? $dns->mode : 'SAFE';
        $dns->safe_generate = 'Y';
        $dns->make_sp_at = time();
        $dns->updated_at = time();
        $dns->user_id = $user->id;
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($dns->domain, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->spide();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => '采集失败'
            ]);
        }

        $dns->save();
        $domain_info = Domain::findOrFail($dns->domain_id);

        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }

    /**
     * 抓取站点
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setSpideBak(Request $request)
    {
        $this->validate($request, [
            'proxy_url' => 'required',
            'sub_domain_id' => 'required',
            'proxy_method' => 'required',
        ], [
            'proxy_url.required' => '请输入抓取地址',
            'sub_domain_id.required' => '请选择二级域名',
            'proxy_method.required' => '请选择站点模式',
        ]);
        $proxy_method = $request->input('proxy_method', 'spide');
        $proxy_site = $request->input('proxy_url');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with('domain')->where('id', $domain_id)->firstOrFail();
        // 填充新的数据
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = $proxy_site;
        // 清理其他的不需要的文件
        $dns->mode = $dns->mode ? $dns->mode : 'SAFE';
        $dns->safe_generate = 'Y';
        $dns->make_sp_at = time();
        $dns->updated_at = time();
        $dns->user_id = $user->id;
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($dns->domain, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->spide();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => '采集失败'
            ]);
        }

        $dns->save();
        $domain_info = Domain::findOrFail($dns->domain_id);

        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        $this->synDomainAndDns($dns);
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }

    public function synDomainAndDns($dns){

        /**
        调用场景： 新增、删除、调整关联offer(新增、删除、调整权重)时
         **/
        $url = '/hw-facebook-admin/dataSync/synDomainAndDns';
        $api = new UnionOfferService();
        $repeat = 0;
        $maxRepeat = 3;
        $end = false;
        while ( !$end && $repeat < $maxRepeat ){
            $data = [
                'domainDns' => $dns,
            ];
            $res = $api->post($url,$data);
            $result = json_decode($res,true);
            if($result['code'] == 1){
                $end = true;
                break;
            }
            $repeat ++;
            if ( !$end ){
                sleep( $repeat * 1 );
            }
        }
    }

    public function subdomain($id, Request $request)
    {
        $user = Auth::guard('user')->user();
        $dns = DomainDns::where('id', $id)->firstOrFail();
        $domain_info = Domain::findOrFail($dns->domain_id);
        //$base_url = Helper::getBaseUri($dns->proxy_site);
        $dns->url = '';
        //$path = str_replace($base_url, '', $dns->proxy_site);
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }

        return response()->json([
            'errcode' => 0,
            'data' => $dns
        ]);
    }


    public function setRandPageBak(Request $request)
    {
        $this->validate($request, [
            'sub_domain_id' => 'required',
        ], [
            'sub_domain_id.required' => '请选择二级目录',
        ]);
        $proxy_method = $request->input('proxy_method', 'rand');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with('domain')->where('id', $domain_id)->firstOrFail();
        // 检查 spide的情况
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = '';
        // 清理其他的不需要的文件
        $dns->title = '';
        $dns->keyword = '';
        $dns->description = 0;
        $dns->cate_id = 0;
        $dns->lp_id = 0;
        $dns->offer_sku = '';
        $dns->offer_url = '';
        $dns->camp = '';
        $dns->signature = '';
        $dns->mode = 'SAFE';
        $dns->cloak_mode = '';
        $dns->offer_generate = 'N';
        $dns->safe_generate = 'Y';
        $dns->pixel_id = '';
        $dns->event_type = '';
        $dns->make_sp_at = time();
        $dns->updated_at = time();
        $dns->user_id = $user->id;
        // 尝试
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($dns->domain, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->random_page();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $dns->save();
        $domain_info = Domain::findOrFail($dns->domain_id);
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        $this->synDomainAndDns($dns);
        // 清理服务器缓存
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }
    /**
     * 生成随机页
     *
     * @param $id
     * @param Request $request
     */
    public function setRandPage(Request $request)
    {
        $this->validate($request, [
            'sub_domain_id' => 'required',
        ], [
            'sub_domain_id.required' => '请选择二级目录',
        ]);
        $proxy_method = $request->input('proxy_method', 'rand');
        $domain_id = $request->input('sub_domain_id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with('domain')->where('id', $domain_id)->firstOrFail();
        // 检查 spide的情况
        $dns->proxy_method = $proxy_method;
        $dns->proxy_site = '';
        // 清理其他的不需要的文件
        $dns->title = '';
        $dns->keyword = '';
        $dns->description = 0;
        $dns->cate_id = 0;
        $dns->lp_id = 0;
        $dns->offer_sku = '';
        $dns->offer_url = '';
        $dns->camp = '';
        $dns->signature = '';
        $dns->mode = 'SAFE';
        $dns->cloak_mode = '';
        $dns->offer_generate = 'N';
        $dns->safe_generate = 'Y';
        $dns->pixel_id = '';
        $dns->event_type = '';
        $dns->make_sp_at = time();
        $dns->updated_at = time();
        $dns->user_id = $user->id;
        // 尝试
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($dns->domain, $is_beta);
        $domain_service->setDns($dns);
        $result = $domain_service->random_page();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $dns->save();
        $domain_info = Domain::findOrFail($dns->domain_id);
        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full;
        } else {
            $dns->url = env('HTTP_SCHEME', 'https://') . $dns->domain_full . '/' . $dns->sub_domain;
        }
        // 清理服务器缓存
        return response()->json([
            'errcode' => 0,
            'data' => $dns,
        ]);
    }

    /**
     * 注入标题信息
     * @param Request $request
     * @return JsonResponse
     */
    public function inject(Request $request)
    {
        $sub_domain_id = $request->input('id');
        $user = Auth::guard('user')->user();
        $dns = DomainDns::where('id', $sub_domain_id)->firstOrFail();
        if ($dns->proxy_method == '301') {
            return response()->json([
                'errcode' => 20001,
                'msg' => '301跳转方式不支持标题注入'
            ]);
        }
        if ($dns->proxy_site == '') {
            return response()->json([
                'errcode' => 20002,
                'msg' => '请先生成安全页'
            ]);
        }
        $data = $request->only(['title', 'keyword', 'description']);
        $dns->fill($data);
        $domain_service = new DomainService($dns->domain);
        $domain_service->setDns($dns);
        $result = $domain_service->injectTitle();
        if (true !== $result) {
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '生成成功'
        ]);
    }

    /**
     * 删除子域名
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function delSubDomain($id, Request $request)
    {
        $user = Auth::guard('user')->user();
        $dns = DomainDns::with('domain')->where('id', $id)->where('user_id', $user->id)->firstOrFail();
        // 删除解析
        $domain_info = $dns->domain;
        if ($domain_info->type_domain != 'SUB_DOMAIN') {
            $is_beta = $request->input('_beta') ? true : false;
            $domain_service = new DomainService($domain_info, $is_beta);
            $domain_service->setDns($dns);
            $result = $domain_service->delete_path();
            if (true !== $result) {
                return response()->json([
                    'errcode' => 30001,
                    'msg' => $result
                ]);
            }
        }
        // $dns->delete();
        $dns->deleted_at = time();
        $dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '删除成功'
        ]);

    }

    /**
     * 生成广告落地页
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function generateAdsBak(Request $request)
    {
        $this->validate($request, [
            'id' => 'required',
            'cate_id' => 'required',
            'lp_id' => 'required',
            'camp_id' => 'required',
            'pixel_id' => 'required'
        ], [
            'id.required' => '请选择域名',
            'cate_id.required' => '请选择模板分类',
            'lp_id.required' => '请选择模板',
            'camp_id.required' => '请填写Campaign',
            'pixel_id.required' => '请填写像素编号'
        ]);
        $id = $request->input('id');
        $data = $request->only(['cate_id', 'lp_id', 'camp_id',  'pixel_id', 'event_type']);
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();

        $domain_info = $domain_dns->domain;
        $camp_id = $request->input('camp_id');
        $domain_dns->fill([
            'camp_id' => $camp_id,
            'lp_id' => $request->input('lp_id'),
        ]);
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_info, $is_beta);
        $domain_service->setDns($domain_dns);
        $result = $domain_service->create_loadpage();
        if (true !== $result) {
            // 生成失败
            return response()->json([
                'errcode' => 30001,
                'msg' => $result,
            ]);
        }
        // 更新像素到s3

        $data['make_lp_at'] = time();
        $data['updated_at'] = time();
        $data['offer_generate'] = 'Y';
        $domain_dns->fill($data);
        $domain_dns->save();

        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $domain_dns->url = env('HTTP_SCHEME', 'https://') . $domain_dns->domain_full;
        } else {
            $domain_dns->url = env('HTTP_SCHEME', 'https://') . $domain_dns->domain_full . '/' . $domain_dns->sub_domain;
        }
        $this->synDomainAndDns($domain_dns);
        return response()->json([
            'errcode' => 0,
            'data' => $domain_dns,
            'msg' => '生成成功'
        ]);
    }

    /**
     * 生成广告落地页
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function generateAds(Request $request)
    {
        $this->validate($request, [
            'id' => 'required',
            'cate_id' => 'required',
            'lp_id' => 'required',
            'offer_sku' => 'required',
            'offer_url' => 'required',
            'pixel_id' => 'required',
            'ads_no' => 'required',
        ], [
            'id.required' => '请选择域名',
            'cate_id.required' => '请选择模板分类',
            'lp_id.required' => '请选择模板',
            'offer_sku.required' => '请选择商品',
            'offer_url.required' => '请填写offer地址',
            'pixel_id.required' => '请填写像素编号',
            'ads_no.required' => '请填写广告编号',
        ]);
        $id = $request->input('id');
        $data = $request->only(['cate_id', 'lp_id', 'offer_sku', 'offer_url', 'pixel_id', 'event_type', 'ads_no']);
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();

        $domain_info = $domain_dns->domain;
        $offer_id = $request->input('offer_sku');
        $offer_url = $request->input('offer_url');
        $pixel_id = $request->input('pixel_id');
        $pixel_id = trim($pixel_id);
        $domain_dns->fill([
            'offer_url' => $offer_url,
            'lp_id' => $request->input('lp_id'),
            'offer_sku' => $offer_id,
            'pixel_id' => $pixel_id
        ]);
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_info, $is_beta);
        $domain_service->setDns($domain_dns);
        $result = $domain_service->create_loadpage();
        if (true !== $result) {
            // 生成失败
            return response()->json([
                'errcode' => 30001,
                'msg' => $result,
            ]);
        }
        // 更新像素到s3
        $ads_no = $request->input('ads_no');
        $ads_no = trim($ads_no);
        $offer_goods = DomainOfferSku::find($offer_id);
        if ($domain_dns->$ads_no != $ads_no || $domain_dns->pixel_id != $pixel_id) {
            $upload_pixel = $this->updatePixel($pixel_id, $ads_no, $offer_goods);
            if (false === $upload_pixel) {
                // 上传文件到s3失败
                return response()->json([
                    'errcode' => 20002,
                    'msg' => '生成像素文件失败'
                ]);
            }
        }

        if (false) {
            $insert_pixel_trace = $this->updateGoodsPixel($offer_goods, $upload_pixel);
            if (empty($insert_pixel_trace)) {
                return response()->json([
                    'errcode' => 20003,
                    'msg' => '像素更新失败'
                ]);
            }
        }
        $data['make_lp_at'] = time();
        $data['updated_at'] = time();
        $data['ads_no'] = strtoupper($data['ads_no']);
        //$data['pixel_url'] = $insert_pixel_trace->url;
        $data['pixel_url'] = "<iframe src=\"{$upload_pixel}\" frameborder=0 width=1 height=1></iframe>";
        $data['offer_generate'] = 'Y';
        $domain_dns->fill($data);

        $domain_dns->save();

        // 更新像素信息
        $pixel_data = [
            'ads_no' => strtoupper($data['ads_no']),
            'pixel_id' => $pixel_id,
            'frame_url' => "<iframe src=\"{$upload_pixel}\" frameborder=0 width=1 height=1></iframe>",
            'source_url' => $upload_pixel,
            'script_content' => '',
            'status' => 'ON',
            'updated_at' => time(),
            'user_id' => $user->id
        ];
        // 严格来讲，这里的ads_no 只能是自己的，不能和别人重
        $pixel_info = DomainPixel::firstOrCreate([
            'ads_no' => strtoupper($data['ads_no']),
        ], array_merge($pixel_data, ['created_at' => time()])
        );
        $pixel_info->fill($pixel_data);
        $pixel_info->save();

        if ($domain_info->type_domain == 'SUB_DOMAIN') {
            $domain_dns->url = env('HTTP_SCHEME', 'https://') . $domain_dns->domain_full;
        } else {
            $domain_dns->url = env('HTTP_SCHEME', 'https://') . $domain_dns->domain_full . '/' . $domain_dns->sub_domain;
        }
        return response()->json([
            'errcode' => 0,
            'data' => $domain_dns,
            'msg' => '生成成功'
        ]);
    }

    /**
     * 创建或者删除像素
     *
     * @param $goods
     * @param $src
     * @return mixed
     */
    protected function updateGoodsPixel($goods, $src)
    {
        $pixel = UnionGoodsPixel::where('url', 'like', "%{$src}%")->first();
        if ($pixel) {
            return $pixel;
        }

        return UnionGoodsPixel::create([
            'goods_id' => $goods->id,
            'is_global' => 'Y',
            'name' => 'Global Pixel',
            'fire_at' => 'Purchase',
            'fire_condition' => '',
            'format' => 'HTML/JS',
            'url' => "<iframe src=\"{$src}\" frameborder=0 width=1 height=1></iframe>",
            'created_at' => time(),
            'updated_at' => time(),
        ]);

    }


    /**
     * 生成到S3的像素
     *
     * @param $pixel_id
     * @param $ads_no
     * @return bool|mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function updatePixel($pixel_id, $ads_no, $goods)
    {
        $price = data_get($goods, 'price', 0);
        $ads_no = strtoupper($ads_no);
        $file_content = <<<GA
<!-- Facebook Pixel Code -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{$pixel_id}');
  fbq('track', 'PageView');
  fbq('track', 'Purchase', {
  value: {$price},
  currency: 'USD'
  });
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={$pixel_id}&ev=PageView&noscript=1"
/></noscript>
<!-- End Facebook Pixel Code -->
GA;
        $file_name = $ads_no . '.html';
        $put_result = Storage::disk('local')->put($file_name, $file_content);
        if (!$put_result) {
            return false;
        }
        Storage::disk('local')->get($file_name);
        // upload to s3
        $key = sprintf("camp:%s", $ads_no);
        Cache::put($key, $pixel_id, 3600);

        try {
            $s3 = App::make('aws')->createClient('s3');
            $result = $s3->putObject(array(
                'Bucket' => env('AWS_BUCKET', 'sz-001'),
                'Key' => $file_name,
                'SourceFile' => storage_path('app/' . $file_name),
                'ACL' => 'public-read'
            ));
            \Log::debug('result', [$result]);
            $url = data_get($result, 'ObjectURL');
            Storage::disk('local')->delete($file_name);
            return $url;
        } catch (\Exception $e) {
            \Log::error($e->getMessage(), $e->getTrace());
            return false;
        }
    }

    public function changeModeBak($id, Request $request)
    {
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();
        $origin_mode = $domain_dns->mode;
        $mode = $request->input('mode');
        $mode = $mode === 'OFFER' ? 'OFFER' : 'SAFE';
        $domain_dns->mode = $mode;
        $domain_dns->updated_at = time();
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_dns->domain, $is_beta);
        $domain_service->setDns($domain_dns);
        if ($mode == 'OFFER') {
            $is_js_cloak = $request->input('is_js_cloak') ? true : false;
            if ($is_js_cloak) {
                $result = $domain_service->change_js_loadpage();
            } else {
                $result = $domain_service->change_loadpage();
            }
        } else {
            $result = $domain_service->change_safe();
        }
        if (true !== $result) {
            // 切换失败
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $domain_dns->change_mode_at = time();
        $sub_path = $domain_dns->sub_domain;
        if ($domain_dns->domain->type_domain == 'SUB_DOMAIN') {
            $sub_path = '';
        }
        $domain_dns->save();
        $this->synDomainAndDns($domain_dns);
        return response()->json([
            'errcode' => 0,
            'msg' => '切换完成'
        ]);
    }
    /**
     * 切换落地页
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function changeMode($id, Request $request)
    {
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();
        $origin_mode = $domain_dns->mode;
        $mode = $request->input('mode');
        $mode = $mode === 'OFFER' ? 'OFFER' : 'SAFE';
        $domain_dns->mode = $mode;
        $domain_dns->updated_at = time();
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_dns->domain, $is_beta);
        $domain_service->setDns($domain_dns);
        if ($mode == 'OFFER') {
            $is_js_cloak = $request->input('is_js_cloak') ? true : false;
            if ($is_js_cloak) {
                $result = $domain_service->change_js_loadpage();
            } else {
                $result = $domain_service->change_loadpage();
            }
        } else {
            $result = $domain_service->change_safe();
        }
        if (true !== $result) {
            // 切换失败
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $domain_dns->change_mode_at = time();
        $sub_path = $domain_dns->sub_domain;
        if ($domain_dns->domain->type_domain == 'SUB_DOMAIN') {
            $sub_path = '';
        }
        $domain_dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '切换完成'
        ]);
    }


    public function cloakMode($id, Request $request)
    {
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();
        $origin_mode = $domain_dns->render_mode;
        $mode = $request->input('mode');
        $domain_dns->render_mode = $mode;
        $domain_dns->updated_at = time();
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_dns->domain, $is_beta);
        $domain_service->setDns($domain_dns);
        $result = $domain_service->resetConfig();
        if (true !== $result) {
            // 切换失败
            return response()->json([
                'errcode' => 30001,
                'msg' => $result
            ]);
        }
        $sub_path = $domain_dns->sub_domain;
        if ($domain_dns->domain->type_domain == 'SUB_DOMAIN') {
            $sub_path = '';
        }
        $domain_dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => '切换完成'
        ]);
    }


    /**
     * 设置cloak
     *
     * @param $id
     * @param Request $request
     *
     * @return
     * @throws
     */
    public function setCloak($id, Request $request)
    {
        $locale = $request->input('locale', 'zh');
        $camp_required = $locale == 'en' ? 'campagin id required' : '请填写camp';
        $signature_required = $locale == 'en' ? 'campaign signature required' : '请填写Signature';
        $this->validate($request, [
            'camp' => 'required_if:cloak_mode,MANUAL',
            'signature' => 'required_if:cloak_mode,MANUAL',
            'cloak_mode' => 'required'
        ], [
            'camp.required_if' => $camp_required,
            'signature.required_if' => $signature_required,
            'cloak_mode.required' => $locale == 'en' ? 'cloak mode required' : '请选择执行方式'
        ]);
        $user = Auth::guard('user')->user();
        $domain_dns = DomainDns::with('domain')->where('id', $id)->firstOrFail();
        $camp = $request->input('camp', '');
        $camp = trim($camp);
        $signature = $request->input('signature', '');
        $signature = trim($signature);
        $mode = $request->input('cloak_mode', '');
        $domain_dns->updated_at = time();
        $domain_dns->camp = $camp;
        $domain_dns->signature = $signature;
        $domain_dns->cloak_mode = $mode;
        $domain_dns->update_cloak_at = time();
        $is_beta = $request->input('_beta') ? true : false;
        $domain_service = new DomainService($domain_dns->domain, $is_beta);
        $domain_service->setDns($domain_dns);
        $result = $domain_service->resetConfig();
        if (true !== $result) {
            return response()->json([
                'errcode' => 50001,
                'msg' => $locale == 'en' ? 'request timeout' : '站点获取超时'
            ]);
        }
        $domain_dns->save();
        return response()->json([
            'errcode' => 0,
            'msg' => $locale == 'en' ? 'Success' : '设置成功'
        ]);
    }


    public function setPixel($id, Request $request)
    {
        if (false) {
            $this->validate($request, [
                'pixel_id' => 'required',
                'event_type' => 'required',
            ], [
                'pixel_id.required' => '请填写像素',
                'event_type.required' => '请选择追踪事件'
            ]);
        }
        $dns = DomainDns::findOrFail($id);
        $dns->safe_pixel_id = $request->input('pixel_id');
        $dns->safe_event_type = $request->input('event_type');
        $dns->updated_at = time();
        $dns->save();
        $api_result = $this->resetSite($id, 'set-pixel');
        if (false === $api_result) {
            return response()->json([
                'errcode' => 50001,
                'msg' => '站点获取超时'
            ]);
        }
        if (data_get($api_result, 'errcode') != 0) {
            return response()->json([
                'errcode' => 50002,
                'msg' => data_get($api_result, 'msg')
            ]);
        }
        return response()->json([
            'errcode' => 0,
            'msg' => '设置成功'
        ]);
    }

    /**
     * 操作日志
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function operationLogs(Request $request)
    {
        $user = Auth::guard('user')->user();
        $user_id = data_get($user, 'id');
        $sql = <<<ED
SELECT
	domain.domain,
	domain_dns.id,
	domain_dns.sub_domain,
	domain_dns.updated_at
FROM
	domain_dns
	LEFT JOIN domain ON domain_dns.domain_id = domain.id
WHERE
	domain.user_id = {$user_id}
	AND domain_dns.deleted_at = 0 
	AND domain_dns.user_id = {$user_id} 
	ORDER BY domain_dns.updated_at DESC
	LIMIT 200
ED;
        $data = DB::select($sql);
        if (!empty($data)) {
            foreach ($data as $k => $val) {
                $data[$k]->domain = $val->sub_domain . '.' . $val->domain;
                $data[$k]->updated_at = date('d/m H:i', $val->updated_at);
            }
        }
        return response()->json([
            'errcode' => 0,
            'data' => $data
        ]);

    }


    public function find_ads_no(Request $request)
    {
        $url = $request->input('url');
        $no = $request->input('ads_no');
        $no = trim($no);
        $result = get_headers($url, 1);
        $has_find = false;
        $location = '';
        if (false !== $result) {
            $locations = data_get($result, 'Location', []);
            $location = data_get($locations, 1);
            if ($location) {
                $arr = parse_url($location);
                $query = data_get($arr, 'query');
                parse_str($query, $params);
                foreach ($params as $k => $val) {
                    if ($val == $no) {
                        $has_find = true;
                        break;
                    }
                }
            }
        }
        return response()->json([
            'errcode' => 0,
            'data' => [
                'status' => $has_find ? 'OK' : 'FAIL',
                'offer_url' => $location
            ],
            'msg' => '您的投手编号'
        ]);
    }

    public function num(Request $request)
    {
        $expireTime = strtotime(date("Y-m-d", strtotime("+1 week")) . '23:59:50');
        $count = \DB::table('domain')
            ->where('expire_at', '>', $expireTime)
            ->where('sale_status', 'AI')
            ->count();
        return response()->json([
            'errcode' => 0,
            'data' => $count
        ]);
    }

    public function bill(Request $request)
    {
        $admin_user = Auth::guard('api')->user();
        $query = DomainSaleBill::with([
            'user' => function ($query) {
                return $query->select(['id', 'name']);
            },
            'company' => function ($query) {
                return $query->select(['org_short_name', 'org_name', 'id']);
            }]);
        $sort = $request->input('sort');
        if (trim($sort) != '') {
            if (strtolower($sort) == 'id desc') {
                $query = $query->OrderBy('id', 'desc');
            } else {
                $query = $query->OrderBy('id', 'asc');
            }
        } else {
            $query = $query->OrderBy('id', 'desc');
        }

        if ($admin_user->id != SUPPER_ADMIN_ID) {
            if ($admin_user->company_id) {
                $query->where('company_id', $admin_user->company_id);
            }
        } else {
            $company_id = $request->input('company_id');
            if ($company_id) {
                $query->where('company_id', $company_id);
            }
        }
        $pagesize = $request->input('pagesize');

        $created_at = $request->input('created_at');
        if ($created_at) {
            $created_at = strtotime($created_at . ' 00:00:00');
            $query = $query->where('created_at', '>=', $created_at)->where('created_at', '<', $created_at + 86400);
        }
        $result = $query->paginate($pagesize);
        return response()->json([
            'errcode' => 0,
            'data' => [
                'items' => $result->items(),
                'total' => $result->total()
            ]
        ]);

    }

    public function export($id, Request $request)
    {
        $admin_user = Auth::guard('api')->user();
        $export = Export::create([
            'payload' => serialize(compact('id', 'user')),
            'status' => 'AI',
            'file_name' => '',
            'file_path' => '',
            'create_user_id' => $admin_user->id,
            'created_at' => time(),
            'updated_at' => time()
        ]);

        $job = new DomainSaleBillJob($export->id, $id, $admin_user);
        dispatch($job)->onQueue('user_export');

        return response()->json([
            'errcode' => 0,
            'data' => $export,
            'msg' => '正在为您导出'
        ]);
    }


    public function sale(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required',
            'num' => 'required'
        ], [
            'num.required' => '请输入购买数量',
            'user_id.required' => '请指定分配的用户'
        ]);
        $expireTime = strtotime(date("Y-m-d", strtotime("+1 week")) . '23:59:50');
        $num = $request->input('num');
        $count = \DB::table('domain')
            ->where('expire_at', '>', $expireTime)
            ->where('sale_status', 'AI')
            ->count();
        if ($count < $num) {
            return response()->json([
                'errcode' => 40001,
                'msg' => '最多只能购买' . $num . '个'
            ]);
        }
        $userId = $request->input('user_id');
        $userInfo = User::where('id', $userId)->first();
        $admin_user = Auth::guard('api')->user();
        DB::beginTransaction();
        try {
            $domains = \DB::table('domain')
                ->where('expire_at', '>', $expireTime)
                ->where('sale_status', 'AI')
                ->take($num)
                ->get();
            $id_array = [];
            if ($domains) {
                $result = $domains->toArray();
                $id_array = array_column($result, 'id');
            }
            $details = [];
            foreach ($domains as $u) {
                $details[] = new DomainSale([
                    'create_user_id' => $admin_user->id,
                    'status' => 'ON',
                    'user_id' => $userId,
                    'domain_id' => $u->id,
                    'domain_name' => $u->domain,
                    'expire_at' => $u->expire_at,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
            $insertBill = [
                'num' => count($details),
                'create_user_id' => $admin_user->id,
                'user_id' => $userId,
                'company_id' => $userInfo->company_id,
                'created_at' => time(),
                'updated_at' => time(),
                'status' => 'ON'
            ];
            //插入订单表
            $order = DomainSaleBill::create($insertBill);
            if (empty($order)) {
                throw new \Exception("订单创建失败");
            }
            //批量更新domain已出售状态
            Domain::whereIn('id', $id_array)->update([
                'sale_status' => 'ON'
            ]);
            //批量更新domain_sale详情
            if (!empty($details)) {
                $result = $order->domain()->saveMany($details);
                if (false === $result) {
                    throw new \Exception("域名订单详情更新失败");
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            \Log::debug('订单创建出错', $e->getTrace());
            return;
        }
        return response()->json([
            'errcode' => 0,
            'msg' => '分配成功'
        ]);
    }



}
