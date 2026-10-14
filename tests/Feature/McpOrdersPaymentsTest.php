<?php

namespace Tests\Feature;

use App\Events\OrderUpdated;
use App\Jobs\MailSend;
use App\Mcp\Domains\OrdersDomain;
use App\Mcp\Domains\PaymentsDomain;
use App\Mcp\Domains\RefundsDomain;
use App\Mcp\Operation;
use App\Mcp\OperationException;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class McpOrdersPaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'database', 'logging.default' => 'null', 'shop_mcp.allowed_hosts' => ['api.dulupay.com']]);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_users', function ($t) { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
        Schema::create('admin_roles', function ($t) { $t->id(); $t->string('slug'); });
        Schema::create('admin_role_users', function ($t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_settings', function ($t) { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
        $admin = AdminUser::create(['username' => 'fixture-admin', 'name' => 'Fixture Admin', 'password' => 'test-only-password']);
        DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
        DB::table('admin_role_users')->insert(['user_id' => $admin->id, 'role_id' => 1]);
        $this->actingAs($admin, 'admin');
        (require database_path('migrations/2026_10_13_000004_create_shop_order_notifications.php'))->up();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function tool(string $name): Operation
    {
        foreach ([OrdersDomain::class, PaymentsDomain::class, RefundsDomain::class] as $class) {
            foreach ((new $class)->tools() as $tool) if ($tool->name === $name) return $tool;
        }
        $this->fail('Missing operation '.$name);
    }

    private function runTool(string $name, array $data = [], string $phase = 'read'): array
    {
        $tool = $this->tool($name);
        Validator::make($data, $tool->rules)->validate();
        return ($tool->{$phase})($data);
    }

    private function order(array $data = []): Order
    {
        $id = DB::table('orders')->insertGetId(array_replace(['order_sn' => 'FIXTURE-'.bin2hex(random_bytes(4)), 'goods_id' => 7, 'title' => 'Synthetic order', 'email' => 'synthetic@example.test', 'buy_ip' => '127.0.0.1', 'pay_id' => 14, 'actual_price' => '10.00', 'total_price' => '10.00', 'status' => 2, 'type' => 2, 'trade_no' => 'TRADE-'.bin2hex(random_bytes(4)), 'created_at' => now(), 'updated_at' => now()], $data));
        return Order::withTrashed()->findOrFail($id);
    }

    private function reject(callable $call, string $reason): void
    {
        try { $call(); $this->fail('Expected rejection '.$reason); }
        catch (OperationException $e) { $this->assertSame($reason, $e->getMessage()); }
    }

    private function refunds(): void
    {
        (require database_path('migrations/2026_10_13_000003_create_shop_order_refunds.php'))->up();
    }

    public function test_catalog_scopes_and_no_unsafe_order_crud(): void
    {
        $names = [];
        foreach ([OrdersDomain::class, PaymentsDomain::class, RefundsDomain::class] as $class) foreach ((new $class)->tools() as $tool) {
            $this->assertNotContains($tool->name, $names); $names[] = $tool->name;
            $this->assertNotEmpty($tool->description);
        }
        $this->assertSame('orders:content', $this->tool('orders_content')->scope);
        $this->assertSame('orders', $this->tool('orders_get')->scope);
        $this->assertSame('billing', $this->tool('payments_get')->scope);
        foreach (['orders_archive', 'orders_restore', 'orders_create', 'orders_update', 'payments_delete'] as $name) $this->assertNotContains($name, $names);
    }

    public function test_order_reads_filters_totals_and_explicit_content_scope(): void
    {
        $a = $this->order(['info' => 'HISTORICAL-SECRET', 'search_pwd' => 'LOOKUP-SECRET']);
        $this->order(['status' => 5, 'email' => 'other@example.test']);
        $archived = $this->order(['status' => 4, 'deleted_at' => now()]);
        $r = $this->runTool('orders_list', ['perPage' => 1]);
        $this->assertSame(2, $r['total']); $this->assertTrue($r['hasMore']); $this->assertCount(1, $r['items']);
        $this->assertStringNotContainsString('@', json_encode($r));
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        foreach ([['search' => 'synthetic@example.test'], ['status' => 2], ['type' => 2, 'tab' => 'manual'], ['goodsId' => 7, 'payId' => 14, 'tab' => 'manual'], ['from' => today()->toDateString(), 'until' => today()->toDateString(), 'tab' => 'manual']] as $p) {
            $r = $this->runTool('orders_list', $p); $this->assertSame(1, $r['total']); $this->assertSame($a->id, $r['items'][0]['id']);
        }
        $this->assertSame(1, $this->runTool('orders_list', ['archived' => 'archived'])['total']);
        $this->assertSame(3, $this->runTool('orders_list', ['archived' => 'all'])['total']);
        $detail = $this->runTool('orders_get', ['id' => $a->id]);
        $this->assertSame('synthetic@example.test', $detail['email']);
        $this->assertSame('Yipay fixture', $detail['payment']['name']);
        $this->assertStringNotContainsString('SECRET', json_encode($detail));
        $this->assertNotNull($this->runTool('orders_get', ['id' => $archived->id])['deleted_at']);
        $content = $this->runTool('orders_content', ['id' => $a->id]);
        $this->assertSame('HISTORICAL-SECRET', $content['info']); $this->assertSame('LOOKUP-SECRET', $content['search_pwd']);
        DB::table('carmis')->update(['carmi' => 'CURRENT-STOCK']);
        $this->assertSame($content, $this->runTool('orders_content', ['id' => $a->id]));
        $counts = $this->runTool('orders_status_counts');
        $this->assertSame(2, $counts['total']); $this->assertSame(1, $counts['tabs']['manual']); $this->assertSame(1, $counts['tabs']['attention']); $this->assertSame(2, $counts['today']);
        $this->assertSame(1, $this->runTool('orders_status_counts', ['tab' => 'manual'])['total']);
    }

    public function test_manual_preview_is_silent_and_snapshots_cover_content(): void
    {
        Event::fake([OrderUpdated::class]);
        $o = $this->order(['info' => 'PRIVATE-ORIGINAL']);
        $p = ['id' => $o->id, 'message' => 'PRIVATE-MESSAGE'];
        $tool = $this->tool('orders_complete'); $before = ($tool->snapshot)($p, false);
        $r = $this->runTool('orders_complete', $p, 'preview');
        $this->assertStringNotContainsString('PRIVATE', json_encode($r)); $this->assertSame(2, (int) $o->fresh()->status);
        Queue::assertNothingPushed(); Event::assertNotDispatched(OrderUpdated::class);
        DB::table('orders')->where('id', $o->id)->update(['info' => 'CHANGED-WITH-SAME-TIMESTAMP']);
        $this->assertNotEquals($before, ($tool->snapshot)($p, false));
    }

    public function test_manual_single_and_bulk_preserve_payment_inventory_and_default_notifications_off(): void
    {
        Event::fake([OrderUpdated::class]); $stock = DB::table('carmis')->get()->toJson();
        $a = $this->order(['info' => 'Original']); $b = $this->order();
        $this->runTool('orders_start_processing', ['id' => $a->id], 'apply');
        $this->assertSame(3, (int) $a->fresh()->status);
        $r = $this->runTool('orders_complete', ['id' => $a->id, 'message' => 'Done'], 'apply');
        $this->assertSame(1, $r['changed']); $this->assertSame("Original\n\nDone", $a->fresh()->info);
        $this->runTool('orders_fail', ['id' => $b->id, 'message' => 'Failed'], 'apply');
        $this->assertSame(5, (int) $b->fresh()->status);
        $this->assertSame($a->trade_no, $a->fresh()->trade_no); $this->assertEquals(10, $a->fresh()->actual_price);
        $this->assertSame($stock, DB::table('carmis')->get()->toJson());
        Queue::assertNothingPushed(); Event::assertNotDispatched(OrderUpdated::class);
    }

    public function test_bulk_reports_each_exact_id_skips_and_unchanged_without_reappending(): void
    {
        foreach (['orders_bulk_complete' => 4, 'orders_bulk_fail' => 5] as $name => $target) {
            $a = $this->order(); $done = $this->order(['status' => $target]); $unpaid = $this->order(['trade_no' => '']); $archived = $this->order(['deleted_at' => now()]);
            $p = ['ids' => [$unpaid->id, $done->id, $a->id, $archived->id, 999999], 'message' => 'Batch'];
            $preview = $this->runTool($name, $p, 'preview');
            $r = $this->runTool($name, $p, 'apply');
            $this->assertSame([1, 1, 3], [$r['changed'], $r['unchanged'], $r['skipped']]);
            $this->assertSame(array_column($preview['items'], 'id'), array_column($r['items'], 'id'));
            $this->assertCount(5, $r['items']); $this->assertSame($target, (int) $a->fresh()->status);
            $again = $this->runTool($name, $p, 'apply'); $this->assertSame(0, $again['changed']);
            $this->assertSame(1, substr_count($a->fresh()->info, 'Batch'));
        }
    }

    public function test_bulk_duplicate_and_oversized_selections_are_rejected(): void
    {
        foreach ([[1, 1], range(1, 1001)] as $ids) {
            try { $this->runTool('orders_bulk_complete', ['ids' => $ids, 'message' => 'x'], 'preview'); $this->fail('Invalid selection accepted'); }
            catch (\Illuminate\Validation\ValidationException|OperationException) { $this->assertSame(0, DB::table('orders')->count()); }
        }
    }

    public function test_manual_guard_rejects_every_ineligible_state_and_zero_price_still_works(): void
    {
        foreach ([['status' => 1], ['status' => -1], ['type' => 1], ['trade_no' => ''], ['deleted_at' => now()], ['status' => 6], ['info' => str_repeat('x', 60000)]] as $change) {
            $o = $this->order($change);
            $r = $this->runTool('orders_complete', ['id' => $o->id, 'message' => 'Do not apply'], 'apply');
            $this->assertSame(1, $r['skipped']); $this->assertSame((int) $o->status, (int) $o->fresh()->status);
        }
        $free = $this->order(['actual_price' => 0, 'trade_no' => '']);
        $this->runTool('orders_complete', ['id' => $free->id, 'message' => 'Free'], 'apply');
        $this->assertSame(4, (int) $free->fresh()->status); $this->assertSame('', $free->fresh()->trade_no);
    }

    public function test_opt_in_notifications_are_after_commit_and_rollback_sends_nothing(): void
    {
        $o = $this->order(); $seen = 0;
        Event::listen(OrderUpdated::class, function () use (&$seen) { $this->assertSame(0, DB::transactionLevel()); $seen++; });
        DB::table('emailtpls')->insert(['tpl_name' => 'Completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        DB::beginTransaction();
        $this->runTool('orders_complete', ['id' => $o->id, 'message' => 'Done', 'notifyCustomer' => true], 'apply');
        Queue::assertNothingPushed(); $this->assertSame(0, $seen); DB::rollBack();
        DB::transaction(fn () => null); Queue::assertNothingPushed();
        DB::transaction(fn () => $this->runTool('orders_complete', ['id' => $o->id, 'message' => 'Done', 'notifyCustomer' => true], 'apply'));
        Queue::assertPushed(MailSend::class, 1); $this->assertSame(1, $seen);
    }

    public function test_resend_uses_only_historical_content_without_allocating_or_mutating_order(): void
    {
        $o = $this->order(['status' => 4, 'type' => 1, 'info' => 'HISTORICAL-KEY']);
        $before = $o->getAttributes(); $stock = DB::table('carmis')->get()->toJson();
        $tool = $this->tool('orders_resend_delivery'); $this->assertTrue($tool->external);
        $preview = $this->runTool('orders_resend_delivery', ['id' => $o->id], 'preview');
        $this->assertStringNotContainsString('HISTORICAL-KEY', json_encode($preview)); Queue::assertNothingPushed();
        $r = $this->runTool('orders_resend_delivery', ['id' => $o->id], 'apply');
        $this->assertSame('submitted', $r['notification']); $this->assertFalse($r['allocatedInventory']);
        Queue::assertPushed(MailSend::class, function ($job) { $data = (array) $job; return in_array('HISTORICAL-KEY', $data, true) && in_array('synthetic@example.test', $data, true); });
        $this->assertSame($before, $o->fresh()->getAttributes()); $this->assertSame($stock, DB::table('carmis')->get()->toJson());
    }

    public function test_payment_metadata_never_discloses_secrets_and_legacy_is_read_only(): void
    {
        $r = $this->runTool('payments_list'); $this->assertSame(2, $r['total']);
        foreach ([$r, $this->runTool('payments_get', ['id' => 14])] as $data) {
            $this->assertStringNotContainsString('fixture-signing-key', json_encode($data)); $this->assertStringNotContainsString('https://pay.example.test', json_encode($data));
        }
        $this->reject(fn () => $this->runTool('payments_update', ['id' => 15, 'is_open' => true], 'preview'), 'gateway_read_only');
        $this->assertSame(1, $this->runTool('payments_list', ['search' => 'Yipay'])['total']);
    }

    public function test_payment_create_update_preview_preserves_write_only_secrets_and_fixes_route(): void
    {
        $p = ['pay_name' => 'New', 'pay_check' => 'wxpay', 'merchant_id' => 'fixture', 'pay_client' => 3, 'is_open' => false, 'merchant_key' => 'https://api.dulupay.com/submit.php', 'merchant_pem' => 'PRIVATE-NEW-KEY'];
        $preview = $this->runTool('payments_create', $p, 'preview');
        $this->assertSame(2, Pay::count()); $this->assertStringNotContainsString('PRIVATE-NEW-KEY', json_encode($preview)); $this->assertStringNotContainsString('https://', json_encode($preview));
        $created = $this->runTool('payments_create', $p, 'apply');
        $pay = Pay::findOrFail($created['id']); $this->assertSame('/pay/yipay', $pay->pay_handleroute); $this->assertSame(Pay::METHOD_JUMP, (int) $pay->pay_method);
        $this->assertStringNotContainsString('PRIVATE-NEW-KEY', json_encode($created));
        $this->runTool('payments_update', ['id' => $pay->id, 'pay_name' => 'Renamed'], 'apply');
        $this->assertSame('PRIVATE-NEW-KEY', $pay->fresh()->merchant_pem);
        $this->runTool('payments_update', ['id' => $pay->id, 'merchant_pem' => ''], 'apply');
        $this->assertSame('PRIVATE-NEW-KEY', $pay->fresh()->merchant_pem);
        $this->reject(fn () => $this->runTool('payments_update', ['id' => $pay->id, 'merchant_key' => 'https://evil.example.test'], 'preview'), 'gateway_host_not_allowed');
        Http::assertNothingSent();
    }

    private function provider(Order $o, array $replace = []): void
    {
        DB::table('pays')->where('id', 14)->update(['merchant_key' => 'https://api.dulupay.com/submit.php']);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(['api.dulupay.com/api.php*' => Http::response(array_replace(['code' => 1, 'status' => 1, 'pid' => 'fixture-merchant', 'out_trade_no' => $o->order_sn, 'trade_no' => 'VERIFIED-TRADE', 'type' => 'alipay', 'money' => '10.00', 'buyer' => 'PRIVATE-BUYER', 'name' => 'PRIVATE-DESCRIPTION', 'key' => 'SECRET'], $replace))]);
    }

    public function test_provider_query_is_read_only_sanitized_and_identity_checked(): void
    {
        $o = $this->order(['status' => -1, 'type' => 1, 'trade_no' => '']); $this->provider($o);
        $r = $this->runTool('payments_query_status', ['orderId' => $o->id]);
        $this->assertSame('paid', $r['providerStatus']); $this->assertSame('VERIFIED-TRADE', $r['tradeNo']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r)); $this->assertStringNotContainsString('SECRET', json_encode($r));
        $this->assertSame(-1, (int) $o->fresh()->status); Queue::assertNothingPushed();
        foreach (['pid' => 'wrong', 'type' => 'wxpay', 'money' => '9.99', 'out_trade_no' => 'wrong', 'trade_no' => ['invalid'], 'status' => 2] as $key => $value) {
            $this->provider($o, [$key => $value]);
            $this->reject(fn () => $this->runTool('payments_query_status', ['orderId' => $o->id]), 'reconciliation_payment_not_verified');
        }
        $this->provider($o, ['status' => 0]); $this->assertSame('unpaid', $this->runTool('payments_query_status', ['orderId' => $o->id])['providerStatus']);
    }

    public function test_expired_recovery_preview_has_no_provider_calls_apply_queries_fresh_and_fulfils_once(): void
    {
        $o = $this->order(['status' => -1, 'type' => 1, 'trade_no' => '', 'info' => '']); $this->provider($o);
        $p = ['orderId' => $o->id, 'tradeNo' => 'VERIFIED-TRADE'];
        $this->runTool('payments_recover_expired', $p, 'preview'); Http::assertNothingSent(); Queue::assertNothingPushed();
        $r = $this->runTool('payments_recover_expired', $p, 'apply');
        $this->assertSame(4, $r['after']['status']); $this->assertSame('VERIFIED-TRADE', $o->fresh()->trade_no);
        $this->assertStringNotContainsString('SYNTHETIC-CARD', json_encode($r));
        $this->runTool('payments_recover_expired', $p, 'apply');
        Queue::assertPushed(MailSend::class, 1); $this->assertSame(1, (int) DB::table('goods')->value('sales_volume')); Http::assertSentCount(2);
    }

    public function test_expired_recovery_rejects_coupon_and_reused_archived_trade(): void
    {
        $o = $this->order(['status' => -1, 'type' => 1, 'trade_no' => '', 'coupon_id' => 3]);
        $this->reject(fn () => $this->runTool('payments_recover_expired', ['orderId' => $o->id, 'tradeNo' => 'VERIFIED-TRADE'], 'preview'), 'recovery_coupon_not_supported');
        DB::table('orders')->where('id', $o->id)->update(['coupon_id' => 0]);
        $this->order(['trade_no' => 'VERIFIED-TRADE', 'status' => 4, 'deleted_at' => now()]); $this->provider($o);
        $this->reject(fn () => $this->runTool('payments_recover_expired', ['orderId' => $o->id, 'tradeNo' => 'VERIFIED-TRADE'], 'apply'), 'payment_trade_already_bound');
        $this->assertSame(-1, (int) $o->fresh()->status); Queue::assertNothingPushed();
    }

    public function test_callback_diagnostics_validates_signed_description_and_never_applies_payment(): void
    {
        $o = $this->order(['status' => 1, 'type' => 1, 'trade_no' => '']);
        $p = ['pid' => 'fixture-merchant', 'trade_no' => 'CALLBACK-TRADE', 'out_trade_no' => $o->order_sn, 'type' => 'alipay', 'name' => 'Descriptive product, not order SN', 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
        ksort($p); $parts = []; foreach ($p as $k => $v) if ($k !== 'sign_type' && $v !== '') $parts[] = "$k=$v";
        $p['sign'] = md5(implode('&', $parts).'fixture-signing-key');
        $r = $this->runTool('payments_callback_diagnostics', ['callback' => $p]); $this->assertSame('verified', $r['reasonCode']);
        $p['name'] = 'Tampered'; $r = $this->runTool('payments_callback_diagnostics', ['callback' => $p]); $this->assertSame('signature', $r['reasonCode']);
        $this->assertStringNotContainsString('Tampered', json_encode($r)); $this->assertSame(1, (int) $o->fresh()->status); Queue::assertNothingPushed();
    }

    public function test_refund_migration_is_additive_and_local_record_moves_nothing(): void
    {
        $o = $this->order(['status' => 4, 'info' => 'HISTORICAL']); $before = $o->getAttributes(); $this->refunds();
        $p = ['orderId' => $o->id, 'amount' => '3.25', 'currency' => 'CNY', 'provider' => 'dulupay', 'externalReference' => 'EXT-ONE', 'refundedAt' => '2026-01-01T10:00:00Z'];
        $preview = $this->runTool('refunds_record_external', $p, 'preview'); $this->assertFalse($preview['moneyMoved']);
        $this->assertSame(0, DB::table('shop_order_refunds')->count());
        $r = $this->runTool('refunds_record_external', $p, 'apply');
        $this->assertFalse($r['moneyMoved']); $this->assertFalse($r['subscriptionChanged']); $this->assertSame(1, $r['changed']);
        $this->assertSame($before, $o->fresh()->getAttributes()); Queue::assertNothingPushed(); Http::assertNothingSent();
        $again = $this->runTool('refunds_record_external', $p, 'apply'); $this->assertSame(1, $again['unchanged']); $this->assertSame($r['id'], $again['id']);
        $this->assertSame('3.25', $this->runTool('refunds_get', ['id' => $r['id']])['amount']);
        $this->assertSame(1, $this->runTool('refunds_list', ['orderId' => $o->id])['total']);
    }

    public function test_refund_ref_collision_cap_currency_and_payment_evidence_are_enforced(): void
    {
        $this->refunds(); $o = $this->order();
        $p = ['orderId' => $o->id, 'amount' => '6.00', 'currency' => 'CNY', 'provider' => 'manual', 'externalReference' => 'EXT-TWO', 'refundedAt' => '2026-01-01T10:00:00Z'];
        $this->runTool('refunds_record_external', $p, 'apply');
        $this->reject(fn () => $this->runTool('refunds_record_external', array_replace($p, ['amount' => '5.00']), 'apply'), 'refund_reference_conflict');
        $this->reject(fn () => $this->runTool('refunds_record_external', array_replace($p, ['externalReference' => 'ANOTHER']), 'apply'), 'refund_exceeds_order_amount');
        $unpaid = $this->order(['status' => 1, 'trade_no' => '']);
        $this->reject(fn () => $this->runTool('refunds_record_external', array_replace($p, ['orderId' => $unpaid->id, 'externalReference' => 'UNPAID']), 'preview'), 'refund_payment_not_recorded');
        $this->assertSame(1, DB::table('shop_order_refunds')->count());
    }

    public function test_notifications_have_durable_safe_status_and_failures_do_not_hide_committed_order(): void
    {
        $this->tool('orders_notification_status');
        $o = $this->order(['info' => 'PRIVATE-HISTORY']);
        DB::table('emailtpls')->insert(['tpl_name' => 'Completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        Event::listen(OrderUpdated::class, fn () => throw new \RuntimeException('PRIVATE-SMTP-URL-AND-SECRET'));
        $r = DB::transaction(fn () => $this->runTool('orders_complete', ['id' => $o->id, 'message' => 'PRIVATE-MESSAGE', 'notifyCustomer' => true], 'apply'));
        $this->assertSame(4, (int) $o->fresh()->status);
        $this->assertSame(1, $r['changed']);
        $status = $this->runTool('orders_notification_status', ['id' => $r['items'][0]['notificationAttemptId']]);
        $this->assertSame('indeterminate', $status['status']);
        $this->assertSame('notification_submission_indeterminate', $status['reason_code']);
        $this->assertStringNotContainsString('PRIVATE', json_encode([$r, $status]));
    }

    public function test_resend_guards_and_manual_template_selection(): void
    {
        foreach ([['status' => 2], ['status' => 5], ['info' => ''], ['deleted_at' => now()], ['email' => 'not-email'], ['trade_no' => '']] as $change) {
            $o = $this->order(array_replace(['status' => 4, 'type' => 1, 'info' => 'HISTORY'], $change));
            try { $this->runTool('orders_resend_delivery', ['id' => $o->id], 'preview'); $this->fail('Unsafe resend accepted'); }
            catch (OperationException) { $this->assertSame((int) $o->status, (int) $o->fresh()->status); }
        }
        Queue::assertNothingPushed();
        $o = $this->order(['status' => 4, 'info' => 'MANUAL-HISTORY']);
        $this->reject(fn () => $this->runTool('orders_resend_delivery', ['id' => $o->id], 'preview'), 'delivery_template_missing');
        DB::table('emailtpls')->insert(['tpl_name' => 'Manual completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        $this->runTool('orders_resend_delivery', ['id' => $o->id], 'apply');
        Queue::assertPushed(MailSend::class, fn ($job) => in_array('Manual completed', (array) $job, true));
    }

    public function test_provider_transport_failures_are_redacted_and_recovery_dispatch_failure_is_reported(): void
    {
        $o = $this->order(['status' => -1, 'type' => 1, 'trade_no' => '', 'info' => '']); $this->provider($o);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(['api.dulupay.com/api.php*' => Http::failedConnection('PRIVATE-PROVIDER-SECRET')]);
        $this->reject(fn () => $this->runTool('payments_query_status', ['orderId' => $o->id]), 'reconciliation_provider_unavailable');
        $this->provider($o);
        Event::listen(OrderUpdated::class, fn () => throw new \RuntimeException('PRIVATE-QUEUE-ERROR'));
        $r = $this->runTool('payments_recover_expired', ['orderId' => $o->id, 'tradeNo' => 'VERIFIED-TRADE'], 'apply');
        $this->assertSame(4, (int) $o->fresh()->status); $this->assertSame('submission_failed_after_commit', $r['notification']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
    }

    public function test_payment_and_refund_snapshots_bind_related_secret_and_ledger_state(): void
    {
        $t = $this->tool('payments_update'); $p = ['id' => 14, 'pay_name' => 'Changed']; $before = ($t->snapshot)($p, false);
        DB::table('pays')->where('id', 14)->update(['merchant_pem' => 'NEW-PRIVATE-SECRET']);
        $this->assertNotEquals($before, ($t->snapshot)($p, false));
        $this->refunds(); $o = $this->order();
        $p = ['orderId' => $o->id, 'amount' => '1.00', 'currency' => 'CNY', 'provider' => 'manual', 'externalReference' => 'SNAPSHOT-REFERENCE', 'refundedAt' => '2026-01-01T10:00:00Z'];
        $t = $this->tool('refunds_record_external'); $before = ($t->snapshot)($p, false);
        $this->runTool('refunds_record_external', $p, 'apply'); $this->assertNotEquals($before, ($t->snapshot)($p, false));
        DB::beginTransaction(); $this->runTool('refunds_record_external', array_replace($p, ['externalReference' => 'ROLLBACK']), 'apply'); DB::rollBack();
        $this->assertSame(1, DB::table('shop_order_refunds')->count());
        $this->assertSame(0, $this->runTool('refunds_list', ['search' => 'ROLLBACK'])['total']);
        $this->assertSame(1, $this->runTool('refunds_list', ['search' => 'SNAPSHOT-REFERENCE', 'provider' => 'manual'])['total']);
    }

    public function test_date_filter_until_without_from_and_sort_allowlist_work(): void
    {
        $old = $this->order(['created_at' => '2025-01-01 12:00:00', 'actual_price' => '1.00']);
        $this->order(['actual_price' => '2.00']);
        $this->assertSame(1, $this->runTool('orders_list', ['until' => '2025-01-02'])['total']);
        $this->assertSame($old->id, $this->runTool('orders_list', ['sort' => 'amount_asc'])['items'][0]['id']);
        $this->assertSame(0, $this->runTool('orders_list', ['search' => "' OR 1=1 --"])['total']);
    }

    public function test_gateway_configuration_rejects_markup_and_unallowlisted_credential_destinations(): void
    {
        foreach ([
            ['merchant_key' => "https://api.dulupay.com/'><script>alert(1)</script>"],
            ['merchant_id' => "id' onfocus='alert(1)"],
            ['merchant_key' => 'https://api.dulupay.com@evil.example.test/submit.php'],
            ['merchant_key' => 'https://api.dulupay.com/submit.php?key=PRIVATE'],
            ['merchant_key' => 'http://api.dulupay.com/submit.php'],
            ['merchant_key' => 'https://api.dulupay.com:444/submit.php'],
        ] as $input) {
            try { $this->runTool('payments_update', ['id' => 14, ...$input], 'preview'); $this->fail('Unsafe payment config accepted'); }
            catch (OperationException|\Illuminate\Validation\ValidationException $e) { $this->assertStringNotContainsString('PRIVATE', $e->getMessage()); }
        }
        $this->assertSame('fixture-merchant', Pay::find(14)->merchant_id);
        $this->assertSame('https://pay.example.test', Pay::find(14)->merchant_key);
        Http::assertNothingSent();
    }

    public function test_list_search_treats_wildcards_as_literal_text(): void
    {
        $o = $this->order(['title' => 'Synthetic 100% literal_under_score!']);
        $this->order(['title' => 'Synthetic other']);
        foreach (['100%', 'literal_under_score', '!'] as $search) {
            $r = $this->runTool('orders_list', ['search' => $search]);
            $this->assertSame(1, $r['total']); $this->assertSame($o->id, $r['items'][0]['id']);
        }
    }

    public function test_refund_boundaries_archived_history_and_reversible_additive_migration(): void
    {
        $this->refunds(); $o = $this->order(['status' => 4, 'deleted_at' => now(), 'info' => 'ARCHIVED-HISTORY']);
        $before = $o->getAttributes();
        $p = ['orderId' => $o->id, 'amount' => '1.00', 'currency' => 'CNY', 'provider' => 'manual', 'externalReference' => 'ARCHIVED-REF', 'refundedAt' => '2026-01-01T10:00:00Z'];
        foreach (['0.00', '-1.00', '00.50', '1.001', '1e1', '999999999.99'] as $amount) {
            try { $this->runTool('refunds_record_external', array_replace($p, ['amount' => $amount]), 'preview'); $this->fail('Invalid amount accepted'); }
            catch (OperationException|\Illuminate\Validation\ValidationException) { $this->assertSame(0, DB::table('shop_order_refunds')->count()); }
        }
        try { $this->runTool('refunds_record_external', array_replace($p, ['currency' => 'USD']), 'preview'); $this->fail('Currency mismatch accepted'); }
        catch (\Illuminate\Validation\ValidationException) { $this->assertSame(0, DB::table('shop_order_refunds')->count()); }
        $this->reject(fn () => $this->runTool('refunds_record_external', array_replace($p, ['refundedAt' => '2099-01-01T10:00:00Z']), 'preview'), 'refund_time_in_future');
        $r = $this->runTool('refunds_record_external', $p, 'apply'); $this->assertSame(1, $r['changed']);
        $this->assertSame($before, $o->fresh()->getAttributes());
        (require database_path('migrations/2026_10_13_000003_create_shop_order_refunds.php'))->down();
        $this->assertFalse(Schema::hasTable('shop_order_refunds')); $this->assertSame($before, $o->fresh()->getAttributes());
    }

    public function test_provider_cash_refund_is_explicitly_unsupported_not_false_success(): void
    {
        $cap = $this->runTool('refunds_capabilities');
        $this->assertFalse($cap['cashRefundSupported']); $this->assertTrue($cap['externalRecordingSupported']);
        $this->reject(fn () => $this->runTool('refunds_provider_refund', ['orderId' => 1, 'amount' => '1.00'], 'preview'), 'provider_refund_not_supported');
        $this->reject(fn () => $this->runTool('refunds_provider_refund', ['orderId' => 1, 'amount' => '1.00'], 'apply'), 'provider_refund_not_supported');
        Http::assertNothingSent();
    }
}
