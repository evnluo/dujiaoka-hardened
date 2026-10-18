# Orders, payments and refunds MCP parity matrix

## Source inventory (before implementation)

Read in full: `Orders/OrderResource`, `Orders/OrderActions`, both Orders pages, `OrderOperations`, `OrderState`, `Payments/PayResource`, `ManagePayments`, `ReconcileDuluPayOrder`, `DuluPayPaymentVerifier`, `OrderProcessService`, `OrderService`, `CarmisService`, `Listeners/OrderUpdated`, and the synthetic business schema / existing admin and DuluPay tests.

| Existing surface / requirement | Semantic MCP operation | Scope | Safeguard / planned test |
|---|---|---|---|
| Order table: SN, title/type/quantity, amount, payment evidence/channel, delivery, trade, timestamps | `orders_list` | read | Bounded pagination and exact totals; no email/IP/content/password by default |
| Search SN/title/email/trade; status/type/product/payment/from/until/archived filters; all/manual/attention/unpaid/completed tabs | `orders_list`, `orders_status_counts` | read | All advertised filters applied to rows and counts; stable allowlisted sort |
| Today's count; manual and attention badges | `orders_status_counts` | read | Filtered status counts, tab counts, today count |
| Detail: email, IP, title/quantity/type/status/SN; payment label/channel/trade; single/total/actual prices and coupon/wholesale discounts; coupon; created/updated/deleted | `orders_get` | orders | Exact explicit detail, archived associations retained; sensitive content separate |
| Password-confirmed full detail download: historical delivery/customer info + lookup password | `orders_content` | orders:content | Explicit protected read, including archived; never from live stock or broad list/audit |
| Begin processing (paid/zero-price manual pending only) | `orders_start_processing` | orders | Shared service, cannot force unpaid/automatic orders paid |
| Single complete and fail with customer-visible message | `orders_complete`, `orders_fail` | orders | Message bounded, append-only historical info; notifyCustomer explicitly defaults false for BOTH (intentional safer MCP difference from UI single-failure auto-mail) |
| Bulk complete/fail (1..1000 selected) | `orders_bulk_complete`, `orders_bulk_fail` | orders | Exact IDs, reject duplicates, sorted deterministic per-ID changed/unchanged/skipped counts, safe reason codes; notify default false |
| Order archive/restore/create/arbitrary edit/delete | Not exposed: UI denies these operations | — | Archived orders readable, never silently broaden UI permissions |
| Explicit repeat delivery notification (new required operation) | `orders_resend_delivery` | orders | Send historical content to stored recipient only; no card allocation/status/payment mutation; external receipt prevents blind retry |
| Durable safe notification submission receipt (new requirement) | `orders_notification_status` | orders | Exact attempt ID from manual mutation; pending/submitted/indeterminate metadata only; detects post-commit submission failure without claiming rollback or actual email delivery |
| Payment table/search: name/check/merchant ID/route/client/open/timestamps/credential presence; open and archive filters | `payments_list`, `payments_get` | billing | Never return gateway URL or signing key; legacy rows read-only |
| Create Yipay: name, fixed-after-create check (alipay/wxpay/qqpay), merchant ID, client(1/2/3), open(default false), HTTPS URL, key | `payments_create` | billing | Shared service extracted from UI; URL/key write-only, bounded inputs; host allowlist for MCP URL changes |
| Edit same fields except immutable check; blank/omitted secrets preserve | `payments_update` | billing | Reject legacy/archived; fixed jump route/method; safe before/after presence only; no secret preview/result |
| Payment archive/restore/delete, legacy activation | Not exposed: not permitted by UI | — | Historical configuration remains intact |
| Official fresh provider payment query | `payments_query_status` | billing | Fixed HTTPS DuluPay endpoint, no redirect, timeout; validate merchant/order/type/amount/status; no response passthrough |
| Callback troubleshooting | `payments_callback_diagnostics` | billing | Read-only canonical validation, safe reason code; signed name is metadata and out_trade_no is identity; no callback replay or log dump |
| `payment:reconcile-dulupay` expired automatic/manual recovery | `payments_recover_expired` | billing | Fresh query at apply; canonical service owns transaction/payment mutex; coupon/trade/history/archived restrictions; manual stays paid pending without card allocation/recharge; never reset paid state; post-commit dispatch caveat |
| Refund UI | No existing refund UI/action/table | — | Additive local journal, never reinterpret delivery failure as refund |
| Record a refund ALREADY issued elsewhere | `refunds_record_external` | billing | Positive exact decimal amount, matching payment currency, paid order evidence, unique provider reference, order-row lock and aggregate cap; immutable journal; no cash/email/stock/subscription effects |
| Refund history/detail | `refunds_list`, `refunds_get` | billing | Bounded exact totals and safe public ledger fields |
| Actual provider cash refund | `refunds_capabilities`, `refunds_provider_refund` | billing | Explicit actionable unsupported; never contact refund endpoint or claim money moved |

## Provider capability evidence

Official DuluPay documentation inspected during implementation:

