<?php

namespace App\Mcp;

final class Input
{
    public static function paginationSchema(): array
    {
        return ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000, 'default' => 1], 'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], 'search' => ['type' => 'string', 'maxLength' => 200], 'archived' => ['type' => 'string', 'enum' => ['active', 'archived', 'all'], 'default' => 'active']];
    }
    public static function paginationRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'string', 'max:200'], 'archived' => ['sometimes', 'in:active,archived,all']];
    }
    public static function page($query, array $p, array $columns): array
    {
        $page = $p['page'] ?? 1; $size = $p['perPage'] ?? 25;
        $total = (clone $query)->count();
        $items = $query->forPage($page, $size)->get($columns)->map(fn ($row) => $row instanceof \Illuminate\Database\Eloquent\Model ? $row->toArray() : (array) $row)->all();
        return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $size, 'hasMore' => $page * $size < $total];
    }
    public static function ids(array $ids): array
    {
        if (! $ids || count($ids) > 1000 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) throw new OperationException('invalid_selection');
        foreach ($ids as $id) if (! is_int($id) || $id < 1 || $id > 2147483647) throw new OperationException('invalid_selection');
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
