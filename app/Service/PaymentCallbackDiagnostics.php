<?php

namespace App\Service;

use App\Models\Order;
use App\Models\Pay;

/** Read-only callback diagnosis. Never replays a callback or reveals its payload. */
final class PaymentCallbackDiagnostics
{
    public const FIELDS = ['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'sign', 'sign_type', 'param'];

    public function inspect(array $data): array
    {
        $reason = $this->reason($data);
        return ['reasonCode' => $reason, 'verified' => $reason === 'verified', 'paymentChanged' => false, 'deliveryChanged' => false,
            'historicalCallbackLogAvailable' => false,
            'explanation' => 'Read-only check of the supplied callback against current local state; not evidence that this callback was received or a payment settled.'];
    }

    private function reason(array $data): string
    {
        foreach (array_diff(self::FIELDS, ['param']) as $key) if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') return 'shape';
        foreach ($data as $key => $value) if (! is_string($key) || ! is_string($value)) return 'shape';
        if (strlen($data['out_trade_no']) > 150 || strlen($data['name']) > 150 || strlen($data['trade_no']) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $data['out_trade_no'].$data['name'].$data['trade_no'])
            || ! preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/D', $data['money']) || strcasecmp($data['sign_type'], 'MD5') !== 0) return 'shape';
        $o = Order::withTrashed()->where('order_sn', $data['out_trade_no'])->first();
        if (! $o) return 'order_missing';
        if ($o->trashed()) return 'order_archived';
        $pay = Pay::find($o->pay_id);
        if (! $pay) return 'gateway_missing';
        if ($pay->pay_handleroute !== '/pay/yipay' || (int) $pay->is_open !== 1) return 'gateway_disabled';
        if (! hash_equals((string) $pay->merchant_id, $data['pid'])) return 'merchant';
        if (! hash_equals((string) $pay->pay_check, $data['type'])) return 'method';
        if ($data['trade_status'] !== 'TRADE_SUCCESS') return 'unpaid';
        // Preserve signed descriptive name exactly; only out_trade_no is identity.
        ksort($data); $parts = [];
        foreach ($data as $key => $value) if (! in_array($key, ['sign', 'sign_type'], true) && $value !== '') $parts[] = $key.'='.$value;
        if (! preg_match('/\A[0-9a-fA-F]{32}\z/D', $data['sign']) || ! hash_equals(md5(implode('&', $parts).$pay->merchant_pem), strtolower($data['sign']))) return 'signature';
        if (bccomp($o->actual_price, $data['money'], 2) !== 0) return 'amount';
        if (Order::withTrashed()->where('trade_no', $data['trade_no'])->where('id', '!=', $o->id)->exists()) return 'payment_trade_already_bound';
        if ((int) $o->status === -1) return 'expired_requires_fresh_recovery';
        if ((int) $o->status === 1) return 'verified';
        if (in_array((int) $o->status, [2, 3, 4, 5, 6], true) && hash_equals((string) $o->trade_no, $data['trade_no'])) return 'verified';
        return 'fulfilment_state_conflict';
    }
}
