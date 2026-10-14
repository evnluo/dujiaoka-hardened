<?php

namespace App\Service;

use App\Filament\Support\AdminAccess;
use App\Filament\Support\CatalogActions;
use App\Mcp\Input;
use App\Mcp\OperationException;
use App\Mcp\Support\CatalogInput as C;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\GoodsGroup;
use App\Support\ShopRichContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Catalog business actions, independent of Livewire and of MCP transport/receipts. */
final class AdminCatalogService
{
    public const PRODUCT_FIELDS = ['group_id', 'gd_name', 'gd_description', 'gd_keywords', 'picture', 'actual_price', 'retail_price', 'buy_limit_num', 'buy_prompt_enabled', 'buy_prompt', 'description', 'wholesale_price_cnf', 'other_ipu_cnf', 'api_hook', 'is_open', 'ord'];
    public const CATEGORY_FIELDS = ['gp_name', 'ord', 'is_open'];

    public static function productView(Goods $goods, bool $detail = false, bool $lock = false): array
    {
        $result = Arr::only($goods->getAttributes(), ['id', 'group_id', 'gd_name', 'gd_description', 'gd_keywords', 'picture', 'actual_price', 'retail_price', 'type', 'in_stock', 'sales_volume', 'buy_limit_num', 'buy_prompt_enabled', 'is_open', 'ord', 'created_at', 'updated_at', 'deleted_at']);
        foreach (['id', 'group_id', 'type', 'in_stock', 'sales_volume', 'buy_limit_num', 'ord'] as $field) {
            if (isset($result[$field])) { $result[$field] = (int) $result[$field]; }
        }
        foreach (['is_open', 'buy_prompt_enabled'] as $field) { $result[$field] = (bool) ($goods->{$field} ?? false); }
        foreach (['actual_price', 'retail_price'] as $field) { $result[$field] = bcadd((string) ($goods->{$field} ?? 0), '0', 2); }
        $result['available_stock'] = (int) $goods->type === Goods::AUTOMATIC_DELIVERY
            ? ($goods->exists ? $goods->carmis()->where('status', Carmis::STATUS_UNSOLD)->when($lock, fn ($q) => $q->lockForUpdate())->count() : 0)
            : (int) ($goods->in_stock ?? 0);
        $result['api_hook_configured'] = filled($goods->api_hook);
        if ($detail) {
            $result['description'] = ShopRichContent::render($goods->description, true);
            $result['buy_prompt'] = ShopRichContent::render($goods->buy_prompt, true);
            $result['wholesale_price_cnf'] = $goods->wholesale_price_cnf ?? '';
            $result['other_ipu_cnf'] = $goods->other_ipu_cnf ?? '';
        }
        return $result;
    }

    public static function categoryView(GoodsGroup $group, bool $lock = false): array
    {
        $result = Arr::only($group->getAttributes(), ['id', 'gp_name', 'ord', 'is_open', 'created_at', 'updated_at', 'deleted_at']);
        if (isset($result['id'])) { $result['id'] = (int) $result['id']; }
        $result['ord'] = (int) ($group->ord ?? 1); $result['is_open'] = (bool) $group->is_open;
        $result['goods_count'] = $group->exists ? $group->goods()->when($lock, fn ($q) => $q->lockForUpdate())->count() : 0;
        $result['all_goods_count'] = $group->exists ? $group->goods()->withTrashed()->when($lock, fn ($q) => $q->lockForUpdate())->count() : 0;
        return $result;
    }

    public static function productSnapshot(array $p, bool $lock): array
    {
        $hint = isset($p['id']) ? Goods::withTrashed()->find($p['id']) : null;
        $groups = array_values(array_unique(array_filter([$hint?->group_id, $p['group_id'] ?? null])));
        sort($groups, SORT_NUMERIC);
        $snapshot = ['categories' => C::select(GoodsGroup::withTrashed()->whereKey($groups)->orderBy('id'), $lock),
            'allowedHosts' => config('shop_mcp.allowed_hosts', [])];
        $snapshot['products'] = isset($p['id']) ? C::select(Goods::withTrashed()->whereKey($p['id']), $lock) : [];
        // Do not lock an unexpected category after the product (would invert global lock order).
        if ($lock && isset($snapshot['products'][0]) && ! in_array($snapshot['products'][0]['group_id'], $groups)) {
            throw new OperationException('state_changed_retry');
        }
        $snapshot['available_stock'] = $hint ? Carmis::query()->where('goods_id', $hint->id)->where('status', 1)->when($lock, fn ($q) => $q->lockForUpdate())->count() : 0;
        return $snapshot;
    }

