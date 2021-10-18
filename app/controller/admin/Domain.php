<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\admin;

use app\model\Domain as DomainM;
use app\model\DomainDns;
use app\model\GoodsCategory;
use think\facade\Cache;

/**
 * @title 域名管理
 * @description 域名管理
 */
class Domain extends Base {

	/**
	 * @title 域名列表
	 * @description 域名管理
	 */
	public function index(DomainM $domain){
		$list = $domain->getDataList($this->request);

		$this->data = [
			'list' => $list,
			'page' => $list->render(),
			'param' => $this->request->param()
		];
		return $this->fetch();
	}

	/**
	 * @title 新增域名
	 * @description 域名管理
	 */
	public function add(){
		if($this->request->isPost()){
			$domains = $this->request->post('domain');
			$expire_at = $this->request->post('expire_at');
			$domains = explode("\n", $domains);
			foreach ($domains as $key => $item) {
				$item = strtolower(trim($item));
				if ($item) {
					$domains[$key] = $item;
				} else {
					unset($domains[$key]);
				}
			}
			$host_type = "SUB_PATH";
			$host = env('HOST', '127.0.0.1');
			$domain_type = "SUB_PATH";

			$inserts = [];
			foreach ($domains as $item) {
				$exists = DomainM::where('domain', $item)->find();
				if(!$exists){
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
						'create_user_id' => $this->request->user['uid'],
						'created_at' => time(),
						'updated_at' => 0,
						'deleted_at' => 0,
						'delete_user_id' => 0,
						'company_id' => 0
					];
				}
			}
			if(!empty($inserts)){
				$result = (new DomainM())->saveAll($inserts);
			}else{
				return $this->error("无数据或已存在！");
			}
			
			if(false !== $result){
				Cache::set('domain', null);
				return $this->success("添加成功！", '/admin/domain/index');
			}else{
				return $this->error("添加失败！");
			}
		}else{
			return $this->fetch('add');
		}
	}

	/**
	 * @title 编辑域名
	 * @description 域名管理
	 */
	public function edit(){
		if($this->request->isPost()){
			$data = $this->request->post();
			if(!$data['id']){
				return $this->error("非法操作！");
			}
			$info = DomainM::find($data['id']);

			try {
				$result = $info->save($data);
			} catch (\Exception $e) {
				$result = false;
				return $this->error($e->getMessage());
			}

			if(false !== $result){
				return $this->success("编辑成功！", '/admin/domain/index');
			}else{
				return $this->error("编辑失败！");
			}
		}else{
			$id = $this->request->param('id', 0);
			$this->data = [
				'info' => DomainM::find($id)
			];
			return $this->fetch();
		}
	}

	/**
	 * @title 删除域名
	 * @description 删除域名
	 */
	public function del(){
		$id = $this->request->param('id');
		if(!is_array($id)){
			if($id){
				$id = [$id];
			}else{
				return $this->error('非法操作！');
			}
		}

		$result = DomainM::destroy($id);
		if(false !== $result){
			return $this->success('删除成功！');
		}else{
			return $this->error('删除失败！');
		}
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
}