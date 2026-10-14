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
            // Capture rendered content while the reviewed template/settings locks are held.
            // The after-commit listener must not reload mutable template state.
            $token = match ((int) $order->status) {
                Order::STATUS_COMPLETED => 'completed_order',
                Order::STATUS_FAILURE => 'failed_order',
                default => throw new OperationException('manual_notification_status_ineligible'),
            };
            $template = \App\Models\Emailtpl::where('tpl_token', $token)->lockForUpdate()->first()
                ?? throw new OperationException('delivery_template_missing');
            DB::table('admin_settings')->where('slug', \App\Support\ShopSettings::SLUG)->lockForUpdate()->first();
            $mail = replace_mail_tpl($template->toArray(), [
                'created_at' => date('Y-m-d H:i'), 'ord_title' => $order->title,
                'webname' => app(\App\Support\ShopSettings::class)->get('text_logo', '独角数卡'),
                'weburl' => config('app.url'), 'order_id' => $order->order_sn,
                'ord_price' => $order->actual_price, 'ord_info' => str_replace(PHP_EOL, '<br/>', $order->info),
            ]);
            $job = new \App\Jobs\MailSend($order->email, $mail['tpl_name'], $mail['tpl_content']);
            $id = DB::table('shop_order_notifications')->insertGetId(['order_id' => $order->id, 'requested_by' => auth('admin')->id(), 'status' => 'pending', 'reason_code' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($order, $id, $job): void {
                $status = 'submitted'; $reason = null;
                try { event(new OrderUpdated($order, $job)); }
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
