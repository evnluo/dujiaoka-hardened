<?php

namespace App\Service;

use App\Events\OrderUpdated;
use App\Filament\Support\AdminAccess;
use App\Mcp\OperationException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Safe durable submission metadata, not a transactional outbox or retry queue. */
final class ManualOrderNotification
{
    public function schedule(Order $order): int
    {
        AdminAccess::authorize();
        return DB::transaction(function () use ($order): int {
            $id = DB::table('shop_order_notifications')->insertGetId(['order_id' => $order->id, 'requested_by' => auth('admin')->id(), 'status' => 'pending', 'reason_code' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($order, $id): void {
                $status = 'submitted'; $reason = null;
                try { event(new OrderUpdated($order)); }
                catch (\Throwable) { $status = 'indeterminate'; $reason = 'notification_submission_indeterminate'; }
                try { DB::table('shop_order_notifications')->where('id', $id)->update(['status' => $status, 'reason_code' => $reason, 'updated_at' => now()]); }
                catch (\Throwable) { Log::warning('mcp_notification_status_persist_failed', ['attempt_id' => $id]); }
            });
            return $id;
        });
    }

    public function status(int $id): array
    {
        $row = DB::table('shop_order_notifications')->where('id', $id)->first(['id', 'order_id', 'requested_by', 'status', 'reason_code', 'created_at', 'updated_at']) ?? throw new OperationException('notification_attempt_not_found');
        return [...(array) $row, 'deliveryGuaranteed' => false, 'retryAutomatically' => false,
            'pendingMeaning' => 'Pending can mean the process stopped after commit. Inspect the exact queue/provider state before requesting a new resend.'];
    }
}
