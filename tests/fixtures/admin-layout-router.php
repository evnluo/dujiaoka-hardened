<?php
// Synthetic browser harness, never a deployment router. No persistent business data.
if (PHP_SAPI !== 'cli-server' || getenv('SHOP_LAYOUT_QA') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public'.$path);
if ($file && str_starts_with($file, $root.'/public/') && is_file($file)) { return false; }
require $root.'/vendor/autoload.php';
$bootstrap = new class { use \Tests\CreatesApplication; };
$app = $bootstrap->createApplication();
config(['app.url' => 'http://127.0.0.1:18776', 'session.secure' => false, 'session.driver' => 'file', 'session.files' => $root.'/.tools/admin-compact/sessions']);
require __DIR__.'/business-schema.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
Schema::create('admin_users', function ($t) { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
Schema::create('admin_roles', function ($t) { $t->id(); $t->string('slug'); });
Schema::create('admin_role_users', function ($t) { $t->integer('user_id'); $t->integer('role_id'); });
Schema::create('admin_settings', function ($t) { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
// No password/login flow: the isolated harness authenticates only this in-memory operator.
DB::table('admin_users')->insert(['id' => 1, 'username' => 'synthetic-qa', 'name' => 'Synthetic QA', 'password' => 'disabled']);
DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
DB::table('admin_role_users')->insert(['user_id' => 1, 'role_id' => 1]);
DB::table('goods')->where('id', 7)->update([
    'gd_name' => '示例订阅 · 标准版', 'gd_description' => '用于后台布局验证的合成商品',
    'description' => '<p>包含账号开通与使用说明。</p>', 'buy_prompt' => '<p>购买后请查看订单中的交付说明。</p>',
    'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00',
]);
DB::table('goods_group')->where('id', 1)->update(['gp_name' => '订阅服务']);
DB::table('carmis')->where('id', 501)->update(['status' => 2, 'carmi' => "SYNTHETIC-LONG-501\n  <script>not executable</script> & literal content\n".str_repeat('unbroken-credential-', 20)]);
foreach (range(502, 526) as $id) {
    DB::table('carmis')->insert(['id' => $id, 'goods_id' => 7, 'carmi' => "SYNTHETIC-CARD-$id\n使用说明：这是合成库存，不含真实账号。", 'status' => $id === 503 ? 1 : 2, 'is_loop' => $id === 503 ? 1 : 0, 'created_at' => '2026-10-18 10:00:00']);
}
foreach (range(1, 30) as $id) {
    DB::table('orders')->insert([
        'id' => $id, 'order_sn' => sprintf('QA20261018%08d', $id), 'goods_id' => 7,
        'title' => '示例订阅 · 标准版', 'email' => "customer$id@example.test", 'buy_ip' => '127.0.0.1',
        'type' => $id % 3 === 0 ? 2 : 1, 'status' => $id % 3 === 0 ? 2 : 4,
        'trade_no' => "SYNTHETIC-TRADE-$id", 'actual_price' => '29.90', 'total_price' => '29.90',
        'goods_price' => '29.90', 'pay_id' => 14, 'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00',
    ]);
}
app(App\Support\ShopSettings::class)->setMany(['title' => "Evan's Shop", 'text_logo' => "Evan's Shop", 'description' => '合成测试店铺', 'notice' => '<p>欢迎查看商品与订单。</p>']);
auth('admin')->setUser(App\Models\AdminUser::findOrFail(1));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
