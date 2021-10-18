<?php
namespace app\services;

use think\facade\Cache;
use think\facade\Log;
use app\services\CopyService;
use app\services\SpideSiteService;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

class ConfigService {
	protected $isFirstVisit = false;
	/**
	 * @var string
	 */
	protected $domain;

	/**
	 * @var array
	 */
	protected $config;
	/**
	 * @var Request
	 */
	protected $request;

	protected $request_id;

	/**
	 * 错误信息
	 *
	 * @var string
	 */
	protected $error;

	public function __construct($domain, $request)
	{
		$this->domain = $domain;
		$this->request = $request;
		$this->request_id = strtoupper(md5(uniqid(mt_rand(), true)));
		$this->initWebroot();
	}

	protected function filesystem(): Filesystem {
		$filesystem = new Filesystem();
		return $filesystem;
	}

	/**
	 * 获取当前域名用的根目录
	 *
	 * @return mixed
	 */
	public function getWebroot()
	{
		$webroot = app()->getRootPath() . DIRECTORY_SEPARATOR . 'spidesite';
		$webroot = $webroot . DIRECTORY_SEPARATOR . $this->domain;
		return $webroot;
	}


	/**
	 * 初始化web目录
	 */
	protected function initWebroot()
	{
		$filesystem = $this->filesystem();
		$webroot = $this->getWebroot();
		if (!$filesystem->exists($webroot)) {
			$filesystem->mkdir($webroot);
		}
	}

	/**
	 * 创建二级目录
	 *
	 * @return mixed
	 */
	public function create_path($sub_path){
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		if (!$sub_path) {
			return return_json(10001, [], '请填写二级目录');
		}
		$web_sub_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path;
		if (file_exists($web_sub_path)) {
			return return_json(10002, [], '二级目录已存在');
		}
		mkdir($web_sub_path, 0777, true);
		$this->initConfig();
		return return_json(0, [], '创建成功');
	}

