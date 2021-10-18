<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------
namespace app\http\validate;

use think\Validate;

/**
 * 域名验证
 */
class Domain extends Validate{
	protected $rule = [
		'domain' => 'require',
		'expire_at' => 'require|date',
	];

	protected $message  =   [
		'domain.require' => '请填写域名',
		'expire_at.require' => '请填写过期时间',
	];

	protected $scene = [
		'adminadd'  =>  ['domain', 'expire_at'],
	]; 
}