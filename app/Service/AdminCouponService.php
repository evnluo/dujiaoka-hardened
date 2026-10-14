<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Mcp\Input;
use App\Mcp\OperationException;
use App\Mcp\Support\CatalogInput as C;
use App\Models\Coupon;
use App\Models\Goods;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class AdminCouponService
{
    public static function view(Coupon $coupon, bool $lock = false): array
    {
        $result = Arr::only($coupon->getAttributes(), ['id', 'coupon', 'discount', 'is_use', 'is_open', 'ret', 'created_at', 'updated_at', 'deleted_at']);
        foreach (['id', 'is_use', 'ret'] as $key) { if (isset($result[$key])) { $result[$key] = (int) $result[$key]; } }
        $result['discount'] = bcadd((string) ($coupon->discount ?? 0), '0', 2);
        $result['is_open'] = (bool) $coupon->is_open;
        $result['goods'] = $coupon->exists ? DB::table('coupons_goods')->where('coupons_id', $coupon->id)->orderBy('goods_id')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('goods_id')->map(fn ($id) => (int) $id)->all() : [];
        // There is no coupon expiry field or checkout expiry enforcement in this installation.
        $result['expirySupported'] = false;
        return $result;
    }

    public static function snapshot(array $p, bool $lock): array
    {
        $hints = isset($p['id']) ? DB::table('coupons_goods')->where('coupons_id', $p['id'])->pluck('goods_id')->all() : [];
        $ids = array_values(array_unique(array_merge($hints, isset($p['goods']) ? Input::ids($p['goods']) : [])));
        sort($ids, SORT_NUMERIC);
        $products = C::select(Goods::withTrashed()->whereKey($ids)->orderBy('id'), $lock, ['id', 'deleted_at']);
        $q = Coupon::withTrashed();
        if (isset($p['id'])) { $q->whereKey($p['id']); }
        else { $q->where('coupon', $p['coupon']); }
        $coupons = C::select($q->orderBy('id'), $lock);
        $links = isset($p['id']) ? DB::table('coupons_goods')->where('coupons_id', $p['id'])->orderBy('goods_id')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all() : [];
        if ($lock && array_diff(array_column($links, 'goods_id'), $ids)) { throw new OperationException('state_changed_retry'); }
        return ['products' => $products, 'coupons' => $coupons, 'associations' => $links];
    }

    public static function save(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $coupon = isset($p['id']) ? C::required(Coupon::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true) : null;
        $before = $coupon ? self::view($coupon, $write) : null;
        $data = Arr::only($p, ['discount', 'is_open']);
        if (! $coupon) {
            $data = array_replace(['is_open' => true, 'ret' => 1, 'is_use' => Coupon::STATUS_UNUSED], Arr::only($p, ['coupon', 'discount', 'is_open', 'ret']));
            if (! mb_check_encoding($data['coupon'], 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $data['coupon'])) { throw new OperationException('invalid_coupon_code'); }
            if (Coupon::withTrashed()->where('coupon', $data['coupon'])->when($write, fn ($q) => $q->lockForUpdate())->first(['id'])) { throw new OperationException('coupon_code_unavailable'); }
        }
        if (isset($data['discount']) && bccomp($data['discount'], '0.01', 2) < 0) { throw new OperationException('invalid_coupon_discount'); }
        $products = null;
        if (isset($p['goods'])) {
            $products = Input::ids($p['goods']);
            $active = Goods::query()->whereKey($products)->orderBy('id')->when($write, fn ($q) => $q->lockForUpdate())->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($active !== $products) { throw new OperationException('active_coupon_products_required'); }
        }
        if (! $coupon && ! $products) { throw new OperationException('coupon_products_required'); }
        $coupon ??= new Coupon();
        $coupon->forceFill($data);
        $currentProducts = $before['goods'] ?? [];
        $linksChanged = $products !== null && $products !== $currentProducts;
        $changed = $coupon->isDirty() || $linksChanged;
        if ($write && $changed) {
            try {
                if ($coupon->isDirty() || ! $coupon->exists) { $coupon->save(); }
                if ($linksChanged) {
                    // Avoid sync()'s repeatable-read snapshot of old pivots after locking the coupon.
                    DB::table('coupons_goods')->where('coupons_id', $coupon->id)->delete();
                    DB::table('coupons_goods')->insert(array_map(fn ($id) => ['coupons_id' => $coupon->id, 'goods_id' => $id], $products));
                }
            } catch (QueryException $exception) {
                // Never expose SQL/bindings (including the submitted coupon) through the MCP error.
                throw new OperationException('coupon_write_conflict');
            }
        }
        $after = self::view($coupon, $write);
        if (! $write && $products !== null) { $after['goods'] = $products; }
        return ['id' => $coupon->id, 'before' => $before, 'after' => $after, 'changed' => $changed ? 1 : 0, 'unchanged' => $changed ? 0 : 1, 'skipped' => 0];
    }

    public static function addUses(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $coupon = C::required(Coupon::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true);
        $before = self::view($coupon, $write);
        $count = (int) $p['count'];
        if ($count < 1 || $count > 1000000 || (int) $coupon->ret > 2147483647 - $count) { throw new OperationException('coupon_usage_limit_exceeded'); }
        if ($write) {
            Coupon::query()->whereKey($coupon->id)->increment('ret', $count);
            $coupon = Coupon::withTrashed()->lockForUpdate()->findOrFail($coupon->id);
        } else { $coupon->ret = (int) $coupon->ret + $count; }
        return C::result($coupon->id, $before, self::view($coupon, $write), true);
    }

    public static function archive(array $p, bool $restore, bool $write): array
    {
        AdminAccess::authorize();
        $coupon = C::required(Coupon::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']));
        $before = self::view($coupon, $write);
        $changed = $restore ? $coupon->trashed() : ! $coupon->trashed();
        if ($changed) {
            if ($write) { $restore ? $coupon->restore() : $coupon->delete(); }
            else { $coupon->deleted_at = $restore ? null : now(); }
        }
        return C::result($coupon->id, $before, self::view($coupon, $write), $changed);
    }
}
