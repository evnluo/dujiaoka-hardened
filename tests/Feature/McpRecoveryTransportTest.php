<?php

namespace Tests\Feature;

use App\Jobs\MailSend;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\McpTestCase;

class McpRecoveryTransportTest extends McpTestCase
{
    public function test_reviewed_manual_recovery_over_http_records_pending_payment_without_allocating_and_replays_once(): void
    {
        config(['logging.default' => 'null']);
        Queue::fake();
        DB::table('pays')->where('id', 14)->update(['merchant_key' => 'https://api.dulupay.com/submit.php']);
        DB::table('goods')->update(['type' => Order::MANUAL_PROCESSING]);
        DB::table('emailtpls')->insert([
            ['tpl_name' => 'Manual pending', 'tpl_token' => 'pending_order', 'tpl_content' => 'Payment received; awaiting manual fulfilment'],
            ['tpl_name' => 'Manual operator', 'tpl_token' => 'manual_send_manage_mail', 'tpl_content' => 'Manual order {order_id}'],
        ]);
        $id = DB::table('orders')->insertGetId(['order_sn' => 'MCP-MANUAL-RECOVERY', 'type' => Order::MANUAL_PROCESSING, 'goods_id' => 7, 'title' => 'Synthetic manual', 'email' => 'fixture@example.test', 'buy_ip' => '127.0.0.1', 'pay_id' => 14, 'actual_price' => '10.00', 'status' => -1, 'info' => '', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['api.dulupay.com/api.php*' => Http::response(['code' => 1, 'status' => 1, 'pid' => 'fixture-merchant', 'out_trade_no' => 'MCP-MANUAL-RECOVERY', 'trade_no' => 'MCP-MANUAL-TRADE', 'type' => 'alipay', 'money' => '10.00'])]);
        $stock = DB::table('carmis')->get()->toJson();
        $p = ['orderId' => $id, 'tradeNo' => 'MCP-MANUAL-TRADE', 'reason' => 'Synthetic manual recovery'];
        $preview = $this->callTool('payments_recover_expired', $p);
        $this->assertArrayHasKey('previewToken', $preview, json_encode($preview));
        $this->assertFalse($preview['plan']['mayAllocateInventory']);
        Http::assertNothingSent(); Queue::assertNothingPushed();
        $this->assertSame(-1, (int) Order::find($id)->status);

        $apply = [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-http-manual-recovery'];
        $result = $this->callTool('payments_recover_expired', $apply);
        $this->assertSame('succeeded', $result['status'], json_encode($result));
        $this->assertFalse($result['result']['deliveryComplete']);
        $this->assertTrue($result['result']['manualAttentionRequired']);
        $this->assertSame(Order::STATUS_PENDING, (int) Order::find($id)->status);
        $this->assertSame('MCP-MANUAL-TRADE', Order::find($id)->trade_no);
        $this->assertSame('', Order::find($id)->info);
        $this->assertTrue($this->callTool('payments_recover_expired', $apply)['replayed']);
        Http::assertSentCount(1); Queue::assertPushed(MailSend::class, 2);
        $this->assertSame($stock, DB::table('carmis')->get()->toJson());
        $this->assertSame(2, (int) DB::table('goods')->value('in_stock'));
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertStringNotContainsString('SYNTHETIC-CARD-501', json_encode(DB::table('shop_mcp_receipts')->get()));
    }

    public function test_reviewed_provider_verified_recovery_over_http_allocates_once_and_replays_without_provider_retry(): void
    {
        config(['logging.default' => 'null']);
        Queue::fake();
        DB::table('pays')->where('id', 14)->update(['merchant_key' => 'https://api.dulupay.com/submit.php']);
        $id = DB::table('orders')->insertGetId(['order_sn' => 'MCP-RECOVERY', 'goods_id' => 7, 'title' => 'Synthetic', 'email' => 'fixture@example.test', 'buy_ip' => '127.0.0.1', 'pay_id' => 14, 'actual_price' => '10.00', 'status' => -1, 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['api.dulupay.com/api.php*' => Http::response(['code' => 1, 'status' => 1, 'pid' => 'fixture-merchant', 'out_trade_no' => 'MCP-RECOVERY', 'trade_no' => 'MCP-TRADE', 'type' => 'alipay', 'money' => '10.00'])]);
        $p = ['orderId' => $id, 'tradeNo' => 'MCP-TRADE', 'reason' => 'Synthetic recovery'];
        $preview = $this->callTool('payments_recover_expired', $p);
        Http::assertNothingSent(); Queue::assertNothingPushed();
        $apply = [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-http-recovery'];
        $result = $this->callTool('payments_recover_expired', $apply);
        $this->assertSame('succeeded', $result['status'], json_encode($result));
        $this->assertTrue($result['result']['deliveryComplete']);
        $this->assertSame(4, (int) Order::find($id)->status);
        $this->assertSame('SYNTHETIC-CARD-501', Order::find($id)->info);
        $this->assertTrue($this->callTool('payments_recover_expired', $apply)['replayed']);
        Http::assertSentCount(1); Queue::assertPushed(MailSend::class, 1);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertStringNotContainsString('SYNTHETIC-CARD-501', json_encode(DB::table('shop_mcp_receipts')->get()));
    }
}
