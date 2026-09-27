<?php

namespace App\Filament\Support;

final class OrderState
{
    public const LABELS = [-1 => '已过期', 1 => '待支付', 2 => '待处理', 3 => '处理中', 4 => '已完成', 5 => '处理失败', 6 => '异常'];
    public const TYPES = [1 => '自动发货', 2 => '人工处理'];

    public static function payment(int $status, ?string $tradeNo): string
    {
        if ($status === 1) {
            return '待支付';
        }
        if ($status === -1) {
            return '未支付 · 已过期';
        }

        return trim((string) $tradeNo) !== '' ? '已记录支付' : '无交易凭证';
    }

    public static function delivery(int $status): string
    {
        return match ($status) {
            1, -1 => '未开始',
            2 => '待处理',
            3 => '处理中',
            4 => '已完成',
            5 => '处理失败',
            6 => '需排查',
            default => '未知状态',
        };
    }

    public static function color(int $status): string
    {
        return match ($status) {
            2, 3 => 'info',
            4 => 'success',
            5, 6 => 'danger',
            default => 'gray',
        };
    }

    public static function canFulfil(int $status, int $type): bool
    {
        return $type === 2 && in_array($status, [2, 3], true);
    }
}
