<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\front;

use app\model\Domain;
use app\model\DomainDns;
use app\model\Goods;
use think\facade\Cache;
use think\facade\Db;
use app\services\CloudFlareService;
use app\services\CloakService;

class Index extends Base {
	
	/**
	 * @title 网站首页
	 * @return [type] [description]
	 */
	public function index() {
		$this->setSeo("网站首页", '网站首页', '网站首页');
		return $this->fetch();
	}
	
	public function CloudFlare(){
		//进行域名解析
		$cloudf = new CloudFlareService();
		$domain = Db::name('domain')->where('dns_1 is NULL')->find();
		if($domain){
		    try {
    			$result = $cloudf->zoneStore('', $domain['domain']);
    			if($result){
    				$cloudf->createDns($result['id'], 'www', $domain['host']);
    				$cloudf->createDns($result['id'], '@', $domain['host']);
    				$file = app()->getRootPath() . DIRECTORY_SEPARATOR . 'spidesite' . DIRECTORY_SEPARATOR . $domain['domain'];
    				if(!file_exists($file)){
    					mkdir($file, 0777, true);
    				}
    				return Db::name('domain')->where('id', $domain['id'])->save(['zone_id'=>$result['id'], 'dns_1'=>$result['name_servers'][0], 'dns_2'=>$result['name_servers'][1], 'status' => 'MX', 'updated_at'=>time()]);
    			}else{
    				return '未知原因';
    			}
    		} catch (\Exception $e) {
    			return '未知错误';
    		}
    		Cache::set(str_replace(".", "_", $domain['domain']), null);
		}
	}

	/**
	 * @title 网站首页
	 * @return [type] [description]
	 */
	public function domain() {
		$host = $this->request->host();
		$host = str_replace("www.", "", explode(':', $host)[0]);
		$sub_domain = $this->request->pathinfo();
		$domain = Cache::get(str_replace(".", "_", $host));
		if(!$domain){
			$domain = Domain::where('domain', $host)->findOrEmpty();
			if(!$domain->isEmpty()){
				$domain = $domain->toArray();
				Cache::set(str_replace(".", "_", $host), $domain);
			}else{
				return "";
			}
		}
		
		$dns = Cache::get('sub_domain_'.$domain['id']. '_' . $sub_domain);
		if(!$dns){
			$dns = DomainDns::where('domain_id', $domain['id'])->where('sub_domain', $sub_domain)->findOrEmpty();
			if(!$dns->isEmpty()){
				$dns = $dns->toArray();
				Cache::set('sub_domain_'.$domain['id']. '_' . $sub_domain, $dns);
			}else{
				return "";
			}
		}

		if(isset($dns['offer_sku']) && $dns['offer_sku']){
			$goods = Goods::where('id', $dns['offer_sku'])->append(['cover'])->findOrEmpty();
			// $goods = Cache::get('goods_' . $dns['offer_sku']);
			// if(!$goods){
			// 	$goods = Goods::where('id', $dns['offer_sku'])->append(['cover'])->findOrEmpty();
			// 	if(!$goods->isEmpty()){
			// 		$goods = $goods->toArray();
			// 		Cache::set('goods_' . $dns['offer_sku'], $goods);
			// 	}else{
			// 		$goods = [];
			// 	}
			// }else{
			// 	$goods = [];
			// }
		}else{
			$goods = [];
		}
		$pixel_url = $dns['pixel_url'] ? parse_url($dns['pixel_url'])['path'] : '';
		$pixel_url = $pixel_url ? str_replace(".html", "_h.html", $pixel_url) : '';
        $pixel_html = file_exists('.' . $pixel_url) ? file_get_contents('.' . $pixel_url) : '';

		$this->data = [
			'dns'  => $dns,
			'goods' => $goods,
			'pixel_html' => $pixel_html
		];
		$temp = "";
		if($dns){
			if($dns['mode'] == 'SAFE'){
				if($dns['proxy_method'] == '301' && $dns['proxy_site'] != ''){
					header("Location: ".$dns['proxy_site']);
				}else{
					$file = app()->getRootPath() . 'spidesite/'.$domain['domain'] . '/' . $sub_domain .'/safe.html';
					if(file_exists($file)){
						$temp = file_get_contents($file);
					}else{
						$temp = "<h1>未采集数据</h1>";
					}
				}
			}elseif($dns['mode'] == 'OFFER'){
			 //   $key = "dnfyaglmqbzntdeidsirochysjopjapfc3jyo5vmrgg0jmri";
    //             $token = $this->request->get("lptoken", '');
    //             $t = substr($token, 0, 2) . substr($token, 4, 2) . substr($token, 8, 2) . substr($token, 12, 2) . substr($token, 16, 2);
    //             $c = substr($token, 2, 2) . substr($token, 6, 2) . substr($token, 10, 2) . substr($token, 14, 2) . substr($token, 18, 2);
    //             $m = md5($key . $t . $_SERVER["HTTP_USER_AGENT"]);
    //             $mp = substr($m, 0, 2) . substr($m, 5, 2) . substr($m, 12, 2) . substr($m, 19, 2) . substr($m, 26, 2);
    //             if (time() > $t || $mp !== $c) {
    //             	exit(0);
    //             }
			    
			    $campaign_id = $dns['camp'] ? $dns['camp'] : '';
			    $campaign_signature = $dns['signature'] ? $dns['signature'] : '';
                $cloak = new CloakService($campaign_id, $campaign_signature);
                $data = $cloak->httpRequestMakePayload();
                $response = $cloak->httpRequestExec($data, $campaign_id);
                $handler = $cloak->httpHandleResponse($response, true);
                if($handler && $campaign_id != ''){
    				if($dns['proxy_method'] == '301' && $dns['proxy_site'] != ''){
    					header("Location: ".$dns['proxy_site']);
    				}else{
    					$file = app()->getRootPath() . 'spidesite/'.$domain['domain'] . '/' . $sub_domain .'/safe.html';
    					if(file_exists($file)){
    						$temp = file_get_contents($file);
    					}else{
    						$temp = "<h1>未采集数据</h1>";
    					}
    				}
                }else{
    				$file = app()->getRootPath() . 'public/themes/'.$dns['lp_template'].'/index.html';
    				if(file_exists($file)){
    					$temp = $this->fetch($file);
    					// todo 这里可以替换像素

    				}else{
    					$temp = "<h1>未选择模板</h1>";
    				}
                }
			}
		}else{
			$temp = '';
		}
		return $temp;
	}

	/**
	 * @title miss
	 * @return [type] [description]
	 */
	public function miss(){
		return $this->fetch();
	}
}
