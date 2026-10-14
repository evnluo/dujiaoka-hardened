<?php

namespace App\Mcp;

use Illuminate\Support\Facades\Validator;

final class Arguments
{
    public static function schema(Operation $op): array
    {
        return $op->schema + ($op->read ? [] : [
            'dryRun' => ['type' => 'boolean', 'default' => true],
            'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            'previewToken' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
            'idempotencyKey' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 100],
        ]);
    }
    public static function rules(Operation $op): array
    {
        return $op->rules + ($op->read ? [] : ['dryRun' => ['sometimes', 'boolean'], 'reason' => ['required', 'string', 'max:500'], 'previewToken' => ['required_if:dryRun,false', 'string', 'size:64'], 'idempotencyKey' => ['required_if:dryRun,false', 'string', 'min:16', 'max:100', 'regex:/\A[a-zA-Z0-9_.:-]+\z/D']]);
    }
    public static function validate(Operation $op, array $input): array
    {
        self::check($input, ['type' => 'object', 'properties' => self::schema($op), 'additionalProperties' => false]);
        $v = Validator::make($input, self::rules($op));
        if ($v->fails()) throw new OperationException('invalid_input');
        return $v->validated();
    }
    private static function check(mixed $value, array $s): void
    {
        $valid = match ($s['type'] ?? null) {
            'object' => is_array($value) && (! $value || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
            'string' => is_string($value), 'boolean' => is_bool($value), 'null' => $value === null,
            default => false,
        };
        if (! $valid || (isset($s['enum']) && ! in_array($value, $s['enum'], true))) throw new OperationException('invalid_input');
        if (is_string($value) && (mb_strlen($value) > ($s['maxLength'] ?? PHP_INT_MAX) || mb_strlen($value) < ($s['minLength'] ?? 0) || str_contains($value, "\0"))) throw new OperationException('invalid_input');
        if ((is_int($value) || is_float($value)) && ($value < ($s['minimum'] ?? -PHP_FLOAT_MAX) || $value > ($s['maximum'] ?? PHP_FLOAT_MAX))) throw new OperationException('invalid_input');
        if (($s['type'] ?? null) === 'object') {
            foreach ($value as $key => $item) {
                if (! isset($s['properties'][$key])) throw new OperationException('invalid_input');
                self::check($item, $s['properties'][$key]);
            }
        }
        if (($s['type'] ?? null) === 'array') {
            if (count($value) < ($s['minItems'] ?? 0) || count($value) > ($s['maxItems'] ?? PHP_INT_MAX)) throw new OperationException('invalid_input');
            if (($s['uniqueItems'] ?? false) && count(array_unique(array_map(fn ($v) => json_encode($v), $value))) !== count($value)) throw new OperationException('invalid_input');
            foreach ($value as $item) self::check($item, $s['items']);
        }
    }
}
