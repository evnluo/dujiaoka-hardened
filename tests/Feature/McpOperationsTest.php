<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\McpTestCase;

class McpOperationsTest extends McpTestCase
{
    public function test_sensitive_reads_audit_target_ids_but_never_returned_contents(): void
    {
        $this->callTool('inventory_cards_content', ['id' => 501]);
        $row = DB::table('shop_mcp_audit')->where('operation', 'inventory_cards_content')->first();
        $this->assertNotNull($row);
        $this->assertSame('{"id":501}', $row->targets ?? null);
        $this->assertStringNotContainsString('SYNTHETIC-CARD', json_encode($row));
    }

    public function test_sales_are_exact_decimal_and_status_and_refund_aware(): void
    {
        foreach ([[4, '0.10', 'paid-a'], [4, '0.20', 'paid-b'], [1, '999.00', '']] as $i => [$status, $amount, $trade]) {
            DB::table('orders')->insert(['order_sn' => 'SYNTH-'.$i, 'goods_id' => 7, 'title' => 'Synthetic', 'email' => 'fixture@example.test', 'buy_ip' => '127.0.0.1', 'actual_price' => $amount, 'trade_no' => $trade, 'status' => $status, 'created_at' => now()]);
        }
        $r = $this->callTool('operations_sales_summary', ['from' => today()->toDateString(), 'until' => today()->toDateString()]);
        $this->assertSame('0.30', $r['completedRevenue']);
        $this->assertSame(3, $r['orders']);
        $this->assertSame(2, $r['completedOrders']);
        $this->assertSame('0.00', $r['externalRefundsRecorded']);
        $stock = $this->callTool('operations_low_stock', ['threshold' => 2]);
        $this->assertSame(1, $stock['total']); $this->assertSame(1, $stock['items'][0]['available_stock']);
    }

    public function test_failed_jobs_are_sanitized_and_retry_does_not_deserialize_or_send(): void
    {
        Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid'); $t->string('connection'); $t->string('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at'); });
        DB::table('failed_jobs')->insert(['uuid' => 'synthetic-failed', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\MailSend', 'data' => ['command' => 'SERIALIZED-SECRET']]), 'exception' => 'SMTP SECRET recipient@example.test', 'failed_at' => now()]);
        $r = $this->callTool('operations_failed_jobs');
        $this->assertSame(1, $r['total']);
        $this->assertSame('App\\Jobs\\MailSend', $r['items'][0]['jobType']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        $this->assertSame('unsafe_legacy_job_retry', $this->callTool('operations_retry_job', ['id' => 1, 'reason' => 'Synthetic'])['error']['code']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, $this->callTool('operations_queue_status')['failedJobs']);
    }

    public function test_history_pagination_and_receipt_reads_enforce_original_scope(): void
    {
        $p = ['gp_name' => 'History', 'reason' => 'Do not retain raw reason'];
        $preview = $this->callTool('catalog_categories_create', $p);
        $r = $this->callTool('catalog_categories_create', [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-history-key']);
        $id = $r['operationId'];
        $this->assertSame('succeeded', $this->callTool('operations_receipt', ['id' => $id])['status']);
        $this->assertSame('forbidden_scope', $this->callTool('operations_receipt', ['id' => $id], ['operations'])['error']['code']);
        $history = $this->callTool('operations_audit', ['perPage' => 1]);
        $this->assertTrue($history['hasMore']);
        $this->assertStringNotContainsString('Do not retain', json_encode($history));
    }
}