- <https://api.dulupay.com/doc_old.html>: V1 `POST /api.php?act=refund` exists, requires merchant-side enablement, pid/key, order identity and money; successful refund uses **code 0**, unlike query's code 1. The documented refund interface has no merchant refund idempotency key, refund ID, refund-status lookup, or timeout reconciliation contract. A local MCP idempotency key alone cannot establish whether a timed-out provider request moved funds.
- <https://api.dulupay.com/doc/index.html>: V2 advertises RSA (`SHA256WithRSA`), different endpoints and refund support, requiring platform public key and merchant private key. This application currently supports V1 MD5 only. Indexed/extracted official content was available; direct browser access to V2 index returned 404. No invented V2 contract or upgrade is implemented.

Therefore **cash refunds are manual/provider-console-only in this integration**, not “DuluPay has no refund API.” Issue and verify the refund in the provider console, then record it locally with its unique external reference. Do not retry an uncertain monetary request. Local recording always reports `moneyMoved: false`, `subscriptionChanged: false`; GPT Plus subscription changes require separate explicitly authorized tools. No historic real customer order is reprocessed.

## Integration / transaction notes

- The canonical recovery service refuses to run inside an existing transaction. Its MCP operation must use the runner's external boundary so the canonical service retains its own payment mutex, transaction, fresh-provider verification, allocation and post-commit notifications. An uncertain external receipt must never automatically replay; query exact local/provider state first. Do not wrap canonical recovery in a second transaction or move notification dispatch before commit.
- Manual notification opt-in is after commit only. A queued/scheduled notification is not a delivery guarantee; there is no transactional outbox.
- Snapshots bind related configuration, historical content internally, and exact selected IDs without returning them as sensitive public output. Audit/receipt storage must not contain messages, delivery contents, lookup passwords, signing keys, gateway URLs or provider payloads.

## Implemented operation-to-test map

All test methods below are in `tests/Feature/McpOrdersPaymentsTest.php` (prefix `test_`). There are **23 unique operations**, programmatically enumerated from the three domain classes.

| Operation | Scope | Direct closure / service tests |
|---|---|---|
| `orders_list` | read | `order_reads_filters_totals_and_explicit_content_scope`, `date_filter_until_without_from_and_sort_allowlist_work`, `list_search_treats_wildcards_as_literal_text` |
| `orders_status_counts` | read | `order_reads_filters_totals_and_explicit_content_scope` |
| `orders_get` | orders | `order_reads_filters_totals_and_explicit_content_scope` |
| `orders_content` | orders:content | `catalog_scopes_and_no_unsafe_order_crud`, `order_reads_filters_totals_and_explicit_content_scope` |
| `orders_start_processing` | orders | `manual_single_and_bulk_preserve_payment_inventory_and_default_notifications_off` |
| `orders_complete` | orders | `manual_preview_is_silent_and_snapshots_cover_content`, `manual_single_and_bulk_preserve_payment_inventory_and_default_notifications_off`, `manual_guard_rejects_every_ineligible_state_and_zero_price_still_works`, `opt_in_notifications_are_after_commit_and_rollback_sends_nothing` |
| `orders_fail` | orders | `manual_single_and_bulk_preserve_payment_inventory_and_default_notifications_off` |
| `orders_bulk_complete` | orders | `bulk_reports_each_exact_id_skips_and_unchanged_without_reappending`, `bulk_duplicate_and_oversized_selections_are_rejected` |
| `orders_bulk_fail` | orders | `bulk_reports_each_exact_id_skips_and_unchanged_without_reappending` |
| `orders_resend_delivery` | orders | `resend_uses_only_historical_content_without_allocating_or_mutating_order`, `resend_guards_and_manual_template_selection` |
| `orders_notification_status` | orders | `notifications_have_durable_safe_status_and_failures_do_not_hide_committed_order` |
| `payments_list` | billing | `payment_metadata_never_discloses_secrets_and_legacy_is_read_only` |
| `payments_get` | billing | `payment_metadata_never_discloses_secrets_and_legacy_is_read_only` |
| `payments_create` | billing | `payment_create_update_preview_preserves_write_only_secrets_and_fixes_route` |
| `payments_update` | billing | `payment_create_update_preview_preserves_write_only_secrets_and_fixes_route`, `payment_and_refund_snapshots_bind_related_secret_and_ledger_state`, `gateway_configuration_rejects_markup_and_unallowlisted_credential_destinations` |
| `payments_query_status` | billing | `provider_query_is_read_only_sanitized_and_identity_checked`, `provider_transport_failures_are_redacted_and_recovery_dispatch_failure_is_reported` |
| `payments_callback_diagnostics` | billing | `callback_diagnostics_validates_signed_description_and_never_applies_payment` |
| `payments_recover_expired` | billing | `expired_recovery_preview_has_no_provider_calls_apply_queries_fresh_and_fulfils_once`, `expired_recovery_rejects_coupon_and_reused_archived_trade`, `provider_transport_failures_are_redacted_and_recovery_dispatch_failure_is_reported`, `manual_recovery_snapshot_binds_manual_templates_not_automatic_inventory`, `manual_recovery_reports_committed_pending_payment_when_notification_dispatch_fails` |
| `refunds_capabilities` | billing | `provider_cash_refund_is_explicitly_unsupported_not_false_success` |
| `refunds_list` | billing | `refund_migration_is_additive_and_local_record_moves_nothing`, `payment_and_refund_snapshots_bind_related_secret_and_ledger_state` |
| `refunds_get` | billing | `refund_migration_is_additive_and_local_record_moves_nothing` |
| `refunds_record_external` | billing | `refund_migration_is_additive_and_local_record_moves_nothing`, `refund_ref_collision_cap_currency_and_payment_evidence_are_enforced`, `payment_and_refund_snapshots_bind_related_secret_and_ledger_state`, `refund_boundaries_archived_history_and_reversible_additive_migration` |
| `refunds_provider_refund` (explicit unsupported) | billing | `provider_cash_refund_is_explicitly_unsupported_not_false_success` |

