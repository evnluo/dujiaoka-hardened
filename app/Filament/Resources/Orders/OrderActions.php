<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Support\ActionFeedback;
use App\Filament\Support\BulkSelection;
use App\Filament\Support\OrderOperations;
use App\Filament\Support\OrderState;
use App\Filament\Support\SensitiveActions;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

final class OrderActions
{
    public static function bulkComplete(): BulkAction
    {
        return self::bulkResult('bulkComplete', '完成所选订单', 4, 'primary');
    }

    public static function bulkFail(): BulkAction
    {
        return self::bulkResult('bulkFail', '标记处理失败', 5, 'danger');
    }

    private static function bulkResult(string $name, string $label, int $target, string $color): BulkAction
    {
        return BulkAction::make($name)->label($label)->color($color)->fetchSelectedRecords(false)
            ->requiresConfirmation()->modalHeading($label)
            ->modalDescription(fn ($livewire): string => '已选 '.count(BulkSelection::ids($livewire)).' 条。每次最多 1,000 条，仅更新已支付或零元的待处理 / 处理中人工订单；不符合条件的记录将跳过并说明原因。不分配卡密、不重新交付、不确认支付；失败不代表退款。')
            ->schema([
                Textarea::make('message')->label('处理结果（客户可见）')->required()->maxLength(10000)->rows(4)
                    ->helperText('同一结果追加到每条已更改订单的详情，不是私密备注。'),
                Toggle::make('notify_customer')->label('通知客户')->default(false)
                    ->helperText('默认只更新记录，不发送邮件、推送或 webhook。开启后仅为本次已更改的订单提交状态邮件。'),
            ])
            ->action(function ($livewire, array $data) use ($target): void {
                $result = ActionFeedback::run(fn () => OrderOperations::bulkFulfil(BulkSelection::ids($livewire), $target, $data['message'], (bool) ($data['notify_customer'] ?? false)));
                $result->send();
                BulkSelection::clear($livewire);
            })->deselectRecordsAfterCompletion();
    }

    public static function processing(): Action
    {
        return Action::make('processing')->label('开始处理')->icon('heroicon-o-play')->requiresConfirmation()
            ->modalDescription('仅将已支付或零元的人工订单转为处理中，不改动支付记录或库存。')
            ->visible(fn (Order $record): bool => (int) $record->type === 2 && (int) $record->status === 2 && (filled($record->trade_no) || OrderState::isZeroTotal($record->actual_price)) && ! $record->trashed())
            ->action(function (Order $record): void {
                $updated = ActionFeedback::run(fn () => OrderOperations::fulfil($record->id, 3));
                $record->status = $updated->status;
                $record->updated_at = $updated->updated_at;
                Notification::make()->title('订单已转为处理中')->success()->send();
            });
    }

    public static function complete(): Action
    {
        return self::result('complete', '完成交付', 4, 'primary', '将订单记为已完成，处理结果追加至客户可见的订单详情。默认只更新记录，不发送通知；勾选「通知客户」才发送状态邮件。');
    }

    public static function fail(): Action
    {
        return self::result('fail', '记录处理失败', 5, 'danger', '只记录交付失败并发送客户通知，不代表已退款。退款须在支付渠道单独核实和执行。');
    }

    private static function result(string $name, string $label, int $target, string $color, string $description): Action
    {
        return Action::make($name)->label($label)->color($color)->modalDescription($description)
            ->visible(fn (Order $record): bool => OrderState::canFulfil((int) $record->status, (int) $record->type) && (filled($record->trade_no) || OrderState::isZeroTotal($record->actual_price)) && ! $record->trashed())
            ->schema([
                Textarea::make('message')->label('处理结果（客户可见）')->required()->maxLength(10000)->rows(5),
                ...($target === 4 ? [Toggle::make('notify_customer')->label('通知客户')->default(false)
                    ->helperText('关闭时仍会完成订单并保存处理结果，但不会发送邮件、推送或 webhook。')] : []),
            ])
            ->action(function (Order $record, array $data) use ($target): void {
                $notifyCustomer = $target === 5 || (bool) ($data['notify_customer'] ?? false);
                $updated = ActionFeedback::run(fn () => OrderOperations::fulfil($record->id, $target, $data['message'], $notifyCustomer));
                // Refresh visible state without hydrating protected order details into Livewire.
                $record->status = $updated->status;
                $record->updated_at = $updated->updated_at;
                Notification::make()->title('交付状态已更新')
                    ->body($notifyCustomer ? '已提交客户状态邮件。支付交易与金额没有变更。' : '仅更新订单记录，未发送客户通知。支付交易与金额没有变更。')->success()->send();
            });
    }

    public static function download(): Action
    {
        return Action::make('downloadDetails')->label('下载完整详情')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->modalDescription('文件包含客户提交信息、交付内容和查询密码。验证后下载，请勿公开分享。')
            ->schema([SensitiveActions::passwordField()])
            ->action(function (Order $record, array $data) {
                SensitiveActions::confirm($data);
                $order = Order::withTrashed()->findOrFail($record->id);
                return SensitiveActions::download('order-'.$order->order_sn.'.txt', "订单：{$order->order_sn}\n查询密码：{$order->search_pwd}\n\n{$order->info}");
            });
    }
}
