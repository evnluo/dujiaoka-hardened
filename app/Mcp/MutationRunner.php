<?php

namespace App\Mcp;

use Illuminate\Support\Facades\DB;

final class MutationRunner
{
    public static function fingerprint(mixed $value): string
    {
        $canonical = function ($v) use (&$canonical) {
            if (is_array($v)) {
                if (! array_is_list($v)) ksort($v, SORT_STRING);
                return array_map($canonical, $v);
            }
            return $v;
        };
        return hash_hmac('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), config('app.key'));
    }
    public function run(Operation $op, array $input): array
    {
        if (DB::transactionLevel() !== 0) throw new OperationException('ambient_transaction_not_supported');
        $isolation = null;
        if (DB::connection()->getDriverName() === 'mysql') {
            $isolation = str_replace('-', ' ', DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level);
            if (! in_array($isolation, ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) throw new OperationException('unsupported_isolation');
            // Auth reads must not freeze an old RR view before waiting for business locks.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        try { return $this->runReviewed($op, $input); }
        finally {
            if ($isolation !== null) DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        }
    }
    private function runReviewed(Operation $op, array $input): array
    {
        $token = Access::authorize($op->scope);
        $payload = array_diff_key($input, array_flip(['dryRun', 'previewToken', 'idempotencyKey']));
        $hash = self::fingerprint($payload);
        if ($input['dryRun'] ?? true) {
            return DB::transaction(function () use ($op, $payload, $hash, $token): array {
                $state = ($op->snapshot)($payload, true);
                $plan = ($op->preview)($payload);
                $previewToken = bin2hex(random_bytes(32));
                DB::table('shop_mcp_previews')->insert(['token_hash' => hash('sha256', $previewToken), 'admin_id' => $token->admin_id, 'token_id' => $token->id, 'operation' => $op->name, 'scope' => $op->scope, 'payload_hash' => $hash, 'state_hash' => self::fingerprint($state), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
                self::audit($op, 'preview', payload: $payload);
                return ['dryRun' => true, 'previewToken' => $previewToken, 'expiresInSeconds' => 600, 'plan' => $plan];
            });
        }
        $binding = self::fingerprint([$token->admin_id, $op->scope, $op->name, $input['idempotencyKey']]);
        $externalId = null;
        try {
            $outcome = DB::transaction(function () use ($op, $payload, $hash, $input, $binding, &$externalId): array {
                // Serializes keys across an actor's tokens; business locks serialize different actors.
                $actorId = Access::authorize($op->scope)->admin_id;
                DB::table('admin_users')->where('id', $actorId)->lockForUpdate()->first();
                $token = Access::authorize($op->scope, true);
                $existing = DB::table('shop_mcp_receipts')->where('binding', $binding)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_hash, $hash)) throw new OperationException('idempotency_conflict');
                    self::audit($op, 'replay', $existing->id, payload: $payload);
                    return $this->result($existing, true);
                }
                $preview = DB::table('shop_mcp_previews')->where('token_hash', hash('sha256', $input['previewToken']))->lockForUpdate()->first();
                if (! $preview || $preview->admin_id != $token->admin_id || $preview->token_id != $token->id || $preview->operation !== $op->name || $preview->scope !== $op->scope || ! hash_equals($preview->payload_hash, $hash)) throw new OperationException('preview_mismatch');
                if (now()->greaterThanOrEqualTo($preview->expires_at)) throw new OperationException('preview_expired');
                $state = ($op->snapshot)($payload, true);
                if (! hash_equals($preview->state_hash, self::fingerprint($state))) throw new OperationException('stale_preview');
                ($op->preview)($payload); // Recheck time-sensitive eligibility while target locks are held.
                $id = DB::table('shop_mcp_receipts')->insertGetId(['binding' => $binding, 'admin_id' => $token->admin_id, 'token_id' => $token->id, 'operation' => $op->name, 'scope' => $op->scope, 'payload_hash' => $hash, 'status' => 'indeterminate', 'created_at' => now(), 'updated_at' => now()]);
                if ($op->external) {
                    $externalId = $id;
                    self::audit($op, 'external_reserved', $id, payload: $payload);
                    return ['operationId' => $id];
                }
                $result = ($op->apply)($payload);
                DB::table('shop_mcp_receipts')->where('id', $id)->update(['status' => 'succeeded', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                self::audit($op, 'succeeded', $id, payload: $payload);
                return ['operationId' => $id, 'status' => 'succeeded', 'result' => $result, 'replayed' => false];
            });
        } catch (\Throwable $e) {
            // A callback may throw AFTER the transaction committed; never reapply that mutation.
            $saved = DB::table('shop_mcp_receipts')->where('binding', $binding)->first();
            if ($saved && hash_equals($saved->payload_hash, $hash) && $saved->status === 'succeeded') {
                self::audit($op, 'post_commit_dispatch_failed', $saved->id, payload: $payload);
                return [...$this->result($saved, false), 'notification' => 'post_commit_dispatch_failed'];
            }
            throw $e;
        }
        if ($externalId !== null) {
            try {
                $reviewed = DB::table('shop_mcp_previews')->where('token_hash', hash('sha256', $input['previewToken']))->first();
                $guard = function () use ($op, $payload, $reviewed): void {
                    if (DB::transactionLevel() < 1) throw new OperationException('review_lock_required');
                    Access::authorize($op->scope, true);
                    if (now()->greaterThanOrEqualTo($reviewed->expires_at)) throw new OperationException('preview_expired');
                    if (! hash_equals($reviewed->state_hash, self::fingerprint(($op->snapshot)($payload, true)))) throw new OperationException('stale_preview');
                };
                $result = ($op->apply)($payload, $guard);
                DB::table('shop_mcp_receipts')->where('id', $externalId)->update(['status' => 'succeeded', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                self::audit($op, 'succeeded', $externalId, payload: $payload);
                return ['operationId' => $externalId, 'status' => 'succeeded', 'result' => $result, 'replayed' => false];
            } catch (\Throwable) {
                self::audit($op, 'indeterminate', $externalId, 'external_outcome_unknown', $payload);
                return ['operationId' => $externalId, 'status' => 'indeterminate', 'result' => ['manualVerificationRequired' => true, 'retryAllowed' => false], 'replayed' => false];
            }
        }
        return $outcome;
    }
    private function result(object $receipt, bool $replay): array
    {
        return ['operationId' => $receipt->id, 'status' => $receipt->status, 'result' => $receipt->result ? json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR) : ['manualVerificationRequired' => true, 'retryAllowed' => false], 'replayed' => $replay];
    }
    public static function audit(Operation $op, string $status, ?int $receipt = null, ?string $code = null, array $payload = []): void
    {
        $token = DB::table('shop_mcp_tokens')->where('id', request()->attributes->get('shop_mcp_token_id'))->first();
        if (! $token) return;
        $targets = array_intersect_key($payload, array_flip(['id', 'ids', 'orderId', 'goods_id', 'group_id']));
        DB::table('shop_mcp_audit')->insert(['admin_id' => $token->admin_id, 'token_id' => $token->id, 'operation' => $op->name, 'scope' => $op->scope, 'status' => $status, 'receipt_id' => $receipt, 'reason_code' => $code, 'targets' => json_encode((object) $targets, JSON_THROW_ON_ERROR), 'payload_hash' => $payload ? self::fingerprint($payload) : null, 'created_at' => now()]);
    }
}
