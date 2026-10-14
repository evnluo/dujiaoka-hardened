<?php

namespace Tests\Feature;

use App\Mcp\TokenManager;
use Illuminate\Support\Facades\DB;
use Tests\McpTestCase;

class McpTokenTest extends McpTestCase
{
    public function test_bootstrap_hashes_tokens_and_rotation_revokes_only_authenticated_owners_token(): void
    {
        $secret = str_repeat('ab', 32);
        $id = app(TokenManager::class)->import('mcp-admin', 'synthetic-password', $secret, ['read', 'catalog'], 'Synthetic bootstrap', 30);
        $row = DB::table('shop_mcp_tokens')->find($id);
        $this->assertSame(hash('sha256', $secret), $row->token_hash);
        $this->assertStringNotContainsString($secret, json_encode($row));
        $new = app(TokenManager::class)->import('mcp-admin', 'synthetic-password', str_repeat('cd', 32), ['read'], 'Synthetic rotated', 30, $id);
        $this->assertNotNull(DB::table('shop_mcp_tokens')->find($id)->revoked_at);
        app(TokenManager::class)->revoke('mcp-admin', 'synthetic-password', $new);
        $this->assertNotNull(DB::table('shop_mcp_tokens')->find($new)->revoked_at);
    }
    public function test_bootstrap_rejects_bad_password_wildcard_scope_and_low_entropy_shape(): void
    {
        foreach ([['bad-password', str_repeat('ab', 32), ['read']], ['synthetic-password', str_repeat('ab', 32), ['*']], ['synthetic-password', 'too-short', ['read']]] as [$password, $secret, $scopes]) {
            try { app(TokenManager::class)->import('mcp-admin', $password, $secret, $scopes, 'Synthetic', 1); $this->fail('Accepted invalid bootstrap'); }
            catch (\App\Mcp\OperationException $e) { $this->assertNotSame('', $e->getMessage()); }
        }
        $this->assertSame(1, DB::table('shop_mcp_tokens')->count());
    }
}
