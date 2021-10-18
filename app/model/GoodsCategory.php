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
use think\Model;

class GoodsCategory extends Model {

	protected function getStatusTextAttr($value, $data){
		$status = [0 => '禁用', 1 => '启用'];
		return isset($status[$data['status']]) ? $status[$data['status']] : '未知';
	}

	/**
	 * @title 根据条件获取商品数据
	 */
	public function getDataList($request){
		$map = [];

		$data = self::where($map)->order('id desc')->paginate($request->pageConfig);
		return $data;
	}
}