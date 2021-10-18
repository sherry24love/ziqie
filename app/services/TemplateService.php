<?php
// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------

namespace app\services;

class TemplateService{

	public function getTemplateList(){
		$path = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR;
		$list = [];
		$files  = scandir($path);
		foreach ($files as $key => $file) {
			if ($file != '.' && $file != '..') {
				$list[] = [
					'id'   => $key,
					'title' => basename($file),
					'name' => basename($file),
					'create_time' => filectime($path . $file),
					'update_time' => filemtime($path . $file),
					'size'        => format_bytes(filesize($path . $file))
				];
			}
		}
		return $list;
	}
}