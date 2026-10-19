<?php

namespace Tests\Feature;

use App\Support\ShopSettings;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StorefrontHomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['SERVER_PORT'] = 80;
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('admin_settings', function ($table): void {
            $table->string('slug')->primary();
            $table->text('value');
            $table->timestamps();
        });
        app(ShopSettings::class)->setMany(['template' => 'hyper', 'text_logo' => "Evan's Shop"]);
    }

    private function renderCatalog(?array $data = null): array
    {
        $html = view('hyper.static_pages.home', ['data' => $data ?? require base_path('tests/fixtures/storefront-catalog.php')])->render();
        return [$html, new XPath(HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS))];
    }

    public function test_real_images_are_unique_visible_by_default_and_prioritized(): void
    {
        [$html, $dom] = $this->renderCatalog();
        $images = $dom->query('//*[@id="shop-products"]//img');
        $this->assertCount(24, $images);
        foreach ($images as $i => $image) {
            $this->assertNotSame('', $image->getAttribute('src'));
            $this->assertNotSame('', $image->getAttribute('alt'));
            $this->assertSame($i < 2 ? 'eager' : 'lazy', $image->getAttribute('loading'));
            $this->assertSame($i === 0 ? 'high' : 'auto', $image->getAttribute('fetchpriority'));
            $this->assertSame('async', $image->getAttribute('decoding'));
            $this->assertSame('1', $image->getAttribute('width'));
            $this->assertSame('1', $image->getAttribute('height'));
            $this->assertStringNotContainsString('opacity-', $image->getAttribute('class') ?? '');
        }
        foreach (['data-src=', 'loading-spinner', 'IntersectionObserver', 'setInterval'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $html);
        }
        $this->assertCount(0, $dom->query('//*[@data-product-group and @hidden]'));
    }

    public function test_filter_controls_and_catalog_order_stock_and_links(): void
    {
        [, $dom] = $this->renderCatalog();
        $buttons = $dom->query('//*[@data-shop-categories]//button');
        $this->assertCount(4, $buttons);
        foreach ($buttons as $i => $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('shop-products', $button->getAttribute('aria-controls'));
            $this->assertSame($i === 0 ? 'true' : 'false', $button->getAttribute('aria-pressed'));
        }
        $cards = $dom->query('//*[@data-product-group]');
        $this->assertCount(24, $cards);
        foreach ($cards as $i => $card) {
            $this->assertSame((string) (intdiv($i, 8) + 1), $card->getAttribute('data-product-group'));
            if ($i < 23) {
                $this->assertStringEndsWith('/buy/' . ($i + 1), $card->getAttribute('href'));
            } else {
                $this->assertSame('sell_out_tip()', $card->getAttribute('onclick'));
                $this->assertStringContainsString(__('hyper.home_out_of_stock'), $card->textContent);
            }
        }
    }

    public function test_all_sort_is_global_and_equal_rank_preserves_group_order(): void
    {
        $data = require base_path('tests/fixtures/storefront-catalog.php');
        $data[1]['goods'][0]['ord'] = 1000;
        $data[2]['goods'][0]['ord'] = 1000;
        [, $dom] = $this->renderCatalog($data);
        $cards = $dom->query('//*[@data-product-group]');
        $this->assertStringEndsWith('/buy/9', $cards[0]->getAttribute('href'));
        $this->assertStringEndsWith('/buy/17', $cards[1]->getAttribute('href'));
        $this->assertStringEndsWith('/buy/1', $cards[2]->getAttribute('href'));
    }

    public function test_shared_footer_retires_only_known_boilerplate_and_preserves_custom_content(): void
    {
        $legacy = 'Need help? <a href="https://me.ohevan.com" style="text-decoration: underline;"> Contact me </a>';
        $custom = '<p>Custom legal notice <a href="/terms">Terms</a></p><script src="https://example.test/analytics.js"></script><style>.custom{color:red}</style>';
        app(ShopSettings::class)->setMany(['footer' => $legacy . $custom]);
        $html = view('hyper.layouts._footer')->render();
        foreach (['Powered by Dujiaoka', 'Modified by Evan', 'buy this template', 'Need help?', 'evannotfound.com'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }
        $this->assertStringContainsString($custom, $html);
        $this->assertStringContainsString('href="' . url('/order-search') . '"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertSame($legacy . $custom, dujiaoka_config_get('footer'));
        app(ShopSettings::class)->setMany(['footer' => '<p>Need help? Custom support and legal information.</p>']);
        $this->assertStringContainsString('<p>Need help? Custom support and legal information.</p>', view('hyper.layouts._footer')->render());
    }

    public function test_available_cards_have_scoped_motion_hook_but_sold_out_does_not(): void
    {
        [, $dom] = $this->renderCatalog();
        $this->assertCount(23, $dom->query('//*[@data-product-group and contains(@class,"shop-product-available")]'));
        $this->assertCount(0, $dom->query('//*[@onclick="sell_out_tip()" and contains(@class,"shop-product-available")]'));
    }

    public function test_empty_catalog_has_no_images_or_invalid_links(): void
    {
        [, $dom] = $this->renderCatalog([]);
        $this->assertCount(0, $dom->query('//*[@id="shop-products"]//img'));
        $this->assertCount(1, $dom->query('//*[@data-shop-categories]//button'));
    }
}
