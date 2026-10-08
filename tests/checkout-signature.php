<?php
// Synthetic HTTP request and gateway only: no .env, database, provider call or order save.
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
foreach ([
    'APP_ENV'=>'testing', 'APP_URL'=>'https://shop.example.test',
    'APP_KEY'=>'base64:'.base64_encode(str_repeat('s', 32)),
    'CACHE_DRIVER'=>'array', 'SESSION_DRIVER'=>'array', 'LOG_CHANNEL'=>'stderr',
] as $key=>$value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.synthetic-checkout-do-not-read-production');
$request = Illuminate\Http\Request::create('http://shop.example.test/synthetic-checkout', 'GET', [], [], [], [
    'REMOTE_ADDR'=>'192.0.2.10', 'HTTP_X_FORWARDED_PROTO'=>'http',
]);
$app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

final class SyntheticSignedCheckout extends App\Http\Controllers\Pay\YipayController
{
    public function __construct()
    {
        $this->order = (object) ['order_sn'=>'SYNTHETICORDER01', 'actual_price'=>'19.90'];
        $this->payGateway = (object) [
            'merchant_id'=>'1001', 'merchant_pem'=>'synthetic-signing-key',
            'merchant_key'=>'https://gateway.example.test/submit.php', 'pay_handleroute'=>'/pay/yipay',
        ];
    }

    public function loadGateWay(string $orderSN, string $payCheck) {}
}

app('router')->get('/synthetic-checkout', fn () => (new SyntheticSignedCheckout())->gateway('alipay', 'SYNTHETICORDER01'));
$response = $kernel->handle($request);
if ($response->getStatusCode() !== 200) {
    fwrite(STDERR, "FAIL: synthetic checkout response\n");
    exit(1);
}
// Reproduce the live reverse proxy's sub_filter, then actual HTML/GET serialization.
$html = str_replace('http://', 'https://', $response->getContent());
$dom = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$fields = [];
foreach ($dom->getElementsByTagName('input') as $input) {
    if ($input->hasAttribute('name')) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
}
parse_str(http_build_query($fields), $received);
// Fixed independent oracle: ASCII-sorted raw V1 fields + synthetic key, Python hashlib MD5.
$expected = '4d2a8fb2d707fbbe279b87e06fc4a3d3';
if (count($received) !== 9 || ($received['sign'] ?? '') !== $expected) {
    fwrite(STDERR, "FAIL: HTTPS callback fields submitted after proxy rewrite do not match signed bytes\n");
    exit(1);
}
if ($html !== $response->getContent()
    || $received['notify_url'] !== 'https://shop.example.test/pay/yipay/notify_url'
    || $received['return_url'] !== 'https://shop.example.test/pay/yipay/return_url?order_id=SYNTHETICORDER01'
    || $dom->getElementsByTagName('form')[0]->getAttribute('action') !== 'https://gateway.example.test/submit.php') {
    fwrite(STDERR, "FAIL: canonical HTTPS callbacks must exist before proxy rewriting\n");
    exit(1);
}
echo "PASS: HTTPS-configured checkout signature survives proxy rewrite and HTML/GET roundtrip\n";

// The HTTPS canonical setting tolerates missing/accurate proxy headers too;
// HTTP development remains HTTP, but still honors a trusted HTTPS proxy.
foreach ([
    ['https://shop.example.test', null, 'https'],
    ['https://shop.example.test', 'https', 'https'],
    ['http://shop.example.test', 'http', 'http'],
    ['http://shop.example.test', 'https', 'https'],
] as [$canonical, $forwarded, $scheme]) {
    config(['app.url'=>$canonical]);
    Illuminate\Support\Facades\URL::forceScheme(null);
    (new App\Providers\AppServiceProvider($app))->boot();
    $server = ['REMOTE_ADDR'=>'192.0.2.10'];
    if ($forwarded !== null) $server['HTTP_X_FORWARDED_PROTO'] = $forwarded;
    $request = Illuminate\Http\Request::create('http://shop.example.test/synthetic-checkout', 'GET', [], [], [], $server);
    $response = $kernel->handle($request);
    $dom = Dom\HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $fields = [];
    foreach ($dom->getElementsByTagName('input') as $input) {
        if ($input->hasAttribute('name')) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    parse_str(http_build_query($fields), $received);
    $canonicalBytes = 'money=19.9&name=SYNTHETICORDER01&notify_url='.$scheme.'://shop.example.test/pay/yipay/notify_url'
        .'&out_trade_no=SYNTHETICORDER01&pid=1001&return_url='.$scheme.'://shop.example.test/pay/yipay/return_url?order_id=SYNTHETICORDER01&type=alipay';
    if ($response->getStatusCode() !== 200 || count($received) !== 9
        || ($received['notify_url'] ?? '') !== $scheme.'://shop.example.test/pay/yipay/notify_url'
        || ($received['return_url'] ?? '') !== $scheme.'://shop.example.test/pay/yipay/return_url?order_id=SYNTHETICORDER01'
        || !hash_equals(md5($canonicalBytes.'synthetic-signing-key'), $received['sign'] ?? '')) {
        fwrite(STDERR, "FAIL: canonical/forwarded scheme compatibility\n");
        exit(1);
    }
}
echo "PASS: missing/HTTPS proxy headers, HTTP development and forwarded HTTPS signatures\n";
