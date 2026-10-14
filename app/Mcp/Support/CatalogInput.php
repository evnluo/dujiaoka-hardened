<?php

namespace App\Mcp\Support;

use App\Mcp\Input;
use App\Mcp\OperationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Bounded schemas and public result shapes shared only by the catalog domains. */
final class CatalogInput
{
    public static function text(int $max): array { return ['type' => 'string', 'maxLength' => $max]; }
    public static function integer(int $min = 0, int $max = 2147483647): array { return ['type' => 'integer', 'minimum' => $min, 'maximum' => $max]; }
    public static function idSchema(): array { return ['id' => self::integer(1)]; }
    public static function idRules(): array { return ['id' => ['required', 'integer', 'min:1', 'max:2147483647']]; }
    public static function idsSchema(): array { return ['ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'uniqueItems' => true, 'items' => self::integer(1)]]; }
    public static function idsRules(): array { return ['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct:strict']]; }
    public static function moneySchema(): array { return ['type' => 'string', 'maxLength' => 11, 'pattern' => '^(0|[1-9][0-9]{0,7})(\\.[0-9]{1,2})?$']; }
    public static function moneyRules(): array { return ['string', 'regex:/\A(?:0|[1-9][0-9]{0,7})(?:\.\d{1,2})?\z/D']; }

    public static function archived(Builder $query, array $p): Builder
    {
        return match ($p['archived'] ?? 'active') { 'all' => $query->withTrashed(), 'archived' => $query->onlyTrashed(), default => $query };
    }

    public static function required(?Model $model, bool $active = false): Model
    {
        if (! $model) { throw new OperationException('not_found'); }
        if ($active && $model->trashed()) { throw new OperationException('record_archived'); }
        return $model;
    }

    public static function result(int $id, ?array $before, array $after, bool $changed): array
    {
        return ['id' => $id, 'before' => $before, 'after' => $after, 'changed' => $changed ? 1 : 0, 'unchanged' => $changed ? 0 : 1, 'skipped' => 0];
    }

    public static function outcomes(array $ids, array $outcomes): array
    {
        $counts = array_count_values(array_column($outcomes, 'status'));
        return ['ids' => Input::ids($ids), 'changed' => $counts['changed'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'outcomes' => $outcomes];
    }

    public static function select(Builder $query, bool $lock, array $columns = ['*']): array
    {
        return ($lock ? $query->lockForUpdate() : $query)->get($columns)->map(fn (Model $row) => $row->getAttributes())->all();
    }
}
