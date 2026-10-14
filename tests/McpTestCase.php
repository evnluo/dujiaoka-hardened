<?php

namespace Tests;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class McpTestCase extends TestCase
{
    protected string $bearer = 'synthetic-mcp-bearer-with-at-least-32-bytes';
    protected int $tokenId;
    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_users', function ($t): void { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
        Schema::create('admin_roles', function ($t): void { $t->id(); $t->string('slug'); });
        Schema::create('admin_role_users', function ($t): void { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_settings', function ($t): void { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
        foreach (glob(base_path('database/migrations/*create_shop_*.php')) as $path) (require $path)->up();
        $this->admin = AdminUser::create(['username' => 'mcp-admin', 'name' => 'Synthetic admin', 'password' => 'synthetic-password']);
        DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
        DB::table('admin_role_users')->insert(['user_id' => $this->admin->id, 'role_id' => 1]);
        $this->tokenId = DB::table('shop_mcp_tokens')->insertGetId(['admin_id' => $this->admin->id, 'name' => 'Synthetic', 'token_hash' => hash('sha256', $this->bearer), 'scopes' => json_encode(['read', 'catalog', 'inventory', 'inventory:content', 'orders', 'orders:content', 'billing', 'settings', 'operations']), 'expires_at' => now()->addDay(), 'created_at' => now()]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->withoutVite();
    }

    protected function rpc(string $method, array $params = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->bearer, 'Accept' => 'application/json, text/event-stream'])->postJson('/api/admin/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    protected function callTool(string $name, array $payload = [], ?array $scopes = null): array
    {
        if ($scopes !== null) DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['scopes' => json_encode($scopes)]);
        $r = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $payload])->assertOk()->json('result');
        return $r['structuredContent'] ?? json_decode($r['content'][0]['text'], true);
    }
}
