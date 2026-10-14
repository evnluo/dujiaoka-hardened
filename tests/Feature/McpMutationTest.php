<?php

namespace Tests\Feature;

use App\Models\GoodsGroup;
use Illuminate\Support\Facades\DB;
use Tests\McpTestCase;

class McpMutationTest extends McpTestCase
{
    public function test_external_review_is_rechecked_inside_side_effect_transaction(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'synthetic', 'version' => '1']]);
        request()->attributes->set('shop_mcp_token_id', $this->tokenId);
        $sent = false;
        $op = new \App\Mcp\Operation(name: 'synthetic_external', description: 'Synthetic', scope: 'catalog', schema: [], rules: [],
            snapshot: fn ($p, $lock) => DB::table('goods_group')->where('id', 1)->first(),
            preview: fn ($p) => [],
            apply: function ($p, $guard = null) use (&$sent) {
                DB::table('goods_group')->where('id', 1)->update(['gp_name' => 'Concurrent change after reservation']);
                return DB::transaction(function () use ($guard, &$sent) { if ($guard) $guard(); $sent = true; return []; });
            }, external: true);
        $runner = app(\App\Mcp\MutationRunner::class);
        $preview = $runner->run($op, ['reason' => 'Synthetic']);
        $runner->run($op, ['reason' => 'Synthetic', 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-boundary-key']);
        $this->assertFalse($sent, 'External apply must reject state changes between reservation and side effect.');
    }

    public function test_default_preview_apply_replay_and_collision_are_bound_to_exact_payload(): void
    {
        $p = ['gp_name' => 'Preview group', 'reason' => 'Synthetic lifecycle'];
        $preview = $this->callTool('catalog_categories_create', $p);
        $this->assertTrue($preview['dryRun']);
        $this->assertSame(1, GoodsGroup::count());
        $apply = [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-key-0000001'];
        $first = $this->callTool('catalog_categories_create', $apply);
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame(2, GoodsGroup::count());
        $replay = $this->callTool('catalog_categories_create', $apply);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['result'], $replay['result']);
        $this->assertSame(2, GoodsGroup::count());
        $collision = $this->callTool('catalog_categories_create', [...$apply, 'gp_name' => 'Different']);
        $this->assertSame('idempotency_conflict', $collision['error']['code']);
        $this->assertStringNotContainsString('Preview group', json_encode(DB::table('shop_mcp_audit')->get()));
    }

    public function test_stale_expired_cross_token_previews_and_missing_preview_cannot_apply(): void
    {
        $p = ['id' => 1, 'gp_name' => 'New name', 'reason' => 'Synthetic stale'];
        $preview = $this->callTool('catalog_categories_update', $p);
        $old = DB::table('shop_mcp_tokens')->find($this->tokenId);
        DB::table('shop_mcp_tokens')->insert(['admin_id' => $old->admin_id, 'name' => 'Other client', 'token_hash' => hash('sha256', str_repeat('cd', 32)), 'scopes' => $old->scopes, 'expires_at' => $old->expires_at, 'created_at' => now()]);
        $original = $this->bearer; $this->bearer = str_repeat('cd', 32);
        $cross = $this->callTool('catalog_categories_update', [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-cross-token']);
        $this->assertSame('preview_mismatch', $cross['error']['code']);
        $this->bearer = $original;
        DB::table('goods_group')->where('id', 1)->update(['gp_name' => 'Concurrent edit']);
        $r = $this->callTool('catalog_categories_update', [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-key-stale']);
        $this->assertSame('stale_preview', $r['error']['code']);
        $this->assertSame('Concurrent edit', GoodsGroup::find(1)->gp_name);
        $this->assertSame('invalid_input', $this->callTool('catalog_categories_update', [...$p, 'dryRun' => false])['error']['code']);
        $preview = $this->callTool('catalog_categories_update', $p);
        $this->travel(11)->minutes();
        $r = $this->callTool('catalog_categories_update', [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-key-expired']);
        $this->assertSame('preview_expired', $r['error']['code']);
    }

    public function test_unknown_fields_wrong_json_types_duplicate_ids_and_injection_are_rejected_without_echo(): void
    {
        foreach ([['id' => '1'], ['id' => 1, 'password' => 'NEVER-ECHO'], ['id' => 1, 'dryRun' => 'false'], ['id' => 1, 'gp_name' => ['nested']]] as $bad) {
            $r = $this->callTool('catalog_categories_update', [...$bad, 'reason' => 'synthetic']);
            $this->assertSame('invalid_input', $r['error']['code']);
            $this->assertStringNotContainsString('NEVER-ECHO', json_encode($r));
        }
        $r = $this->callTool('catalog_categories_set_published', ['ids' => [1, 1], 'published' => true, 'reason' => 'synthetic']);
        $this->assertSame('invalid_input', $r['error']['code']);
        $this->assertSame(1, GoodsGroup::count());
    }
}
