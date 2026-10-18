<?php

namespace Tests\Feature;

use App\Mcp\Domains\CatalogDomain;
use App\Mcp\Domains\CouponsDomain;
use App\Mcp\Domains\InventoryDomain;
use App\Mcp\Operation;
use App\Mcp\OperationException;
use App\Models\AdminUser;
use App\Models\Carmis;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\GoodsGroup;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Business-seam tests. Transport authorization/receipts are exercised by the shared MCP suite. */
class McpCatalogDomainsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_users', function ($t): void { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps(); });
        Schema::create('admin_roles', function ($t): void { $t->id(); $t->string('slug'); });
        Schema::create('admin_role_users', function ($t): void { $t->integer('user_id'); $t->integer('role_id'); });
        $admin = AdminUser::create(['username' => 'domain-admin', 'name' => 'Synthetic admin', 'password' => 'synthetic-password']);
        DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
        DB::table('admin_role_users')->insert(['user_id' => $admin->id, 'role_id' => 1]);
        $this->actingAs($admin, 'admin');
        Storage::fake('admin', ['url' => 'https://shop.example.test/uploads']);
        Bus::fake();
    }

    private function operation(string $name): Operation
    {
        foreach ([new CatalogDomain(), new InventoryDomain(), new CouponsDomain()] as $domain) {
            foreach ($domain->tools() as $operation) {
                if ($operation->name === $name) { return $operation; }
            }
        }
        $this->fail('Missing semantic operation: '.$name);
    }

    private function invoke(string $name, array $payload = [], string $phase = 'apply'): array
    {
        $operation = $this->operation($name);
        // Shared adapter rejects unknown fields/types separately. This helper exercises domain rules/closures.
        $payload = Validator::make($payload, $operation->rules)->validate();
        if ($phase === 'read') { return ($operation->read)($payload); }
        if ($phase === 'snapshot') { return ($operation->snapshot)($payload, false); }
        if ($phase === 'preview') {
            ($operation->snapshot)($payload, false);
            return ($operation->preview)($payload);
        }
        return DB::transaction(function () use ($operation, $payload): array {
            ($operation->snapshot)($payload, true);
            return ($operation->apply)($payload);
        });
    }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Invalid request was accepted.'); }
        catch (OperationException|ValidationException $exception) { $this->assertNotSame('', $exception->getMessage()); }
    }

    private function product(array $overrides = []): array
    {
        return array_replace(['group_id' => 1, 'gd_name' => 'New product', 'gd_description' => 'Summary', 'type' => 1, 'actual_price' => '12.34'], $overrides);
    }

    private function card(string $content, int $status = 1, int $loop = 0, bool $archived = false): int
    {
        return DB::table('carmis')->insertGetId(['goods_id' => 7, 'carmi' => $content, 'status' => $status, 'is_loop' => $loop, 'deleted_at' => $archived ? now() : null]);
    }

    private function coupon(array $overrides = []): array
    {
        return array_replace(['coupon' => 'SYNTHETIC-CODE', 'discount' => '1.25', 'goods' => [7], 'ret' => 2, 'is_open' => true], $overrides);
    }

    public function test_tool_catalog_is_unique_semantic_and_content_reads_are_narrowly_scoped(): void
    {
        $operations = array_merge((new CatalogDomain())->tools(), (new InventoryDomain())->tools(), (new CouponsDomain())->tools());
        $names = array_column($operations, 'name');
        $this->assertCount(35, $operations);
        $this->assertCount(count(array_unique($names)), $names);
        preg_match_all('/`((?:catalog_|inventory_|coupons_)[a-z_]+)`/', file_get_contents(base_path('docs/shop-mcp-catalog-matrix.md')), $matches);
        $matrixNames = array_values(array_unique($matches[1]));
        sort($names); sort($matrixNames);
        $this->assertSame($names, $matrixNames, 'Every semantic tool must appear in the scoped parity matrix.');
        $this->assertSame('inventory:content', $this->operation('inventory_cards_content')->scope);
        $this->assertSame('inventory:content', $this->operation('inventory_cards_export')->scope);
        $this->assertSame('read', $this->operation('inventory_cards_list')->scope);
        foreach ($operations as $operation) {
            $this->assertStringContainsString('untrusted', strtolower($operation->description));
            if ($operation->read === null) {
                $this->assertNotNull($operation->snapshot, $operation->name);
                $this->assertNotNull($operation->preview, $operation->name);
                $this->assertNotNull($operation->apply, $operation->name);
            }
        }
    }

    public function test_product_create_preview_is_effect_free_and_all_writable_fields_persist_safely(): void
    {
        $payload = $this->product([
            'gd_keywords' => 'keyword', 'retail_price' => '20.00', 'in_stock' => 0,
            'buy_limit_num' => 3, 'buy_prompt_enabled' => false, 'is_open' => true, 'ord' => 8,
            'description' => '<p>Body <strong>bold</strong></p><script>evil()</script><img src="javascript:evil()">',
            'buy_prompt' => '<p>Retain disabled instructions</p>', 'wholesale_price_cnf' => '2=11.00',
            'other_ipu_cnf' => 'account=Account=true=Enter account', 'api_hook' => '',
        ]);
        $preview = $this->invoke('catalog_products_create', $payload, 'preview');
        $this->assertSame(1, Goods::count());
        $this->assertNotEmpty($preview);
        $result = $this->invoke('catalog_products_create', $payload);
        $goods = Goods::findOrFail($result['id']);
        $this->assertSame(0, (int) $goods->sales_volume);
        $this->assertSame('keyword', $goods->gd_keywords);
        $this->assertSame('2=11.00', $goods->wholesale_price_cnf);
        $this->assertSame('account=Account=true=Enter account', $goods->other_ipu_cnf);
        $this->assertSame(3, (int) $goods->buy_limit_num);
        $this->assertFalse($goods->buy_prompt_enabled);
        $this->assertStringContainsString('Retain disabled', $goods->buy_prompt);
        $this->assertStringNotContainsString('evil()', $goods->description);
        $this->assertStringContainsString('<strong>bold</strong>', $goods->description);
        Bus::assertNothingDispatched();
    }

    public function test_product_update_omission_preserves_stock_prompt_type_sales_and_hook(): void
    {
        DB::table('goods')->where('id', 7)->update(['buy_prompt' => '<p>Old prompt</p>', 'buy_prompt_enabled' => true, 'sales_volume' => 6, 'api_hook' => 'https://legacy.example.test/secret']);
        $this->invoke('catalog_products_update', ['id' => 7, 'gd_name' => 'Renamed', 'buy_prompt_enabled' => false]);
        $row = Goods::findOrFail(7);
        $this->assertSame('Renamed', $row->gd_name);
        $this->assertSame('<p>Old prompt</p>', $row->buy_prompt);
        $this->assertFalse($row->buy_prompt_enabled);
        $this->assertSame(3, (int) $row->getRawOriginal('in_stock'));
        $this->assertSame(6, (int) $row->sales_volume);
        $this->assertSame(1, (int) $row->type);
        $this->assertSame('https://legacy.example.test/secret', $row->api_hook);
        foreach (['in_stock', 'sales_volume', 'type'] as $field) {
            $this->assertArrayNotHasKey($field, $this->operation('catalog_products_update')->schema);
        }
        $result = $this->invoke('catalog_products_get', ['id' => 7], 'read');
        $this->assertStringNotContainsString('legacy.example', json_encode($result));
    }

    public function test_product_price_config_category_and_image_validation_are_bounded(): void
    {
        foreach ([['actual_price' => '1e3'], ['actual_price' => '-1'], ['actual_price' => '1.234'], ['actual_price' => '100000000.00'], ['wholesale_price_cnf' => '0=5'], ['wholesale_price_cnf' => '1=1e3'], ['wholesale_price_cnf' => '1=100000000'], ['other_ipu_cnf' => 'email=<script>=true'], ['group_id' => 999], ['picture' => '../../private.php'], ['picture' => 'https://169.254.169.254/image.png'], ['in_stock' => 1]] as $bad) {
            $this->rejected(fn () => $this->invoke('catalog_products_create', $this->product($bad), 'preview'));
        }
        GoodsGroup::withoutEvents(fn () => GoodsGroup::findOrFail(1)->delete());
        $this->rejected(fn () => $this->invoke('catalog_products_create', $this->product(), 'preview'));
    }

    public function test_manual_stock_is_additive_manual_only_and_capped_without_notices(): void
    {
        $id = $this->invoke('catalog_products_create', $this->product(['type' => 2, 'in_stock' => 9]))['id'];
        $this->invoke('catalog_products_add_stock', ['id' => $id, 'quantity' => 3], 'preview');
        $this->assertSame(9, (int) Goods::find($id)->in_stock);
        $result = $this->invoke('catalog_products_add_stock', ['id' => $id, 'quantity' => 3]);
        $this->assertSame(12, (int) Goods::find($id)->in_stock);
        $this->assertSame(9, $result['before']['in_stock']);
        $this->assertSame(12, $result['after']['in_stock']);
        $this->rejected(fn () => $this->invoke('catalog_products_add_stock', ['id' => 7, 'quantity' => 1], 'preview'));
        $this->rejected(fn () => $this->invoke('catalog_products_add_stock', ['id' => $id, 'quantity' => 1000001], 'preview'));
        DB::table('goods')->where('id', $id)->update(['in_stock' => 2147483647]);
        $this->rejected(fn () => $this->invoke('catalog_products_add_stock', ['id' => $id, 'quantity' => 1], 'preview'));
        Bus::assertNothingDispatched();
    }

    public function test_product_reads_support_search_filters_archives_totals_and_computed_stock(): void
    {
        $id = $this->invoke('catalog_products_create', $this->product(['type' => 2, 'in_stock' => 9, 'is_open' => false]))['id'];
        $page = $this->invoke('catalog_products_list', ['perPage' => 1, 'page' => 1], 'read');
        $this->assertSame(2, $page['total']); $this->assertTrue($page['hasMore']);
        $filtered = $this->invoke('catalog_products_list', ['search' => 'New', 'group_id' => 1, 'type' => 2, 'is_open' => false], 'read');
        $this->assertSame(1, $filtered['total']);
        $this->assertSame(9, $filtered['items'][0]['available_stock']);
        $auto = $this->invoke('catalog_products_get', ['id' => 7], 'read');
        $this->assertSame(1, $auto['available_stock']);
        $this->invoke('catalog_products_archive', ['id' => $id]);
        $archived = $this->invoke('catalog_products_list', ['archived' => 'archived'], 'read');
        $this->assertSame(1, $archived['total']); $this->assertSame($id, $archived['items'][0]['id']);
    }

    public function test_category_full_lifecycle_and_nonempty_archive_snapshot_include_archived_products(): void
    {
        $payload = ['gp_name' => 'Extra', 'ord' => 7, 'is_open' => false];
        $this->invoke('catalog_categories_create', $payload, 'preview');
        $this->assertSame(1, GoodsGroup::count());
        $id = $this->invoke('catalog_categories_create', $payload)['id'];
        $this->invoke('catalog_categories_update', ['id' => $id, 'gp_name' => 'Changed', 'ord' => 3]);
        $this->assertSame('Changed', $this->invoke('catalog_categories_get', ['id' => $id], 'read')['gp_name']);
        $this->assertSame(1, $this->invoke('catalog_categories_list', ['search' => 'Changed'], 'read')['total']);
        $empty = $this->invoke('catalog_categories_archive', ['id' => $id], 'snapshot');
        $productId = $this->invoke('catalog_products_create', $this->product(['group_id' => $id]))['id'];
        $this->invoke('catalog_products_archive', ['id' => $productId]);
        $full = $this->invoke('catalog_categories_archive', ['id' => $id], 'snapshot');
        $this->assertNotEquals($empty, $full);
        $this->rejected(fn () => $this->invoke('catalog_categories_archive', ['id' => $id], 'preview'));
        // UI cannot edit an archived product; restore, move, then archive the empty category.
        $this->invoke('catalog_products_restore', ['id' => $productId]);
        $this->invoke('catalog_products_update', ['id' => $productId, 'group_id' => 1]);
        $this->invoke('catalog_categories_archive', ['id' => $id]);
        $this->assertTrue(GoodsGroup::withTrashed()->find($id)->trashed());
        $this->invoke('catalog_categories_restore', ['id' => $id]);
        $this->assertNotNull(GoodsGroup::find($id));
    }

    public function test_product_archive_restore_preserve_all_inventory_and_delivery_history(): void
    {
        $this->card('SOLD-HISTORY', 2); $this->card('CYCLING', 1, 1);
        $before = DB::table('carmis')->orderBy('id')->get()->toArray();
        $this->invoke('catalog_products_archive', ['id' => 7]);
        $this->assertTrue(Goods::withTrashed()->find(7)->trashed());
        $this->assertEquals($before, DB::table('carmis')->orderBy('id')->get()->toArray());
        $this->invoke('catalog_products_restore', ['id' => 7]);
        $this->assertNotNull(Goods::find(7));
        Bus::assertNothingDispatched();
    }

    public function test_visibility_bulk_results_bind_ids_and_report_changed_unchanged_and_missing(): void
    {
        foreach ([['catalog_products_set_published', 7, 'published'], ['catalog_categories_set_published', 1, 'published']] as [$tool, $id, $field]) {
            $r = $this->invoke($tool, ['ids' => [999, $id], $field => false]);
            $this->assertSame([$id, 999], $r['ids']);
            $this->assertSame(1, $r['changed']); $this->assertSame(1, $r['skipped']);
            $this->assertSame('not_found', $r['outcomes'][1]['reason']);
            $r = $this->invoke($tool, ['ids' => [$id], $field => false]);
            $this->assertSame(1, $r['unchanged']);
            $this->rejected(fn () => $this->invoke($tool, ['ids' => [$id, $id], $field => true], 'preview'));
        }
    }

    public function test_catalog_bulk_visibility_updates_archived_flags_without_restoring_like_the_ui(): void
    {
        $couponId = $this->invoke('coupons_create', $this->coupon())['id'];
        $categoryId = $this->invoke('catalog_categories_create', ['gp_name' => 'Empty'])['id'];
        foreach ([['catalog_products', Goods::class, 7, 'published'], ['catalog_categories', GoodsGroup::class, $categoryId, 'published'], ['coupons', Coupon::class, $couponId, 'enabled']] as [$prefix, $model, $id, $field]) {
            $this->invoke($prefix.'_archive', ['id' => $id]);
            $result = $this->invoke($prefix.($prefix === 'coupons' ? '_set_enabled' : '_set_published'), ['ids' => [$id], $field => false]);
            $this->assertSame(1, $result['changed']);
            $record = $model::withTrashed()->find($id);
            $this->assertTrue($record->trashed());
            $this->assertSame(0, (int) $record->is_open);
        }
    }

    public function test_hooks_fail_closed_and_never_appear_in_public_results(): void
    {
        foreach (['http://hooks.example.test/cb', 'https://localhost/cb', 'https://127.0.0.1/cb', 'https://169.254.169.254/cb', 'https://u:p@hooks.example.test/cb', 'https://hooks.example.test.evil.invalid/cb', 'https://hooks.example.test:8443/cb', "https://hooks.example.test/cb\nhttps://hooks.example.test/other"] as $url) {
            $this->rejected(fn () => $this->invoke('catalog_products_update', ['id' => 7, 'api_hook' => $url], 'preview'));
        }
        config(['shop_mcp.allowed_hosts' => ['hooks.example.test']]);
        $payload = ['id' => 7, 'api_hook' => 'https://hooks.example.test/callback?key=SYNTHETIC-HOOK-SECRET'];
        $preview = $this->invoke('catalog_products_update', $payload, 'preview');
        $result = $this->invoke('catalog_products_update', $payload);
        $this->assertStringNotContainsString('SYNTHETIC-HOOK-SECRET', json_encode([$preview, $result]));
        $this->assertSame($payload['api_hook'], Goods::find(7)->api_hook);
        $this->invoke('catalog_products_update', ['id' => 7, 'api_hook' => '']);
        $this->assertSame('', Goods::find(7)->api_hook);
    }

    public function test_product_hook_dispatch_pins_public_https_and_disables_redirects_without_logging_secrets(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $options = []; $status = 200;
        \Illuminate\Support\Facades\Http::fake(function ($request, array $sentOptions) use (&$options, &$status) {
            $options = $sentOptions;
            return \Illuminate\Support\Facades\Http::response('', $status, ['Location' => 'http://169.254.169.254/']);
        });
        app()->instance(\App\Service\ProductHookDelivery::class, new \App\Service\ProductHookDelivery(fn () => ['93.184.216.34']));
        DB::table('goods')->where('id', 7)->update(['api_hook' => 'https://hooks.example.test/cb?key=SYNTHETIC-HOOK-SECRET']);
        $order = (new \App\Models\Order())->forceFill(['goods_id' => 7, 'order_sn' => 'SYNTHETIC-HOOK', 'email' => 'synthetic@example.test', 'info' => 'SYNTHETIC-DELIVERY', 'actual_price' => '10.00', 'title' => 'Synthetic']);
        (new \App\Jobs\ApiHook($order))->handle();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['hooks.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $status = 302;
        try { (new \App\Jobs\ApiHook($order))->handle(); $this->fail('Redirect accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('product_hook_delivery_failed', $e->getMessage()); }
    }

    public function test_product_hooks_reject_nonpublic_dns_addresses_and_non_https_urls_without_requests(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();
        foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.64.0.1', '198.18.0.1', '192.0.0.8', '203.0.113.1', '224.0.0.1', '::1', '::ffff:127.0.0.1'] as $address) {
            try { (new \App\Service\ProductHookDelivery(fn () => [$address]))->send('https://hooks.example.test/cb', []); $this->fail('Nonpublic address accepted'); }
            catch (\RuntimeException $e) { $this->assertSame('product_hook_destination_rejected', $e->getMessage()); }
        }
        foreach (['http://hooks.example.test/cb', 'file:///etc/passwd', 'https://user:secret@hooks.example.test/cb', 'https://hooks.example.test:8443/cb', 'https://127.0.0.1/cb'] as $url) {
            try { (new \App\Service\ProductHookDelivery(fn () => ['93.184.216.34']))->send($url, []); $this->fail('Unsafe URL accepted'); }
            catch (\RuntimeException $e) { $this->assertSame('product_hook_destination_rejected', $e->getMessage()); }
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_policy_changes_and_related_state_are_bound_in_product_and_coupon_snapshots(): void
    {
        $product = ['id' => 7, 'gd_name' => 'Change'];
        $snapshot = $this->invoke('catalog_products_update', $product, 'snapshot');
        config(['shop_mcp.allowed_hosts' => ['new-approved.example.test']]);
        $this->assertNotEquals($snapshot, $this->invoke('catalog_products_update', $product, 'snapshot'));
        $id = $this->invoke('coupons_create', $this->coupon())['id'];
        $coupon = ['id' => $id, 'discount' => '2.00'];
        $snapshot = $this->invoke('coupons_update', $coupon, 'snapshot');
        $other = $this->invoke('catalog_products_create', $this->product())['id'];
        DB::table('coupons_goods')->insert(['coupons_id' => $id, 'goods_id' => $other]);
        $this->assertNotEquals($snapshot, $this->invoke('coupons_update', $coupon, 'snapshot'));
        $snapshot = $this->invoke('inventory_cards_import', ['goods_id' => 7, 'cards' => 'NEW'], 'snapshot');
        $this->card('NEW', 2, 0, true);
        $this->assertNotEquals($snapshot, $this->invoke('inventory_cards_import', ['goods_id' => 7, 'cards' => 'NEW'], 'snapshot'));
    }

    public function test_forged_fields_never_reach_persistence_even_when_a_domain_closure_is_called_directly(): void
    {
        $op = $this->operation('catalog_products_update');
        DB::transaction(fn () => ($op->apply)(['id' => 7, 'gd_name' => 'Allowed', 'type' => 2, 'in_stock' => 999, 'sales_volume' => 999, 'deleted_at' => now()]));
        $row = Goods::find(7);
        $this->assertSame(1, (int) $row->type); $this->assertSame(3, (int) $row->in_stock); $this->assertSame(0, (int) $row->sales_volume);
        $this->assertSame('Allowed', $row->gd_name);
        $id = $this->invoke('coupons_create', $this->coupon())['id'];
        $op = $this->operation('coupons_update');
        DB::transaction(fn () => ($op->apply)(['id' => $id, 'coupon' => 'FORGED', 'ret' => 999, 'is_use' => 2]));
        $row = Coupon::find($id);
        $this->assertSame('SYNTHETIC-CODE', $row->coupon); $this->assertSame(2, (int) $row->ret); $this->assertSame(1, (int) $row->is_use);
    }

    private function media(string $format = 'png'): array
    {
        $image = imagecreatetruecolor(120, 40);
        ob_start();
        match ($format) { 'jpg' => imagejpeg($image), 'webp' => imagewebp($image), default => imagepng($image) };
        $bytes = ob_get_clean();
        return ['filename' => 'fixture.'.$format, 'mimeType' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$format], 'contentBase64' => base64_encode($bytes), 'purpose' => 'rich_content'];
    }

    public function test_media_decode_reencode_preview_retry_and_rich_editor_roundtrip(): void
    {
        foreach (['png', 'jpg', 'webp'] as $format) {
            $payload = $this->media($format);
            // Trailing executable data must never survive the decode/reencode boundary.
            $payload['contentBase64'] = base64_encode(base64_decode($payload['contentBase64']).'<?php SYNTHETIC_EVIL(); ?>');
            $count = count(Storage::disk('admin')->allFiles());
            $this->invoke('catalog_media_upload', $payload, 'preview');
            $this->assertCount($count, Storage::disk('admin')->allFiles());
            $r = $this->invoke('catalog_media_upload', $payload);
            $this->assertSame(120, $r['width']); $this->assertSame(40, $r['height']);
            $this->assertStringNotContainsString('SYNTHETIC_EVIL', Storage::disk('admin')->get($r['path']));
            $this->assertSame(1, $r['changed']);
            $retry = $this->invoke('catalog_media_upload', $payload);
            $this->assertSame($r['path'], $retry['path']);
            $this->assertSame(0, $retry['changed']); $this->assertSame(1, $retry['unchanged']);
            $html = '<p>Body</p><img data-id="'.$r['path'].'" src="'.$r['url'].'" alt="Wide" width="60" height="999" onerror="evil()">';
            $this->invoke('catalog_products_update', ['id' => 7, 'picture' => $r['path'], 'description' => $html]);
            $saved = Goods::find(7)->description;
            $this->assertStringContainsString('data-id="'.$r['path'].'"', $saved);
            $this->assertStringContainsString('width: 60px', $saved);
            $this->assertStringContainsString('alt="Wide"', $saved);
            $this->assertStringNotContainsString('height=', $saved);
            $this->assertStringNotContainsString('evil()', $saved);
            $this->invoke('catalog_products_update', ['id' => 7, 'description' => $saved]);
            $this->assertSame($saved, Goods::find(7)->description);
        }
    }

    public function test_media_rejects_invalid_base64_mime_extensions_svg_oversize_and_remote_sources(): void
    {
        $valid = $this->media();
        foreach ([['filename' => '../file.png'], ['filename' => 'shell.php.png'], ['filename' => 'shell.php'], ['mimeType' => 'image/jpeg'], ['mimeType' => 'image/svg+xml', 'filename' => 'x.svg', 'contentBase64' => base64_encode('<svg onload="evil()"/>')], ['contentBase64' => '!!!!'], ['contentBase64' => base64_encode('<?php evil();')], ['contentBase64' => 'data:image/png;base64,'.$valid['contentBase64']], ['contentBase64' => base64_encode(str_repeat('a', 4194305))]] as $bad) {
            $this->rejected(fn () => $this->invoke('catalog_media_upload', array_replace($valid, $bad), 'preview'));
        }
        $this->assertSame([], Storage::disk('admin')->allFiles());
        $this->assertArrayNotHasKey('url', $this->operation('catalog_media_upload')->schema);
        $this->rejected(fn () => $this->invoke('catalog_products_update', ['id' => 7, 'description' => '<img data-id="../../private.txt" src="/uploads/private.txt">'], 'preview'));
    }

    public function test_image_dimension_bombs_are_rejected_before_decoding(): void
    {
        $payload = $this->media();
        $bytes = base64_decode($payload['contentBase64']);
        // PNG IHDR dimensions; actual raster stays tiny. No giant image allocation in the test.
        $bytes = substr_replace($bytes, pack('NN', 8192, 8192), 16, 8);
        $payload['contentBase64'] = base64_encode($bytes);
        $this->rejected(fn () => $this->invoke('catalog_media_upload', $payload, 'preview'));
        $this->assertSame([], Storage::disk('admin')->allFiles());
    }

    public function test_image_decoder_checks_available_memory_before_allocating_pixels(): void
    {
        $payload = $this->media();
        $bytes = substr_replace(base64_decode($payload['contentBase64']), pack('NN', 4000, 4000), 16, 8);
        $payload['contentBase64'] = base64_encode($bytes);
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            $this->expectException(OperationException::class);
            $this->expectExceptionMessage('image_resource_budget_exceeded');
            $this->invoke('catalog_media_upload', $payload, 'preview');
        } finally { ini_set('memory_limit', $previous); }
    }

    public function test_inventory_reads_filter_and_content_is_only_in_explicit_read_tools(): void
    {
        $id = $this->card('SYNTHETIC-SOLD-SECRET', 2, 1, true);
        $list = $this->invoke('inventory_cards_list', ['archived' => 'all', 'perPage' => 1], 'read');
        $this->assertSame(2, $list['total']); $this->assertTrue($list['hasMore']);
        $this->assertStringNotContainsString('SECRET', json_encode($list));
        $this->assertArrayNotHasKey('carmi', $this->invoke('inventory_cards_get', ['id' => $id], 'read'));
        $filtered = $this->invoke('inventory_cards_list', ['goods_id' => 7, 'status' => 2, 'is_loop' => true, 'archived' => 'archived', 'search' => 'Synthetic product'], 'read');
        $this->assertSame(1, $filtered['total']);
        $this->assertSame($id, $filtered['items'][0]['id']);
        $this->assertSame('SYNTHETIC-SOLD-SECRET', $this->invoke('inventory_cards_content', ['id' => $id], 'read')['carmi']);
        $export = $this->invoke('inventory_cards_export', ['ids' => [$id, 501]], 'read');
        $this->assertSame([501, $id], array_column($export['items'], 'id'));
        $this->assertSame('SYNTHETIC-SOLD-SECRET', $export['items'][1]['carmi']);
        $this->rejected(fn () => $this->invoke('inventory_cards_export', ['ids' => [501, 501]], 'read'));
    }

    public function test_inventory_import_is_preview_safe_deduplicated_and_returns_no_card_contents(): void
    {
        $soldId = $this->card('SOLD', 2); $archivedId = $this->card('ARCHIVED', 1, 0, true);
        $payload = ['goods_id' => 7, 'cards' => "\xEF\xBB\xBFNEW-SECRET\r\nSOLD\rARCHIVED\nNEW-SECRET\n\n", 'is_loop' => true];
        $before = Carmis::withTrashed()->count();
        $preview = $this->invoke('inventory_cards_import', $payload, 'preview');
        $this->assertSame($before, Carmis::withTrashed()->count());
        $result = $this->invoke('inventory_cards_import', $payload);
        $this->assertSame(1, $result['added']); $this->assertSame(2, $result['skipped']);
        $this->assertSame([
            ['reason' => 'already_in_inventory', 'ids' => [$soldId]],
            ['reason' => 'already_in_inventory', 'ids' => [$archivedId]],
        ], $result['skips']);
        $this->assertCount(1, $result['ids']);
        $this->assertStringNotContainsString('NEW-SECRET', json_encode([$preview, $result]));
        $this->assertSame(1, (int) Carmis::find($result['ids'][0])->is_loop);
        $this->assertSame(0, $this->invoke('inventory_cards_import', $payload)['added']);
        $this->assertSame(2, (int) Carmis::where('carmi', 'SOLD')->first()->status);
        Bus::assertNothingDispatched();
    }

    public function test_inventory_import_enforces_existing_parser_and_product_restrictions(): void
    {
        foreach (["bad\0line", "\xFF", str_repeat("card\n", 10001), str_repeat('a', 60001), '', str_repeat('a', 5242881)] as $text) {
            $this->rejected(fn () => $this->invoke('inventory_cards_import', ['goods_id' => 7, 'cards' => $text], 'preview'));
        }
        DB::table('goods')->where('id', 7)->update(['type' => 2]);
        $this->rejected(fn () => $this->invoke('inventory_cards_import', ['goods_id' => 7, 'cards' => 'VALID'], 'preview'));
    }

    public function test_sold_and_loop_content_edits_preserve_history_and_blank_keeps_existing(): void
    {
        DB::table('orders')->insert(['order_sn' => 'SYNTHETIC-HISTORY', 'goods_id' => 7, 'title' => 'History', 'status' => 4, 'email' => 'synthetic@example.test', 'buy_ip' => '127.0.0.1', 'info' => 'ORIGINAL-DELIVERY']);
        foreach ([[2, 0], [1, 1], [2, 1]] as [$status, $loop]) {
            $id = $this->card('OLD-'.$status.'-'.$loop, $status, $loop);
            $payload = ['id' => $id, 'carmi' => 'REPLACEMENT-SECRET-'.$id];
            $before = $this->invoke('inventory_cards_update', $payload, 'snapshot');
            $preview = $this->invoke('inventory_cards_update', $payload, 'preview');
            $result = $this->invoke('inventory_cards_update', $payload);
            $this->assertStringNotContainsString('REPLACEMENT-SECRET', json_encode([$preview, $result]));
            $card = Carmis::find($id);
            $this->assertSame($payload['carmi'], $card->carmi);
            $this->assertSame($status, (int) $card->status); $this->assertSame($loop, (int) $card->is_loop);
            $this->assertNotEquals($before, $this->invoke('inventory_cards_update', $payload, 'snapshot'));
            $this->invoke('inventory_cards_update', ['id' => $id, 'carmi' => '']);
            $this->assertSame($payload['carmi'], $card->fresh()->carmi);
        }
        $this->assertSame('ORIGINAL-DELIVERY', DB::table('orders')->value('info'));
        Bus::assertNothingDispatched();
    }

    public function test_card_duplicate_replacement_and_related_state_snapshot_include_archived_content(): void
    {
        $payload = ['id' => 501, 'carmi' => 'DUPLICATE'];
        $before = $this->invoke('inventory_cards_update', $payload, 'snapshot');
        $this->card('DUPLICATE', 2, 0, true);
        $after = $this->invoke('inventory_cards_update', $payload, 'snapshot');
        $this->assertNotEquals($before, $after);
        $this->rejected(fn () => $this->invoke('inventory_cards_update', $payload, 'preview'));
        $this->rejected(fn () => $this->invoke('inventory_cards_update', $payload));
        $this->assertSame('SYNTHETIC-CARD-501', Carmis::find(501)->carmi);
    }

    public function test_inventory_status_and_cycling_bulk_are_independent_skip_archives_and_reject_duplicates(): void
    {
        $sold = $this->card('SOLD', 2, 1); $archived = $this->card('ARCHIVED', 1, 0, true);
        $ids = [999999, $sold, $archived, 501];
        $preview = $this->invoke('inventory_cards_set_status', ['ids' => $ids, 'status' => 1], 'preview');
        $this->assertSame(2, (int) Carmis::find($sold)->status);
        $r = $this->invoke('inventory_cards_set_status', ['ids' => $ids, 'status' => 1]);
        $this->assertSame(1, $r['changed']); $this->assertSame(1, $r['unchanged']); $this->assertSame(2, $r['skipped']);
        $this->assertSame(1, (int) Carmis::find($sold)->is_loop);
        $r = $this->invoke('inventory_cards_set_cycling', ['ids' => [$sold, 501], 'enabled' => false]);
        $this->assertSame(1, $r['changed']); $this->assertSame(1, $r['unchanged']);
        $this->assertSame(1, (int) Carmis::find($sold)->status);
        $this->assertStringNotContainsString('SYNTHETIC-CARD', json_encode([$preview, $r]));
        $this->rejected(fn () => $this->invoke('inventory_cards_set_status', ['ids' => [501, 501], 'status' => 2], 'preview'));
        $this->rejected(fn () => $this->invoke('inventory_cards_set_cycling', ['ids' => range(1, 1001), 'enabled' => true], 'preview'));
        Bus::assertNothingDispatched();
    }

    public function test_inventory_archive_restore_are_atomic_and_restricted_to_unused_noncycling(): void
    {
        $sold = $this->card('SOLD', 2);
        $this->rejected(fn () => $this->invoke('inventory_cards_archive', ['ids' => [501, $sold]]));
        $this->assertNotNull(Carmis::find(501));
        $this->invoke('inventory_cards_archive', ['ids' => [501]], 'preview');
        $this->assertNotNull(Carmis::find(501));
        $this->invoke('inventory_cards_archive', ['ids' => [501]]);
        $this->assertNull(Carmis::find(501));
        DB::table('carmis')->where('id', 501)->update(['is_loop' => 1]);
        $this->rejected(fn () => $this->invoke('inventory_cards_restore', ['ids' => [501]]));
        DB::table('carmis')->where('id', 501)->update(['is_loop' => 0]);
        $this->invoke('inventory_cards_restore', ['ids' => [501]]);
        $this->assertNotNull(Carmis::find(501));
        Bus::assertNothingDispatched();
    }

    public function test_domain_references_and_pagination_bounds_fail_closed_without_business_writes(): void
    {
        foreach (['catalog_products_get', 'catalog_categories_get', 'inventory_cards_get', 'inventory_cards_content', 'coupons_get'] as $name) {
            $this->rejected(fn () => $this->invoke($name, ['id' => 999999], 'read'));
        }
        foreach (['catalog_products_list', 'catalog_categories_list', 'inventory_cards_list', 'coupons_list'] as $name) {
            foreach ([['page' => 0], ['perPage' => 101], ['archived' => 'wrong']] as $payload) {
                $this->rejected(fn () => $this->invoke($name, $payload, 'read'));
            }
            $this->assertSame([], $this->invoke($name, ['page' => 999], 'read')['items']);
        }
        $this->assertSame(1, Goods::count()); $this->assertSame(1, Carmis::count()); $this->assertSame(0, Coupon::count());
    }

    public function test_inventory_archive_queries_parent_before_card_mutation(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $this->invoke('inventory_cards_archive', ['ids' => [501]]);
        $productRead = null; $cardMutation = null;
        foreach ($queries as $i => $query) {
            if ($productRead === null && str_contains($query, 'from "goods"')) { $productRead = $i; }
            if (str_starts_with($query, 'update "carmis"')) { $cardMutation = $i; break; }
        }
        $this->assertNotNull($productRead); $this->assertNotNull($cardMutation); $this->assertLessThan($cardMutation, $productRead);
        // SQLite confirms query ordering only; MariaDB race tests remain a separate required gate.
    }

    public function test_coupon_create_update_full_fields_usage_immutability_and_association_sync(): void
    {
        $payload = $this->coupon();
        $this->invoke('coupons_create', $payload, 'preview');
        $this->assertSame(0, Coupon::count()); $this->assertSame(0, DB::table('coupons_goods')->count());
        $id = $this->invoke('coupons_create', $payload)['id'];
        $other = $this->invoke('catalog_products_create', $this->product())['id'];
        DB::table('coupons')->where('id', $id)->update(['ret' => 1, 'is_use' => 2]);
        $this->invoke('coupons_update', ['id' => $id, 'discount' => '2.50', 'goods' => [$other], 'is_open' => false]);
        $coupon = Coupon::find($id);
        $this->assertSame('SYNTHETIC-CODE', $coupon->coupon);
        $this->assertSame(1, (int) $coupon->ret); $this->assertSame(2, (int) $coupon->is_use);
        $this->assertSame([$other], $coupon->goods()->pluck('goods.id')->all());
        $this->assertEquals('2.50', $coupon->discount); $this->assertSame(0, (int) $coupon->is_open);
        foreach (['coupon', 'ret', 'is_use'] as $field) { $this->assertArrayNotHasKey($field, $this->operation('coupons_update')->schema); }
    }

    public function test_coupon_validates_unique_codes_product_associations_discounts_and_caps(): void
    {
        foreach ([['coupon' => ''], ['discount' => '0.00'], ['discount' => '1.001'], ['goods' => []], ['goods' => [7, 7]], ['goods' => [999]], ['ret' => 0], ['ret' => 1000001]] as $bad) {
            $this->rejected(fn () => $this->invoke('coupons_create', $this->coupon($bad), 'preview'));
        }
        $id = $this->invoke('coupons_create', $this->coupon())['id'];
        $this->invoke('coupons_archive', ['id' => $id]);
        $this->rejected(fn () => $this->invoke('coupons_create', $this->coupon(), 'preview'));
        $this->assertSame(1, Coupon::withTrashed()->count());
        $this->invoke('catalog_products_archive', ['id' => 7]);
        $this->rejected(fn () => $this->invoke('coupons_create', $this->coupon(['coupon' => 'ANOTHER']), 'preview'));
    }

    public function test_coupon_add_uses_is_atomic_additive_and_snapshot_binds_counter_and_associations(): void
    {
        $id = $this->invoke('coupons_create', $this->coupon())['id'];
        $payload = ['id' => $id, 'count' => 3];
        $before = $this->invoke('coupons_add_uses', $payload, 'snapshot');
        DB::table('coupons')->where('id', $id)->decrement('ret', 1, ['is_use' => 2]);
        $this->assertNotEquals($before, $this->invoke('coupons_add_uses', $payload, 'snapshot'));
        $this->invoke('coupons_add_uses', $payload, 'preview');
        $this->assertSame(1, (int) Coupon::find($id)->ret);
        $r = $this->invoke('coupons_add_uses', $payload);
        $this->assertSame(4, (int) Coupon::find($id)->ret); $this->assertSame(2, (int) Coupon::find($id)->is_use);
        $this->assertSame(1, $r['before']['ret']); $this->assertSame(4, $r['after']['ret']);
        DB::table('coupons')->where('id', $id)->update(['ret' => 2147483647]);
        $this->rejected(fn () => $this->invoke('coupons_add_uses', $payload, 'preview'));
    }

    public function test_coupon_archive_restore_enable_reads_and_expiry_capability_preserve_usage(): void
    {
        $id = $this->invoke('coupons_create', $this->coupon())['id'];
        $this->invoke('coupons_set_enabled', ['ids' => [$id], 'enabled' => false]);
        $this->assertSame(1, $this->invoke('coupons_list', ['search' => 'SYNTHETIC', 'is_open' => false], 'read')['total']);
        $this->assertFalse($this->invoke('coupons_get', ['id' => $id], 'read')['expirySupported']);
        $this->invoke('coupons_archive', ['id' => $id]);
        $this->assertSame(1, $this->invoke('coupons_list', ['archived' => 'archived'], 'read')['total']);
        $this->invoke('coupons_restore', ['id' => $id]);
        $this->assertSame(2, (int) Coupon::find($id)->ret);
        $this->assertSame([7], Coupon::find($id)->goods()->pluck('goods.id')->all());
    }
}
