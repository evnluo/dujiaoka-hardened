<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TurnstileOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'database']);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_settings', function ($table): void {
            $table->string('slug')->primary(); $table->text('value'); $table->timestamps();
        });
        app(ShopSettings::class)->setMany(['template' => 'hyper', 'is_open_img_code' => 0, 'is_open_geetest' => 0]);
        config(['turnstile.enabled' => true, 'turnstile.site_key' => '1x00000000000000000000AA', 'turnstile.secret_key' => '1x0000000000000000000000000000000AA', 'turnstile.hostname' => 'localhost']);
        Http::preventStrayRequests();
        Http::fake([]);
    }

    private function input(): array
    {
        return ['gid' => 7, 'email' => 'buyer@example.test', 'payway' => 14, 'by_amount' => 1];
    }

    private function assertNoEffects(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, (int) DB::table('carmis')->value('status'));
        $this->assertSame(3, (int) DB::table('goods')->value('in_stock'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_missing_token_is_rejected_before_order_side_effects(): void
    {
        $this->postJson('/create-order', $this->input())->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertNoEffects();
        Http::assertNothingSent();
    }

    public function test_siteverify_failure_rejects_order_without_side_effects(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => 'forged-token'])
            ->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertNoEffects();
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['response'] === 'forged-token'
            && $request['secret'] === config('turnstile.secret_key')
            && !isset($request['remoteip']));
    }

    public function test_verified_token_creates_one_order_without_a_second_captcha(): void
    {
        app(ShopSettings::class)->setMany(['is_open_img_code' => 1, 'is_open_geetest' => 1]);
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])]);
        $response = $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => 'valid-token']);
        $response->assertOk()->assertJsonStructure(['redirect']);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(url('/bill/'.Order::first()->order_sn), $response->json('redirect'));
        Http::assertSentCount(1);
    }

    public function test_replayed_token_is_reverified_and_cannot_create_a_second_order(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
            ->push(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])
            ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
        $input = $this->input() + ['cf-turnstile-response' => 'single-use-token'];
        $this->postJson('/create-order', $input)->assertOk();
        $this->postJson('/create-order', $input)->assertStatus(422);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('jobs')->count());
        Http::assertSentCount(2);
    }

    public function test_post_verification_validation_failure_returns_inline_json_without_order_writes(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])]);
        $this->postJson('/create-order', array_replace($this->input(), ['by_amount' => 99, 'cf-turnstile-response' => 'valid-token']))
            ->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertNoEffects();
    }

    public function test_direct_non_json_submission_still_cannot_bypass_turnstile(): void
    {
        $this->post('/create-order', $this->input())->assertOk()->assertSee(__('checkout.required'));
        $this->assertNoEffects();
        Http::assertNothingSent();
    }

    public function test_timeout_is_a_friendly_failure_and_does_not_log_provider_payload(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('SYNTHETIC-PRIVATE-PAYLOAD'));
        $response = $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => 'candidate-token']);
        $response->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PAYLOAD', $response->getContent());
        $this->assertNoEffects();
    }

    public function test_incomplete_server_configuration_never_falls_back_to_legacy(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])]);
        foreach (['site_key', 'secret_key', 'hostname'] as $key) {
            $original = config('turnstile.'.$key);
            config(['turnstile.'.$key => '']);
            $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => 'candidate-token'])->assertStatus(422);
            $this->assertNoEffects();
            config(['turnstile.'.$key => $original]);
        }
        Http::assertNothingSent();
    }

    public function test_test_credentials_are_refused_outside_testing_or_local_environments(): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])]);
        app()->instance('env', 'production');
        try {
            $denied = false;
            try {
                app(\App\Service\OrderService::class)->validatorCreateOrder(\Illuminate\Http\Request::create('/create-order', 'POST', $this->input() + ['cf-turnstile-response' => 'candidate-token']));
            } catch (\App\Exceptions\RuleValidationException) { $denied = true; }
            $this->assertTrue($denied);
            $this->assertNoEffects();
            Http::assertNothingSent();
        } finally { app()->instance('env', 'testing'); }
    }

    public static function invalidTokens(): array
    {
        return [[null], [''], [['nested']], [123], [true], [str_repeat('x', 2049)]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTokens')]
    public function test_invalid_token_shape_is_rejected_without_calling_provider(mixed $token): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'])]);
        $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => $token])->assertStatus(422);
        $this->assertNoEffects();
        Http::assertNothingSent();
    }

    public static function untrustedResponses(): array
    {
        return [
            'wrong host' => [['success' => true, 'hostname' => 'attacker.test', 'action' => 'create_order'], 200],
            'wrong action' => [['success' => true, 'hostname' => 'localhost', 'action' => 'login'], 200],
            'missing context' => [['success' => true], 200],
            'truthy success' => [['success' => 'true', 'hostname' => 'localhost', 'action' => 'create_order'], 200],
            'HTTP outage' => [['success' => true, 'hostname' => 'localhost', 'action' => 'create_order'], 503],
            'invalid JSON' => ['<html>unavailable</html>', 200],
            'scalar JSON' => ['true', 200],
            'null JSON' => ['null', 200],
            'expired' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 200],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedResponses')]
    public function test_untrusted_provider_response_fails_closed(array|string $payload, int $status): void
    {
        Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response($payload, $status)]);
        $this->postJson('/create-order', $this->input() + ['cf-turnstile-response' => 'candidate-token'])->assertStatus(422);
        $this->assertNoEffects();
    }
}
