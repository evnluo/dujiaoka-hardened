<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Jobs\MailSend;
use App\Models\Order;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class DuluPayRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.default' => 'null']);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'database', 'turnstile.enabled' => true]);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_settings', function ($table): void {
            $table->string('slug')->primary(); $table->text('value'); $table->timestamps();
        });
        app(ShopSettings::class)->setMany(['template' => 'hyper']);
        DB::table('pays')->where('id', 14)->update(['merchant_key' => 'https://api.dulupay.com/submit.php']);
        DB::table('orders')->insert(['order_sn' => 'RECOVERY001', 'goods_id' => 7, 'title' => 'Synthetic product', 'email' => 'buyer@example.test', 'buy_ip' => '127.0.0.1', 'pay_id' => 14, 'actual_price' => '10.00', 'status' => -1, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeProvider(Http::response($this->payment()));
        Queue::fake();
    }

    private function payment(): array
    {
        return ['code' => 1, 'status' => 1, 'pid' => 'fixture-merchant', 'out_trade_no' => 'RECOVERY001', 'trade_no' => 'RECOVERYTRADE001', 'type' => 'alipay', 'money' => '10.00'];
    }

    private function fakeProvider($response): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['api.dulupay.com/api.php*' => $response]);
    }

    private function callbackPayload(): array
    {
        $data = ['pid' => 'fixture-merchant', 'out_trade_no' => 'RECOVERY001', 'trade_no' => 'RECOVERYTRADE001', 'type' => 'alipay', 'money' => '10.00', 'name' => 'Synthetic product', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
        return $this->sign($data);
    }

    private function sign(array $data, string $key = 'fixture-signing-key'): array
    {
        ksort($data);
        $parts = [];
        foreach ($data as $name => $value) {
            if (!in_array($name, ['sign', 'sign_type'], true) && $value !== '') $parts[] = $name.'='.$value;
        }
        $data['sign'] = md5(implode('&', $parts).$key);
        return $data;
    }

    public function test_signed_product_name_route_delivers_once_without_turnstile(): void
    {
        DB::table('orders')->update(['status' => 1]);
        for ($i = 0; $i < 2; $i++) $this->get('/pay/yipay/notify_url?'.http_build_query($this->callbackPayload()))->assertOk()->assertContent('success');
        $this->assertSame(4, (int) Order::first()->status);
        $this->assertSame(2, (int) DB::table('carmis')->value('status'));
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        Queue::assertPushed(MailSend::class, 1);
        Http::assertNothingSent();
    }

    public function test_verified_expired_order_recovery_delivers_once(): void
    {
        $this->assertTrue(method_exists(app('Service\\OrderProcessService'), 'reconcileDuluPayOrder'), 'Provider-verified recovery entry point is missing.');
        $result = app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
        $this->assertSame(4, (int) $result->status);
        $this->assertSame('RECOVERYTRADE001', $result->trade_no);
        $this->assertSame('SYNTHETIC-CARD-501', $result->info);
        $this->assertSame(2, (int) DB::table('carmis')->value('status'));
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        Queue::assertPushed(MailSend::class, 1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.dulupay.com/api.php?') && $request['act'] === 'order' && $request['out_trade_no'] === 'RECOVERY001' && $request['pid'] === 'fixture-merchant' && $request['key'] === 'fixture-signing-key');
        app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
        Queue::assertPushed(MailSend::class, 1);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_trade_owned_by_another_archived_order_cannot_be_reused(): void
    {
        $other = Order::first()->replicate();
        $other->order_sn = 'OTHERORDER';
        $other->trade_no = 'RECOVERYTRADE001';
        $other->status = 4;
        $other->deleted_at = now();
        $other->saveQuietly();
        try {
            app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
            $this->fail('A provider trade already bound to an archived order was reused.');
        } catch (RuleValidationException) {
            $this->assertSame(-1, (int) Order::first()->status);
            $this->assertSame(1, (int) DB::table('carmis')->value('status'));
            Queue::assertNothingPushed();
        }
    }

    public function test_operator_command_requires_explicit_execution_and_reports_safe_receipt(): void
    {
        $this->artisan('payment:reconcile-dulupay', ['order' => 'RECOVERY001', '--trade' => 'RECOVERYTRADE001', '--actor' => 'test-operator'])
            ->assertExitCode(1);
        $this->assertSame(-1, (int) Order::first()->status);
        Http::assertNothingSent();
        $this->artisan('payment:reconcile-dulupay', ['order' => 'RECOVERY001', '--trade' => 'RECOVERYTRADE001', '--actor' => 'test-operator', '--execute' => true])
            ->expectsOutputToContain('"status":4')->assertExitCode(0);
        Queue::assertPushed(MailSend::class, 1);
    }

    public function test_invalid_callbacks_never_deliver(): void
    {
        DB::table('orders')->update(['status' => 1]);
        $base = $this->callbackPayload();
        $cases = [array_replace($base, ['name' => 'Changed without resigning']), $this->sign($base, 'wrong-key'), array_replace($base, ['sign' => '0e'.str_repeat('0', 30)])];
        foreach (['pid' => 'wrong', 'type' => 'wxpay', 'money' => '10.01', 'trade_status' => 'WAIT_BUYER_PAY', 'out_trade_no' => 'UNKNOWN', 'name' => '', 'trade_no' => '', 'sign_type' => 'SHA256'] as $field => $value) {
            $cases[] = $this->sign(array_replace($base, [$field => $value]));
        }
        foreach (['name', 'trade_no', 'out_trade_no', 'pid', 'money', 'type', 'trade_status', 'sign', 'sign_type'] as $field) {
            $missing = $base; unset($missing[$field]); $cases[] = $missing;
            $cases[] = array_replace($base, [$field => ['invalid']]);
        }
        foreach ([str_repeat('a', 151), "bad\0name", "bad\nname"] as $value) $cases[] = $this->sign(array_replace($base, ['name' => $value]));
        foreach ($cases as $data) $this->get('/pay/yipay/notify_url?'.http_build_query($data))->assertOk()->assertContent('fail');
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertSame(1, (int) DB::table('carmis')->value('status'));
        Queue::assertNothingPushed();
    }

    public function test_signed_callback_cannot_recover_expired_order(): void
    {
        $this->get('/pay/yipay/notify_url?'.http_build_query($this->callbackPayload()))->assertContent('fail');
        $this->assertSame(-1, (int) Order::first()->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_callback_rejection_logs_only_reason_and_hashed_order(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $data = array_replace($this->callbackPayload(), ['name' => 'Tampered']);
        $this->get('/pay/yipay/notify_url?'.http_build_query($data))->assertContent('fail');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('payment_callback_rejected', [
            'reason' => 'signature', 'order_hash' => hash('sha256', 'RECOVERY001'),
        ]);
    }

    public function test_provider_mismatches_and_failures_leave_expired_order_untouched(): void
    {
        $cases = [];
        foreach (['code' => 0, 'status' => 0, 'pid' => 'wrong', 'out_trade_no' => 'wrong', 'trade_no' => 'wrong', 'type' => 'wxpay', 'money' => '9.99'] as $field => $value) {
            $cases[] = array_replace($this->payment(), [$field => $value]);
            $cases[] = array_replace($this->payment(), [$field => true]);
            $cases[] = array_replace($this->payment(), [$field => ['invalid']]);
            $missing = $this->payment(); unset($missing[$field]); $cases[] = $missing;
        }
        foreach (['1e1', '10.001', 10.0, '-10.00'] as $money) $cases[] = array_replace($this->payment(), ['money' => $money]);
        foreach ($cases as $data) {
            $this->fakeProvider(Http::response($data));
            $this->assertRecoveryRejected();
        }
        foreach ([Http::response('invalid json'), Http::response($this->payment(), 500), Http::response('', 302, ['Location' => 'https://evil.example.test']), Http::failedConnection('fixture-signing-key must never escape')] as $response) {
            $this->fakeProvider($response);
            $this->assertRecoveryRejected();
        }
    }

    private function assertRecoveryRejected(): void
    {
        try {
            app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
            $this->fail('Unsafe recovery accepted.');
        } catch (RuleValidationException $error) {
            $this->assertStringNotContainsString('fixture-signing-key', $error->getMessage());
            $this->assertSame(1, (int) DB::table('carmis')->value('status'));
            $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
            $this->assertSame(0, DB::transactionLevel());
            Queue::assertNothingPushed();
        }
    }

    public function test_ineligible_local_states_are_not_recovered(): void
    {
        $original = (array) DB::table('orders')->first();
        foreach ([['status' => 1], ['status' => 5], ['type' => 2], ['buy_amount' => 0], ['coupon_id' => 999], ['coupon_ret_back' => 1], ['coupon_discount_price' => '1.00'], ['trade_no' => 'OTHER'], ['info' => 'Prior delivery'], ['deleted_at' => now()]] as $changes) {
            DB::table('orders')->update(array_replace($original, $changes));
            $this->assertRecoveryRejected();
            $this->assertEquals(array_replace($original, $changes), (array) DB::table('orders')->first());
        }
    }

    public function test_gateway_validation_blocks_credential_disclosure(): void
    {
        $original = (array) DB::table('pays')->where('id', 14)->first();
        foreach ([['merchant_key' => 'https://evil.example.test/submit.php'], ['merchant_key' => 'http://api.dulupay.com/submit.php'], ['is_open' => 0], ['pay_handleroute' => '/pay/other'], ['deleted_at' => now()], ['merchant_pem' => '']] as $changes) {
            DB::table('pays')->where('id', 14)->update(array_replace($original, $changes));
            $this->assertRecoveryRejected();
        }
        Http::assertNothingSent();
    }

    public function test_stock_shortage_records_payment_without_false_delivery_or_email(): void
    {
        DB::table('carmis')->delete();
        $order = app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
        $this->assertSame(6, (int) $order->status);
        $this->assertSame('RECOVERYTRADE001', $order->trade_no);
        Queue::assertNotPushed(MailSend::class);
        app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }
}
