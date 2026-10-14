<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Filament\Support\OrderState;
use App\Jobs\MailSend;
use App\Mcp\OperationException;
use App\Models\Emailtpl;
use App\Models\Goods;
use App\Models\Order;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\DB;

/** Sends a historical copy only. Never calls settlement or allocation services. */
final class OrderDeliveryNotification
{
    public function snapshot(int $id, bool $lock): array
    {
        $q = Order::withTrashed()->whereKey($id);
        if ($lock) $q->lockForUpdate();
        $order = $q->first();
        return [
            'order' => $order?->getAttributes(),
            'template' => $order ? Emailtpl::where('tpl_token', $this->token($order))->first()?->getAttributes() : null,
            'product' => $order ? Goods::withTrashed()->whereKey($order->goods_id)->first(['id', 'gd_name'])?->toArray() : null,
            'settings' => app(ShopSettings::class)->getAll(),
            'appUrl' => config('app.url'),
        ];
    }

    public function preview(int $id): array
    {
        $this->eligible($id);
        return ['id' => $id, 'recipient' => 'stored_order_customer', 'source' => 'historical_order_content', 'notification' => 'explicit_resend', 'allocatedInventory' => false, 'deliveryGuaranteed' => false];
    }

    public function send(int $id, ?\Closure $guard = null): array
    {
        AdminAccess::authorize();
        // Capture an immutable historical payload under a short order lock. Network /
        // queue dispatch occurs after this transaction, never in an allocation path.
        [$to, $mail] = DB::transaction(function () use ($id, $guard): array {
            if ($guard) $guard();
            $o = $this->eligible($id, true);
            $template = Emailtpl::where('tpl_token', $this->token($o))->first();
            $mail = replace_mail_tpl($template->toArray(), [
                'created_at' => $o->created_at, 'product_name' => Goods::withTrashed()->find($o->goods_id)?->gd_name ?? $o->title,
                'webname' => app(ShopSettings::class)->get('text_logo', '独角数卡'), 'weburl' => config('app.url'),
                'ord_info' => str_replace(PHP_EOL, '<br/>', (string) $o->info), 'ord_title' => $o->title,
                'order_id' => $o->order_sn, 'buy_amount' => $o->buy_amount, 'ord_price' => $o->actual_price,
            ]);
            return [$o->email, $mail];
        });
        try { MailSend::dispatch($to, $mail['tpl_name'], $mail['tpl_content']); }
        catch (\Throwable) { throw new OperationException('notification_submission_indeterminate', ['id' => $id, 'allocatedInventory' => false]); }
        return ['id' => $id, 'notification' => 'submitted', 'deliveryGuaranteed' => false, 'allocatedInventory' => false, 'orderChanged' => false];
    }

    private function token(Order $o): string
    {
        return (int) $o->type === Order::AUTOMATIC_DELIVERY ? 'card_send_user_email' : 'completed_order';
    }

    private function eligible(int $id, bool $lock = false): Order
    {
        $q = Order::withTrashed()->whereKey($id);
        if ($lock) $q->lockForUpdate();
        $o = $q->first() ?? throw new OperationException('order_not_found');
        if ($o->trashed()) throw new OperationException('order_archived');
        if ((int) $o->status !== Order::STATUS_COMPLETED || ! in_array((int) $o->type, [1, 2], true) || blank($o->info)) throw new OperationException('delivery_not_completed');
        if (blank($o->trade_no) && ! OrderState::isZeroTotal($o->actual_price)) throw new OperationException('payment_evidence_missing');
        if (! filter_var($o->email, FILTER_VALIDATE_EMAIL)) throw new OperationException('invalid_stored_recipient');
        if (! Emailtpl::where('tpl_token', $this->token($o))->exists()) throw new OperationException('delivery_template_missing');
        return $o;
    }
}
