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
use think\facade\Log;
use think\Model;
use Aws\S3\S3Client;

class DomainDns extends Model
{

    protected $type = [
        'bind_at' => '',
        'created_at' => '',
        'update_at' => '',
        'delete_at' => '',

    ];

    public static function onAfterWrite($dns)
    {
        Cache::delete('sub_domain_' . $dns['domain_id'] . '_' . $dns['sub_domain']);
    }

    public function updatePixel($data)
    {
        $pixel_id = $data['pixel_id'];
        $pixel_type = $data['pixel_type'];
        if (!$pixel_id || !$data['ads_no']) {
            return "";
        }
        $price = Goods::where('id', $data['offer_sku'])->value('price');
        $price = $price ? $price : 0;
        $file_content = <<<GA
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
  fbq('track', 'Purchase', {
  value: {$price},
  currency: 'USD'
  });
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={$pixel_id}&ev=PageView&noscript=1"
/></noscript>
<!-- End Facebook Pixel Code -->
GA;
        $file_content_h = <<<GA
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
GA;

        $file_content_titok = <<<TITOK
<script>
        !function (w, d, t) {
            w.TiktokAnalyticsObject = t;
            var ttq = w[t] = w[t] || [];
            ttq.methods = ["page", "track", "identify", "instances", "debug", "on", "off", "once", "ready", "alias", "group", "enableCookie", "disableCookie"], ttq.setAndDefer = function (t, e) {
                t[e] = function () {
                    t.push([e].concat(Array.prototype.slice.call(arguments, 0)))
                }
            };
            for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);
            ttq.instance = function (t) {
                for (var e = ttq._i[t] || [], n = 0; n < ttq.methods.length; n++) ttq.setAndDefer(e, ttq.methods[n]);
                return e
            }, ttq.load = function (e, n) {
                var i = "https://analytics.tiktok.com/i18n/pixel/events.js";
                ttq._i = ttq._i || {}, ttq._i[e] = [], ttq._i[e]._u = i, ttq._t = ttq._t || {}, ttq._t[e] = +new Date, ttq._o = ttq._o || {}, ttq._o[e] = n || {};
                var o = document.createElement("script");
                o.type = "text/javascript", o.async = !0, o.src = i + "?sdkid=" + e + "&lib=" + t;
                var a = document.getElementsByTagName("script")[0];
                a.parentNode.insertBefore(o, a)
            };

            ttq.load('{$pixel_id}');
            ttq.page();
        }(window, document, 'ttq');
    </script>
TITOK;

        $file_name = $data['ads_no'] . '.html';
        $file_name_h = $data['ads_no'] . '_h.html';

        // Instantiate an Amazon S3 client.
        // $s3 = new S3Client([
        // 	'version' => 'latest',
        // 	'region'  => 'us-west-1',
        // 	'credentials' => new \Aws\Credentials\Credentials('AKIAS2OAJHTMXBMQHYHB' , 'DXBXDyw/AuT6LfeRJds7hbFA7POE+0Fq3aqMkb0f')
        // ]);
        $path = './uploads/file/' . $data['id'] . '/';
        if (!is_dir($path)) {
            mk_dir($path);
        }
        Log::debug($pixel_type);
        if ($pixel_type == 'TITOK') {
            file_put_contents($path . $file_name, $file_content_titok);
        } else {
            file_put_contents($path . $file_name, $file_content);
        }
        file_put_contents($path . $file_name_h, $file_content_h);

        return 'https://www.' . $data['domain'] . '/' . substr($path, 2, strlen($path) - 2) . $file_name;
        // Upload a publicly accessible file. The file size and type are determined by the SDK.
        // try {
        // 	$result = $s3->putObject([
        // 		'Bucket' => 'shengli',
        // 		'Key'    => $file_name,
        // 		'Body'   => fopen('./uploads/' . $file_name, 'r'),
        // 		'ACL'    => 'public-read',
        // 	]);
        // 	$pixel_url = $result['ObjectURL'];
        // 	unlink('./uploads/' . $file_name);
        // 	return $pixel_url;
        // } catch (Aws\S3\Exception\S3Exception $e) {
        // 	return $e;
        // }
    }
}