## Test evidence / integration boundary

- TDD initial RED: `McpOrdersPaymentsTest` failed on the absent OrdersDomain. Later RED tests caught missing durable notification status, unsafe gateway markup, and cross-database literal wildcard search; these were fixed and rerun.
- Domain run: **26 tests, 262 assertions, passing** with PHP 8.5.8 / PHPUnit 12.5.36, synthetic in-memory SQLite, real business services, fake queue and provider. Command: `.tools/php/php vendor/bin/phpunit --bootstrap /opt/data/cache/scratch/mcp-orders-bootstrap.php tests/Feature/McpOrdersPaymentsTest.php` with `APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_DRIVER=array SESSION_DRIVER=array`.
- At this contributor checkpoint the coordinator's `Operation`, `Input`, and `OperationException` were not yet on disk. The **temporary scratch bootstrap contains only contract carriers/pagination/ID validation**, never provider or business data. It conditionally defers to real core classes when available and is NOT a production/repository dependency. Run the test normally once core lands; HTTP scopes, preview tokens, stale fingerprints, receipts/replay and SDK transport are coordinator-owned and are **not claimed verified by these closure tests**.
- Regression run without scratch bootstrap: `.tools/php/php vendor/bin/phpunit tests/Feature/DuluPayRecoveryTest.php tests/Feature/FilamentAdminTest.php tests/Feature/BackendRegressionTest.php` under the same synthetic environment: **66 tests, 1657 assertions, passing**. Includes canonical signed descriptive name, merchant/method/status/amount/signature validation, archived trade binding, fresh recovery, coupon/state rejection, stock-shortage/idempotence, credential destination checks, HTTPS-before-signing, legacy backend/security suites, and existing Filament UI operations.
- MariaDB race harness was NOT executed locally: `docker info` reports no Docker daemon; no local `mariadbd`/`mysqld` executable was found. The refund order-row lock, unique reference hash and exact decimal cap need real MariaDB parallel verification in the coordinator's CI harness. No production data, notifications, cash refunds, deployments or commits were used.

## Additive persistence and handoff

- `2026_10_13_000003_create_shop_order_refunds.php`: immutable external-refund journal, globally unique provider/reference hash, original order/pay/trade IDs, exact decimal CNY amount and actor/time. Does not mutate orders or subscriptions. Recording uses an order row lock plus a unique constraint; duplicate reference/different order, amount or time fails closed.
- `2026_10_13_000004_create_shop_order_notifications.php`: safe metadata-only manual notification attempts. Apply schedules the existing OrderUpdated event after the owning transaction commits. The attempt ID is persisted in the MCP result; `orders_notification_status` distinguishes pending/submitted/indeterminate. A crash can leave pending; no automatic resend/retry and no claim of an outbox or guaranteed delivery. No recipient/body/message is stored in this table.
- Filament `PayResource::saveGateway` delegates to `PaymentGatewayConfiguration`; current blank/omitted credential behavior and legacy read-only restrictions retain passing UI coverage. MCP adds an exact deployment host allowlist (gateway URL supplied only), disallows userinfo/query/fragment/nonstandard port, and validates markup-free HTTPS URLs and merchant identifiers. Existing credentials are never returned.
- No order/payment archive/restore/delete endpoint was added because current UI denies them. Archived records remain readable and preserve history; archived orders may receive a local external refund record without modifying the order.
- `payments_recover_expired` and `orders_resend_delivery` are `external=true` for durable uncertain-before-attempt receipts. Recovery is **not** a generic external payment call: the canonical service retains its own transaction, payment mutex, provider verification and idempotent fulfilment. Do not wrap it inside the runner's transaction. Automatic recovery may legitimately finish in paid-abnormal status 6 for stock shortage; this is reported, not called completed. Manual recovery finishes in paid-pending status 2, with `deliveryComplete:false` and `manualAttentionRequired:true`: manual product stock/sales and pending/operator notifications follow the canonical path exactly once, without cards or actual recharge. `McpRecoveryTransportTest::test_reviewed_manual_recovery_over_http_records_pending_payment_without_allocating_and_replays_once` verifies the real HTTP preview/apply/receipt replay path.
