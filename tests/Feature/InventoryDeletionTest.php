<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Filament\Support\InventoryOperations;
use App\Filament\Resources\Cards\Pages\ManageCards;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\McpTestCase;

class InventoryDeletionTest extends McpTestCase
{
    public function test_filament_single_delete_requires_permanent_count_confirmation(): void
    {
        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        DB::table('carmis')->where('id', 501)->update(['status' => 2]);
        $card = Carmis::findOrFail(501);
        $page = Livewire::test(ManageCards::class);
        $this->assertNotNull($page->instance()->getTable()->getAction('delete'), 'Permanent delete action must exist.');
        $page->assertActionVisible(TestAction::make('delete')->table($card))
            ->mountAction(TestAction::make('delete')->table($card))
            ->assertMountedActionModalSee(['永久删除 1 条卡密', '无法恢复']);
        $this->assertNotNull(Carmis::find(501));
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified();
        $this->assertNull(Carmis::withTrashed()->find(501));
    }

    public function test_mcp_permanently_deletes_exact_sold_cards_without_changing_history(): void
    {
        Bus::fake();
        DB::table('carmis')->where('id', 501)->update(['status' => 2]);
        DB::table('carmis')->insert([
            ['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-SOLD-LOOP', 'status' => 2, 'is_loop' => 1],
            ['id' => 503, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-RETAINED', 'status' => 1, 'is_loop' => 1],
        ]);
        DB::table('orders')->insert(['order_sn' => 'DELETE-HISTORY', 'goods_id' => 7, 'title' => 'Synthetic', 'email' => 'buyer@example.test', 'buy_ip' => '127.0.0.1', 'status' => 4, 'info' => 'SYNTHETIC-CARD-501', 'pay_id' => 14, 'trade_no' => 'SYNTHETIC-TRADE']);
        $history = DB::table('orders')->get()->toArray();
        $payments = DB::table('pays')->get()->toArray();
        $goods = DB::table('goods')->get()->toArray();
        $payload = ['ids' => [502, 501], 'reason' => 'Remove selected synthetic sold rows'];
        $preview = $this->callTool('inventory_cards_delete', $payload);
        $this->assertArrayHasKey('plan', $preview);
        $this->assertSame([501, 502], $preview['plan']['ids']);
        $this->assertSame(2, $preview['plan']['count']);
        $this->assertTrue($preview['plan']['permanent']);
        $this->assertSame(3, Carmis::withTrashed()->count());
        $result = $this->callTool('inventory_cards_delete', [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-delete-history']);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(2, $result['result']['changed']);
        $this->assertNull(Carmis::withTrashed()->find(501));
        $this->assertNull(Carmis::withTrashed()->find(502));
        $this->assertNotNull(Carmis::find(503));
        $this->assertEquals($history, DB::table('orders')->get()->toArray());
        $this->assertEquals($payments, DB::table('pays')->get()->toArray());
        $this->assertEquals($goods, DB::table('goods')->get()->toArray());
        $serialized = json_encode([$preview, $result, DB::table('shop_mcp_audit')->get(), DB::table('shop_mcp_previews')->get(), DB::table('shop_mcp_receipts')->get()]);
        foreach (['SYNTHETIC-CARD-501', 'SYNTHETIC-SOLD-LOOP', 'fixture-signing-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        Bus::assertNothingDispatched();
    }

    public function test_filament_bulk_delete_confirms_count_and_removes_only_selected_records(): void
    {
        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        DB::table('carmis')->where('id', 501)->update(['status' => 2]);
        DB::table('carmis')->insert([
            ['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-ARCHIVED', 'status' => 2, 'deleted_at' => now()],
            ['id' => 503, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-RETAIN', 'status' => 1, 'deleted_at' => null],
        ]);
        $page = Livewire::test(ManageCards::class)->filterTable('trashed', true)->selectTableRecords([501, 502]);
        $this->assertNotNull($page->instance()->getTable()->getBulkAction('delete'), 'Permanent bulk delete must exist.');
        $page->mountAction(TestAction::make('delete')->table()->bulk())
            ->assertMountedActionModalSee(['永久删除 2 条卡密', '无法恢复']);
        $this->assertSame(3, Carmis::withTrashed()->count());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified();
        $this->assertSame([503], Carmis::withTrashed()->pluck('id')->all());
        $this->assertSame([], $page->get('selectedTableRecords'));
    }

    public function test_bulk_delete_freezes_select_all_ids_when_confirmation_opens(): void
    {
        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $page = Livewire::test(ManageCards::class)->set('isTrackingDeselectedTableRecords', true)
            ->mountAction(TestAction::make('delete')->table()->bulk())
            ->assertMountedActionModalSee(['永久删除 1 条卡密']);
        DB::table('carmis')->insert(['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-ARRIVED-AFTER-CONFIRMATION']);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertNull(Carmis::withTrashed()->find(501));
        $this->assertNotNull(Carmis::find(502), 'Unreviewed new stock must not join a pending deletion.');
    }

    public function test_active_reusable_stock_rejects_the_whole_selection_in_mcp_and_filament(): void
    {
        DB::table('carmis')->where('id', 501)->update(['status' => 2]);
        DB::table('carmis')->insert(['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-REUSABLE', 'status' => 1, 'is_loop' => 1]);
        $before = DB::table('carmis')->orderBy('id')->get()->toArray();
        $result = $this->callTool('inventory_cards_delete', ['ids' => [501, 502], 'reason' => 'Synthetic protected selection']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, DB::table('shop_mcp_previews')->count());
        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Livewire::test(ManageCards::class)->selectTableRecords([501, 502])
            ->callAction(TestAction::make('delete')->table()->bulk())->assertNotified('未执行操作');
        Livewire::test(ManageCards::class)->callAction(TestAction::make('delete')->table(Carmis::find(502)))
            ->assertNotified('未执行操作');
        $this->assertEquals($before, DB::table('carmis')->orderBy('id')->get()->toArray());
    }

    public function test_archived_cards_can_be_permanently_deleted_without_restoring_them(): void
    {
        $this->actingAs($this->admin, 'admin');
        foreach ([[1, 0], [1, 1], [2, 0], [2, 1]] as [$status, $loop]) {
            $id = DB::table('carmis')->insertGetId(['goods_id' => 7, 'carmi' => 'SYNTHETIC-ARCHIVED', 'status' => $status, 'is_loop' => $loop, 'deleted_at' => now()]);
            $this->assertSame(1, InventoryOperations::delete([$id]));
            $this->assertNull(Carmis::withTrashed()->find($id));
        }
        $this->assertNotNull(Carmis::find(501));
    }

    public function test_delete_rejects_missing_invalid_duplicate_and_unbounded_ids(): void
    {
        foreach ([[], [501, 501], [501, 999], ['501'], range(1, 1001)] as $ids) {
            $result = $this->callTool('inventory_cards_delete', ['ids' => $ids, 'reason' => 'Synthetic invalid selection']);
            $this->assertArrayHasKey('error', $result);
            $this->assertNotNull(Carmis::find(501));
        }
        $this->assertSame(0, DB::table('shop_mcp_previews')->count());
        $this->assertSame(0, DB::table('shop_mcp_receipts')->count());
    }

    public function test_delete_requires_inventory_scope_and_current_admin_role(): void
    {
        $payload = ['ids' => [501], 'reason' => 'Synthetic authorization'];
        $this->assertArrayHasKey('error', $this->callTool('inventory_cards_delete', $payload, ['read', 'inventory:content']));
        DB::table('shop_mcp_tokens')->where('id', $this->tokenId)->update(['scopes' => '["inventory"]']);
        $preview = $this->callTool('inventory_cards_delete', $payload);
        DB::table('admin_role_users')->delete();
        $this->rpc('tools/call', ['name' => 'inventory_cards_delete', 'arguments' => [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-role-removed']])->assertForbidden();
        $this->actingAs($this->admin, 'admin');
        $this->get('/evansadmin/cards')->assertForbidden();
        try { InventoryOperations::delete([501]); $this->fail('Unauthorized delete accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertNotNull(Carmis::find(501));
    }

    public function test_delete_preview_rejects_card_edits_status_changes_archival_or_missing_rows(): void
    {
        foreach ([['carmi' => 'SYNTHETIC-EDIT'], ['status' => 2], ['is_loop' => 1], ['deleted_at' => '2026-01-01 00:00:00'], null] as $index => $change) {
            DB::table('carmis')->updateOrInsert(['id' => 501], ['goods_id' => 7, 'carmi' => 'SYNTHETIC-CARD-501', 'status' => 1, 'is_loop' => 0, 'deleted_at' => null]);
            $payload = ['ids' => [501], 'reason' => 'Synthetic stale preview'];
            $preview = $this->callTool('inventory_cards_delete', $payload);
            if ($change === null) { DB::table('carmis')->where('id', 501)->delete(); }
            else { DB::table('carmis')->where('id', 501)->update($change); }
            $before = DB::table('carmis')->get()->toArray();
            $result = $this->callTool('inventory_cards_delete', [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-stale-delete-'.$index]);
            $this->assertSame('stale_preview', $result['error']['code']);
            $this->assertEquals($before, DB::table('carmis')->get()->toArray());
        }
        $this->assertSame(0, DB::table('shop_mcp_receipts')->count());
    }

    public function test_delete_replays_saved_receipt_after_rows_are_gone_and_rejects_key_collision(): void
    {
        $payload = ['ids' => [501], 'reason' => 'Synthetic replay'];
        $preview = $this->callTool('inventory_cards_delete', $payload);
        $apply = [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-delete-replay'];
        $first = $this->callTool('inventory_cards_delete', $apply);
        $replay = $this->callTool('inventory_cards_delete', $apply);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['operationId'], $replay['operationId']);
        $this->assertSame($first['result'], $replay['result']);
        $this->assertSame(1, DB::table('shop_mcp_receipts')->count());
        $collision = $this->callTool('inventory_cards_delete', [...$apply, 'ids' => [502]]);
        $this->assertSame('idempotency_conflict', $collision['error']['code']);
        $audit = DB::table('shop_mcp_audit')->where('operation', 'inventory_cards_delete')->where('status', 'succeeded')->first();
        $this->assertSame(['ids' => [501]], json_decode($audit->targets, true));
        $this->assertSame($first['operationId'], $audit->receipt_id);
    }

    public function test_delete_failure_rolls_back_selected_rows_and_receipt_then_same_apply_can_retry(): void
    {
        DB::table('carmis')->insert(['id' => 502, 'goods_id' => 7, 'carmi' => 'SYNTHETIC-ROLLBACK', 'status' => 2]);
        $payload = ['ids' => [501, 502], 'reason' => 'Synthetic rollback'];
        $preview = $this->callTool('inventory_cards_delete', $payload);
        $apply = [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-delete-rollback'];
        DB::unprepared("CREATE TRIGGER fail_delete BEFORE DELETE ON carmis WHEN OLD.id = 502 BEGIN SELECT RAISE(ABORT, 'synthetic delete failure'); END");
        $result = $this->callTool('inventory_cards_delete', $apply);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(2, Carmis::withTrashed()->count());
        $this->assertSame(0, DB::table('shop_mcp_receipts')->count());
        $this->assertSame(0, DB::table('shop_mcp_audit')->where('status', 'succeeded')->count());
        DB::unprepared('DROP TRIGGER fail_delete');
        $this->assertSame('succeeded', $this->callTool('inventory_cards_delete', $apply)['status']);
        $this->assertSame(0, Carmis::withTrashed()->count());
    }

    public function test_receipt_failure_after_delete_rolls_inventory_back(): void
    {
        $payload = ['ids' => [501], 'reason' => 'Synthetic receipt rollback'];
        $preview = $this->callTool('inventory_cards_delete', $payload);
        DB::unprepared("CREATE TRIGGER fail_receipt BEFORE UPDATE ON shop_mcp_receipts WHEN NEW.status = 'succeeded' BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        $result = $this->callTool('inventory_cards_delete', [...$payload, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-delete-receipt-failure']);
        $this->assertArrayHasKey('error', $result);
        $this->assertNotNull(Carmis::withTrashed()->find(501));
        $this->assertSame(0, DB::table('shop_mcp_receipts')->count());
        $this->assertSame(0, DB::table('shop_mcp_audit')->where('status', 'succeeded')->count());
    }

    public function test_bulk_confirmation_ids_cannot_be_replaced_by_client_hydration(): void
    {
        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $page = Livewire::test(ManageCards::class)->selectTableRecords([501])
            ->mountAction(TestAction::make('delete')->table()->bulk());
        try { $page->set('deletionIds', [999]); $this->fail('Client replaced confirmed IDs.'); }
        catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            $this->assertNotNull(Carmis::find(501));
        }
    }
}
