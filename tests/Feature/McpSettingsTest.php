<?php

namespace Tests\Feature;

use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;
use Tests\McpTestCase;

class McpSettingsTest extends McpTestCase
{
    public function test_smtp_transport_pins_validated_address_and_preserves_tls_identity(): void
    {
        $this->assertTrue(method_exists(\App\Service\AdminTestMail::class, 'transport'), 'SMTP must pin the validated address at connect time.');
        $transport = \App\Service\AdminTestMail::transport(['host' => 'smtp.example.test', 'address' => '8.8.8.8', 'port' => 587, 'encryption' => 'tls', 'username' => 'synthetic', 'password' => 'synthetic']);
        $this->assertSame('8.8.8.8', $transport->getStream()->getHost());
        $this->assertSame('smtp.example.test', $transport->getStream()->getStreamOptions()['ssl']['peer_name']);
        $this->assertTrue($transport->isTlsRequired());
    }

    public function test_bark_job_uses_pinned_tls_no_redirect_transport_with_synthetic_payload(): void
    {
        \Illuminate\Support\Facades\Http::fake(['bark.example.test/*' => \Illuminate\Support\Facades\Http::response(['code' => 200])]);
        app()->instance(\App\Service\ProductHookDelivery::class, new \App\Service\ProductHookDelivery(fn () => ['8.8.8.8']));
        app(ShopSettings::class)->setMany(['bark_server' => 'https://bark.example.test', 'bark_token' => 'SYNTHETIC-BARK']);
        $order = \App\Models\Order::forceCreate(['order_sn' => 'BARK-SYNTHETIC', 'goods_id' => 7, 'pay_id' => 14, 'title' => 'Synthetic', 'actual_price' => '10.00', 'email' => 'fixture@example.test', 'buy_ip' => '127.0.0.1']);
        (new \App\Jobs\BarkPush($order))->handle();
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->url() === 'https://bark.example.test/SYNTHETIC-BARK' && str_contains($r['body'], 'BARK-SYNTHETIC'));
    }

    public function test_template_create_update_and_list_preserve_token_and_do_not_send_mail(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $r = $this->applyTool('email_templates_create', ['tpl_token' => 'mcp_test', 'tpl_name' => 'Title', 'tpl_content' => '<p>{ord_info}</p>']);
        $this->assertSame('succeeded', $r['status']);
        $id = $r['result']['id'];
        $this->applyTool('email_templates_update', ['id' => $id, 'tpl_name' => 'Changed', 'tpl_content' => '<p>New {ord_info}</p>']);
        $this->assertSame('Changed', $this->callTool('email_templates_get', ['id' => $id])['tpl_name']);
        $this->assertSame(1, $this->callTool('email_templates_list', ['search' => 'mcp_test'])['total']);
        $this->assertSame('invalid_input', $this->callTool('email_templates_update', ['id' => $id, 'tpl_token' => 'new', 'reason' => 'Synthetic'])['error']['code']);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_test_email_is_explicit_and_duplicate_safe_with_no_sensitive_receipt(): void
    {
        app(ShopSettings::class)->setMany(['host' => 'smtp.example.test', 'from_address' => 'shop@example.test', 'password' => 'SMTP-SYNTHETIC']);
        config(['shop_mcp.allowed_hosts' => ['smtp.example.test']]);
        $sender = \Mockery::mock(\App\Service\AdminTestMail::class);
        $sender->shouldReceive('send')->once()->andReturn(['submission' => 'accepted_not_delivery_confirmed']);
        $this->app->instance(\App\Service\AdminTestMail::class, $sender);
        $p = ['to' => 'fixture@example.test', 'subject' => 'MAIL-SUBJECT', 'body' => 'MAIL-CONTENT', 'reason' => 'Synthetic'];
        $preview = $this->callTool('email_send_test', $p);
        $this->assertArrayHasKey('previewToken', $preview);
        $apply = [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-email-key'];
        $this->assertSame('succeeded', $this->callTool('email_send_test', $apply)['status']);
        $this->assertTrue($this->callTool('email_send_test', $apply)['replayed']);
        $persisted = json_encode(DB::table('shop_mcp_receipts')->get());
        foreach (['fixture@example.test', 'MAIL-SUBJECT', 'MAIL-CONTENT', 'SMTP-SYNTHETIC'] as $s) $this->assertStringNotContainsString($s, $persisted);
    }

    public function test_uncertain_external_send_is_never_retried_for_same_key(): void
    {
        app(ShopSettings::class)->setMany(['host' => 'smtp.example.test', 'from_address' => 'shop@example.test']);
        config(['shop_mcp.allowed_hosts' => ['smtp.example.test']]);
        $sender = \Mockery::mock(\App\Service\AdminTestMail::class);
        $sender->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP-SECRET-URL'));
        $this->app->instance(\App\Service\AdminTestMail::class, $sender);
        $p = ['to' => 'fixture@example.test', 'subject' => 'Title', 'body' => 'Body', 'reason' => 'Synthetic'];
        $preview = $this->callTool('email_send_test', $p);
        $apply = [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-email-uncertain'];
        $this->assertSame('indeterminate', $this->callTool('email_send_test', $apply)['status']);
        $this->assertSame('indeterminate', $this->callTool('email_send_test', $apply)['status']);
        $this->assertStringNotContainsString('SMTP-SECRET-URL', json_encode(DB::table('shop_mcp_audit')->get()));
    }

    protected function applyTool(string $tool, array $p): array
    {
        $p += ['reason' => 'Synthetic settings'];
        $preview = $this->callTool($tool, $p);
        $this->assertArrayHasKey('previewToken', $preview, json_encode($preview));
        return $this->callTool($tool, [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-'.bin2hex(random_bytes(10))]);
    }

    public function test_settings_secret_patch_preview_and_read_never_disclose_values(): void
    {
        app(ShopSettings::class)->setMany(['title' => 'Old', 'password' => 'OLD-SYNTHETIC', 'telegram_bot_token' => 'BOT-SYNTHETIC']);
        $p = ['values' => ['title' => 'New', 'password' => 'NEW-SYNTHETIC', 'footer' => '<p>Allowed</p><script>bad()</script>'], 'reason' => 'Synthetic'];
        $preview = $this->callTool('settings_update', $p);
        $this->assertArrayHasKey('previewToken', $preview, json_encode($preview));
        $this->assertSame('Old', app(ShopSettings::class)->get('title'));
        $this->assertStringNotContainsString('NEW-SYNTHETIC', json_encode($preview));
        $r = $this->callTool('settings_update', [...$p, 'dryRun' => false, 'previewToken' => $preview['previewToken'], 'idempotencyKey' => 'synthetic-settings-1']);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame('NEW-SYNTHETIC', app(ShopSettings::class)->get('password'));
        $this->assertSame('BOT-SYNTHETIC', app(ShopSettings::class)->get('telegram_bot_token'));
        $this->assertStringNotContainsString('script', app(ShopSettings::class)->get('footer'));
        $read = $this->callTool('settings_get');
        foreach (['OLD-SYNTHETIC', 'NEW-SYNTHETIC', 'BOT-SYNTHETIC'] as $secret) $this->assertStringNotContainsString($secret, json_encode([$r, $read, DB::table('shop_mcp_receipts')->get(), DB::table('shop_mcp_audit')->get()]));
        $this->applyTool('settings_update', ['values' => ['password' => '']]);
        $this->assertSame('NEW-SYNTHETIC', app(ShopSettings::class)->get('password'));
    }

    public function test_settings_reject_unknown_keys_ssrf_and_preserve_turnstile(): void
    {
        foreach ([['APP_KEY' => 'oops'], ['host' => '127.0.0.1'], ['bark_server' => 'https://169.254.169.254'], ['port' => 70000], ['driver' => 'sendmail']] as $values) {
            $r = $this->callTool('settings_update', ['values' => $values, 'reason' => 'Synthetic']);
            $this->assertArrayHasKey('error', $r);
        }
        config(['turnstile.enabled' => true, 'turnstile.secret_key' => 'NEVER-RETURN']);
        app(ShopSettings::class)->setMany(['is_open_geetest' => 1]);
        $this->applyTool('settings_update', ['values' => ['is_open_geetest' => false, 'title' => 'Changed']]);
        $this->assertSame(1, app(ShopSettings::class)->get('is_open_geetest'));
        $this->assertStringNotContainsString('NEVER-RETURN', json_encode($this->callTool('settings_turnstile_status')));
        $this->assertSame('environment_managed', $this->callTool('settings_turnstile_update', ['enabled' => false, 'reason' => 'Synthetic'])['error']['code']);
    }
}
