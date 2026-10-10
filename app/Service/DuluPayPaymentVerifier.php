<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\Http;

final class DuluPayPaymentVerifier
{
    /** Only the fixed official endpoint may receive this gateway's credential. */
    public function assertPaid(Order $order, string $tradeNo): void
    {
        $pay = Pay::query()->whereKey($order->pay_id)->lockForUpdate()->first();
        if (!$pay || (int) $pay->is_open !== 1 || $pay->pay_handleroute !== '/pay/yipay'
            || $pay->merchant_key !== 'https://api.dulupay.com/submit.php'
            || !is_string($pay->merchant_pem) || $pay->merchant_pem === '') {
            throw new RuleValidationException('reconciliation_gateway_not_supported');
        }
        try {
            $response = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->get('https://api.dulupay.com/api.php', [
                    'act' => 'order', 'pid' => $pay->merchant_id,
                    'key' => $pay->merchant_pem, 'out_trade_no' => $order->order_sn,
                ]);
            $data = $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            // The transport exception can contain the credential-bearing query URL.
            throw new RuleValidationException('reconciliation_provider_unavailable');
        }
        if (!is_array($data) || !in_array($data['code'] ?? null, [1, '1'], true)
            || !in_array($data['status'] ?? null, [1, '1'], true)
            || !(is_string($data['pid'] ?? null) || is_int($data['pid'] ?? null))
            || !hash_equals((string) $pay->merchant_id, (string) $data['pid'])
            || !is_string($data['out_trade_no'] ?? null) || !hash_equals($order->order_sn, $data['out_trade_no'])
            || !is_string($data['trade_no'] ?? null) || !hash_equals($tradeNo, $data['trade_no'])
            || !is_string($data['type'] ?? null) || !hash_equals($pay->pay_check, $data['type'])
            || !is_string($data['money'] ?? null) || !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/D', $data['money'])
            || bccomp($order->actual_price, $data['money'], 2) !== 0) {
            throw new RuleValidationException('reconciliation_payment_not_verified');
        }
    }
}
