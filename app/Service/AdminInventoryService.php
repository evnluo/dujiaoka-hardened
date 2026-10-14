<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Filament\Support\InventoryOperations;
use App\Mcp\Input;
use App\Mcp\OperationException;
use App\Mcp\Support\CatalogInput as C;
use App\Models\Carmis;
use App\Models\Goods;
use Illuminate\Support\Arr;

/** Safe plans/results around the same inventory operations used by Filament. */
final class AdminInventoryService
{
    public const META = ['id', 'goods_id', 'status', 'is_loop', 'created_at', 'updated_at', 'deleted_at'];

    public static function view(Carmis $card): array
    {
        $result = Arr::only($card->getAttributes(), self::META);
        foreach (['id', 'goods_id', 'status'] as $key) { $result[$key] = (int) $card->{$key}; }
        $result['is_loop'] = (bool) $card->is_loop;
        $result['product_name'] = Goods::withTrashed()->whereKey($card->goods_id)->value('gd_name');
        return $result;
    }

    public static function snapshot(array $p, bool $lock): array
    {
        $ids = isset($p['ids']) ? Input::ids($p['ids']) : [$p['id']];
        // Routing hints never authorize an update; cards are reread after product locks.
        $goodsIds = Carmis::withTrashed()->whereKey($ids)->pluck('goods_id')->unique()->sort()->values()->all();
        $products = C::select(Goods::withTrashed()->whereKey($goodsIds)->orderBy('id'), $lock, ['id', 'type', 'deleted_at']);
        $cards = C::select(Carmis::withTrashed()->whereKey($ids)->orderBy('goods_id')->orderBy('id'), $lock);
        foreach ($cards as $card) {
            if ($lock && ! in_array($card['goods_id'], $goodsIds)) { throw new OperationException('state_changed_retry'); }
        }
        $duplicates = [];
        if (isset($p['carmi']) && filled($p['carmi']) && $cards) {
            $duplicates = C::select(Carmis::withTrashed()->where('goods_id', $cards[0]['goods_id'])->where('carmi', $p['carmi'])->whereKeyNot($p['id'])->orderBy('id'), $lock);
        }
        return ['products' => $products, 'cards' => $cards, 'duplicates' => $duplicates];
    }

    public static function importSnapshot(array $p, bool $lock): array
    {
        $cards = InventoryOperations::parse($p['cards']);
        $product = C::select(Goods::withTrashed()->whereKey($p['goods_id']), $lock, ['id', 'type', 'deleted_at']);
        $existing = [];
        // Lock the parent first. All card contents below are internal keyed-hash state only.
        foreach (array_chunk($cards, 200) as $chunk) {
            $existing = array_merge($existing, C::select(Carmis::withTrashed()->where('goods_id', $p['goods_id'])->whereIn('carmi', $chunk)->orderBy('id'), $lock));
        }
        $existing = array_values(array_column($existing, null, 'id'));
        usort($existing, fn ($a, $b) => $a['id'] <=> $b['id']);
        return ['product' => $product, 'existing' => $existing];
    }

