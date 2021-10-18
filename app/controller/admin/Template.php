<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\controller\admin;


/**
 * @title 模板管理
 * @description 模板管理
 */
class Template extends Base {

	/**
	 * @title 模板管理
	 * @description 模板管理
	 */
	public function index(){
		$this->data = [
			'list'   => (new \app\services\TemplateService())->getTemplateList()
		];
		return $this->fetch();
	}

	/**
	 * @title 新增模板
	 * @description 新增模板
	 */
	public function add(){
		return $this->fetch();
	}
}