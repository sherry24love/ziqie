<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\user;

use app\model\Domain;
use app\model\DomainDns;
use app\model\GoodsCategory;
use think\facade\Session;
use think\facade\Cache;
use app\services\ConfigService;
use app\services\CloudFlareService;

/**
 * @title 页面生成
 */
class Generate extends Base
{

    /**
     * @title 页面生成
     */
    public function index()
    {
        $this->data = [
            'goods_category' => GoodsCategory::select(),
            'template' => (new \app\services\TemplateService())->getTemplateList(),
            'require' => ['jsname' => 'domain', 'actionname' => 'generate']
        ];
        return $this->fetch();
    }

    /**
     * @title 校验域名
     */
    public function verify()
    {
        $domain = $this->request->param('domain', '');
        $info = Domain::where('domain', $domain)->find();

        if ($info) {
            if (strtotime($info['expire_at']) < time()) {
                return $this->error('域名已经过期，请更换域名!');
            } else {
                $this->data = [
                    'code' => 1,
                    'data' => $info,
                    'msg' => '正常！'
                ];
                return $this->data;
            }
        } else {
            return $this->error('该域名不存在!');
        }
    }

    public function dns()
    {
        $domain_id = $this->request->param('domain');
        $this->data['code'] = 1;
        $this->data['data'] = DomainDns::where('domain_id', $domain_id)->field(['sub_domain', 'id'])->select();
        return $this->data;
    }

    public function dnsinfo()
    {
        $dns_id = $this->request->param('dns_id');
        $info = DomainDns::where('id', $dns_id)->find();

        if ($info) {
            $this->data['code'] = 1;
            $this->data['data'] = $info;
        } else {
            $this->data['code'] = 0;
            $this->data['data'] = [];
        }
        return $this->data;
    }

    public function delsubpath()
    {
        $dns_id = $this->request->param('dns_id');
        $result = DomainDns::destroy($dns_id);

        if (false !== $result) {
            return $this->success('删除成功！');
        } else {
            return $this->error('删除失败！');
        }
    }

    public function togglepage()
    {
        $dns_id = $this->request->param('dns_id');
        $type = $this->request->param('type', 'OFFER');

        $dns = DomainDns::find($dns_id);
        if (!$dns) {
            return $this->error('无法切换！');
        }
        $campaign_id = $this->request->param('campaign_id', '');
        $campaign_signature = $this->request->param('campaign_signature', '');
        $save = ['mode' => $type, 'camp' => $campaign_id, 'signature' => $campaign_signature];
        Cache::delete('sub_domain_' . $dns['domain_id'] . '_' . $dns['sub_domain']);
        $result = $dns->save($save);
        if (false !== $result) {
            return $this->success('已切换');
        } else {
            return $this->error('未切换');
        }
    }

    public function createpath()
    {
        $domain_id = $this->request->param('domain_id');
        $sub_path = $this->request->param('sub_path');

        $domain = Domain::find($domain_id);
        $dns = DomainDns::where('domain_id', $domain_id)->where('sub_domain', $sub_path)->find();
        if ($dns) {
            return $this->error('该子目录已存在！');
        }

        $data = [
            'domain_id' => $domain_id,
            'sub_domain' => $sub_path,
            'zone_id' => $domain['zone_id'],
            'domain_full' => 'http:://www.' . $domain['domain'] . '/' . $sub_path
        ];

        $result = DomainDns::create($data);
        if (false !== $result) {
            $conf = new ConfigService($domain['domain'], $this->request);
            $conf->create_path($sub_path);
            return $this->success('创建成功！');
        } else {
            return $this->error('创建失败！');
        }
    }

    public function spide_content()
    {
        $domain_id = $this->request->param('domain_id');
        $sub_path = $this->request->param('sub_path');
        $domain = Domain::find($domain_id);

        $config = new ConfigService($domain['domain'], $this->request);
        $content = $config->spide_content();

        $dns = DomainDns::where('domain_id', $domain_id)->where('sub_domain', $sub_path)->find();

        $data = [
            'proxy_method' => 'REVERSE',
            'proxy_site' => $this->request->param('proxy_site')
        ];
        $result = DomainDns::update($data, ['id' => $dns['id']]);
        if (false !== $result) {
            return $this->success('采集成功！');
        } else {
            return $this->error('采集失败！');
        }
    }

    public function set301()
    {
        $domain_id = $this->request->param('domain_id');
        $sub_path = $this->request->param('sub_path');
        $domain = Domain::find($domain_id);

        $dns = DomainDns::where('domain_id', $domain_id)->where('sub_domain', $sub_path)->find();

        $data = [
            'proxy_method' => '301',
            'proxy_site' => $this->request->param('proxy_site')
        ];
        $result = DomainDns::update($data, ['id' => $dns['id']]);
        if (false !== $result) {
            return $this->success('生成成功！');
        } else {
            return $this->error('生成失败！');
        }
    }

    public function injectTitle()
    {
        $dns_id = $this->request->param('dns_id');
        if (!$dns_id) {
            return $this->error('非法操作！');
        }
        $dns = DomainDns::find($dns_id);
        $domain = Domain::where('id', $dns['domain_id'])->value('domain');

        //判断是否已采集数据
        $file = app()->getRootPath() . 'spidesite' . DIRECTORY_SEPARATOR . $domain . DIRECTORY_SEPARATOR . $dns['sub_domain'] . DIRECTORY_SEPARATOR . 'safe.html';
        echo $file;
        if (!file_exists($file)) {
            return $this->error('请先采集生成页面！');
        } else {
            $config = new ConfigService($domain, $this->request);
            $content = $config->injectTitle();
        }
        $data = [
            'title' => $this->request->param('title'),
            'keyword' => $this->request->param('keyword'),
            'description' => $this->request->param('description'),
            'updated_at' => time(),
            'inject_title_at' => time()
        ];

        $result = $dns->save($data);
        if (false !== $result) {
            return $this->success('已注入！');
        } else {
            return $this->error('注入失败！');
        }
    }

    public function updatedns()
    {
        $data = $this->request->post();
        $dns = DomainDns::find($data['sub_path']);

        $save = [
            'id' => $dns['id'],
            'make_sp_at' => time(),
            'cate_id' => $data['cate_id'],
            'lp_template' => $data['template'],
            'ads_no' => $data['ads_no'],
            'offer_url' => $data['offer_url'],
            'pixel_id' => $data['pixel_id'],
            'pixel_type' => $data['pixel_type'],
            'offer_sku' => $data['offer_sku']
        ];
        foreach ($save as $key => $value) {
            if (in_array($key, ['cate_id', 'lp_template', 'ads_no', 'offer_url', 'pixel_type', 'pixel_id', 'offer_sku']) && $value == '') {
                return $this->error('请把信息填写完整！');
            }
        }
        $save['domain'] = Domain::where('id', $dns['domain_id'])->value('domain');
        $save['pixel_url'] = (new DomainDns())->updatePixel($save);

        $result = $dns->save($save);
        if (false !== $result) {
            return $this->success('已生成！');
        } else {
            return $this->error('生成失败！');
        }
    }
}