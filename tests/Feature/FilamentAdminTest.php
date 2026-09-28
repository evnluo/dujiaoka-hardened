<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportCards;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Cards\CarmisResource;
use App\Filament\Resources\Cards\Pages\ManageCards;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Filament\Resources\Goods\Pages\CreateGoods;
use App\Filament\Resources\Goods\Pages\EditGoods;
use App\Filament\Resources\Goods\Pages\ListGoods;
use App\Filament\Resources\Groups\Pages\ManageGroups;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PayResource;
use App\Filament\Support\InventoryOperations;
use App\Filament\Support\OrderOperations;
use App\Filament\Support\OrderState;
use App\Models\AdminUser;
use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Support\ShopSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_users', function ($t): void {
            $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('admin_roles', function ($t): void { $t->id(); $t->string('slug'); });
        Schema::create('admin_role_users', function ($t): void { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_settings', function ($t): void { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
        $admin = AdminUser::create(['username' => 'test-admin', 'name' => 'Test Admin', 'password' => 'test-only-password']);
        DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
        DB::table('admin_role_users')->insert(['user_id' => $admin->id, 'role_id' => 1]);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $this->withoutVite();
    }

    public function test_admin_screens_keep_configuration_secrets_hidden_and_show_inventory_content(): void
    {
        app(ShopSettings::class)->setMany(['title' => 'Test shop', 'password' => 'SYNTHETIC-SMTP-SECRET', 'telegram_bot_token' => 'SYNTHETIC-BOT-SECRET']);
        foreach ([ListGoods::class, CreateGoods::class, ManageGroups::class, ManageCards::class, ImportCards::class, ListOrders::class, ManagePayments::class, ManageCoupons::class, ManageEmailTemplates::class, Settings::class] as $page) {
            $screen = Livewire::test($page)->assertOk()->assertDontSee('fixture-signing-key')
                ->assertDontSee('SYNTHETIC-SMTP-SECRET')->assertDontSee('SYNTHETIC-BOT-SECRET');
            if ($page === ManageCards::class) { $screen->assertSee('SYNTHETIC-CARD-501'); }
            else { $screen->assertDontSee('SYNTHETIC-CARD-501'); }
        }
        Livewire::test(EditGoods::class, ['record' => 7])->assertOk();
    }

    public function test_administrator_role_is_required_on_direct_routes(): void
    {
        DB::table('admin_role_users')->delete();
        $this->assertFalse(OrderResource::canViewAny());
        $this->assertFalse(Settings::canAccess());
        $this->get(OrderResource::getUrl())->assertForbidden();
    }

    public function test_inventory_import_skips_sold_and_archived_duplicates(): void
    {
        DB::table('carmis')->insert([
            ['goods_id' => 7, 'carmi' => 'SOLD', 'status' => 2, 'deleted_at' => null],
            ['goods_id' => 7, 'carmi' => 'ARCHIVED', 'status' => 1, 'deleted_at' => now()],
        ]);
        $result = InventoryOperations::import(7, "\xEF\xBB\xBFNEW\r\nSOLD\rARCHIVED\nNEW\n\n");
        $this->assertSame(['added' => 1, 'skipped' => 2], $result);
        $this->assertSame(1, Carmis::withTrashed()->where('carmi', 'NEW')->count());
        $this->assertSame(2, (int) Carmis::where('carmi', 'SOLD')->first()->status);
    }

    public function test_bulk_inventory_archive_rejects_entire_selection_if_any_card_is_sold(): void
    {
        $soldId = DB::table('carmis')->insertGetId(['goods_id' => 7, 'carmi' => 'SOLD', 'status' => 2]);
        try { InventoryOperations::archive([501, $soldId]); $this->fail('Sold card must block the operation.'); }
        catch (ValidationException) { $this->assertNotNull(Carmis::find(501)); $this->assertNotNull(Carmis::find($soldId)); }
    }

    public function test_admin_can_edit_sold_and_loop_cards_without_changing_delivery_history(): void
    {
        Event::fake();
        $order = $this->order(['status' => 4, 'info' => 'ORIGINAL-DELIVERED-CARD']);
        foreach ([[2, 0], [1, 1], [2, 1]] as [$status, $loop]) {
            $id = DB::table('carmis')->insertGetId(['goods_id' => 7, 'carmi' => "ORIGINAL-$status-$loop", 'status' => $status, 'is_loop' => $loop]);
            $card = InventoryOperations::update($id, ['carmi' => "EDITED-$status-$loop", 'status' => 1, 'is_loop' => 0, 'goods_id' => 999]);
            $this->assertTrue(CarmisResource::canEdit($card));
            $this->assertSame("EDITED-$status-$loop", $card->fresh()->carmi);
            $this->assertSame($status, (int) $card->fresh()->status);
            $this->assertSame($loop, (int) $card->fresh()->is_loop);
            $this->assertSame(7, (int) $card->fresh()->goods_id);
            $this->assertFalse(CarmisResource::canRestore($card));
        }
        $this->assertSame('ORIGINAL-DELIVERED-CARD', $order->fresh()->info);
        $this->assertSame(4, (int) $order->fresh()->status);
        Event::assertNotDispatched(\App\Events\OrderUpdated::class);
    }

    public function test_payments_keep_secrets_on_blank_edits_and_reject_unsupported_gateways(): void
    {
        $pay = PayResource::saveGateway(['pay_name' => 'Renamed', 'merchant_key' => '', 'merchant_pem' => '', 'is_open' => 1], Pay::find(14));
        $this->assertSame('fixture-signing-key', $pay->merchant_pem);
        $this->assertSame('https://pay.example.test', $pay->merchant_key);
        $this->assertFalse(PayResource::canEdit(Pay::find(15)));
        $this->expectException(ValidationException::class);
        PayResource::saveGateway(['is_open' => 1], Pay::find(15));
    }

    public function test_settings_do_not_hydrate_existing_secrets_and_blank_means_unchanged(): void
    {
        app(ShopSettings::class)->setMany(['title' => 'Test shop', 'password' => 'SYNTHETIC-SMTP-SECRET']);
        Livewire::test(Settings::class)->assertSet('data.password', null)->fillForm([
            'title' => 'Updated shop', 'template' => 'hyper', 'language' => 'zh_CN', 'driver' => 'smtp',
            'port' => 587, 'order_expire_time' => 5, 'password' => '',
        ])->call('save')->assertHasNoFormErrors()->assertNotified()->assertDontSee('SYNTHETIC-SMTP-SECRET');
        $this->assertSame('SYNTHETIC-SMTP-SECRET', app(ShopSettings::class)->get('password'));
        $this->assertSame('Updated shop', app(ShopSettings::class)->get('title'));
    }

    public function test_order_view_never_hydrates_card_content_or_lookup_password(): void
    {
        $order = $this->order(['info' => 'SYNTHETIC-DELIVERY-SECRET', 'search_pwd' => 'SYNTHETIC-LOOKUP-SECRET']);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertOk()
            ->assertDontSee('SYNTHETIC-DELIVERY-SECRET')->assertDontSee('SYNTHETIC-LOOKUP-SECRET');
        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canDelete($order));
        $this->assertFalse(OrderResource::canEdit($order));
    }

    public function test_order_secrets_are_excluded_after_livewire_hydration_and_inherited_calls(): void
    {
        $order = $this->order(['info' => 'SYNTHETIC-DELIVERY-SECRET', 'search_pwd' => 'SYNTHETIC-LOOKUP-SECRET']);
        foreach (['getRecord', 'getBaseRecord', 'getWidgetData'] as $method) {
            $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->call($method)->assertOk();
            $payload = json_encode([$page->effects, $page->snapshot], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SYNTHETIC-DELIVERY-SECRET', $payload, $method);
            $this->assertStringNotContainsString('SYNTHETIC-LOOKUP-SECRET', $payload, $method);
        }
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->call('refreshFormData', ['info', 'search_pwd'])->assertOk();
        $payload = json_encode([$page->effects, $page->snapshot], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SYNTHETIC-DELIVERY-SECRET', $payload);
        $this->assertStringNotContainsString('SYNTHETIC-LOOKUP-SECRET', $payload);
    }

    public function test_manual_fulfilment_cannot_mark_unpaid_or_automatic_orders_as_paid(): void
    {
        Event::fake();
        foreach ([[1, 2, ''], [1, 2, 'trade'], [-1, 2, 'trade'], [2, 1, 'trade'], [2, 2, ''], [3, 2, ''], [4, 2, 'trade'], [5, 2, 'trade']] as [$status, $type, $trade]) {
            $order = $this->order(['status' => $status, 'type' => $type, 'trade_no' => $trade]);
            try { OrderOperations::fulfil($order->id, 4, 'Delivered'); $this->fail('Unsafe transition allowed.'); }
            catch (ValidationException) { $this->assertSame($status, (int) $order->fresh()->status); }
        }
        $paid = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
        OrderOperations::fulfil($paid->id, 3);
        OrderOperations::fulfil($paid->id, 4, 'Delivery completed');
        $this->assertSame(4, (int) $paid->fresh()->status);
        $this->assertSame('verified-trade', $paid->fresh()->trade_no);
        $this->assertSame('无交易凭证', OrderState::payment(4, ''));
    }

    public function test_redis_cache_prefix_retains_the_legacy_key_separator(): void
    {
        $this->assertStringEndsWith(':', config('cache.prefix'));
        $this->assertStringNotContainsString('::', config('cache.prefix'));
    }

    public function test_zero_total_manual_orders_can_be_fulfilled_without_fabricated_payment_evidence(): void
    {
        Event::fake();
        $free = $this->order(['status' => 2, 'type' => 2, 'trade_no' => '', 'actual_price' => 0]);
        Livewire::test(ViewOrder::class, ['record' => $free->id])->assertActionVisible('processing')->assertActionVisible('complete');
        OrderOperations::fulfil($free->id, 3);
        OrderOperations::fulfil($free->id, 4, 'Free purchase delivered');
        $this->assertSame(4, (int) $free->fresh()->status);
        $this->assertSame('', $free->fresh()->trade_no);
        $this->assertSame('无需支付', OrderState::payment(4, '', '0.00'));
        foreach (['0.01', '0.001', '-1', '', null] as $amount) {
            $this->assertFalse(OrderState::isZeroTotal($amount));
        }
    }

    public function test_manual_completion_is_silent_by_default_and_when_notifications_are_off(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        DB::table('emailtpls')->insert(['tpl_name' => 'Completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        $events = [];
        Event::listen(\App\Events\OrderUpdated::class, function ($event) use (&$events): void { $events[] = $event; });
        $stock = DB::table('goods')->where('id', 7)->first();
        foreach ([2, 3] as $status) {
            $order = $this->order(['status' => $status, 'type' => 2, 'trade_no' => 'verified-trade', 'info' => 'Original details']);
            if ($status === 2) { OrderOperations::fulfil($order->id, 4, 'Bookkeeping result'); }
            else { OrderOperations::fulfil($order->id, 4, 'Bookkeeping result', false); }
            $this->assertSame(4, (int) $order->fresh()->status);
            $this->assertSame('verified-trade', $order->fresh()->trade_no);
            $this->assertEquals(10, $order->fresh()->actual_price);
            $this->assertSame("Original details\n\nBookkeeping result", $order->fresh()->info);
            try { OrderOperations::fulfil($order->id, 4, 'Retry', true); $this->fail('Completed order retried.'); }
            catch (ValidationException) { $this->assertSame("Original details\n\nBookkeeping result", $order->fresh()->info); }
        }
        $this->assertSame([], $events);
        $this->assertSame(0, DB::table('jobs')->count());
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertEquals($stock, DB::table('goods')->where('id', 7)->first());
        $this->assertSame(1, Carmis::where('status', 1)->count());
    }

    public function test_completion_ui_defaults_to_no_notification_and_honours_opt_in(): void
    {
        config(['queue.default' => 'database']);
        DB::table('emailtpls')->insert(['tpl_name' => 'Completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        foreach ([false, true] as $notify) {
            $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
            $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('complete')
                ->assertActionDataSet(['notify_customer' => false])
                ->assertMountedActionModalSee(['通知客户', '处理结果（客户可见）']);
            $page->fillForm(['message' => 'UI delivery result', 'notify_customer' => $notify])
                ->callMountedAction()->assertHasNoActionErrors()->assertNotified();
            $this->assertSame(4, (int) $order->fresh()->status);
            $this->assertSame($notify ? 1 : 0, DB::table('jobs')->count());
            $page->assertActionHidden('complete');
            $this->assertSame(4, (int) $page->instance()->getRecord()->status);
            $this->assertArrayNotHasKey('info', $page->instance()->getRecord()->getAttributes());
        }
        $another = $this->order(['status' => 3, 'type' => 2, 'trade_no' => '', 'actual_price' => 0]);
        Livewire::test(ViewOrder::class, ['record' => $another->id])->mountAction('complete')
            ->assertActionDataSet(['notify_customer' => false]);
    }

    public function test_processing_action_updates_displayed_state_without_hydrating_secrets(): void
    {
        $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade', 'info' => 'PRIVATE-DETAIL']);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('processing')
            ->assertHasNoActionErrors()->assertActionHidden('processing');
        $this->assertSame(3, (int) $page->instance()->getRecord()->status);
        $this->assertArrayNotHasKey('info', $page->instance()->getRecord()->getAttributes());
    }

    public function test_opted_in_completion_queues_one_status_mail_only_after_commit_and_not_on_retry(): void
    {
        config(['queue.default' => 'database']);
        DB::table('emailtpls')->insert(['tpl_name' => 'Completed', 'tpl_token' => 'completed_order', 'tpl_content' => '{ord_info}']);
        $events = 0;
        Event::listen(\App\Events\OrderUpdated::class, function ($event) use (&$events): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(4, (int) $event->order->fresh()->status);
            $events++;
        });
        $order = $this->order(['status' => 3, 'type' => 2, 'trade_no' => 'verified-trade', 'info' => 'Original']);
        DB::beginTransaction();
        OrderOperations::fulfil($order->id, 4, 'Delivered', true);
        $this->assertSame(0, $events);
        $this->assertSame(0, DB::table('jobs')->count());
        DB::commit();
        $this->assertSame(1, $events);
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(\App\Jobs\MailSend::class, $payload['displayName']);
        $this->assertStringContainsString('Delivered', $payload['data']['command']);
        try { OrderOperations::fulfil($order->id, 4, 'Retry', true); $this->fail('Completed order retried.'); }
        catch (ValidationException) {
            $this->assertSame(1, $events);
            $this->assertSame(1, DB::table('jobs')->count());
            $this->assertSame("Original\n\nDelivered", $order->fresh()->info);
        }
    }

    public function test_completion_rollback_does_not_queue_notification_or_change_order(): void
    {
        config(['queue.default' => 'database']);
        $events = 0;
        Event::listen(\App\Events\OrderUpdated::class, function () use (&$events): void { $events++; });
        $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade', 'info' => 'Original']);
        foreach ([false, true] as $notify) {
            DB::beginTransaction();
            OrderOperations::fulfil($order->id, 4, 'Rolled back', $notify);
            DB::rollBack();
            $this->assertSame(2, (int) $order->fresh()->status);
            $this->assertSame('Original', $order->fresh()->info);
            $this->assertSame(0, DB::table('jobs')->count());
        }
        DB::transaction(fn () => null);
        $this->assertSame(0, $events);
    }

    public function test_archived_manual_order_cannot_be_completed_even_with_notifications_off(): void
    {
        $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
        DB::table('orders')->where('id', $order->id)->update(['deleted_at' => now()]);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('complete');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        OrderOperations::fulfil($order->id, 4, 'Do not complete', false);
    }

    public function test_order_table_completion_is_silent_and_failure_keeps_existing_notification(): void
    {
        config(['queue.default' => 'database']);
        DB::table('emailtpls')->insert(['tpl_name' => 'Failure', 'tpl_token' => 'failed_order', 'tpl_content' => '{ord_info}']);
        $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
        Livewire::test(ListOrders::class)->callAction(TestAction::make('complete')->table($order), data: ['message' => 'Internal bookkeeping'])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(4, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $failed = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
        Livewire::test(ViewOrder::class, ['record' => $failed->id])->callAction('fail', data: ['message' => 'Unable to deliver'])
            ->assertHasNoActionErrors()->assertActionHidden('fail');
        $this->assertSame(5, (int) $failed->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_inventory_access_and_mutations_require_administrator_even_after_hydration(): void
    {
        $page = Livewire::test(ManageCards::class)->assertSee('SYNTHETIC-CARD-501');
        DB::table('admin_role_users')->delete();
        $this->get(CarmisResource::getUrl())->assertForbidden()->assertDontSee('SYNTHETIC-CARD-501');
        $page->call('$refresh')->assertForbidden();
        $this->assertFalse(CarmisResource::canEdit(Carmis::find(501)));
        try { InventoryOperations::update(501, ['carmi' => 'UNAUTHORIZED']); $this->fail('Unauthorized edit.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $order = $this->order(['status' => 2, 'type' => 2, 'trade_no' => 'verified-trade']);
        try { OrderOperations::fulfil($order->id, 4, 'UNAUTHORIZED'); $this->fail('Unauthorized completion.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        auth('admin')->logout();
        $this->get(CarmisResource::getUrl())->assertRedirect()->assertDontSee('SYNTHETIC-CARD-501');
        $this->get('/')->assertDontSee('SYNTHETIC-CARD-501');
        $this->assertSame('SYNTHETIC-CARD-501', Carmis::find(501)->carmi);
        $this->assertSame(2, (int) $order->fresh()->status);
    }

    public function test_sold_and_loop_edits_still_reject_duplicates_and_preserve_blank_values(): void
    {
        foreach ([[2, 0], [1, 1]] as [$status, $loop]) {
            DB::table('carmis')->where('id', 501)->update(['status' => $status, 'is_loop' => $loop]);
            $card = InventoryOperations::update(501, ['carmi' => '']);
            $this->assertSame('SYNTHETIC-CARD-501', $card->carmi);
            foreach ([null, now()] as $archived) {
                $id = DB::table('carmis')->insertGetId(['goods_id' => 7, 'status' => 2, 'carmi' => 'DUPLICATE', 'deleted_at' => $archived]);
                try { InventoryOperations::update(501, ['carmi' => 'DUPLICATE']); $this->fail('Duplicate allowed.'); }
                catch (ValidationException) { $this->assertSame('SYNTHETIC-CARD-501', $card->fresh()->carmi); }
                DB::table('carmis')->where('id', $id)->delete();
            }
        }
    }

    public function test_archived_cards_remain_viewable_but_only_safe_stock_can_be_restored(): void
    {
        foreach ([[1, 0], [2, 0], [1, 1], [2, 1]] as [$status, $loop]) {
            DB::table('carmis')->where('id', 501)->update(['status' => $status, 'is_loop' => $loop, 'deleted_at' => now()]);
            $card = Carmis::withTrashed()->find(501);
            $this->assertFalse(CarmisResource::canEdit($card));
            $this->assertSame($status === 1 && $loop === 0, CarmisResource::canRestore($card));
            Livewire::test(ManageCards::class)->filterTable('trashed', true)
                ->mountAction(TestAction::make('viewContent')->table($card))
                ->assertActionDataSet(['carmi' => 'SYNTHETIC-CARD-501']);
            try { InventoryOperations::update(501, ['carmi' => 'ARCHIVED-EDIT']); $this->fail('Archived card edited.'); }
            catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertSame('SYNTHETIC-CARD-501', $card->fresh()->carmi); }
        }
    }

    public function test_goods_can_be_created_through_the_form(): void
    {
        Livewire::test(CreateGoods::class)->fillForm([
            'gd_name' => 'New synthetic item', 'group_id' => 1, 'gd_description' => 'Test description', 'gd_keywords' => '',
            'actual_price' => 15, 'retail_price' => 20, 'buy_limit_num' => 0, 'ord' => 1, 'type' => 1, 'is_open' => false,
        ])->call('create')->assertHasNoFormErrors()->assertNotified();
        $this->assertDatabaseHas('goods', ['gd_name' => 'New synthetic item', 'actual_price' => 15]);
    }

    public function test_group_coupon_and_template_forms_create_and_update_real_records(): void
    {
        Livewire::test(ManageGroups::class)->callAction('create', data: ['gp_name' => 'New category', 'ord' => 3, 'is_open' => true])
            ->assertHasNoActionErrors()->assertNotified();
        $group = \App\Models\GoodsGroup::where('gp_name', 'New category')->firstOrFail();
        Livewire::test(ManageGroups::class)->callAction(TestAction::make('edit')->table($group), data: ['gp_name' => 'Updated category', 'ord' => 5, 'is_open' => false])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('Updated category', $group->fresh()->gp_name);
        Livewire::test(ManageCoupons::class)->callAction('create', data: ['coupon' => 'TEST-DISCOUNT', 'discount' => 2, 'goods' => [7], 'ret' => 3, 'is_open' => true])
            ->assertHasNoActionErrors()->assertNotified();
        $coupon = \App\Models\Coupon::where('coupon', 'TEST-DISCOUNT')->firstOrFail();
        $this->assertSame([7], $coupon->goods->modelKeys());
        $this->assertSame(3, (int) $coupon->ret);
        Livewire::test(ManageCoupons::class)->callAction(TestAction::make('edit')->table($coupon), data: ['discount' => 3, 'goods' => [7], 'is_open' => false])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(3, (int) $coupon->fresh()->ret);
        Livewire::test(ManageCoupons::class)->callAction(TestAction::make('addUses')->table($coupon), data: ['count' => 2])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(5, (int) $coupon->fresh()->ret);
        Livewire::test(ManageEmailTemplates::class)->callAction('create', data: ['tpl_name' => 'Test message', 'tpl_token' => 'test_message', 'tpl_content' => '<p>{ord_info}</p>'])
            ->assertHasNoActionErrors()->assertNotified();
        $template = \App\Models\Emailtpl::where('tpl_token', 'test_message')->firstOrFail();
        Livewire::test(ManageEmailTemplates::class)->callAction(TestAction::make('edit')->table($template), data: ['tpl_name' => 'Updated message', 'tpl_content' => '<p>Updated {ord_info}</p>'])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('Updated message', $template->fresh()->tpl_name);
    }

    public function test_payment_edit_modal_never_returns_current_secrets(): void
    {
        Livewire::test(ManagePayments::class)->mountAction(TestAction::make('edit')->table(Pay::find(14)))
            ->assertActionDataSet(['merchant_key' => null, 'merchant_pem' => null])
            ->assertDontSee('fixture-signing-key')->assertDontSee('https://pay.example.test');
        Livewire::test(ManagePayments::class)->callAction(TestAction::make('edit')->table(Pay::find(14)), data: [
            'pay_name' => 'Changed gateway', 'merchant_id' => 'fixture-merchant', 'pay_client' => 3,
            'is_open' => true, 'merchant_key' => '', 'merchant_pem' => '',
        ])->assertHasNoActionErrors()->assertNotified()->assertDontSee('fixture-signing-key');
        $this->assertSame('Changed gateway', Pay::find(14)->pay_name);
    }

    public function test_inventory_content_is_visible_copyable_and_editable_for_all_active_card_types(): void
    {
        foreach ([[1, 0], [2, 0], [1, 1], [2, 1]] as [$status, $loop]) {
            $content = "LINE-ONE-$status-$loop\nLINE-TWO\n".str_repeat('long-content ', 30).'<script>alert("card")</script>';
            DB::table('carmis')->where('id', 501)->update(['status' => $status, 'is_loop' => $loop, 'carmi' => $content]);
            $card = Carmis::find(501);
            $page = Livewire::test(ManageCards::class)->assertSee("LINE-ONE-$status-$loop")
                ->assertDontSee('<script>alert("card")</script>', escape: false)
                ->assertActionVisible(TestAction::make('edit')->table($card))
                ->mountAction(TestAction::make('viewContent')->table($card))
                ->assertActionDataSet(['carmi' => $content]);
            $column = $page->instance()->getTable()->getColumn('carmi')->record($card);
            $this->assertTrue($column->isCopyable($content));
            $this->assertSame($content, $column->getCopyableState($content));
            $this->assertLessThan(mb_strlen($content), mb_strlen($column->formatState($content)));
            Livewire::test(ManageCards::class)->mountAction(TestAction::make('edit')->table($card))
                ->assertActionDataSet(['carmi' => $content]);
            Livewire::test(ManageCards::class)->callAction(TestAction::make('edit')->table($card), data: ['carmi' => "EDITED-$status-$loop"])
                ->assertHasNoActionErrors()->assertNotified()->assertSee("EDITED-$status-$loop");
            $this->assertSame("EDITED-$status-$loop", $card->fresh()->carmi);
            $this->assertSame($status, (int) $card->fresh()->status);
            $this->assertSame($loop, (int) $card->fresh()->is_loop);
        }
    }

    public function test_goods_archiving_preserves_card_history_and_nonempty_group_is_blocked(): void
    {
        Livewire::test(ListGoods::class)->callAction(TestAction::make('archive')->table(\App\Models\Goods::find(7)))->assertNotified();
        $this->assertNotNull(Carmis::find(501));
        $this->assertNotNull(\App\Models\Goods::withTrashed()->find(7)->deleted_at);
        Livewire::test(ManageGroups::class)->callAction(TestAction::make('archive')->table(\App\Models\GoodsGroup::find(1)))
            ->assertNotified('分类仍有商品');
        $this->assertNotNull(\App\Models\GoodsGroup::find(1));
    }

    public function test_import_page_and_download_reauthentication(): void
    {
        Livewire::test(ImportCards::class)->fillForm(['goods_id' => 7, 'cards' => "PAGE-CARD-1\nPAGE-CARD-2"])
            ->call('import')->assertHasNoFormErrors()->assertNotified();
        $this->assertDatabaseHas('carmis', ['goods_id' => 7, 'carmi' => 'PAGE-CARD-1']);
        Livewire::test(ManageCards::class)->callAction(TestAction::make('download')->table(Carmis::find(501)), data: ['current_password' => 'incorrect'])
            ->assertHasActionErrors(['current_password']);
        Livewire::test(ManageCards::class)->callAction(TestAction::make('download')->table(Carmis::find(501)), data: ['current_password' => 'test-only-password'])
            ->assertHasNoActionErrors()->assertFileDownloaded('card-501.txt');
    }

    public function test_username_login_uses_admin_guard_and_preserves_custom_admin_path(): void
    {
        auth('admin')->logout();
        $this->get('/evansadmin/login')->assertOk();
        Livewire::test(\App\Filament\Pages\Auth\Login::class)->fillForm(['username' => 'test-admin', 'password' => 'incorrect'])
            ->call('authenticate')->assertHasFormErrors(['username']);
        Livewire::test(\App\Filament\Pages\Auth\Login::class)->fillForm(['username' => 'test-admin', 'password' => 'test-only-password'])
            ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
        $this->assertAuthenticated('admin');
    }

    public function test_manual_stock_is_replenished_atomically_and_not_overwritten_by_item_edits(): void
    {
        DB::table('goods')->where('id', 7)->update(['type' => 2, 'in_stock' => 3]);
        Livewire::test(EditGoods::class, ['record' => 7])->fillForm(['gd_name' => 'Edited manual item', 'gd_description' => 'Test', 'in_stock' => 500])
            ->call('save')->assertHasNoFormErrors()->assertNotified();
        $this->assertSame(3, (int) \App\Models\Goods::find(7)->in_stock);
        Livewire::test(ListGoods::class)->callAction(TestAction::make('addStock')->table(\App\Models\Goods::find(7)), data: ['quantity' => 4])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(7, (int) \App\Models\Goods::find(7)->in_stock);
    }

    private function order(array $values = []): Order
    {
        $id = DB::table('orders')->insertGetId(array_replace([
            'order_sn' => 'TEST-'.bin2hex(random_bytes(5)), 'goods_id' => 7, 'title' => 'Synthetic order',
            'email' => 'customer@example.test', 'buy_ip' => '127.0.0.1', 'actual_price' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ], $values));
        return Order::findOrFail($id);
    }
}
