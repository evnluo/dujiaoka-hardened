<?php

namespace App\Filament\Support;

use App\Models\Carmis;
use App\Models\Goods;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryOperations
{
    public static function parse(string $content): array
    {
        if (strlen($content) > 5 * 1024 * 1024 || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['cards' => '请使用不超过 5 MB 的 UTF-8 文本。']);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content)), fn ($line) => $line !== ''));
        if (! $lines || count($lines) > 10000) {
            throw ValidationException::withMessages(['cards' => '每次导入 1–10,000 行卡密，每行一条。']);
        }
        foreach ($lines as $line) {
            if (strlen($line) > 60000 || str_contains($line, "\0")) {
                throw ValidationException::withMessages(['cards' => '卡密包含无效内容或单行过长。']);
            }
        }

        return array_values(array_unique($lines, SORT_STRING));
    }

    public static function import(int $goodsId, string $content, bool $isLoop = false): array
    {
        AdminAccess::authorize();
        $cards = self::parse($content);

        return DB::transaction(function () use ($goodsId, $cards, $isLoop): array {
            $goods = Goods::query()->lockForUpdate()->findOrFail($goodsId);
            if ((int) $goods->type !== Goods::AUTOMATIC_DELIVERY) {
                throw ValidationException::withMessages(['goods_id' => '仅自动发货商品可以导入卡密。']);
            }
            $added = 0;
            foreach (array_chunk($cards, 200) as $chunk) {
                // Current read: an enclosing repeatable-read transaction may have an older snapshot.
                // Include sold and archived stock: neither may be accidentally resold.
                $existing = Carmis::withTrashed()->where('goods_id', $goodsId)->whereIn('carmi', $chunk)->lockForUpdate()->pluck('carmi')->all();
                $rows = [];
                foreach (array_diff($chunk, $existing) as $card) {
                    $rows[] = ['goods_id' => $goodsId, 'carmi' => $card, 'status' => Carmis::STATUS_UNSOLD, 'is_loop' => $isLoop ? 1 : 0, 'created_at' => now(), 'updated_at' => now()];
                }
                if ($rows) {
                    Carmis::query()->insert($rows);
                    $added += count($rows);
                }
            }

            return ['added' => $added, 'skipped' => count($cards) - $added];
        });
    }

    public static function archive(array $ids): int
    {
        AdminAccess::authorize();

        return DB::transaction(function () use ($ids): int {
            $cards = Carmis::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($cards->contains(fn (Carmis $card): bool => (int) $card->status !== Carmis::STATUS_UNSOLD || (int) $card->is_loop === 1)) {
                throw ValidationException::withMessages(['cards' => '已售出或循环卡密不能归档。此次操作没有改动任何卡密。']);
            }
            foreach ($cards as $card) {
                $card->delete();
            }

            return $cards->count();
        });
    }

    public static function update(int $id, array $data): Carmis
    {
        AdminAccess::authorize();
        // Resolve the product before opening a repeatable-read transaction.
        $goodsId = Carmis::query()->findOrFail($id, ['id', 'goods_id'])->goods_id;

        return DB::transaction(function () use ($id, $goodsId, $data): Carmis {
            // Imports and replacements take the same product lock before any card lock.
            Goods::withTrashed()->lockForUpdate()->findOrFail($goodsId);
            $card = Carmis::query()->where('goods_id', $goodsId)->lockForUpdate()->findOrFail($id);

            if (filled($data['carmi'] ?? null)) {
                if (Carmis::withTrashed()->where('goods_id', $card->goods_id)->where('carmi', $data['carmi'])->whereKeyNot($card->id)->lockForUpdate()->first(['id'])) {
                    throw ValidationException::withMessages(['carmi' => '该商品已有此卡密（包括已售出及归档记录）。']);
                }
                $card->carmi = $data['carmi'];
            }
            $card->save();

            return $card;
        });
    }
}
