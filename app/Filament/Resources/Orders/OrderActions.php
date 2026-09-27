<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Support\ActionFeedback;
use App\Filament\Support\OrderOperations;
use App\Filament\Support\OrderState;
use App\Filament\Support\SensitiveActions;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class OrderActions
{
    public static function processing(): Action
    {
        return Action::make('processing')->label('开始处理')->icon('heroicon-o-play')->requiresConfirmation()
            ->modalDescription('仅将已支付或零元的人工订单转为处理中，不改动支付记录或库存。')
            ->visible(fn (Order $record): bool => (int) $record->type === 2 && (int) $record->status === 2 && (filled($record->trade_no) || OrderState::isZeroTotal($record->actual_price)) && ! $record->trashed())
            ->action(function (Order $record): void {
                ActionFeedback::run(fn () => OrderOperations::fulfil($record->id, 3));
                Notification::make()->title('订单已转为处理中')->success()->send();
            });
    }

    public static function complete(): Action
    {
        return self::result('complete', '完成交付', 4, 'primary', '确认已完成商品交付。处理结果会追加至订单详情，并发送客户状态通知。');
    }

    public static function fail(): Action
    {
        return self::result('fail', '记录处理失败', 5, 'danger', '只记录交付失败并发送客户通知，不代表已退款。退款须在支付渠道单独核实和执行。');
    }

    private static function result(string $name, string $label, int $target, string $color, string $description): Action
    {
        return Action::make($name)->label($label)->color($color)->modalDescription($description)
            ->visible(fn (Order $record): bool => OrderState::canFulfil((int) $record->status, (int) $record->type) && (filled($record->trade_no) || OrderState::isZeroTotal($record->actual_price)) && ! $record->trashed())
            ->schema([Textarea::make('message')->label('处理结果（客户可见）')->required()->maxLength(10000)->rows(5)])
            ->action(function (Order $record, array $data) use ($target): void {
                ActionFeedback::run(fn () => OrderOperations::fulfil($record->id, $target, $data['message']));
                Notification::make()->title('交付状态已更新')->body('支付交易与金额没有变更。')->success()->send();
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
