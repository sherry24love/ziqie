<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\user;

use app\model\GoodsCategory;
use app\model\Goods as GoodsM;

/**
 * @title 产品模块
 */
class Goods extends Base {

	public function index(){
		$param = $this->request->param();
		$map = [];
		if(isset($param['category_id']) && $param['category_id']){
			$map[] = ['category_id', '=', $param['category_id']];
		}

		$list = GoodsM::where($map)->order('id desc')->select();

		$this->data['data'] = $list;
		return $this->data;
	}
}