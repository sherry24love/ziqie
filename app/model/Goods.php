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

class Goods extends Model {

	public static function onAfterWrite($goods){
		Cache::delete('goods_' . $goods['id']);
	}

	protected function getCategoryAttr($value, $data){
		$category = GoodsCategory::find($data['category_id']);
		return $category ? $category['title'] : '未知';
	}

	protected function getCoverAttr($value, $data){
		$cover = get_attach($data['cover_id']);
		$cover = $cover ? $cover->toArray() : [];
		return $cover;
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