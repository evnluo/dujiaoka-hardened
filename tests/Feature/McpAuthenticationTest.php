<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\McpTestCase;

class McpAuthenticationTest extends McpTestCase
{
    public function test_all_tools_fail_closed_without_their_exact_scope(): void
    {
        foreach (\App\Mcp\ShopServer::operations() as $op) {
            $r = $this->callTool($op->name, [], []);
            $this->assertSame('forbidden_scope', $r['error']['code'], $op->name);
        }
    }

    public function test_rate_limits_and_malformed_json_have_no_tool_side_effects(): void
    {
        config(['shop_mcp.requests_per_minute' => 1]);
        $this->rpc('tools/list')->assertOk();
        $this->rpc('tools/list')->assertStatus(429);
        \Illuminate\Support\Facades\RateLimiter::clear('shop-mcp:'.$this->tokenId);
        $this->call('POST', '/api/admin/mcp', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer, 'CONTENT_TYPE' => 'application/json'], '{broken')->assertStatus(400);
        $this->assertSame(0, DB::table('shop_mcp_receipts')->count());
    }

    public function test_real_initialize_and_paginated_discovery_are_authenticated_and_scoped(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'synthetic', 'version' => '1']])->assertOk()->assertJsonPath('result.serverInfo.name', 'Shop Admin');
        $names = []; $cursor = null;
        do {
            $r = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : [])->assertOk()->json('result');
            foreach ($r['tools'] as $tool) { $names[] = $tool['name']; $this->assertFalse($tool['inputSchema']['additionalProperties']); $this->assertSame('object', $tool['outputSchema']['type']); }
            $cursor = $r['nextCursor'] ?? null;
        } while ($cursor);
        $this->assertContains('catalog_products_list', $names);
        $this->assertContains('orders_complete', $names);
        $this->assertSame(count($names), count(array_unique($names)));
        DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['scopes' => '["read"]']);
        $this->assertSame('forbidden_scope', $this->callTool('orders_complete', ['id' => 1, 'message' => 'Done', 'reason' => 'test'])['error']['code']);
    }

    public function test_expired_revoked_missing_role_and_cookie_only_auth_fail_closed(): void
    {
        DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['expires_at' => now()->subMinute()]);
        $this->rpc('tools/list')->assertUnauthorized();
        DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->rpc('tools/list')->assertUnauthorized();
        DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['revoked_at' => null]);
        DB::table('admin_role_users')->delete();
        $this->rpc('tools/list')->assertForbidden();
        $this->actingAs($this->admin, 'admin')->withHeaders(['Authorization' => ''])->postJson('/api/admin/mcp', [])->assertUnauthorized();
    }

    public function test_origin_size_method_and_content_type_boundaries(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.invalid'])->rpc('tools/list')->assertForbidden();
        $this->withHeaders(['Origin' => 'https://shop.oknice.ca'])->rpc('tools/list')->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->bearer])->getJson('/api/admin/mcp')->assertStatus(405);
        $this->call('POST', '/api/admin/mcp', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer, 'CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
        $this->call('POST', '/api/admin/mcp', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer, 'CONTENT_TYPE' => 'application/json'], str_repeat('x', 9 * 1024 * 1024))->assertStatus(413);
    }
}
