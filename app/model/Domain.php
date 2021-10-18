<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\model;

use think\facade\Cache;
use think\facade\Db;
use think\Model;
use app\services\CloudFlareService;

class Domain extends Model {

	protected $type = [
		'expire_at'     => 'timestamp:Y-m-d H:i:s',
		'bind_at'       => 'timestamp:Y-m-d H:i:s',
		'created_at'    => 'timestamp:Y-m-d H:i:s',
		'update_at'     => 'timestamp:Y-m-d H:i:s',
		'delete_at'     => 'timestamp:Y-m-d H:i:s',
	];

	public static function onAfterWrite($domain){
		//进行域名解析
		$cloudf = new CloudFlareService();
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
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		Cache::set(str_replace(".", "_", $domain['domain']), null);
	}

	public static function onAfterDelete($domain){
		$cloudf = new CloudFlareService();
		$result = $cloudf->zoneDelete($domain['zone_id']);
	}

	public function getDataList($request){
	    $domain = $request->param('domain', '');
		$map = [];

        $map[] = ['domain', 'LIKE', '%' . $domain . '%'];
		$data = self::where($map)->order('id desc')->paginate($request->pageConfig);
		return $data;
	}
}