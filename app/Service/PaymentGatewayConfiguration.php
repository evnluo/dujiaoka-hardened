<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared Filament/MCP gateway configuration; no Livewire dependency. */
final class PaymentGatewayConfiguration
{
    public const FIELDS = ['pay_name', 'pay_check', 'merchant_id', 'pay_client', 'is_open', 'merchant_key', 'merchant_pem'];

    public function save(array $data, ?Pay $record = null): Pay
    {
        AdminAccess::authorize();
        return DB::transaction(function () use ($data, $record): Pay {
            $pay = $this->prepare($data, $record?->id, true);
            $pay->save();
            return $pay;
        });
    }

    /** Validate exactly as apply without writing a row (preview safe). */
    public function prepare(array $data, ?int $id, bool $lock = false): Pay
    {
        AdminAccess::authorize();
        $create = $id === null;
        $q = Pay::query()->whereKey($id);
        if ($lock) $q->lockForUpdate();
        $pay = $create ? new Pay() : $q->firstOrFail();
        if (! $create && $pay->pay_handleroute !== '/pay/yipay') throw ValidationException::withMessages(['pay_name' => '旧支付渠道只读，不允许启用。']);
        Validator::make($data, [
            'pay_name' => [$create ? 'required' : 'sometimes', 'string', 'max:200'],
            'merchant_id' => [$create ? 'required' : 'sometimes', 'string', 'max:200', 'regex:/\A[A-Za-z0-9._-]{1,200}\z/D'],
            'pay_check' => [$create ? 'required' : 'sometimes', 'string', 'in:alipay,wxpay,qqpay'],
            'pay_client' => ['sometimes', 'integer', 'in:1,2,3'], 'is_open' => ['sometimes', 'boolean'],
            'merchant_key' => [$create ? 'required' : 'sometimes', 'nullable', 'string', 'max:2000'],
            'merchant_pem' => [$create ? 'required' : 'sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();
        if (! $create && isset($data['pay_check']) && $data['pay_check'] !== $pay->pay_check) throw ValidationException::withMessages(['pay_check' => '支付标识创建后不可变更。']);
        foreach (['pay_name', 'merchant_id', 'pay_client', 'is_open'] as $key) if (array_key_exists($key, $data)) $pay->{$key} = $data[$key];
        if ($create) {
            $pay->pay_check = $data['pay_check'];
            $pay->pay_client = $data['pay_client'] ?? 3;
            $pay->is_open = $data['is_open'] ?? false;
            if (Pay::withTrashed()->where('pay_check', $pay->pay_check)->exists()) throw ValidationException::withMessages(['pay_check' => '该支付标识已有记录（包括旧渠道）。请编辑对应的现有易支付渠道。']);
        }
        foreach (['merchant_key', 'merchant_pem'] as $key) if (filled($data[$key] ?? null)) $pay->{$key} = $data[$key];
        $pay->pay_handleroute = '/pay/yipay';
        $pay->pay_method = Pay::METHOD_JUMP;
        if (! filter_var($pay->merchant_key, FILTER_VALIDATE_URL) || parse_url($pay->merchant_key, PHP_URL_SCHEME) !== 'https'
            || preg_match('/[\x00-\x20\x22\x27\x3C\x3E\x60\x7F]/', (string) $pay->merchant_key)
            || blank($pay->merchant_pem)) throw ValidationException::withMessages(['merchant_key' => '请配置有效的 HTTPS 网关地址及商户签名密钥。']);
        return $pay;
    }

    public function metadata(Pay $p): array
    {
        return [...$p->only(['id', 'pay_name', 'pay_check', 'merchant_id', 'pay_client', 'pay_method', 'pay_handleroute', 'is_open', 'created_at', 'updated_at', 'deleted_at']),
            'hasCredentials' => filled($p->merchant_pem), 'hasGatewayUrl' => filled($p->merchant_key),
            'editable' => ! $p->trashed() && $p->pay_handleroute === '/pay/yipay'];
    }
}
