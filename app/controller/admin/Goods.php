<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\admin;

use app\model\Goods as GoodsM;
use app\model\GoodsCategory;

/**
 * @title 商品管理
 * @description 商品管理
 */
class Goods extends Base {

	/**
	 * @title 商品列表首页
	 * @description 商品列表首页展示
	 */
	public function index(GoodsM $goods){
		$list = $goods->getDataList($this->request);

		$this->data = [
			'list' => $list,
			'page' => $list->render(),
		];
		return $this->fetch();
	}

	/**
	 * @title 新增商品
	 * @description 商品管理
	 */
	public function add(){
		if($this->request->isPost()){
			$data = $this->request->param();

			$result = GoodsM::create($data);

			if(false !== $result){
				return $this->success('添加成功！', url('/admin/goods/index'));
			}else{
				return $this->error('添加失败！！');
			}
		}else{
			$this->data = [
				'category' => GoodsCategory::where('status', 1)->select(),
			];
			return $this->fetch('add');
		}
	}

	/**
	 * @title 编辑商品
	 * @description 商品管理
	 */
	public function edit(){
		if($this->request->isPost()){
			$data = $this->request->param();
			$info = GoodsM::find($data['id']);

			$result = $info->save($data);

			if(false !== $result){
				return $this->success('修改成功！', url('/admin/goods/index'));
			}else{
				return $this->error('修改失败！！');
			}
		}else{
			$id = $this->request->param('id');
			$this->data = [
				'category' => GoodsCategory::where('status', 1)->select(),
				'info'     => GoodsM::find($id)
			];
			return $this->fetch('add');
		}
	}

	/**
	 * @title 删除商品
	 * @description 商品管理
	 */
	public function del(){
		$id = $this->request->param('id');

		$result = GoodsM::where('id', $id)->delete();

		if(false !== $result){
			return $this->success('删除成功！');
		}else{
			return $this->error('删除失败！！');
		}
	}

	/**
	 * @title 商品分类
	 * @description 商品分类
	 */
	public function category(GoodsCategory $category){
		$list = $category->getDataList($this->request);

		$this->data = [
			'list' => $list,
			'page' => $list->render(),
		];
		return $this->fetch();
	}

	/**
	 * @title 新增商品分类
	 * @description 商品管理
	 */
	public function addcate(){
		if($this->request->isPost()){
			$data = $this->request->param();

			$result = GoodsCategory::create($data);

			if(false !== $result){
				return $this->success('添加成功！', url('/admin/goods/category'));
			}else{
				return $this->error('添加失败！！');
			}
		}else{
			return $this->fetch('addcate');
		}
	}

	/**
	 * @title 编辑商品分类
	 * @description 商品管理
	 */
	public function editcate(){
		if($this->request->isPost()){
			$data = $this->request->param();
			$info = GoodsCategory::find($data['id']);

			$result = $info->save($data);

			if(false !== $result){
				return $this->success('修改成功！', url('/admin/goods/category'));
			}else{
				return $this->error('修改失败！！');
			}
		}else{
			$id = $this->request->param('id');
			$this->data = [
				'info'  => GoodsCategory::find($id),
			];
			return $this->fetch('addcate');
		}
	}

	/**
	 * @title 删除商品分类
	 * @description 商品管理
	 */
	public function delcate(){
		$id = $this->request->param('id');

		$result = GoodsCategory::where('id', $id)->delete();

		if(false !== $result){
			return $this->success('删除成功！');
		}else{
			return $this->error('删除失败！！');
		}
	}
}