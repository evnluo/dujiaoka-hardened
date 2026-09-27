<?php
// Isolated in-memory database. Never reads .env or connects to live services.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__.'/../vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $path) {
    if (!is_dir(__DIR__.'/../storage/'.$path)) mkdir(__DIR__.'/../storage/'.$path, 0775, true);
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.backend-test-does-not-exist');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function expectBackend(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$tests = [];
$tests['modern application boots with original translations and business models'] = function (): void {
    expectBackend(str_starts_with(app()->version(), '13.'), 'Laravel 13 expected');
    expectBackend(__('dujiaoka.status_open') !== 'dujiaoka.status_open', 'legacy translations must resolve');
    expectBackend(App\Models\Order::getStatusMap()[4] !== 'order.fields.status_completed', 'model maps must work without Dcat');
    expectBackend(app('Service\\OrderService') instanceof App\Service\OrderService, 'business service binding');
};
$tests['legacy cached settings import is durable, non-destructive and repeatable'] = function (): void {
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
    $schema->create('admin_settings', function ($table): void {
        $table->string('slug', 100)->primary();
        $table->text('value');
        $table->timestamps();
    });
    $legacy = ['title' => 'Synthetic shop', 'order_expire_time' => '5', 'password' => 'synthetic-mail-value', 'unknown_option' => ['retained' => true], 'is_open_img_code' => 0];
    Illuminate\Support\Facades\Cache::put('system-setting', $legacy);
    $settings = app(App\Support\ShopSettings::class);
    expectBackend($settings->getAll() === $legacy, 'legacy cache fallback must retain every value');
    expectBackend(Illuminate\Support\Facades\Artisan::call('shop:import-settings') === 0, 'explicit import succeeds');
    expectBackend(Illuminate\Support\Facades\Cache::get('system-setting') === $legacy, 'import does not destroy legacy cache');
    Illuminate\Support\Facades\Cache::forget('system-setting');
    expectBackend($settings->getAll() === $legacy, 'settings survive cache eviction');
    $settings->setMany(['title' => 'Changed']);
    expectBackend($settings->get('unknown_option') === ['retained' => true], 'partial update preserves unknown settings');
    expectBackend(dujiaoka_config_get('is_open_img_code', 1) === 0, 'false/zero settings do not fall back');
    Illuminate\Support\Facades\Cache::put('system-setting', $legacy);
    expectBackend(Illuminate\Support\Facades\Artisan::call('shop:import-settings') === 0, 'repeat import is harmless');
    expectBackend($settings->get('title') === 'Changed', 'import never overwrites existing canonical settings');
};
$tests['only existing administrator-role accounts can authenticate to the panel'] = function (): void {
    Illuminate\Support\Facades\Schema::create('admin_users', function ($table): void {
        $table->id(); $table->string('username')->unique(); $table->string('name');
        $table->string('password'); $table->rememberToken(); $table->timestamps();
    });
    Illuminate\Support\Facades\Schema::create('admin_roles', function ($table): void { $table->id(); $table->string('slug'); });
    Illuminate\Support\Facades\Schema::create('admin_role_users', function ($table): void { $table->unsignedBigInteger('role_id'); $table->unsignedBigInteger('user_id'); });
    Illuminate\Support\Facades\DB::table('admin_users')->insert([
        ['id'=>1, 'username'=>'authorized-fixture', 'name'=>'Fixture admin', 'password'=>password_hash('synthetic-test-password', PASSWORD_BCRYPT)],
        ['id'=>2, 'username'=>'not-an-admin', 'name'=>'Unprivileged', 'password'=>password_hash('synthetic-test-password', PASSWORD_BCRYPT)],
    ]);
    Illuminate\Support\Facades\DB::table('admin_roles')->insert(['id'=>1, 'slug'=>'administrator']);
    Illuminate\Support\Facades\DB::table('admin_role_users')->insert(['role_id'=>1, 'user_id'=>1]);
    $guard = Illuminate\Support\Facades\Auth::guard('admin');
    expectBackend(!$guard->attempt(['username'=>'authorized-fixture', 'password'=>'incorrect']), 'invalid password rejected');
    expectBackend($guard->attempt(['username'=>'authorized-fixture', 'password'=>'synthetic-test-password']), 'legacy bcrypt login via username succeeds');
    $panel = Filament\Panel::make()->id('admin');
    expectBackend($guard->user()->canAccessPanel($panel), 'authorized original administrator allowed');
    expectBackend(!App\Models\AdminUser::find(2)->canAccessPanel($panel), 'ordinary admin_users row denied');
    expectBackend(!(new App\Models\AdminUser())->canAccessPanel($panel), 'unsaved users denied');
    Illuminate\Support\Facades\DB::table('admin_role_users')->delete();
    expectBackend(!$guard->user()->canAccessPanel($panel), 'role revocation takes effect immediately');
    Illuminate\Support\Facades\DB::table('admin_role_users')->insert(['role_id'=>1, 'user_id'=>1]);
    $guard->logout();
};
$tests['storefront boots on HTTP while the destructive installer and inactive gateways are absent'] = function (): void {
    app(App\Support\ShopSettings::class)->setMany(['template'=>'hyper', 'language'=>'zh_CN', 'is_open_anti_red'=>0]);
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('http://localhost/order-search');
    $response = $kernel->handle($request);
    expectBackend($response->getStatusCode() === 200, 'storefront order search must render: '.$response->getStatusCode());
    expectBackend(str_contains($response->getContent(), '<html'), 'rendered storefront HTML');
    $kernel->terminate($request, $response);
    $uris = array_map(fn ($route) => $route->uri(), app('router')->getRoutes()->getRoutes());
    expectBackend(in_array('pay/yipay/notify_url', $uris, true), 'Yipay callback path preserved');
    expectBackend(!in_array('install', $uris, true) && !in_array('do-install', $uris, true), 'no destructive public installer');
    expectBackend(!in_array('pay/stripe/charge', $uris, true), 'inactive unsupported payment endpoints removed');
};
function backendRequest(string $method, string $uri, array $data = []): Symfony\Component\HttpFoundation\Response {
    static $cookies = [];
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::create('http://localhost'.$uri, $method, $data, $cookies, [], ['HTTP_USER_AGENT'=>'Synthetic browser']);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    $kernel->terminate($request, $response);
    return $response;
}
$tests['checkout requires image captcha, renders the existing storefront and creates a delayed expiration job'] = function (): void {
    require __DIR__.'/fixtures/business-schema.php';
    config(['queue.default'=>'database', 'captcha.characters'=>['x'], 'captcha.default.characters'=>['x'], 'captcha.default.length'=>4, 'captcha.default.math'=>false, 'captcha.default.bgImage'=>false]);
    app(App\Support\ShopSettings::class)->setMany(['is_open_img_code'=>1, 'is_open_geetest'=>0, 'order_expire_time'=>'5']);
    $input = ['gid'=>7, 'email'=>'buyer@example.test', 'payway'=>14, 'by_amount'=>1];
    $rejected = false;
    try { app('Service\\OrderService')->validatorCreateOrder(Illuminate\Http\Request::create('/create-order', 'POST', $input)); }
    catch (App\Exceptions\RuleValidationException $e) { $rejected = true; }
    expectBackend($rejected, 'missing captcha must not bypass validation');
    $home = backendRequest('GET', '/');
    expectBackend($home->getStatusCode() === 200 && str_contains($home->getContent(), 'Synthetic product'), 'existing storefront home renders products');
    $buy = backendRequest('GET', '/buy/7');
    expectBackend($buy->getStatusCode() === 200 && str_contains($buy->getContent(), 'Yipay fixture'), 'existing buy page renders payment options');
    expectBackend(!str_contains($buy->getContent(), 'Removed integration fixture'), 'only supported gateway is selectable');
    $image = backendRequest('GET', '/captcha/default');
    expectBackend($image->getStatusCode() === 200 && str_starts_with((string) $image->headers->get('Content-Type'), 'image/'), 'real image captcha renders on PHP8.5');
    expectBackend(strlen($image->getContent()) > 100, 'captcha has image bytes');
    $created = backendRequest('POST', '/create-order', $input + ['img_verify_code'=>'xxxx']);
    expectBackend($created->getStatusCode() === 302, 'valid captcha checkout redirects to bill, got '.$created->getStatusCode());
    $order = App\Models\Order::first();
    expectBackend($order !== null && (int)$order->status === App\Models\Order::STATUS_WAIT_PAY, 'one waiting order created');
    expectBackend((string)$order->actual_price === '10', 'original price calculation preserved');
    expectBackend(Illuminate\Support\Facades\DB::table('jobs')->count() === 1, 'expiration queued after successful checkout');
    $job = Illuminate\Support\Facades\DB::table('jobs')->first();
    expectBackend($job->available_at >= time()+290, 'expiration delay accepts legacy numeric string setting');
    expectBackend(backendRequest('GET', '/bill/'.$order->order_sn)->getStatusCode() === 200, 'bill renders after checkout');
};
$tests['queued legacy mail jobs use durable settings and the modern mail transport'] = function (): void {
    app(App\Support\ShopSettings::class)->setMany([
        'driver'=>'array', 'host'=>'smtp.example.test', 'port'=>'465', 'encryption'=>'ssl',
        'username'=>'fixture', 'password'=>'synthetic-mail-value', 'from_address'=>'shop@example.test', 'from_name'=>'Fixture shop',
    ]);
    Illuminate\Support\Facades\Cache::forget('system-setting');
    $job = unserialize(serialize(new App\Jobs\MailSend('buyer@example.test', 'Fixture subject', 'Synthetic delivery')));
    $job->handle();
    expectBackend(config('mail.mailers.smtp.host') === 'smtp.example.test', 'durable SMTP host mapped to modern config');
    expectBackend(config('mail.mailers.smtp.scheme') === 'smtps', 'legacy SSL maps to implicit TLS');
    $messages = Illuminate\Support\Facades\Mail::mailer('array')->getSymfonyTransport()->messages();
    expectBackend($messages->count() === 1, 'mail submitted exactly once to isolated array transport');
    expectBackend($messages->first()->getOriginalMessage()->getSubject() === 'Fixture subject', 'queued job payload is preserved');
};
$tests['optional Geetest uses its signed server validation and rejects replay'] = function (): void {
    app(App\Support\ShopSettings::class)->setMany(['is_open_geetest'=>1, 'geetest_id'=>'fixture-id', 'geetest_key'=>'fixture-geetest-private']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        'api.geetest.com/register.php*'=>Illuminate\Support\Facades\Http::response(str_repeat('a', 32)),
        'api.geetest.com/validate.php'=>Illuminate\Support\Facades\Http::response(['seccode'=>md5('fixture-seccode')]),
    ]);
    $request = Illuminate\Http\Request::create('/check-geetest');
    $request->setLaravelSession(app('session')->driver());
    $service = app(App\Support\GeetestCaptcha::class);
    $registration = $service->register($request);
    $request->merge(['geetest_challenge'=>$registration['challenge'], 'geetest_validate'=>md5('fixture-geetest-private'.'geetest'.$registration['challenge']), 'geetest_seccode'=>'fixture-seccode']);
    expectBackend($service->validate($request), 'verified server response accepted');
    expectBackend(!$service->validate($request), 'consumed challenge cannot be replayed');
    app(App\Support\ShopSettings::class)->setMany(['is_open_geetest'=>0]);
};
$tests['real HTTP Yipay callbacks fulfil stock once and leave the expiration harmless'] = function (): void {
    $order = App\Models\Order::firstOrFail();
    $gateway = backendRequest('GET', '/pay/yipay/alipay/'.$order->order_sn);
    expectBackend($gateway->getStatusCode() === 200 && str_contains($gateway->getContent(), 'pay.example.test'), 'Yipay payment initiation renders provider form');
    $data = ['pid'=>'fixture-merchant', 'trade_no'=>'SYNTHETIC-TRADE-1', 'out_trade_no'=>$order->order_sn, 'type'=>'alipay', 'name'=>$order->order_sn, 'money'=>'10.00', 'trade_status'=>'TRADE_SUCCESS', 'sign_type'=>'MD5'];
    $signed = $data; unset($signed['sign_type']); ksort($signed);
    $data['sign'] = md5(urldecode(http_build_query($signed)).'fixture-signing-key');
    $invalid = $data; $invalid['sign'] = str_repeat('0', 32);
    expectBackend(backendRequest('GET', '/pay/yipay/notify_url?'.http_build_query($invalid))->getContent() === 'fail', 'invalid callback rejected');
    expectBackend((int)$order->fresh()->status === App\Models\Order::STATUS_WAIT_PAY, 'invalid callback does not fulfil');
    expectBackend(backendRequest('POST', '/pay/yipay/notify_url', $data)->getContent() === 'success', 'signed callback acknowledged');
    $jobs = Illuminate\Support\Facades\DB::table('jobs')->count();
    expectBackend(backendRequest('GET', '/pay/yipay/notify_url?'.http_build_query($data))->getContent() === 'success', 'exact retry acknowledged');
    expectBackend(Illuminate\Support\Facades\DB::table('jobs')->count() === $jobs, 'retry does not queue duplicate mail');
    expectBackend((int)$order->fresh()->status === App\Models\Order::STATUS_COMPLETED && $order->fresh()->info === 'SYNTHETIC-CARD-501', 'exact card delivered');
    expectBackend((int)App\Models\Carmis::find(501)->status === App\Models\Carmis::STATUS_SOLD, 'stock sold once');
    (new App\Jobs\OrderExpired($order->order_sn))->handle();
    expectBackend((int)$order->fresh()->status === App\Models\Order::STATUS_COMPLETED, 'legacy expiration job cannot overwrite payment');
    expectBackend(backendRequest('GET', '/detail-order-sn/'.$order->order_sn)->getStatusCode() === 200, 'delivered order page renders');
};
$tests['Filament denies unauthenticated access and validates legacy admin login through Livewire'] = function (): void {
    $panel = Filament\Facades\Filament::getPanel('admin');
    Filament\Facades\Filament::setCurrentPanel($panel);
    Filament\Facades\Filament::bootCurrentPanel();
    $prefix = '/'.trim(config('admin.route.prefix'), '/');
    $guard = Illuminate\Support\Facades\Auth::guard('admin');
    $guard->logout();
    $denied = backendRequest('GET', $prefix.'/orders');
    expectBackend($denied->getStatusCode() === 302 && str_ends_with($denied->headers->get('Location'), $prefix.'/login'), 'guest cannot read order data');
    // Exercise the real component lifecycle without requiring PHPUnit in the production image.
    $login = new App\Filament\Pages\Auth\Login();
    $login->setId('synthetic-login-denied'); $login->mount();
    $login->form->fill(['username'=>'not-an-admin', 'password'=>'synthetic-test-password']);
    $rejected = false;
    try { $login->authenticate(); }
    catch (Illuminate\Validation\ValidationException $e) { $rejected = isset($e->errors()['data.username']); }
    expectBackend($rejected && !$guard->check(), 'unprivileged correct-password user remains logged out');
    $login = new App\Filament\Pages\Auth\Login();
    $login->setId('synthetic-login-authorized'); $login->mount();
    $login->form->fill(['username'=>'authorized-fixture', 'password'=>'synthetic-test-password']);
    $login->authenticate();
    expectBackend($guard->check() && (int)$guard->id() === 1, 'real Filament login authenticates existing administrator');
    $guard->logout();
    expectBackend(backendRequest('GET', $prefix.'/register')->getStatusCode() === 404, 'public registration is absent');
};
$tests['forged checkout inputs cannot select retired payment records or bypass goods validation'] = function (): void {
    app(App\Support\ShopSettings::class)->setMany(['is_open_img_code'=>0]);
    $valid = ['gid'=>7, 'email'=>'buyer@example.test', 'payway'=>14, 'by_amount'=>1];
    app('Service\\OrderService')->validatorCreateOrder(Illuminate\Http\Request::create('/create-order', 'POST', $valid));
    foreach ([['payway'=>15], ['gid'=>['malformed']]] as $change) {
        $rejected = false;
        try { app('Service\\OrderService')->validatorCreateOrder(Illuminate\Http\Request::create('/create-order', 'POST', array_replace($valid, $change))); }
        catch (App\Exceptions\RuleValidationException $e) { $rejected = true; }
        expectBackend($rejected, 'unsupported payment and non-scalar product IDs rejected');
    }
    $before = App\Models\Order::count();
    $missing = backendRequest('POST', '/create-order', array_replace($valid, ['gid'=>999]));
    expectBackend($missing->getStatusCode() !== 500 && App\Models\Order::count() === $before, 'missing products produce a storefront error, not an engine failure');
    expectBackend(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'failed checkout leaves no transaction open');
    app(App\Support\ShopSettings::class)->setMany(['is_open_img_code'=>1]);
};
$tests['explicit settings import command preserves cache and prints only non-secret metadata'] = function (): void {
    Illuminate\Support\Facades\DB::table('admin_settings')->where('slug', App\Support\ShopSettings::SLUG)->delete();
    Illuminate\Support\Facades\Cache::forever('system-setting', ['title'=>'Recovered title', 'password'=>'SYNTHETIC-BACKUP-VALUE']);
    expectBackend(Illuminate\Support\Facades\Artisan::call('shop:import-settings') === 0, 'operator import command succeeds');
    expectBackend(app(App\Support\ShopSettings::class)->get('password') === 'SYNTHETIC-BACKUP-VALUE', 'cached secret copied without transformation');
    expectBackend(!str_contains(Illuminate\Support\Facades\Artisan::output(), 'SYNTHETIC-BACKUP-VALUE'), 'operator output is metadata only');
    expectBackend(Illuminate\Support\Facades\Cache::get('system-setting')['title'] === 'Recovered title', 'rollback cache untouched');
    $path = storage_path('framework/synthetic-settings-backup.json');
    file_put_contents($path, json_encode(['title'=>'Backup title', 'preserved_unknown_setting'=>'value']));
    try {
        Illuminate\Support\Facades\DB::table('admin_settings')->where('slug', App\Support\ShopSettings::SLUG)->delete();
        expectBackend(Illuminate\Support\Facades\Artisan::call('shop:import-settings', ['--from-json'=>$path]) === 0, 'protected JSON backup import works without Redis');
        expectBackend(app(App\Support\ShopSettings::class)->get('preserved_unknown_setting') === 'value', 'unrecognized settings survive backup import');
    } finally { unlink($path); }
};
$tests['production CSRF is enforced on checkout and exempted only for the Yipay callback'] = function (): void {
    $before = App\Models\Order::count();
    app()->instance('env', 'production');
    try {
        expectBackend(backendRequest('POST', '/create-order', ['gid'=>7])->getStatusCode() === 419, 'checkout without CSRF is rejected outside testing mode');
        expectBackend(backendRequest('POST', '/pay/yipay/notify_url', ['invalid'=>'payload'])->getContent() === 'fail', 'callback reaches signature validation without CSRF');
        expectBackend(App\Models\Order::count() === $before, 'CSRF and malformed callbacks do not mutate orders');
    } finally { app()->instance('env', 'testing'); }
};
$tests['legacy PNG QR helpers and all retained storefront templates work without Imagick'] = function (): void {
    $image = QrCode::format('png')->size(200)->generate('https://shop.example.test');
    expectBackend(str_starts_with($image, "\x89PNG\r\n\x1a\n"), 'QR helper returns actual PNG bytes with GD');
    foreach (['hyper', 'luna', 'unicorn'] as $template) {
        app(App\Support\ShopSettings::class)->setMany(['template'=>$template]);
        expectBackend(backendRequest('GET', '/buy/7')->getStatusCode() === 200, $template.' buy page renders');
    }
};
$failed = 0;
foreach ($tests as $name => $run) {
    try { $run(); echo "PASS: $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL: $name: ".get_class($e).': '.$e->getMessage()."\n"; }
}
echo count($tests)." backend integration tests, $failed failures\n";
exit($failed ? 1 : 0);
