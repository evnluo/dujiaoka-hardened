<?php

namespace Tests\Feature;

use App\Events\OrderUpdated;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Support\OrderOperations;
use App\Models\AdminUser;
use App\Models\Order;
use App\Service\OrderService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAdminExperienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('sqlite');
        require base_path('tests/fixtures/business-schema.php');
        Schema::create('admin_users', function ($t): void {
            $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('admin_roles', function ($t): void { $t->id(); $t->string('slug'); });
        Schema::create('admin_role_users', function ($t): void { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('admin_settings', function ($t): void { $t->string('slug')->primary(); $t->text('value'); $t->timestamps(); });
        $admin = AdminUser::create(['username' => 'synthetic-admin', 'name' => 'Synthetic Admin', 'password' => 'test-only-password']);
        DB::table('admin_roles')->insert(['id' => 1, 'slug' => 'administrator']);
        DB::table('admin_role_users')->insert(['user_id' => $admin->id, 'role_id' => 1]);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        $this->withoutVite();
        Bus::fake();
        Event::fake([OrderUpdated::class]);
    }

    private function order(array $values = []): Order
    {
        return Order::findOrFail(DB::table('orders')->insertGetId(array_replace([
            'order_sn' => 'SYNTHETIC-'.bin2hex(random_bytes(5)), 'goods_id' => 7, 'title' => 'Synthetic order',
            'email' => 'customer@example.test', 'buy_ip' => '127.0.0.1', 'actual_price' => '10.00',
            'info' => "客户提交：synthetic@example.test\n  ORIGINAL-DELIVERY\n<script>alert('synthetic')</script>",
            'search_pwd' => 'SYNTHETIC-LOOKUP', 'created_at' => now(), 'updated_at' => now(),
        ], $values)));
    }

    public function test_page_and_modal_render_escaped_multiline_history_without_password_gate(): void
    {
        $order = $this->order(['status' => 4]);
        DB::table('carmis')->where('id', 501)->update(['carmi' => 'MUTATED-CURRENT-STOCK']);
        $before = DB::table('orders')->get()->toJson();
        $stock = DB::table('carmis')->get()->toJson();
        foreach ([
            Livewire::test(ViewOrder::class, ['record' => $order->id]),
            Livewire::test(ListOrders::class)->mountAction(TestAction::make('view')->table($order)),
        ] as $index => $page) {
            foreach ([false, true] as $refresh) {
                if ($refresh) { $page->call('$refresh'); }
                $html = $index === 0 ? $page->html() : $page->getMountedActionModalHtml();
                foreach (['ORIGINAL-DELIVERY', 'SYNTHETIC-LOOKUP', 'shop-order-content', "\n  ORIGINAL-DELIVERY\n", e("<script>alert('synthetic')</script>")] as $expected) {
                    $this->assertStringContainsString($expected, $html);
                }
                foreach (['MUTATED-CURRENT-STOCK', '敏感订单内容', "<script>alert('synthetic')</script>"] as $absent) {
                    $this->assertStringNotContainsString($absent, $html);
                }
            }
        }
        Livewire::test(ListOrders::class)->assertDontSee('ORIGINAL-DELIVERY')->assertDontSee('SYNTHETIC-LOOKUP');
        $this->assertSame($before, DB::table('orders')->get()->toJson());
        $this->assertSame($stock, DB::table('carmis')->get()->toJson());
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_download_on_page_and_table_needs_no_password_and_keeps_original_content(): void
    {
        $order = $this->order();
        foreach ([
            Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('downloadDetails'),
            Livewire::test(ListOrders::class)->callAction(TestAction::make('downloadDetails')->table($order)),
        ] as $page) {
            $page->assertHasNoActionErrors()->assertFileDownloaded('order-'.$order->order_sn.'.txt');
            $download = base64_decode($page->effects['download']['content']);
            $this->assertStringContainsString($order->info, $download);
            $this->assertStringContainsString($order->search_pwd, $download);
        }
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_single_archive_and_restore_preserve_public_access_and_every_business_field(): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $stock = DB::table('carmis')->get()->toJson();
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('archive')->assertHasNoActionErrors();
        $this->assertNotNull($order->fresh()->archived_at);
        $this->assertNull($order->fresh()->deleted_at);
        $page->assertActionHidden('archive')->assertActionVisible('restore');
        Livewire::test(ListOrders::class)->assertCanNotSeeTableRecords([$order])
            ->filterTable('trashed', false)->assertCanSeeTableRecords([$order])
            ->mountAction(TestAction::make('view')->table($order))->assertMountedActionModalSee('ORIGINAL-DELIVERY');
        $service = app(OrderService::class);
        $this->assertSame($order->id, $service->detailOrderSN($order->order_sn)->id);
        $this->assertSame($order->id, DB::transaction(fn () => $service->detailOrderSNForUpdate($order->order_sn)->id));
        $this->assertSame([$order->id], $service->withEmailAndPassword($order->email, $order->search_pwd)->modelKeys());
        $this->assertSame([], $service->withEmailAndPassword($order->email, 'wrong')->modelKeys());
        $this->assertSame([$order->id], $service->byOrderSNS([$order->order_sn])->modelKeys());
        $page->callAction('restore')->assertHasNoActionErrors()->assertActionVisible('archive')->assertActionHidden('restore');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($stock, DB::table('carmis')->get()->toJson());
        Livewire::test(ListOrders::class)->filterTable('trashed', null)->assertCanSeeTableRecords([$order]);
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_bulk_archive_restore_and_table_single_actions_are_persistent_and_idempotent(): void
    {
        $orders = new \Illuminate\Database\Eloquent\Collection(array_map(fn ($status) => $this->order(['status' => $status]), [1, 2, 4, -1]));
        $legacy = $this->order();
        DB::table('orders')->where('id', $legacy->id)->update(['deleted_at' => now()]);
        $before = DB::table('orders')->orderBy('id')->get()->toJson();
        $page = Livewire::test(ListOrders::class)->selectTableRecords($orders->modelKeys())
            ->callAction(TestAction::make('bulkArchive')->table()->bulk())->assertHasNoActionErrors()->assertSet('selectedTableRecords', [])
            ->assertCanNotSeeTableRecords($orders)->filterTable('trashed', false)->assertCanSeeTableRecords($orders);
        $this->assertSame(4, Order::whereNotNull('archived_at')->count());
        $result = OrderOperations::setArchived([...$orders->modelKeys(), $legacy->id, 999999], true);
        $this->assertSame([0, 4, 2], [$result->changed, $result->unchanged, $result->skipped]);
        $page->selectTableRecords($orders->modelKeys())->callAction(TestAction::make('bulkRestore')->table()->bulk())->assertHasNoActionErrors();
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
        $page->filterTable('trashed', null)->callAction(TestAction::make('archive')->table($orders[0]))->assertHasNoActionErrors();
        $this->assertNotNull($orders[0]->fresh()->archived_at);
        $page->filterTable('trashed', false)->callAction(TestAction::make('restore')->table($orders[0]))->assertHasNoActionErrors();
        $this->assertNull($orders[0]->fresh()->archived_at);
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_archived_unpaid_order_still_settles_and_delivers_normally_once(): void
    {
        $order = $this->order(['info' => '', 'type' => 1]);
        OrderOperations::setArchived([$order->id], true);
        $process = app('Service\\OrderProcessService');
        $process->completedOrder($order->order_sn, 10, 'SYNTHETIC-TRADE');
        $fresh = $order->fresh();
        $this->assertSame(4, (int) $fresh->status);
        $this->assertSame('SYNTHETIC-CARD-501', trim($fresh->info));
        $this->assertNotNull($fresh->archived_at);
        $jobs = count(Bus::dispatched(\App\Jobs\MailSend::class));
        $process->completedOrder($order->order_sn, 10, 'SYNTHETIC-TRADE');
        $this->assertSame($fresh->getRawOriginal(), $order->fresh()->getRawOriginal());
        $this->assertSame($jobs, count(Bus::dispatched(\App\Jobs\MailSend::class)));
        $this->assertSame(1, (int) DB::table('goods')->where('id', 7)->value('sales_volume'));
    }

    public function test_details_download_and_archiving_deny_revoked_admins_and_guests(): void
    {
        $order = $this->order();
        foreach ([false, true] as $guest) {
            foreach (['archive', 'downloadDetails', '$refresh'] as $action) {
                DB::table('admin_role_users')->insertOrIgnore(['user_id' => 1, 'role_id' => 1]);
                $this->actingAs(AdminUser::find(1), 'admin');
                $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
                if ($guest) { auth('admin')->logout(); }
                else { DB::table('admin_role_users')->delete(); }
                if ($action === '$refresh') { $page->call($action)->assertForbidden(); }
                else { $page->call('mountAction', $action)->assertForbidden(); }
                try { OrderOperations::setArchived([$order->id], true); $this->fail('Unauthorized archive'); }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
            }
        }
        $this->get(OrderResource::getUrl('view', ['record' => $order->id]))->assertRedirect();
        $this->assertNull($order->fresh()->archived_at);
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_copy_uses_raw_content_and_badges_exclude_archived_orders(): void
    {
        $order = $this->order(['type' => 2, 'status' => 2]);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        foreach (['info', 'search_pwd'] as $name) {
            $entry = $page->instance()->getSchema('infolist')->getComponentByStatePath($name);
            $this->assertSame($order->$name, $entry->getCopyableState($entry->formatState($entry->getState())));
        }
        $list = Livewire::test(ListOrders::class);
        $this->assertSame('1', $list->instance()->getCachedTabs()['manual']->getBadge());
        OrderOperations::setArchived([$order->id], true);
        $list->call('$refresh');
        $this->assertSame('0', $list->instance()->getCachedTabs()['manual']->getBadge());
        DB::table('orders')->where('id', $order->id)->update(['status' => 5]);
        $list->call('$refresh');
        $this->assertSame('0', $list->instance()->getCachedTabs()['attention']->getBadge());
    }

    public function test_archive_bulk_submission_rechecks_admin_and_rejects_invalid_selection(): void
    {
        $order = $this->order();
        foreach ([[], range(1, 1001), ['01'], [true]] as $ids) {
            try { OrderOperations::setArchived($ids, true); $this->fail('Invalid selection accepted'); }
            catch (\Illuminate\Validation\ValidationException) { $this->assertNull($order->fresh()->archived_at); }
        }
        foreach (['bulkArchive', 'bulkRestore'] as $action) {
            foreach ([false, true] as $guest) {
                DB::table('admin_role_users')->insertOrIgnore(['user_id' => 1, 'role_id' => 1]);
                $this->actingAs(AdminUser::find(1), 'admin');
                $page = Livewire::test(ListOrders::class)->selectTableRecords([$order->id])
                    ->mountAction(TestAction::make($action)->table()->bulk());
                if ($guest) { auth('admin')->logout(); }
                else { DB::table('admin_role_users')->delete(); }
                $page->call('callMountedAction')->assertForbidden();
            }
        }
        $this->assertNull($order->fresh()->archived_at);
        Event::assertNotDispatched(OrderUpdated::class);
        Bus::assertNothingDispatched();
    }

    public function test_archive_migration_is_additive_and_preserves_legacy_deletion(): void
    {
        Schema::table('orders', function ($t): void { $t->dropIndex(['archived_at']); $t->dropColumn('archived_at'); });
        $order = $this->order();
        DB::table('orders')->where('id', $order->id)->update(['deleted_at' => now()]);
        $before = (array) DB::table('orders')->find($order->id);
        $migration = require database_path('migrations/2026_10_19_000001_add_archived_at_to_orders.php');
        $migration->up();
        $after = (array) DB::table('orders')->find($order->id);
        $this->assertNull($after['archived_at']);
        unset($after['archived_at']);
        $this->assertSame($before, $after);
        $migration->down();
        $this->assertSame($before, (array) DB::table('orders')->find($order->id));
    }
}
