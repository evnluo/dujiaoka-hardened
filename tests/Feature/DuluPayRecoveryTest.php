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

    public function test_verified_expired_manual_order_recovery_records_payment_pending_once_without_allocating_cards(): void
    {
        DB::table('orders')->update(['type' => Order::MANUAL_PROCESSING, 'info' => '']);
        DB::table('goods')->update(['type' => Order::MANUAL_PROCESSING]);
        DB::table('emailtpls')->insert([
            ['tpl_name' => 'Manual pending', 'tpl_token' => 'pending_order', 'tpl_content' => 'Payment received; awaiting manual fulfilment'],
            ['tpl_name' => 'Manual operator', 'tpl_token' => 'manual_send_manage_mail', 'tpl_content' => 'Manual order {order_id}'],
        ]);
        app(ShopSettings::class)->setMany(['manage_email' => 'operator@example.test']);
        $stock = DB::table('carmis')->get()->toJson();
        $events = 0;
        \Illuminate\Support\Facades\Event::listen(\App\Events\OrderUpdated::class, function () use (&$events): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(Order::STATUS_PENDING, (int) Order::first()->status);
            $events++;
        });

        for ($i = 0; $i < 2; $i++) {
            $result = app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
            $this->assertSame(Order::STATUS_PENDING, (int) $result->status);
            $this->assertSame('RECOVERYTRADE001', $result->trade_no);
            $this->assertSame('', $result->info);
            $this->assertSame($stock, DB::table('carmis')->get()->toJson());
            $this->assertSame(2, (int) DB::table('goods')->value('in_stock'));
            $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertSame(1, $events);
        Queue::assertPushed(MailSend::class, 2);
        Queue::assertPushed(MailSend::class, fn ($job) => in_array('Manual pending', (array) $job, true) && in_array('buyer@example.test', (array) $job, true));
        Queue::assertPushed(MailSend::class, fn ($job) => in_array('Manual operator', (array) $job, true) && in_array('operator@example.test', (array) $job, true));
        Queue::assertPushed(\App\Jobs\ApiHook::class, 1);
        Http::assertSentCount(2);
    }

    public static function deliveryTypes(): array
    {
        return ['automatic' => [Order::AUTOMATIC_DELIVERY], 'manual' => [Order::MANUAL_PROCESSING]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryTypes')]
    public function test_trade_owned_by_another_archived_order_cannot_be_reused(int $type): void
    {
        DB::table('orders')->update(['type' => $type]);
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

    public function test_operator_command_reports_manual_recovery_as_paid_awaiting_fulfilment(): void
    {
        DB::table('orders')->update(['type' => Order::MANUAL_PROCESSING, 'info' => '']);
        DB::table('emailtpls')->insert([
            ['tpl_name' => 'Manual pending', 'tpl_token' => 'pending_order', 'tpl_content' => 'Pending'],
            ['tpl_name' => 'Manual operator', 'tpl_token' => 'manual_send_manage_mail', 'tpl_content' => 'Manual order'],
        ]);
        $this->artisan('payment:reconcile-dulupay', ['order' => 'RECOVERY001', '--trade' => 'RECOVERYTRADE001', '--actor' => 'test-operator'])
            ->assertExitCode(1);
        Http::assertNothingSent(); Queue::assertNothingPushed();
        $this->assertSame(-1, (int) Order::first()->status);
        $this->artisan('payment:reconcile-dulupay', ['order' => 'RECOVERY001', '--trade' => 'RECOVERYTRADE001', '--actor' => 'test-operator', '--execute' => true])
            ->expectsOutputToContain('"status":2,"trade_recorded":true,"delivery_complete":false,"manual_attention_required":true,"previously_paid":false')
            ->assertExitCode(0);
        $this->assertSame(2, (int) Order::first()->status);
        Queue::assertPushed(MailSend::class, 2);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryTypes')]
    public function test_signed_callback_cannot_recover_expired_order(int $type): void
    {
        DB::table('orders')->update(['type' => $type]);
        $this->get('/pay/yipay/notify_url?'.http_build_query($this->callbackPayload()))->assertContent('fail');
        $this->assertSame(-1, (int) Order::first()->status);
        $this->assertSame('', Order::first()->trade_no);
        $this->assertSame(1, (int) DB::table('carmis')->value('status'));
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

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryTypes')]
    public function test_provider_mismatches_and_failures_leave_expired_order_untouched(int $type): void
    {
        DB::table('orders')->update(['type' => $type]);
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
        $before = (array) DB::table('orders')->first();
        try {
            app('Service\\OrderProcessService')->reconcileDuluPayOrder('RECOVERY001', 'RECOVERYTRADE001', 'test-operator');
            $this->fail('Unsafe recovery accepted.');
        } catch (RuleValidationException $error) {
            $this->assertStringNotContainsString('fixture-signing-key', $error->getMessage());
            $this->assertEquals($before, (array) DB::table('orders')->first());
            $this->assertSame(1, (int) DB::table('carmis')->value('status'));
            $this->assertSame(3, (int) DB::table('goods')->value('in_stock'));
            $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
            $this->assertSame(0, DB::transactionLevel());
            Queue::assertNothingPushed();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryTypes')]
    public function test_ineligible_local_states_are_not_recovered(int $type): void
    {
        DB::table('orders')->update(['type' => $type]);
        $original = (array) DB::table('orders')->first();
        foreach ([['status' => 0], ['status' => 1], ['status' => 2], ['status' => 3], ['status' => 4], ['status' => 5], ['status' => 6], ['type' => 0], ['type' => 3], ['buy_amount' => 0], ['buy_amount' => -1], ['coupon_id' => 999], ['coupon_ret_back' => 1], ['coupon_discount_price' => '1.00'], ['trade_no' => 'OTHER'], ['info' => 'Prior delivery'], ['info' => ' '], ['deleted_at' => now()]] as $changes) {
            DB::table('orders')->update(array_replace($original, $changes));
            $this->assertRecoveryRejected();
            $this->assertEquals(array_replace($original, $changes), (array) DB::table('orders')->first());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deliveryTypes')]
    public function test_gateway_validation_blocks_credential_disclosure(int $type): void
    {
        DB::table('orders')->update(['type' => $type]);
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
