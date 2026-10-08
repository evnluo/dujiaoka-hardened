<?php

namespace Tests\Feature;

use App\Http\Controllers\Home\HomeController;
use App\Models\Goods;
use App\Support\ShopSettings;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TurnstileViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['SERVER_PORT'] = 80;
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_settings', function ($table): void {
            $table->string('slug')->primary();
            $table->text('value');
            $table->timestamps();
        });
        app(ShopSettings::class)->setMany([
            'is_open_img_code' => 1, 'is_open_geetest' => 1, 'is_open_search_pwd' => 1,
        ]);
        config([
            'turnstile.enabled' => true,
            'turnstile.site_key' => '1x00000000000000000000AA',
            'turnstile.secret_key' => 'SERVER-ONLY-TURNSTILE-SECRET',
        ]);
    }

    public static function themes(): array
    {
        return [['hyper'], ['luna'], ['unicorn']];
    }

    #[DataProvider('themes')]
    public function test_visible_turnstile_exclusively_replaces_legacy_captchas(string $theme): void
    {
        $html = $this->renderBuy($theme);
        $dom = $this->dom($html);
        $this->assertSame(1, $dom->query('//form//*[@data-turnstile-checkout]')->length, $theme);
        $this->assertSame(1, $dom->query('//*[@data-sitekey="1x00000000000000000000AA"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-action="create_order"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-size="flexible"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-appearance="always"]')->length);
        $this->assertSame(1, $dom->query('//form//input[@name="cf-turnstile-response"]')->length);
        $this->assertSame(1, $dom->query('//form//button[@type="submit" and @disabled]')->length);
        $this->assertSame(1, $dom->query('//*[@data-turnstile-status and @role="status"]')->length);
        $this->assertSame(1, $dom->query('//*[@data-checkout-error and @role="alert"]')->length);
        $this->assertSame(1, $dom->query('//script[contains(@src,"checkout-turnstile.js")]')->length);
        $this->assertStringNotContainsString('SERVER-ONLY-TURNSTILE-SECRET', $html);
        $this->assertStringNotContainsString('&amp;nbsp;', $html, 'Escaped spacing entities must not overflow mobile labels.');
        foreach (['img_verify_code', 'geetest', 'captcha/buy', 'initGeetest', 'lay-submit', 'lay-filter="postOrder"'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $html, $theme . ': ' . $legacy);
        }
    }

    #[DataProvider('themes')]
    public function test_disabling_turnstile_preserves_explicit_legacy_settings(string $theme): void
    {
        config(['turnstile.enabled' => false]);
        $html = $this->renderBuy($theme);
        $this->assertStringNotContainsString('data-turnstile-checkout', $html);
        $this->assertStringNotContainsString('checkout-turnstile.js', $html);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $html);
        $this->assertStringContainsString('name="img_verify_code"', $html);
        if ($theme !== 'unicorn') {
            $this->assertStringContainsString('id="geetest-captcha"', $html);
            $this->assertStringContainsString('https://static.geetest.com/static/tools/gt.js', $html);
        }
        $this->assertSame(0, $this->dom($html)->query('//form//button[@type="submit" and @disabled]')->length);

        app(ShopSettings::class)->setMany(['is_open_img_code' => 0, 'is_open_geetest' => 0]);
        $html = $this->renderBuy($theme);
        foreach (['img_verify_code', 'static.geetest.com', 'id="geetest-captcha"', 'checkout-turnstile.js'] as $captcha) {
            $this->assertStringNotContainsString($captcha, $html, $theme);
        }
    }

    #[DataProvider('themes')]
    public function test_every_customer_field_stays_in_the_checkout_form(string $theme): void
    {
        DB::table('goods')->where('id', 7)->update([
            'type' => Goods::MANUAL_PROCESSING,
            'other_ipu_cnf' => "account_name=Account=true=Enter account\nregion=Region[East|West]=true=Select region",
        ]);
        DB::table('coupons')->insert(['id' => 1, 'coupon' => 'SYNTHETIC-COUPON']);
        DB::table('coupons_goods')->insert(['goods_id' => 7, 'coupons_id' => 1]);
        $dom = $this->dom($this->renderBuy($theme));
        foreach (['_token', 'gid', 'email', 'by_amount', 'search_pwd', 'coupon_code', 'account_name', 'region', 'payway'] as $name) {
            $this->assertGreaterThan(0, $dom->query('//form//*[@name="' . $name . '" and not(@disabled)]')->length, $theme . ': ' . $name);
        }
    }

    #[DataProvider('themes')]
    public function test_missing_site_key_does_not_enable_unprotected_submission(string $theme): void
    {
        config(['turnstile.site_key' => '']);
        $dom = $this->dom($this->renderBuy($theme));
        $this->assertSame(1, $dom->query('//form//*[@data-turnstile-checkout]')->length);
        $this->assertSame(1, $dom->query('//form//button[@type="submit" and @disabled]')->length);
        $this->assertSame(0, $dom->query('//form//input[@name="img_verify_code"]')->length);
    }

    private function renderBuy(string $theme): string
    {
        app(ShopSettings::class)->setMany(['template' => $theme]);
        return app(HomeController::class)->buy(7)->render();
    }

    private function dom(string $html): XPath
    {
        // Use browser HTML5 tree construction, not libxml's version-dependent HTML4 form repair.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        return new XPath($document);
    }
}
