<?php
namespace app\services;

use GuzzleHttp\Client;
use think\facade\Log;

class CloudFlareService {
	protected $api_email;
	protected $api_key;
	protected $request;

	protected $headers = [];

	public function __construct()
	{
		$this->api_email = '5685767@qq.com';
		$this->api_key = 'd3969108b9b423434a18ab1746786bfc8cec9';
		$this->headers = [
			'X-Auth-Email' => $this->api_email,
			'X-Auth-Key' => $this->api_key,
			"Content-Type" => "application/json"
		];
		$this->request = new Client([
			'base_uri' => 'https://api.cloudflare.com/client/v4/',
			'timeout' => 0,
			'allow_redirects' => false,
		]);
	}

	/**
	 * 创建域名绑定
	 * @param $account_id
	 * @param $domain
	 * @return bool|mixed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function zoneStore($account_id, $domain)
	{
		$account_id = '6583bb44bb7bfce9b41174c997287b1a';
        // $account_id = 'hr2saqgjx2J72bmyyeTlviwIvcdVxERrFIsXZpRt';
		$post = [
			'name' => $domain,
			'account_id' => [
				'id' => $account_id
			]
		];
		try {
			$response = $this->request->request('POST', 'zones', [
				'headers' => $this->headers,
				'json' => $post
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					if ($this->updateZone(data_get($content, 'result.id', ''))) {
						return data_get($content, 'result', []);
					}
					return false;
				} else {
					$result = data_get($content, 'errors');
				}
				return false;
			}
		} catch (\Exception $e) {
			// 这里查询一次
			Log::debug($e->getMessage());
			$res = $this->domainZone($domain);
			if (false === $res) {
				return false;
			}
			return $res;
		}
		return false;

	}


	/**
	 * 解除绑定 删除domain
	 *
	 * @param $zone_id
	 * @return bool|mixed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function zoneDelete($zone_id)
	{
		try {
			$response = $this->request->request('DELETE', 'zones/' . $zone_id, [
				'headers' => $this->headers,
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					return data_get($content, 'result.id', '');
				}
				return false;
			}
		} catch (\Exception $e) {
			Log::debug($e->getMessage());
			return false;
		}
		return false;
	}

	/**
	 * 绑定域名的时候获取zone_id
	 * @param $domain
	 * @return bool|mixed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function domainZone($domain = null)
	{
		$query_params = [
			'match' => 'all'
		];
		if ($domain) {
			$query_params['name'] = $domain;
		}
		try {
			$response = $this->request->request('GET', 'zones', [
				'headers' => $this->headers,
				'query' => $query_params
			]);
			if ($response->getStatusCode() == 200) {
				// 如果正常返回
				$contents = $response->getBody()->getContents();
				$contents = json_decode($contents, true);
				if (data_get($contents, 'success') === true) {
					// 取出result
					$zones = data_get($contents, 'result', []);
					if (!is_array($zones)) {
						return false;
					}
					if (empty($zones)) {
						return false;
					}
					$zone = current($zones);
					return $zone ;
				}
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		return false;

	}

	public function zoneLists($domain = null)
	{
		$query_params = [
			'status' => 'active',
			'match' => 'all',
			'per_page' => 50
		];
		if ($domain) {
			$query_params['name'] = $domain;
		}
		try {
			$response = $this->request->request('GET', 'zones', [
				'headers' => $this->headers,
				'query' => $query_params
			]);
			if ($response->getStatusCode() == 200) {
				// 如果正常返回
				$contents = $response->getBody()->getContents();
				$contents = json_decode($contents, true);
				if (data_get($contents, 'success') === true) {
					// 取出result
					$zones = data_get($contents, 'result', []);
					if (!is_array($zones)) {
						return false;
					}
					if (empty($zones)) {
						return false;
					}
					return $zones;
				}
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		return false;

	}

	/**
	 * 解析域名
	 * @param $zone_id
	 * @param $alias
	 * @param $host
	 * @return bool|mixed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function createDns($zone_id, $alias, $host)
	{
		$post = [
			'type' => 'A',
			'name' => $alias,
			'content' => $host,
			'priority' => 10,
			'proxied' => true,
			'ttl' => 1
		];
		$uri = 'zones/' . $zone_id . '/dns_records';
		try {
			$response = $this->request->request('POST', $uri, [
				'headers' => $this->headers,
				'json' => $post
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					return data_get($content, 'result.id', '');
				}
				return false;
			}
		} catch (\Exception $e) {
			Log::debug($e->getMessage());
			return false;
		}
		return false;
	}


	public function updateZone($zone_id)
	{
		$account_id = '6583bb44bb7bfce9b41174c997287b1a';
		$post = [
			'value' => 'flexible',
		];
		try {
			$response = $this->request->request('PATCH', 'zones/' . $zone_id . '/settings/ssl', [
				'headers' => $this->headers,
				'json' => $post
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				Log::debug($content);
				if (data_get($content, 'success') === true) {
					return data_get( $content , 'result' , []);
				}
				return false;
			}
		} catch (\Exception $e) {
			Log::debug($e->getMessage());
			return false;
		}
		return false;
	}


	public function updateDns($zone_id, $dns_id, $alias, $host)
	{
		$post = [
			'type' => 'A',
			'name' => $alias,
			'content' => $host,
			'priority' => 10,
			'proxied' => true,
			'ttl' => 1
		];
		$uri = 'zones/' . $zone_id . '/dns_records/' . $dns_id;
		try {
			$response = $this->request->request('PUT', $uri, [
				'headers' => $this->headers,
				'json' => $post
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					return data_get($content, 'result.id', '');
				}
				return false;
			}
		} catch (\Exception $e) {
			Log::debug($e->getMessage());
			return false;
		}
		return false;
	}

	public function dnsList($zone_id)
	{
		$uri = 'zones/' . $zone_id . '/dns_records?per_page=100';
		try {
			$response = $this->request->request('GET', $uri, [
				'headers' => $this->headers,
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					return data_get($content, 'result');
				}
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		return false;
	}

	/**
	 * 删除域名解析
	 *
	 * @param $zone_id
	 * @param $dns_id
	 * @return bool|mixed
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function deleteDns($zone_id, $dns_id)
	{
		$uri = 'zones/' . $zone_id . '/dns_records/' . $dns_id;
		try {
			$response = $this->request->request('DELETE', $uri, [
				'headers' => $this->headers,
			]);
			if ($response->getStatusCode() == 200) {
				$content = $response->getBody()->getContents();
				$content = json_decode($content, true);
				if (data_get($content, 'success') === true) {
					return data_get($content, 'result.id', '');
				}
				return false;
			}
		} catch (\Exception $e) {
			return false;
		}
		return false;
	}
}