	/**
	 * 删除创建的二级目录
	 *
	 * @return mixed
	 */
	public function delete_path()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$sub_path = data_get($_GET, 'sub_path');
		if (!$sub_path) {
			return return_json(10001, [], '请填写二级目录');
		}
		$web_sub_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path;
		if (!file_exists($web_sub_path)) {
			return return_json(10002, [], '二级目录不存在');
		}
		$this->unlink_dir($web_sub_path);
		// 删除缓存
		$this->clearConfig();
		return return_json(0, [], '删除成功');
	}

	/**
	 * 递归删除文件夹
	 *
	 * @param $path
	 */
	public function unlink_dir($path)
	{
		$dh = opendir($path);
		while (($d = readdir($dh)) !== false) {
			if ($d == '.' || $d == '..') {//如果为.或..
				continue;
			}
			$tmp = $path . '/' . $d;
			if (!is_dir($tmp)) {//如果为文件
				unlink($tmp);
			} else {//如果为目录
				$this->unlink_dir($tmp);
			}
		}
		closedir($dh);
		rmdir($path);
	}

	/**
	 * 采集内容
	 *
	 */
	public function spide_content()
	{
		$spide_url = $this->request->get('proxy_site');
		if (empty($spide_url)) {
			return return_json(10002, '', '请提供要抓取的站点');
		}
		$sub_domain = $this->request->get('sub_path');
		$type_domain = $this->request->get('type_domain', 'SUB_PATH');
		$webroot = $this->getWebroot();
		if ($type_domain != 'SUB_DOMAIN') {
			$webroot = $webroot . DIRECTORY_SEPARATOR . $sub_domain;
		}


		$type_domain = strtoupper($type_domain);
		$domain_full = $this->request->get('domain_full');
		if ($type_domain == 'SUB_DOMAIN') {
			$url = sprintf("https://%s", $domain_full);
		} else {
			$url = sprintf("https://%s/%s", $domain_full, $sub_domain);
		}

		$spide_service = new SpideSiteService();
		$html = $spide_service->SpideWrite($spide_url, $url);
		if (false === $html) {
			return return_json(50001, '', '采集失败');
		}
		$html = $this->replaceOg($html);
		// 生成 安全页
		$dest_path = $webroot . DIRECTORY_SEPARATOR . 'safe.html';
		$script_field = "<!--script_field-->";
		$html = preg_replace("/<head\s*>/i", "<head>" . PHP_EOL . $script_field, $html);
		$pixel_filed = "<!---pixel_field-->";
		$html = preg_replace("/<\s*\/\s*body\s*>/i", PHP_EOL . $pixel_filed . PHP_EOL . "</body>", $html);
		$this->filesystem()->dumpFile($dest_path, $html);

		// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
		$index_path = $webroot . DIRECTORY_SEPARATOR . 'index.html';
		// 生成安全页访问文件
		copy($dest_path, $index_path);
		// 更新配置文件
		$this->initConfig();
		return return_json(0, '', '采集成功');
	}

	protected function replaceOg($content, $op = false)
	{
		if (!$op) {
			$webroot_path = $this->getWebroot();
			if (!is_writeable($webroot_path)) {
				return return_json(10000, [], '目录不可写');
			}
		}
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		$domain_full = $this->request->get('domain_full');
		if ($type_domain == 'SUB_DOMAIN') {
			$url = sprintf("https://%s", $domain_full);
		} else {
			$sub_path = data_get($_GET, 'sub_path');
			$url = sprintf("https://%s/%s", $domain_full, $sub_path);
		}
		$reg_title = "/<title>([\S\s]*?)<\/title>/i";
		if (preg_match($reg_title, $content, $match)) {
			$title = data_get($match, 1);
		} else {
			$title = $url;
		}
		$reg_keyword = "/<meta\s*name=[\"\']keywords[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
		if (preg_match($reg_keyword, $content, $match)) {
			// 如果有keyword 标签
			$keyword = data_get($match, 1);
		} else {
			$keyword = $title;
		}
		$reg_description = "/<meta\s*name=[\"\']description[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
		if (preg_match($reg_description, $content, $match)) {
			$description = data_get($match, 1);
		} else {
			$description = $keyword;
		}

		$reg_og_title = "/<meta\s*property=[\"\']og:title[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
		if (preg_match($reg_og_title, $content)) {
			$content = preg_replace($reg_og_title, "<meta property=\"og:title\" content=\"{$title}\" />", $content);
		} else {
			$append[] = "<meta property=\"og:title\" content=\"{$title}\" />";
		}
		$reg_og_url = "/<meta\s*property=[\"\']og:url[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
		if (preg_match($reg_og_url, $content)) {
			$content = preg_replace($reg_og_url, "<meta property=\"og:url\" content=\"{$url}\" />", $content);
		} else {
			$append[] = "<meta property=\"og:url\" content=\"{$url}\" />";
		}
		$reg_og_description = "/<meta\s*property=[\"\']og:description[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
		if (preg_match($reg_og_description, $content)) {
			$content = preg_replace($reg_og_description, "<meta property=\"og:description\" content=\"{$description}\" />", $content);
		} else {
			$append[] = "<meta property=\"og:description\" content=\"{$description}\" />";
		}
		if (!empty($append)) {
			$append = implode(PHP_EOL, $append);
			$content = preg_replace("/<head\s*>/i", "<head>" . PHP_EOL . $append, $content);
		}
		return $content;
	}

	/**
	 * 向安全页注入标题
	 *
	 */
	public function injectTitle()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$type_domain = $this->request->param('type_domain', 'SUB_DOMAIN');
		$type_domain = strtoupper($type_domain);
		$method = $this->request->param('proxy_method', 'spide');
		$method = strtolower($method);
		$mode = $this->request->get('mode');
		$mode = strtoupper($mode);
		if ($method == 'spide') {
			$url = "";
			if ($type_domain == 'SUB_DOMAIN') {
				$src_path = $webroot_path . DIRECTORY_SEPARATOR . 'safe.html';
				$dest_path = $webroot_path . DIRECTORY_SEPARATOR . 'index.html';
				$url = sprintf("https://%s", $this->request->get('domain_full'));
			} else {
				$sub_path = data_get($_GET, 'sub_path');
				$src_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'safe.html';
				$dest_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'index.html';
				$url = sprintf("https://%s/%s", $this->request->get('domain_full'), $this->request->get('sub_path'));
			}
			$title = $this->request->get('title');
			$keyword = $this->request->get('keyword');
			$description = $this->request->get('description');
			// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
			if (file_exists($src_path)) {
				$content = file_get_contents($src_path);
				// 生成安全页访问文件
				$append = [];
				$reg_title = "/<title>([\S\s]*?)<\/title>/i";
				if (preg_match($reg_title, $content)) {
					$content = preg_replace($reg_title, "<title>{$title}</title>", $content);
				} else {
					$append[] = "<title>{$title}</title>";
				}
				$reg_keyword = "/<meta\s*name=[\"\']keywords[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
				if (preg_match($reg_keyword, $content)) {
					// 如果有keyword 标签
					$content = preg_replace($reg_keyword, "<meta name=\"keywords\" content=\"{$keyword}\" />", $content);
				} else {
					$append[] = "<meta name=\"keywords\" content=\"{$keyword}\" />";
				}
				$reg_description = "/<meta\s*name=[\"\']description[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
				if (preg_match($reg_description, $content)) {
					$content = preg_replace($reg_description, "<meta name=\"description\" content=\"{$description}\" />", $content);
				} else {
					$append[] = "<meta name=\"description\" content=\"{$keyword}\" />";
				}

				$reg_og_title = "/<meta\s*property=[\"\']og:title[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
				if (preg_match($reg_og_title, $content)) {
					$content = preg_replace($reg_og_title, "<meta property=\"og:title\" content=\"{$title}\" />", $content);
				} else {
					$append[] = "<meta property=\"og:title\" content=\"{$title}\" />";
				}
				$reg_og_url = "/<meta\s*property=[\"\']og:url[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
				if (preg_match($reg_og_url, $content)) {
					$content = preg_replace($reg_og_url, "<meta property=\"og:url\" content=\"{$url}\" />", $content);
				} else {
					$append[] = "<meta property=\"og:url\" content=\"{$url}\" />";
				}
				$reg_og_description = "/<meta\s*property=[\"\']og:description[\"\']\s*content=[\"\']([\S\s]*?)[\"\']\s*\/?>/i";
				if (preg_match($reg_og_description, $content)) {
					$content = preg_replace($reg_og_description, "<meta property=\"og:description\" content=\"{$description}\" />", $content);
				} else {
					$append[] = "<meta property=\"og:description\" content=\"{$description}\" />";
				}
				if (!empty($append)) {
					$append = implode(PHP_EOL, $append);
					$content = preg_replace("/<head\s*>/i", "<head>" . PHP_EOL . $append, $content);
				}
				$this->filesystem()->dumpFile($src_path, $content);
			} else {
				return return_json(40001, [], '请先生成安全页');
			}
		}

		$this->initConfig();
		return return_json(0, [], '注入成功');
	}

	public function redirect()
	{
		$this->initConfig();
		return return_json(0, '', '生成成功');
	}

	public function random_path()
	{
		$copy_service = new CopyService($this->domain);
		$products = $copy_service->list_products();
		if (false === $products) {
			return return_json(10002, [], '当前站点下面没有商品目录,请更换域名');
		}
		if (empty($products)) {
			return return_json(10003, [], '当前站点没有商品可使用，请更换域名');
		}
		// 重新映射
		$cache_key = sprintf("random:page:%s", $this->domain);
		$index = Cache::fetch($cache_key);
		if (trim($index) != '') {
			$index++;
		} else {
			$index = 0;
		}
		$key = $index % count($products);
		Cache::set($cache_key, $index);
		$product = $products[$key];
		// 复制文件到指定目录
		$sub_path = $this->request->get('sub_path');
		$web_sub_path = $this->getWebroot() . DIRECTORY_SEPARATOR . $sub_path;
		if (!file_exists($web_sub_path)) {
			return return_json(10002, [], '二级目录不存在,请重新创建');
		}
		// 移动文件
		$src_path = $product;
		Log::debug('move' . $src_path);
		$dest_path = $web_sub_path . DIRECTORY_SEPARATOR . 'safe.html';
		if (copy($src_path, $dest_path)) {
			$html = file_get_contents($dest_path);
			$html = $this->replaceOg($html);
			$script_field = "<!--script_field-->";
			$html = preg_replace("/<head\s*>/i", "<head>" . PHP_EOL . $script_field, $html);
			$this->filesystem()->dumpFile($dest_path, $html);
			// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
			$index_path = $web_sub_path . DIRECTORY_SEPARATOR . 'index.html';
			// 生成安全页访问文件
			copy($dest_path, $index_path);
		} else {
			return return_json(50001, [], '生成随机页失败');
		}
		$this->initConfig($copy_service->getWebroot());

		return return_json(0, [], '生成完成');
	}

	/**
	 * 复制落地页
	 *
	 * @return mixed
	 */
	public function copy_loadpage()
	{

		$result = $this->buildLoadpage();
		if (false === $result) {
			$msg = $this->error ? $this->error : '模板生成出错';
			return return_json(40001, '', $msg);
		}
		$this->initConfig();
		return return_json(0, '', '更新成功');
	}

	/**
	 * 下载商品图片
	 *
	 * @param $image_url
	 * @return bool|string
	 */
	protected function getGoodsImage($image_url)
	{
		$image_name = basename($image_url);
		$image_path = '/public/images/' . $image_name;
		$local_path = public_path('images/' . $image_name);
		if (file_exists($local_path)) {
			return $image_path;
		}
		// file get content
		$client = new Client();
		try {
			$response = $client->get($image_url, ['save_to' => $local_path]);
			if (file_exists($local_path)) {
				return $image_path;
			}
			$this->error = "商品图片下载失败";
			return false;
		} catch (\Exception $e) {
			Log::debug($e->getMessage());
			$this->error = "商品图片下载失败";
			return false;
		}
	}

	/**
	 * 复制生成一个安全页
	 * @param $file_loadpage
	 * @return bool
	 */
	protected function buildLoadpage()
	{
		$type_domain = $this->request->get('type_domain');
		$sub_path = $this->request->get('sub_path');
		$goods_name = $this->request->get('goods_name');
		$goods_image = $this->request->get('goods_image');
		$pixel_id = $this->request->get('pixel_id');
		Log::execute_at($this->request_id, 'start build loadpage');
		$goods_image = $this->getGoodsImage($goods_image);
		Log::execute_at($this->request_id, 'download goods image');
		// 下载图片
		if (false === $goods_image) {
			return false;
		}

		$offer_link = $this->request->get('offer_url');
		$template_name = $this->request->get('template_name');
		// 检查二级目录
		$webroot_path = $this->getWebroot();
		$tpl_path = tpl_path();
		// copy 文件
		$template = $tpl_path . DIRECTORY_SEPARATOR . $template_name;
		if (!file_exists($template)) {
			return false;
		}
		$web_sub_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path;
		$type_domain = strtoupper($type_domain);
		if ($type_domain == 'SUB_DOMAIN') {
			$web_sub_path = $webroot_path;
			$sub_path = "";
		}
		if ($sub_path) {
			$sub_path = '/' . $sub_path;
		}
		//xCopy($template, $web_sub_path);
		// 生成 跳转页面
		$file_loadpage = $web_sub_path . DIRECTORY_SEPARATOR . 'loadpage.html';
		$page = $web_sub_path . DIRECTORY_SEPARATOR . 'page.html';
		$page_html = <<<HTML
<!DOCTYPE HTML>  
<html>  
	<head>  
		<meta http-equiv="Refresh" content="0;url={$offer_link}" />
	</head>
</html>
HTML;
		$this->filesystem()->dumpFile($page, $page_html);
		// 成功
		$dwt_file = $template . DIRECTORY_SEPARATOR . 'index.dwt';
		if (!file_exists($dwt_file)) {
			return false;
		}
		$loadpage_content = file_get_contents($dwt_file);
		Log::execute_at($this->request_id, 'load template file');
		// 替换文件内容
		$pixel = $this->pixel($pixel_id);
		$loadpage_content = str_replace([
			'<--offer_url-->',
			'<--goods_name-->',
			'<--product_name-->',
			'<--goods_image-->',
			'<--real_offer_url-->',
			'<--pixel-->'
		], [
			$sub_path . '/pr/toPage',
			ctag($goods_name),
			$goods_name,
			$goods_image,
			$offer_link,
			$pixel
		], $loadpage_content);
		$this->filesystem()->dumpFile($file_loadpage, $loadpage_content);
		Log::execute_at($this->request_id, 'wirte loadpage');
		return true;
	}


	/**
	 * 切换到安全页
	 *
	 * @return mixed
	 */
	public function change_to_safepage()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		$mode = $this->request->get('proxy_method');
		$mode = strtolower($mode);
		if ($mode == 'spide') {
			if ($type_domain == 'SUB_DOMAIN') {
				$src_path = $webroot_path . DIRECTORY_SEPARATOR . 'safe.html';
				$dest_path = $webroot_path . DIRECTORY_SEPARATOR . 'index.html';
			} else {
				$sub_path = data_get($_GET, 'sub_path');
				$src_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'safe.html';
				$dest_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'index.html';

			}
			// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
			if (file_exists($src_path)) {
				// 生成安全页访问文件
				copy($src_path, $dest_path);
			} else {
				return return_json(40001, [], '请先生成安全页');
			}
		}

		$this->initConfig();
		return return_json(0, [], '切换成功');
	}

	/**
	 * 切换到lp页面
	 *
	 * @return mixed
	 */
	public function change_to_loadpage()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		if ($type_domain == 'SUB_DOMAIN') {
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . 'index.html';
		} else {
			$sub_path = $this->request->get('sub_path');
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'index.html';

		}
		// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
		if (file_exists($src_path)) {
			// 生成安全页访问文件
			copy($src_path, $dest_path);
		} else {
			return return_json(40001, [], '请先生成落地页');
		}
		$this->initConfig();
		return return_json(0, [], '切换成功');
	}

	/**
	 * 切换到js cloak 模式的loadpage
	 *
	 */
	public function change_to_js_loadpage()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		if ($type_domain == 'SUB_DOMAIN') {
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . 'index.html';
		} else {
			$sub_path = $this->request->get('sub_path');
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'index.html';

		}
		// 检查是不是存在index.html  如果存在则忽略 如果不存在则复制创建
		if (file_exists($src_path)) {
			$safe_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'safe.html';
			$content = file_get_contents($safe_path);
			// 这里加入 js cloak项
			$src = "?do=js";
			$replace = "<head><script type='text/javascript' src='{$src}'></script>";
			// 需要在header里加入一个Js
			$content = preg_replace("/<head(.+?)>/i", $replace, $content, 1);
			// 需要把body 这个标签 改成 <body style="display:none"
			$content = preg_replace("/<body(.+?)/i", "<body style='display:none;'", $content);
			$this->filesystem()->dumpFile($dest_path, $content);
			$this->initConfig();
			return return_json(0, [], '切换成功');
		} else {
			return return_json(40001, [], '请先生成落地页');
		}
	}


	public function render_mode()
	{
		$webroot_path = $this->getWebroot();
		if (!is_writeable($webroot_path)) {
			return return_json(10000, [], '目录不可写');
		}
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		if ($type_domain == 'SUB_DOMAIN') {
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . 'index.html';
		} else {
			$sub_path = $this->request->get('sub_path');
			$src_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'loadpage.html';
			$dest_path = $webroot_path . DIRECTORY_SEPARATOR . $sub_path . DIRECTORY_SEPARATOR . 'index.html';
		}
		$dns_model = $this->request->get('dns_model');
		$render_mode = data_get($dns_model, 'render_mode', 'load');
		switch ($render_mode) {
			case 'load':
				// default is load 不需要改变什么
				break;
			case 'no_load':
				// 需要替换 script 标签
				// 需要替换 loadpage 中的标签 因为落地页中自带模板标签

				// 如果不是301模式 则需要替换 safe.html的标签
				break;
			case 'no_load_js':
				break;
		}
		$this->initConfig();
		return return_json(0, [], '更新成功');
	}


	/**
	 * 清理数据
	 *
	 * @return bool
	 */
	public function clearConfig()
	{
		$type_domain = $this->request->get('type_domain');
		if ($type_domain == 'SUB_DOMAIN') {
			// 如果这个域名是二级域名
			$key = sprintf('site:config:%s', $this->domain);
		} else {
			// 如果这个是二级目录则
			$sub_path = $this->request->get('sub_path');
			$key = sprintf('site:config:%s', $this->domain) . ':' . $sub_path;
		}
		Cache::delete($key);
		return true;
	}

	/**
	 * 重置配置
	 *
	 */
	public function resetConfig()
	{
		$this->initConfig();
		return return_json(0, '', '更新成功');
	}

	public function initConfig($rand_webroot = '')
	{
		//Log::debug('reset config' );
		//Log::debug( $this->request->get('mode'));
		$type_domain = $this->request->get('type_domain');
		$domain_full = $this->request->get('domain_full');
		$sub_path = $this->request->get('sub_path');
		// 设置域名的属性类型
		Cache::set('site:domain:config:' . $this->domain, $type_domain);
		if ($type_domain == 'SUB_DOMAIN') {
			// 如果这个域名是二级域名
			$key = sprintf('site:config:%s', $this->domain);
		} else {
			// 如果这个是二级目录则
			$sub_path = $this->request->get('sub_path');
			$key = sprintf('site:config:%s', $this->domain) . ':' . $sub_path;
		}
		$config = [
			'type_domain' => $type_domain,
			'domain_full' => $domain_full,
			'sub_path' => $sub_path,
			'proxy_method' => $this->request->get('proxy_method'),
			'proxy_site' => $this->request->get('proxy_site'),
			'goods_image' => $this->request->get('goods_image'),
			'goods_name' => $this->request->get('goods_name'),
			'offer_url' => $this->request->get('offer_url'),
			'template_name' => $this->request->get('template_name'),
			'camp' => $this->request->get('camp'),
			'signature' => $this->request->get('signature'),
			'cloak_mode' => $this->request->get('cloak_mode'),
			'mode' => $this->request->get('mode'),
		];
		$dns_model = $this->request->get('dns_model');
		$domain_model = $this->request->get('domain_model');
		// try {
		// 	$expire_at = data_get($domain_model, 'expireAt');
		// 	$ttl = $expire_at - time();
		// 	$key = sprintf("fb-ck:domain_dns:%s_%s", data_get($dns_model, 'domainFull'), data_get($dns_model, 'subDomain'));
		// 	Redis::init()->set($key, json_encode($dns_model));
		// 	Redis::init()->expire($key, $ttl);
		// 	$key = sprintf("fb-ck:domain_dns:%s", data_get($dns_model, 'id'));
		// 	Redis::init()->set($key, json_encode($dns_model));
		// 	Redis::init()->expire($key, $ttl);
		// 	$key = sprintf("fb-ck:domain:%s", data_get($dns_model, 'domainFull'));
		// 	Redis::init()->set($key, data_get($domain_model, 'typeDomain'));
		// 	Redis::init()->expire($key, $ttl);
		// 	if ($rand_webroot) {
		// 		$key = sprintf("fb-ck:randpath:domain:%s", data_get($dns_model, 'domainFull'));
		// 		Redis::init()->set($key, $rand_webroot);
		// 		Redis::init()->expire($key, $ttl);
		// 	}
		// } catch (\Exception $e) {
		// 	Log::debug($e->getMessage());
		// }

		Cache::set($key, $config);
		return true;
	}


	/**
	 * 测试主题路径
	 * @return bool|void
	 */
	public function theme()
	{
		$goods_name = 'Test Sample';
		$goods_image = '/public/sample/goods_image.jpg';
		$offer_link = 'https://www.weibo.com';
		$template_name = $this->request->get('template_name');
		$pixel_id = $this->request->get('pixel_id');
		// 检查二级目录
		$tpl_path = tpl_path();
		// copy 文件
		$template = $tpl_path . DIRECTORY_SEPARATOR . $template_name;
		if (!file_exists($template)) {
			return false;
		}
		$dwt_file = $template . DIRECTORY_SEPARATOR . 'index.html';
		if (!file_exists($dwt_file)) {
			return false;
		}
		$loadpage_content = file_get_contents($dwt_file);
		Log::execute_at($this->request_id, 'load template file');
		// 替换文件内容
		$pixel = $this->pixel($pixel_id);
		$loadpage_content = str_replace([
			'<--offer_url-->',
			'<--goods_name-->',
			'<--product_name-->',
			'<--goods_image-->',
			'<--pixel-->',
		], [
			$offer_link,
			ctag($goods_name),
			$goods_name,
			$goods_image,
			$pixel
		], $loadpage_content);
		return html_out($loadpage_content);
	}

	protected function pixel($pixel_id)
	{
		if (!$pixel_id) {
			return "";
		}
		$doc = <<<EOT
<!-- Facebook Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$pixel_id}');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id={$pixel_id}&ev=PageView&noscript=1"
/></noscript>
<!-- End Facebook Pixel Code -->
EOT;
		return $doc;
	}

	public function thirdCall()
	{
		$request = $this->request;
		$spide_url = $request->get('proxy_site');
		$type_domain = $this->request->get('type_domain');
		$type_domain = strtoupper($type_domain);
		$domain_full = $this->request->get('domain_full');
		if ($type_domain == 'SUB_DOMAIN') {
			$url = sprintf("https://%s", $domain_full);
		} else {
			$sub_path = data_get($_GET, 'sub_path');
			$url = sprintf("https://%s/%s", $domain_full, $sub_path);
		}
		$spide_service = new SpideSiteService();
		$result = $spide_service->SpideWriteThird($spide_url, $url);
		if (false === $result['html']) {
			return return_json(50001, '', '采集失败');
		}
		$html = $this->replaceOg($result['html'], true);
		$script_field = "<!--script_field-->";
		$html = preg_replace("/<head\s*>/i", "<head>" . PHP_EOL . $script_field, $html);
		$pixel_filed = "<!---pixel_field-->";
		$html = preg_replace("/<\s*\/\s*body\s*>/i", PHP_EOL . $pixel_filed . PHP_EOL . "</body>", $html);
		header("content-type:{$result['header']}");
		echo $html;
		exit(900);

	}
}
