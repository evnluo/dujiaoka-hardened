<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Mcp\OperationException;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Immutable record of a refund the operator says was already issued externally. */
final class ExternalRefundRecording
{
    public const PUBLIC_COLUMNS = ['id', 'order_id', 'pay_id', 'trade_no', 'amount', 'currency', 'provider', 'external_reference', 'refunded_at', 'recorded_by', 'created_at', 'updated_at'];

    public function snapshot(array $p, bool $lock): array
    {
        $q = Order::withTrashed()->whereKey($p['orderId']); if ($lock) $q->lockForUpdate();
        $o = $q->first();
        $refunds = DB::table('shop_order_refunds')->where(fn ($q) => $q->where('order_id', $p['orderId'])->orWhere('reference_hash', $this->referenceHash($p)))->orderBy('id');
        if ($lock) $refunds->lockForUpdate();
        return ['order' => $o?->getAttributes(), 'refunds' => $refunds->get()->map(fn ($r) => (array) $r)->all()];
    }

    public function preview(array $p): array
    {
        [$o, $existing, $total] = $this->check($p);
        return ['orderId' => $o->id, 'amount' => $p['amount'], 'currency' => $p['currency'], 'provider' => $p['provider'], 'externalReference' => $p['externalReference'], 'recordedTotalBefore' => $total, 'recordedTotalAfter' => $existing ? $total : bcadd($total, $p['amount'], 2), 'existingRecordId' => $existing?->id, 'moneyMoved' => false, 'subscriptionChanged' => false, 'meaning' => 'Record an already-issued external refund only; the shop does not verify or move provider funds.'];
    }

    public function record(array $p): array
    {
        AdminAccess::authorize();
        try {
            return DB::transaction(function () use ($p): array {
                // Serializes aggregate cap enforcement for parallel partial refunds.
                [$o, $existing, $total] = $this->check($p, true);
                if ($existing) return $this->result((int) $existing->id, false);
                $id = DB::table('shop_order_refunds')->insertGetId([
                    'order_id' => $o->id, 'pay_id' => $o->pay_id, 'trade_no' => $o->trade_no,
                    'amount' => $p['amount'], 'currency' => $p['currency'], 'provider' => $p['provider'],
                    'external_reference' => $p['externalReference'], 'reference_hash' => $this->referenceHash($p),
                    'refunded_at' => $this->refundTime($p), 'recorded_by' => auth('admin')->id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return $this->result($id, true);
            }, 3);
        } catch (\Illuminate\Database\QueryException) {
            // Unique reference conflict / database failure: no SQL or row data escapes.
            throw new OperationException('refund_record_conflict');
        }
    }

    public function get(int $id): array
    {
        $r = DB::table('shop_order_refunds')->where('id', $id)->first(self::PUBLIC_COLUMNS) ?? throw new OperationException('refund_not_found');
        return $this->publicRow($r);
    }

    public function publicRow(object|array $row): array
    {
        $row = (array) $row; $row['amount'] = bcadd((string) $row['amount'], '0', 2);
        return [...$row, 'recordType' => 'external_refund_record', 'moneyMoved' => false, 'subscriptionChanged' => false];
    }

    private function result(int $id, bool $changed): array
    {
        return ['id' => $id, 'changed' => $changed ? 1 : 0, 'unchanged' => $changed ? 0 : 1, 'record' => $this->get($id), 'moneyMoved' => false, 'subscriptionChanged' => false];
    }

    private function referenceHash(array $p): string
    {
        return hash('sha256', $p['provider']."\0".$p['externalReference']);
    }

    private function refundTime(array $p): string
    {
        try { $t = CarbonImmutable::parse($p['refundedAt'])->utc(); }
        catch (\Throwable) { throw new OperationException('refund_time_invalid'); }
        if ($t->isFuture()) throw new OperationException('refund_time_in_future');
        return $t->format('Y-m-d H:i:s');
    }

    private function check(array $p, bool $lock = false): array
    {
        if (! is_string($p['amount']) || ! preg_match('/\A(?:0|[1-9]\d{0,7})\.\d{2}\z/D', $p['amount']) || bccomp($p['amount'], '0', 2) <= 0) throw new OperationException('refund_amount_invalid');
        if ($p['currency'] !== 'CNY') throw new OperationException('refund_currency_not_supported');
        if (! in_array($p['provider'], ['dulupay', 'manual'], true) || ! preg_match('/\A[A-Za-z0-9._:-]{1,160}\z/D', $p['externalReference'])) throw new OperationException('refund_reference_invalid');
        $time = $this->refundTime($p);
        $q = Order::withTrashed()->whereKey($p['orderId']); if ($lock) $q->lockForUpdate();
        $o = $q->first() ?? throw new OperationException('order_not_found');
        if (! in_array((int) $o->status, [2, 3, 4, 5, 6], true) || blank($o->trade_no) || bccomp($o->actual_price, '0', 2) <= 0) throw new OperationException('refund_payment_not_recorded');
        $existing = DB::table('shop_order_refunds')->where('reference_hash', $this->referenceHash($p))->first();
        if ($existing && ((int) $existing->order_id !== $o->id || bccomp((string) $existing->amount, $p['amount'], 2) !== 0 || $existing->currency !== $p['currency'] || $existing->refunded_at !== $time)) throw new OperationException('refund_reference_conflict');
        // Sum decimals in decimal arithmetic, not PHP floats / a rounded JSON number.
        $total = '0.00';
        foreach (DB::table('shop_order_refunds')->where('order_id', $o->id)->orderBy('id')->pluck('amount') as $amount) $total = bcadd($total, (string) $amount, 2);
        if (! $existing && bccomp(bcadd($total, $p['amount'], 2), $o->actual_price, 2) > 0) throw new OperationException('refund_exceeds_order_amount');
        return [$o, $existing, $total];
    }
}