    public static function categorySnapshot(array $p, bool $lock): array
    {
        if (! isset($p['id'])) { return []; }
        return ['category' => C::select(GoodsGroup::withTrashed()->whereKey($p['id']), $lock),
            'products' => C::select(Goods::withTrashed()->where('group_id', $p['id'])->orderBy('id'), $lock, ['id', 'group_id', 'deleted_at'])];
    }

    public static function visibilitySnapshot(string $model, array $p, bool $lock): array
    {
        return C::select($model::withTrashed()->whereKey(Input::ids($p['ids']))->orderBy('id'), $lock);
    }

    private static function productData(array $p, ?Goods $goods, bool $lock): array
    {
        $fields = self::PRODUCT_FIELDS;
        if (! $goods) { $fields = array_merge($fields, ['type', 'in_stock']); }
        $data = Arr::only($p, $fields);
        if (! $goods) {
            $data = array_replace(['gd_keywords' => '', 'picture' => '', 'retail_price' => '0.00', 'in_stock' => 0, 'sales_volume' => 0,
                'buy_limit_num' => 0, 'buy_prompt_enabled' => false, 'buy_prompt' => '', 'description' => '', 'wholesale_price_cnf' => '',
                'other_ipu_cnf' => '', 'api_hook' => '', 'is_open' => false, 'ord' => 1, 'type' => 1], $data);
            if ((int) $data['type'] === Goods::AUTOMATIC_DELIVERY && (int) $data['in_stock'] !== 0) {
                throw new OperationException('automatic_stock_is_computed');
            }
        }
        if (isset($data['group_id']) && ! GoodsGroup::query()->whereKey($data['group_id'])->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            throw new OperationException('active_category_required');
        }
        foreach (['description', 'buy_prompt'] as $field) {
            if (array_key_exists($field, $data)) { $data[$field] = AdminMediaService::sanitizeHtml($data[$field]); }
        }
        if (isset($data['picture']) && $data['picture'] !== '') { AdminMediaService::assertImagePath($data['picture']); }
        if (array_key_exists('wholesale_price_cnf', $data)) { self::validateWholesale($data['wholesale_price_cnf']); }
        if (array_key_exists('other_ipu_cnf', $data)) { self::validateCheckoutFields($data['other_ipu_cnf']); }
        if (array_key_exists('api_hook', $data)) { self::validateHook($data['api_hook']); }
        foreach ($data as $field => $value) {
            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || strlen($value) > 65535)) {
                throw new OperationException('invalid_product_text', ['field' => $field]);
            }
        }
        return $data;
    }

    private static function validateWholesale(string $value): void
    {
        $seen = [];
        foreach (preg_split('/\R/', trim($value)) as $line) {
            if ($line === '') { continue; }
            if (! preg_match('/\A([1-9]\d{0,9})=((?:0|[1-9]\d{0,7})(?:\.\d{1,2})?)\z/D', trim($line), $matches)
                || (int) $matches[1] > 2147483647 || isset($seen[$matches[1]])) {
                throw new OperationException('invalid_wholesale_configuration');
            }
            $seen[$matches[1]] = true;
        }
    }

    private static function validateCheckoutFields(string $value): void
    {
        $seen = [];
        $reserved = ['_token', 'goods_id', 'buy_amount', 'email', 'search_pwd', 'payway', 'coupon_code', 'cf-turnstile-response'];
        foreach (preg_split('/\R/', trim($value)) as $line) {
            if ($line === '') { continue; }
            $parts = explode('=', $line);
            if (count($parts) < 3 || count($parts) > 4 || ! preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $parts[0])
                || in_array($parts[0], $reserved, true) || isset($seen[$parts[0]]) || ! in_array($parts[2], ['true', 'false'], true)
                || $parts[1] === '' || preg_match('/[<>\x00-\x1F]/', $line)) {
                throw new OperationException('invalid_checkout_field_configuration');
            }
            $seen[$parts[0]] = true;
        }
    }

    private static function validateHook(string $url): void
    {
        if ($url === '') { return; }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $allowed = config('shop_mcp.allowed_hosts', []);
        if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($host, $allowed, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\x00-\x20\\\\]/', $url)
            || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')
            || preg_match('/(?:\A|\.)(?:localhost|local|internal|lan)\z/iD', $host)) {
            throw new OperationException('hook_requires_approved_https_host');
        }
    }

    public static function saveProduct(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $goods = isset($p['id']) ? C::required(Goods::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true) : null;
        $data = self::productData($p, $goods, $write);
        $before = $goods ? self::productView($goods, false, $write) : null;
        $model = $goods ?? new Goods();
        $model->forceFill($data);
        $changed = $model->isDirty();
        $fields = array_keys($model->getDirty());
        if ($write && $changed) { $model->save(); }
        $after = self::productView($model, false, $write);
        return ['id' => $model->id, 'before' => $before, 'after' => $after, 'fields' => $fields, 'changed' => $changed ? 1 : 0, 'unchanged' => $changed ? 0 : 1, 'skipped' => 0];
    }

    public static function saveCategory(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $group = isset($p['id']) ? C::required(GoodsGroup::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true) : null;
        $before = $group ? self::categoryView($group, $write) : null;
        $data = Arr::only($p, self::CATEGORY_FIELDS);
        if (! $group) { $data = array_replace(['ord' => 1, 'is_open' => true], $data); }
        $group ??= new GoodsGroup();
        $group->forceFill($data);
        $changed = $group->isDirty();
        if ($write && $changed) { $group->save(); }
        return ['id' => $group->id, 'before' => $before, 'after' => self::categoryView($group, $write), 'changed' => $changed ? 1 : 0, 'unchanged' => $changed ? 0 : 1, 'skipped' => 0];
    }

    public static function archiveProduct(int $id, bool $restore, bool $write): array
    {
        AdminAccess::authorize();
        $model = C::required(Goods::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($id));
        return self::archive($model, $restore, $write, fn ($m) => self::productView($m, false, $write));
    }

    public static function archiveCategory(int $id, bool $restore, bool $write): array
    {
        AdminAccess::authorize();
        $model = C::required(GoodsGroup::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($id));
        if (! $restore && ! $model->trashed() && $model->goods()->withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->first(['id'])) {
            throw new OperationException('category_has_products');
        }
        return self::archive($model, $restore, $write, fn ($m) => self::categoryView($m, $write));
    }

    private static function archive($model, bool $restore, bool $write, \Closure $view): array
    {
        $before = $view($model);
        $changed = $restore ? $model->trashed() : ! $model->trashed();
        if ($changed) {
            if ($write) {
                // Legacy deletion listeners cascade to inventory. Archival intentionally must not.
                $model::withoutEvents(fn () => $restore ? $model->restore() : $model->delete());
            } else { $model->deleted_at = $restore ? null : now(); }
        }
        return C::result($model->id, $before, $view($model), $changed);
    }

    public static function addStock(array $p, bool $write): array
    {
        AdminAccess::authorize();
        $goods = C::required(Goods::withTrashed()->when($write, fn ($q) => $q->lockForUpdate())->find($p['id']), true);
        if ((int) $goods->type !== Goods::MANUAL_PROCESSING) { throw new OperationException('manual_product_required'); }
        $before = self::productView($goods);
        $quantity = (int) $p['quantity'];
        if ($quantity < 1 || $quantity > 1000000 || (int) $goods->in_stock > 2147483647 - $quantity) { throw new OperationException('stock_limit_exceeded'); }
        if ($write) {
            Goods::query()->whereKey($goods->id)->increment('in_stock', $quantity);
            $goods = Goods::withTrashed()->lockForUpdate()->findOrFail($goods->id);
        } else { $goods->in_stock = (int) $goods->in_stock + $quantity; }
        return C::result($goods->id, $before, self::productView($goods), true);
    }

    public static function visibility(string $model, array $ids, bool $visible, bool $write): array
    {
        AdminAccess::authorize();
        $ids = Input::ids($ids);
        $records = $model::withTrashed()->whereKey($ids)->orderBy('id')->when($write, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $outcomes = [];
        foreach ($ids as $id) {
            $record = $records->get($id);
            if (! $record) {
                $outcomes[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'not_found'];
                continue;
            }
            $before = (bool) $record->is_open;
            $changed = $before !== $visible;
            if ($write && $changed) { CatalogActions::setVisibility($record, $visible); }
            $outcomes[] = ['id' => $id, 'status' => $changed ? 'changed' : 'unchanged',
                'before' => ['is_open' => $before, 'archived' => $record->trashed()],
                'after' => ['is_open' => $visible, 'archived' => $record->trashed()]];
        }
        return C::outcomes($ids, $outcomes);
    }
}
