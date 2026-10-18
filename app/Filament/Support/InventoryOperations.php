<?php

namespace App\Filament\Support;

use App\Models\Carmis;
use App\Models\Goods;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryOperations
{
    public static function setStatus(array $ids, int $status): BulkResult
    {
        AdminAccess::authorize();
        if (! in_array($status, [Carmis::STATUS_SOLD, Carmis::STATUS_UNSOLD], true)) {
            throw ValidationException::withMessages(['selection' => '请选择已使用或未使用状态。']);
        }
        return self::setField($ids, 'status', $status);
    }

    public static function setCycling(array $ids, bool $enabled): BulkResult
    {
        AdminAccess::authorize();
        return self::setField($ids, 'is_loop', $enabled ? 1 : 0);
    }

    private static function setField(array $ids, string $field, int $value): BulkResult
    {
        $ids = BulkResult::ids($ids);
        $result = new BulkResult();
        // Routing hints only. Every card is re-read under its product lock below.
        $products = Carmis::withTrashed()->whereKey($ids)->pluck('goods_id', 'id')->all();
        usort($ids, fn ($a, $b) => [($products[$a] ?? 0), $a] <=> [($products[$b] ?? 0), $b]);
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach ($chunk as $id) {
                try {
                    $outcome = DB::transaction(function () use ($id, $products, $field, $value): string {
                        $goodsId = $products[$id] ?? null;
                        $goods = $goodsId ? Goods::withTrashed()->lockForUpdate()->find($goodsId) : null;
                        if (! $goods) { return '所属商品或卡密不存在，请刷新后核查。'; }
                        $card = Carmis::withTrashed()->where('goods_id', $goodsId)->lockForUpdate()->find($id);
                        if (! $card || $card->trashed()) { return '卡密已归档或不存在；如需恢复，请单独核查归档记录。'; }
                        if ((int) $card->{$field} === $value) { return 'unchanged'; }
                        // A setter changes only its field (plus the audit timestamp).
                        $card->{$field} = $value;
                        $card->save();
                        return 'changed';
                    });
                    if ($outcome === 'changed') { $result->changed++; }
                    elseif ($outcome === 'unchanged') { $result->unchanged++; }
                    else { $result->skip($outcome); }
                } catch (QueryException) {
                    $result->skip('数据库写入失败，此条未更改；请刷新后重试。');
                }
            }
        }
        return $result;
    }

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
        $result = self::importDetailed($goodsId, $content, $isLoop);
        return ['added' => $result['added'], 'skipped' => $result['skipped']];
    }

    /** Same importer, with exact new IDs for non-UI clients; content never leaves this result. */
    public static function importDetailed(int $goodsId, string $content, bool $isLoop = false): array
    {
        AdminAccess::authorize();
        $cards = self::parse($content);

        return DB::transaction(function () use ($goodsId, $cards, $isLoop): array {
            $goods = Goods::query()->lockForUpdate()->findOrFail($goodsId);
            if ((int) $goods->type !== Goods::AUTOMATIC_DELIVERY) {
                throw ValidationException::withMessages(['goods_id' => '仅自动发货商品可以导入卡密。']);
            }
            $lastId = (int) Carmis::withTrashed()->where('goods_id', $goodsId)->orderByDesc('id')->lockForUpdate()->value('id');
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

            return ['added' => $added, 'skipped' => count($cards) - $added,
                'ids' => Carmis::withTrashed()->where('goods_id', $goodsId)->where('id', '>', $lastId)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all()];
        });
    }

    /** Permanent exact-selection deletion; preview takes the same locks and eligibility path. */
    public static function delete(array $ids, bool $write = true): int
    {
        AdminAccess::authorize();
        $ids = BulkResult::ids($ids);

        return DB::transaction(function () use ($ids, $write): int {
            // Routing hints only; current card reads below must belong to a locked parent.
            $goodsIds = Carmis::withTrashed()->whereKey($ids)->pluck('goods_id')->unique()->sort()->values()->all();
            $lockedIds = Goods::withTrashed()->whereKey($goodsIds)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $cards = Carmis::withTrashed()->whereKey($ids)->orderBy('goods_id')->orderBy('id')->lockForUpdate()
                ->get(['id', 'goods_id', 'status', 'is_loop', 'deleted_at']);
            if ($cards->count() !== count($ids) || $cards->contains(fn (Carmis $card): bool => ! in_array($card->goods_id, $lockedIds))) {
                throw ValidationException::withMessages(['selection' => '所选卡密或所属商品已变化，请刷新后重新选择。本次未删除。']);
            }
            if ($cards->contains(fn (Carmis $card): bool => ! $card->trashed() && (int) $card->status === Carmis::STATUS_UNSOLD && (int) $card->is_loop === 1)) {
                throw ValidationException::withMessages(['selection' => '所选记录包含未售出的循环库存，本次未删除。']);
            }
            // Only this table: no model deletion listeners, parent deletion, or status transition.
            if ($write) {
                $deleted = Carmis::withTrashed()->whereKey($ids)->forceDelete();
                if ($deleted !== count($ids)) {
                    throw ValidationException::withMessages(['selection' => '所选卡密已变化，本次未删除。']);
                }
            }

            return $cards->count();
        });
    }

    public static function archive(array $ids): int
    {
        AdminAccess::authorize();

        $ids = BulkResult::ids($ids);
        return DB::transaction(function () use ($ids): int {
            self::lockProducts($ids);
            $cards = Carmis::query()->whereKey($ids)->orderBy('goods_id')->orderBy('id')->lockForUpdate()->get();
            if ($cards->contains(fn (Carmis $card): bool => (int) $card->status !== Carmis::STATUS_UNSOLD || (int) $card->is_loop === 1)) {
                throw ValidationException::withMessages(['cards' => '已售出或循环卡密不能归档。此次操作没有改动任何卡密。']);
            }
            foreach ($cards as $card) {
                $card->delete();
            }

            return $cards->count();
        });
    }

    /** Consistent ordering for every stock mutation: products ascending, then cards. */
    public static function lockProducts(array $ids): void
    {
        $goodsIds = Carmis::withTrashed()->whereKey($ids)->pluck('goods_id')->unique()->sort()->values()->all();
        Goods::withTrashed()->whereKey($goodsIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public static function restore(array $ids): int
    {
        AdminAccess::authorize();
        $ids = BulkResult::ids($ids);
        return DB::transaction(function () use ($ids): int {
            self::lockProducts($ids);
            $cards = Carmis::onlyTrashed()->whereKey($ids)->orderBy('goods_id')->orderBy('id')->lockForUpdate()->get();
            if ($cards->contains(fn (Carmis $card): bool => (int) $card->status !== Carmis::STATUS_UNSOLD || (int) $card->is_loop !== 0)) {
                throw ValidationException::withMessages(['cards' => '已售出或循环卡密不能恢复。此次操作没有改动任何卡密。']);
            }
            foreach ($cards as $card) { $card->restore(); }
            return $cards->count();
        });
    }

    public static function update(int $id, array $data): Carmis
    {
        AdminAccess::authorize();
        if (filled($data['carmi'] ?? null) && (! is_string($data['carmi']) || ! mb_check_encoding($data['carmi'], 'UTF-8')
            || mb_strlen($data['carmi']) > 10000 || str_contains($data['carmi'], "\0"))) {
            throw ValidationException::withMessages(['carmi' => '卡密包含无效内容或过长。']);
        }
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
