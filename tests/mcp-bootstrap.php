<?php
// Dedicated synthetic database only. No production credentials, no network notifications.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (getenv('MCP_SYNTHETIC') !== '1' || getenv('APP_ENV') !== 'testing' || DB::connection()->getDatabaseName() !== 'dujiaoka_mcp_test') {
    fwrite(STDERR, "Refusing non-synthetic MCP database\n"); exit(2);
}
Schema::dropAllTables();
require __DIR__.'/fixtures/business-schema.php';
Schema::create('admin_users', function ($t) { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
Schema::create('admin_roles', function ($t) { $t->id(); $t->string('slug'); });
Schema::create('admin_role_users', function ($t) { $t->integer('user_id'); $t->integer('role_id'); });
Schema::create('admin_settings', function ($t) { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
foreach (glob(__DIR__.'/../database/migrations/*create_shop_*.php') as $file) (require $file)->up();
foreach (['shop_mcp_tokens' => ['expires_at', 'revoked_at', 'created_at'], 'shop_mcp_previews' => ['expires_at', 'created_at'], 'shop_mcp_audit' => ['created_at']] as $table => $columns) {
    foreach ($columns as $column) {
        $definition = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->first();
        if (! $definition || strtolower($definition->DATA_TYPE) !== 'datetime' || str_contains(strtolower($definition->EXTRA), 'on update')) {
            fwrite(STDERR, "MCP dates must not inherit automatic timestamp mutation\n"); exit(1);
        }
    }
}
$admin = App\Models\AdminUser::create(['username' => 'sdk-admin', 'name' => 'Synthetic SDK', 'password' => 'synthetic-password']);
DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
DB::table('admin_role_users')->insert(['user_id' => $admin->id, 'role_id' => 1]);
// This known synthetic credential is valid only in the disposable test database.
app(App\Mcp\TokenManager::class)->import('sdk-admin', 'synthetic-password', str_repeat('ab', 32), App\Mcp\Access::SCOPES, 'SDK synthetic', 1);
// Fixed database-local date shared with the SDK summary assertions; independent of the runner's UTC day.
DB::table('orders')->insert(['order_sn' => 'SDK-MANUAL', 'goods_id' => 7, 'title' => 'Synthetic manual', 'email' => 'fixture@example.test', 'buy_ip' => '127.0.0.1', 'actual_price' => '10.00', 'trade_no' => 'SYNTHETIC-PAID', 'status' => 2, 'type' => 2, 'created_at' => '2026-01-15 12:00:00']);
print("Synthetic MCP fixture initialized\n");
