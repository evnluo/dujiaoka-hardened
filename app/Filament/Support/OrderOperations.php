<?php

namespace App\Filament\Support;

use App\Events\OrderUpdated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderOperations
{
    public static function fulfil(int $id, int $target, ?string $message = null): Order
    {
        AdminAccess::authorize();

        return DB::transaction(function () use ($id, $target, $message): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            if (! OrderState::canFulfil((int) $order->status, (int) $order->type) || blank($order->trade_no)) {
                throw ValidationException::withMessages(['message' => '订单状态已变化，或没有支付交易凭证。刷新后再处理；此处不能确认支付。']);
            }
            if (! in_array($target, [3, 4, 5], true) || ($target === 3 && (int) $order->status !== 2)) {
                throw ValidationException::withMessages(['message' => '不允许此状态变更。']);
            }
            if ($target !== 3 && (blank($message) || mb_strlen($message) > 10000)) {
                throw ValidationException::withMessages(['message' => '请输入不超过 10,000 字的处理结果。']);
            }
            if (filled($message)) {
                $order->info = trim((string) $order->info)."\n\n".trim($message);
                if (strlen($order->info) > 60000) {
                    throw ValidationException::withMessages(['message' => '订单详情过长，请缩短处理结果。']);
                }
            }
            $order->status = $target;
            // Publish status mail only after the state transition commits.
            Order::withoutEvents(fn () => $order->save());
            DB::afterCommit(fn () => event(new OrderUpdated($order)));

            return $order;
        });
    }
}
