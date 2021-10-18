<?php
namespace app\services;

use GuzzleHttp\Client;
use think\facade\Cache;
use think\facade\Log;
use Symfony\Component\Filesystem\Filesystem;

class SpideSiteService {
	protected $filesystem;

	public function __construct(){
		$this->filesystem = new Filesystem();
	}

	public function spide($spide_url,$url){
		$spide_url_lowercase = strtolower($spide_url);
		$spide_key = md5($spide_url_lowercase);
		$html = Cache::get($spide_key);
		if ($html) {
			return $html;
		}

		try {
			$client = new Client();
			$res = $client->request('GET', $spide_url, [
				//'verify' => false,
				'connect_timeout' => 10,
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36',
					//'Accept-Encoding' => 'gzip, deflate, br',
				]
			]);
			$html = (string)$res->getBody();
			$html = $this->replace_url($html, $spide_url,$url);
			Cache::set($spide_key, $html, 3600);
			return $html;
		} catch (\Exception $e) {
			// 抓取失败
			Log::debug($e->getMessage(), $e->getTrace());
			return false;
		}
	}

	public function spideThird($spide_url)
	{
		try {
			$client = new Client();
			$res = $client->request('GET', $spide_url, [
				//'verify' => false,
				'connect_timeout' => 10,
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36',
					//'Accept-Encoding' => 'gzip, deflate, br',
				]
			]);
			$html = (string)$res->getBody();
			$hearder = $res->getHeader("Content-type");
			return [
				'html'=>$html,
				'hearder'=>$hearder
			];

		} catch (\Exception $e) {
			// 抓取失败
			Log::debug($e->getMessage(), $e->getTrace());
			return false;
		}
	}

	protected function getCacheHtml($spide_url)
	{
		$spide_url = strtolower($spide_url);
		$spide_key = md5($spide_url);
		Cache::get($spide_key);
	}

	protected function replace_url($html, $spide_url)
	{
		$preg = "/[\s]href\s?=\s?(\"|\')([\S]*)(\"|')/";
		$path = $this->getUrlPath($spide_url);
		$host = $this->getUrlHost($spide_url);
		$html = preg_replace_callback($preg, function ($match) use ($host, $path) {
			$url = $path . $match[2];
			if (startsWith($match[2], "http://")) {
				// 如果是以http 开头
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "https://")) {
				// 如果是以http 开头
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "//")) {
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "javascript:")) {
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "#")) {
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "tel:")) {
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "mailto:")) {
				return " href='" . $match[2] . "'";
			}
			if (startsWith($match[2], "/")) {
				return " href='" . $host . $match[2] . "'";
			}
			if (startsWith($match[2], "..")) {
				return " href='" . $path . $match[2] . "'";
			}
			if (startsWith($match[2], ".")) {
				return " href='" . $path . $match[2] . "'";
			}
			return ' href="' . $url . '"';
		}, $html);

		$preg = "/[\s]src\s?=\s?(\"|\')([\S]*)(\"|')/";
		$html = preg_replace_callback($preg, function ($match) use ($host, $path) {
			$url = $match[2];

			if (startsWith($match[2], "http://")) {
				// 如果是以http 开头
				return " src='" . $match[2] . "'";
			}
			if (startsWith($match[2], "https://")) {
				// 如果是以http 开头
				return " src='" . $match[2] . "'";
			}
			if (startsWith($match[2], "//")) {
				return " src='" . $match[2] . "'";
			}
			if (startsWith($match[2], "/")) {
				return " src='" . $host . $match[2] . "'";
			}
			if (startsWith($match[2], "..")) {
				return " src='" . $path . $match[2] . "'";
			}
			if (startsWith($match[2], ".")) {
				return " src='" . $path . $match[2] . "'";
			}
			return ' src="' . $url . '"';
		}, $html);
		return $html;
	}


	public function SpideWrite($spide_url,$url)
	{
		$html = $this->spide($spide_url,$url);
		if (false === $html) {
			return false;
		}
		return $html;
	}

	public function SpideWriteThird($spide_url,$url)
	{
		$html = $this->spideThird($spide_url);
		if (false === $html) {
			return false;
		}
		return $html;
	}


	public function getSpide($file_name, $spide_url)
	{
		$file_name = strtoupper($file_name);
		if ($this->filesystem->exists(data_path($file_name))) {
			$html = file_get_contents(data_path($file_name));
			return $html;
		}
		return $this->SpideWrite($spide_url, $file_name);

	}

	protected function getUrlHost($url)
	{
		$url_array = parse_url($url);
		$port = data_get($url_array, 'port', 80);
		if ($port == 80 || $port == 443) {
			return data_get($url_array, 'scheme') . "://" . data_get($url_array, 'host');
		}
		return data_get($url_array, 'scheme') . "://" . data_get($url_array, 'host') . ':' . $port;
	}

	protected function getUrlPath($url)
	{
		$url_array = parse_url($url);
		$port = data_get($url_array, 'port', 80);
		$path = data_get($url_array, 'path');
		if (!endsWith($path, '/')) {
			$path = explode('/', $path);
			array_pop($path);
			$path = implode('/', $path);
		}

		if ($port == 80 || $port == 443) {
			return data_get($url_array, 'scheme') . "://" . data_get($url_array, 'host') . $path . '/';
		}
		return data_get($url_array, 'scheme') . "://" . data_get($url_array, 'host') . ':' . $port . $path . '/';
	}


}