    public static function import(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $goods = C::required(Goods::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['goods_id']), true);
        if ((int) $goods->type !== Goods::AUTOMATIC_DELIVERY) { throw new OperationException('automatic_product_required'); }
        $cards = InventoryOperations::parse($p['cards']);
        $state = self::importSnapshot($p, $write);
        $matches = [];
        foreach ($state['existing'] as $row) { $matches['content:'.$row['carmi']][] = (int) $row['id']; }
        $skips = [];
        foreach ($cards as $content) {
            if (isset($matches['content:'.$content])) {
                $skips[] = ['reason' => 'already_in_inventory', 'ids' => $matches['content:'.$content]];
            }
        }
        if ($write) {
            $result = InventoryOperations::importDetailed($goods->id, $p['cards'], (bool) ($p['is_loop'] ?? false));
            if ($result['skipped'] !== count($skips)) { throw new OperationException('inventory_state_changed'); }
        } else {
            $result = ['ids' => [], 'added' => count($cards) - count($skips), 'skipped' => count($skips)];
        }
        return $result + ['skips' => $skips, 'goods_id' => (int) $goods->id, 'is_loop' => (bool) ($p['is_loop'] ?? false), 'changed' => $result['added'], 'unchanged' => 0];
    }

    public static function edit(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $card = C::required(Carmis::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true);
        if (! Goods::withTrashed()->whereKey($card->goods_id)->exists()) { throw new OperationException('product_not_found'); }
        $content = $p['carmi'];
        if (! mb_check_encoding($content, 'UTF-8') || mb_strlen($content) > 10000 || str_contains($content, "\0")) { throw new OperationException('invalid_card_content'); }
        $before = self::view($card);
        $changed = filled($content) && $card->carmi !== $content;
        if (filled($content) && Carmis::withTrashed()->where('goods_id', $card->goods_id)->where('carmi', $content)->whereKeyNot($card->id)->when($write, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            throw new OperationException('duplicate_card_content');
        }
        if ($write && $changed) { $card = InventoryOperations::update($card->id, ['carmi' => $content]); }
        return C::result($card->id, $before, self::view($card), $changed) + ['fields' => $changed ? ['carmi'] : []];
    }

    public static function setField(array $p, string $field, bool $write): array
    {
        AdminAccess::authorize();
        $ids = Input::ids($p['ids']);
        $value = $field === 'status' ? (int) $p['status'] : ($p['enabled'] ? 1 : 0);
        $records = Carmis::withTrashed()->whereKey($ids)->orderBy('goods_id')->orderBy('id')->when($write, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $productIds = Goods::withTrashed()->whereKey($records->pluck('goods_id')->unique()->all())->pluck('id')->all();
        $outcomes = [];
        foreach ($ids as $id) {
            $card = $records->get($id);
            if (! $card || $card->trashed() || ! in_array($card->goods_id, $productIds)) {
                $outcomes[] = ['id' => $id, 'status' => 'skipped', 'reason' => ! $card ? 'not_found' : ($card->trashed() ? 'record_archived' : 'product_not_found')];
                continue;
            }
            $before = self::view($card);
            $changed = (int) $card->{$field} !== $value;
            if ($write && $changed) {
                $result = $field === 'status' ? InventoryOperations::setStatus([$id], $value) : InventoryOperations::setCycling([$id], (bool) $value);
                if ($result->skipped) { $outcomes[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'inventory_write_failed']; continue; }
                $card = Carmis::withTrashed()->lockForUpdate()->findOrFail($id);
            } else { $card->{$field} = $value; }
            $outcomes[] = ['id' => $id, 'status' => $changed ? 'changed' : 'unchanged', 'before' => $before, 'after' => self::view($card)];
        }
        return C::outcomes($ids, $outcomes);
    }

    public static function archive(array $p, bool $restore, bool $write): array
    {
        AdminAccess::authorize();
        $ids = Input::ids($p['ids']);
        $records = Carmis::withTrashed()->whereKey($ids)->orderBy('goods_id')->orderBy('id')->when($write, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $eligible = []; $outcomes = [];
        foreach ($ids as $id) {
            $card = $records->get($id);
            if (! $card) { $outcomes[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'not_found']; continue; }
            $changed = $restore ? $card->trashed() : ! $card->trashed();
            if ($changed && ((int) $card->status !== Carmis::STATUS_UNSOLD || (int) $card->is_loop !== 0)) {
                // Reject the whole selection before any write, like the UI archive operation.
                throw new OperationException('only_unused_noncycling_cards', ['id' => $id]);
            }
            if ($changed && ! Goods::withTrashed()->whereKey($card->goods_id)->exists()) { throw new OperationException('product_not_found'); }
            $before = self::view($card);
            if ($changed) { $eligible[] = $id; $card->deleted_at = $restore ? null : now(); }
            $outcomes[] = ['id' => $id, 'status' => $changed ? 'changed' : 'unchanged', 'before' => $before, 'after' => self::view($card)];
        }
        if ($write && $eligible) {
            $count = $restore ? InventoryOperations::restore($eligible) : InventoryOperations::archive($eligible);
            if ($count !== count($eligible)) { throw new OperationException('state_changed_retry'); }
            foreach ($outcomes as &$outcome) {
                if ($outcome['status'] === 'changed') { $outcome['after'] = self::view(Carmis::withTrashed()->lockForUpdate()->findOrFail($outcome['id'])); }
            }
            unset($outcome);
        }
        return C::outcomes($ids, $outcomes);
    }
}
