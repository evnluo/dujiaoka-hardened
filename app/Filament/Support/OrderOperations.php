<?php

namespace App\Filament\Support;

use App\Events\OrderUpdated;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderOperations
{
    public static function fulfil(int $id, int $target, ?string $message = null, bool $notifyCustomer = false): Order
    {
        AdminAccess::authorize();

        return DB::transaction(function () use ($id, $target, $message, $notifyCustomer): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            self::apply($order, $target, $message);
            if ($notifyCustomer) {
                DB::afterCommit(fn () => event(new OrderUpdated($order)));
            }

            return $order;
        });
    }

    public static function bulkFulfil(array $ids, int $target, string $message, bool $notifyCustomer = false): BulkResult
    {
        AdminAccess::authorize();
        $ids = BulkResult::ids($ids);
        if (! in_array($target, [4, 5], true) || blank($message) || mb_strlen($message) > 10000) {
            throw ValidationException::withMessages(['message' => '请选择完成或失败，并填写不超过 10,000 字的客户可见处理结果。']);
        }
        $result = new BulkResult();
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach ($chunk as $id) {
                try {
                    $outcome = DB::transaction(function () use ($id, $target, $message, $notifyCustomer, $result): string {
                        $order = Order::withTrashed()->lockForUpdate()->find($id);
                        if (! $order || $order->trashed()) {
                            return '记录已归档或不存在，请刷新列表核查。';
                        }
                        if ((int) $order->type === 2 && (int) $order->status === $target) {
                            return 'unchanged';
                        }
                        self::apply($order, $target, $message);
                        if ($notifyCustomer) {
                            DB::afterCommit(function () use ($order, $result): void {
                                try { event(new OrderUpdated($order)); }
                                catch (\Throwable) { $result->notificationFailures++; }
                            });
                        }
                        return 'changed';
                    });
                    if ($outcome === 'changed') { $result->changed++; }
                    elseif ($outcome === 'unchanged') { $result->unchanged++; }
                    else { $result->skip($outcome); }
                } catch (ValidationException $e) {
                    $result->skip($e->validator->errors()->first());
                } catch (QueryException) {
                    $result->skip('数据库写入失败，此条未更改；请刷新后重试。');
                }
            }
        }
        return $result;
    }

    private static function apply(Order $order, int $target, ?string $message): void
    {
        if (! OrderState::canFulfil((int) $order->status, (int) $order->type)) {
            throw ValidationException::withMessages(['message' => '仅处理待处理 / 处理中的人工订单；请筛选待人工处理后核查。']);
        }
        if (blank($order->trade_no) && ! OrderState::isZeroTotal($order->actual_price)) {
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
        // Suppress the model's automatic OrderUpdated event for silent bookkeeping.
        Order::withoutEvents(fn () => $order->save());
    }
}
