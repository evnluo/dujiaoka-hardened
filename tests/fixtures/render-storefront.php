<?php
// Render the actual Blade page with in-memory settings for local browser QA.
require __DIR__ . '/../../vendor/autoload.php';
$bootstrap = new class { use \Tests\CreatesApplication; };
$app = $bootstrap->createApplication();
$_SERVER['SERVER_PORT'] = 80;
\Illuminate\Support\Facades\Schema::create('admin_settings', function ($table): void {
    $table->string('slug')->primary(); $table->text('value'); $table->timestamps();
});
app(\App\Support\ShopSettings::class)->setMany([
    'template' => 'hyper', 'text_logo' => "Evan's Shop", 'title' => "Evan's Shop",
    'notice' => '<h3>欢迎来到 Evan\'s Shop</h3><p>本站点是 Evan 的个人售卖站，卖各种账号 / 订阅等。经常更新，欢迎光顾！</p><p>如需联系我（售后 / 问题），请前往 <a href="https://me.ohevan.com">me.ohevan.com</a> 加我联系方式。</p><p>本站包售后，不满意包退款。</p>',
]);
config(['filesystems.disks.admin.url' => '/uploads']);
echo view('hyper.static_pages.home', ['data' => require __DIR__ . '/storefront-catalog.php'])->render();
