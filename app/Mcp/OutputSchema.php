<?php

namespace App\Mcp;

/** Wire-level contracts; domain projections never serialize unrestricted models. */
final class OutputSchema
{
    public static function for(Operation $op): array
    {
        $object = ['type' => 'object', 'additionalProperties' => true];
        $integer = ['type' => 'integer', 'minimum' => 0];
        $id = ['type' => 'integer', 'minimum' => 1];
        if (! $op->read) return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'dryRun' => ['type' => 'boolean', 'const' => true],
                'previewToken' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                'expiresInSeconds' => ['type' => 'integer', 'const' => 600], 'plan' => $object,
                'operationId' => $id, 'status' => ['type' => 'string', 'enum' => ['succeeded', 'indeterminate']],
                'result' => $object, 'replayed' => ['type' => 'boolean'],
                'notification' => ['type' => 'string', 'enum' => ['post_commit_dispatch_failed']],
            ],
            'oneOf' => [['required' => ['dryRun', 'previewToken', 'expiresInSeconds', 'plan']], ['required' => ['operationId', 'status', 'result', 'replayed']]],
        ];
        if (str_ends_with($op->name, '_list') || in_array($op->name, ['operations_low_stock', 'operations_failed_jobs', 'operations_audit'], true)) return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['items', 'total', 'page', 'perPage', 'hasMore'],
            'properties' => ['items' => ['type' => 'array', 'maxItems' => 100, 'items' => $object], 'total' => $integer, 'page' => $id, 'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'hasMore' => ['type' => 'boolean'], 'available' => ['type' => 'boolean'], 'expirySupported' => ['type' => 'boolean', 'const' => false]],
        ];
        $properties = match ($op->name) {
            'inventory_cards_export' => ['items' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'object', 'required' => ['id', 'goods_id', 'carmi'], 'properties' => ['id' => $id, 'goods_id' => $id, 'carmi' => ['type' => 'string']], 'additionalProperties' => false]], 'total' => $integer, 'missingIds' => ['type' => 'array', 'maxItems' => 1000, 'items' => $id]],
            'orders_status_counts' => ['total' => $integer, 'statuses' => $object, 'tabs' => $object, 'today' => $integer],
            'settings_get' => ['values' => $object, 'configured' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']]],
            'settings_turnstile_status' => ['enabled' => ['type' => 'boolean'], 'configured' => ['type' => 'boolean'], 'hasSecretKey' => ['type' => 'boolean']],
            'refunds_capabilities' => ['cashRefundSupported' => ['type' => 'boolean', 'const' => false], 'externalRecordingSupported' => ['type' => 'boolean', 'const' => true]],
            'operations_sales_summary' => ['orders' => $integer, 'completedOrders' => $integer, 'completedRevenue' => ['type' => 'string', 'pattern' => '^\\d+\\.\\d{2}$'], 'externalRefundsRecorded' => ['type' => 'string', 'pattern' => '^\\d+\\.\\d{2}$']],
            'operations_receipt' => ['operationId' => $id, 'status' => ['type' => 'string', 'enum' => ['succeeded', 'indeterminate']]],
            'operations_queue_status' => ['driver' => ['type' => 'string'], 'pendingJobs' => ['type' => ['integer', 'null']], 'failedJobs' => ['type' => ['integer', 'null']]],
            default => str_ends_with($op->name, '_get') || str_ends_with($op->name, '_content') ? ['id' => $id] : [],
        };
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => true];
    }
}
