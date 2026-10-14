# Shop Admin MCP

## Endpoint and access

`POST https://<shop>/api/admin/mcp` is the official `laravel/mcp` Streamable HTTP transport. It is stateless (no MCP session persistence, SSE subscription or DELETE session lifecycle); authenticated GET returns 405, unsupported methods return 405, and unauthenticated requests of every method return 401. Initialize normally, then paginate `tools/list`; there are currently **73 semantic tools**. The official TypeScript SDK smoke exercises real initialize/list/call behavior, not local tool stubs.

Bearer authentication is mandatory; an admin browser session is never accepted. Revoked/expired tokens, removed admins and loss of the `administrator` role deny access. Authorization is rechecked at tool invocation and mutation execution. Scopes are exact: `read`, `catalog`, `inventory`, `inventory:content`, `orders`, `orders:content`, `billing`, `settings`, `operations`. No wildcard, credential-management tool or arbitrary model/action dispatcher exists. Content scopes are deliberately separate: card contents remain viewable/editable, including sold/looping cards, without rewriting historical deliveries.

Apply the additive migrations with the normal deployment process before enabling MCP. They create token, preview, receipt, audit, external-refund-record and manual-notification-attempt tables; no existing order/card/payment/customer table is replaced. No tokens exist after migration. Existing storefront/admin authentication remains unchanged.

### CLI bootstrap and revocation

Run `php artisan shop:mcp-token import --admin=<existing-admin> --name=<client-label> --scopes=read,catalog --days=30` with a JSON object on standard input containing `password` (the existing administrator password) and `token` (a newly generated, password-manager-held 32-byte random value encoded as 64 hexadecimal characters). Never put either value in arguments, shell history, reports or a checked-in client config. Import verifies the admin password/role, validates every scope and stores only SHA-256(token). Output contains only the record ID and scopes.

Run `php artisan shop:mcp-token revoke --admin=<existing-admin> --id=<record-id>` with `{ "password": "<secret supplied via protected stdin>" }` on stdin. There is intentionally no CLI token export or agent tool for creating credentials. The CLI smoke uses only disposable synthetic identities and verifies import/hash/revoke/rejection through the actual subprocess.

Client configuration: use Streamable HTTP, the HTTPS endpoint above and `Authorization: Bearer <credential resolved by the client>`. Do not copy the real bearer into this document. Cookie auth and URL query tokens do not work. `Origin`, if present, must exactly match a comma-separated `SHOP_MCP_ORIGINS` entry; native clients without Origin work normally. Configure TLS at the existing proxy. The default limit is 120 authenticated requests/minute per token, with an 8 MiB request-body bound; inventory imports and images have tighter field-level limits.

### Outbound destinations

`SHOP_MCP_ALLOWED_HOSTS` is a deployment-owned comma-separated list of explicitly approved DNS hostnames for changing SMTP/Bark/product callbacks; never let a tool write the allowlist. Literal-IP targets are rejected. Existing payment gateways are not generic provider-query URLs: verified recovery/query uses only the fixed DuluPay endpoint and configured gateway credentials. Product callback and Bark delivery now reject private DNS answers at delivery, pin public IPv4 connections, verify TLS and refuse redirects. SMTP tests similarly pin a public address while retaining the configured TLS peer hostname and requiring STARTTLS when selected. Changing legacy HTTP/private/self-signed Bark or product hooks requires operator migration to a reviewed public HTTPS endpoint; there is no silent unsafe fallback. IPv6-only destinations are currently unsupported by this conservative outbound policy.

## Mutation protocol

1. Call a mutation with its full payload and a meaningful `reason`. `dryRun` defaults to `true`; preview writes only operational metadata, never business data or customer notifications.
2. Inspect the returned `plan`, target IDs, field names, eligibility and skip reasons. Secrets, cards, delivery messages and email bodies are not echoed in the plan.
3. Within 600 seconds, repeat the identical payload/reason with `dryRun:false`, the returned `previewToken`, and a unique `idempotencyKey` (16–100 allowed characters).
4. Store `operationId` and inspect `result`. Within an actor/scope/tool, the same key/payload replays the durable receipt without another effect; key reuse with a different payload is an explicit conflict. Initial application requires the preview's exact client token. Another authorized token for the same administrator can replay an existing safe receipt, but cannot use the original token's preview to perform a new effect. A stale/expired/cross-client preview requires a fresh review, not an override.

Preview fingerprints are keyed HMACs over canonical payload and relevant state, including related rows/config and protected fields. Only hashes are persisted. Writes take deterministic domain locks and run at READ COMMITTED on MariaDB, restoring the prior connection isolation afterward: an auth read must not freeze a repeatable-read snapshot before a waited-on business lock. Ambient transactions are rejected. Independent parallel tests cover same-key replay, key collision and cross-actor stale settings updates.

External submissions reserve a durable `indeterminate` receipt before any effect. The reviewed state is checked again inside the canonical transaction immediately before capturing the immutable mail payload or verifying/settling payment. A crash/timeout must not trigger a blind retry: inspect `operations_receipt`, local payment state and provider status first. External receipt ambiguity is intentional; there is no exactly-once email guarantee or transactional outbox. Successful manual order mutation receipts remain successful if after-commit notification dispatch fails, with a separate notification-attempt status.

Returned data is marked untrusted. Storefront HTML, order notes, template text and card contents are data, not instructions to the client. Sensitive reads require explicit content scopes, and their bodies are never written into audit records. The tool adapter exposes JSON input/output schemas plus mirrored structured/text results for standard clients. Errors return sanitized machine codes rather than SQL, provider bodies, SMTP URLs or stack traces.

## Scope and parity

The pre-implementation inventories are the detailed acceptance references:

- [Catalog, categories, media, inventory and coupons](shop-mcp-catalog-matrix.md).
- [Orders, delivery, payment settings/query/recovery and refunds](shop-mcp-orders-matrix.md).
- [All settings groups, email templates/test mail and operations](shop-mcp-settings-matrix.md).

Read pagination is 25 by default, maximum 100. Exact-ID exports/bulk selections are capped at 1,000; inventory text imports retain the canonical 10,000-card/5 MB ceiling. Search/status/archive inputs are allowlisted, not arbitrary query fragments. Media uploads decode/re-encode allowlisted raster types, enforce byte/dimension limits and return local storage paths/URLs. Uploaded rich images preserve reviewed widths.

Intentional explicit rejections, not simulated support:

- `payments_recover_expired`: at most 1,000 cards per order. Larger legacy orders return `recovery_quantity_exceeds_review_limit` before any preview/provider call and need protected local operator reconciliation. Never split an order or silently truncate reviewed stock.
- Template writes retain safe HTML tables/formatting/placeholders, but remove executable markup and inline styles. Existing templates are not rewritten unless submitted.
- `refunds_provider_refund`: no trusted refund API/credential contract is present. `refunds_record_external` records an already-completed external refund, caps cumulative refund value, and never moves money or changes delivery/payment state.
- `settings_turnstile_update`: server-owned environment configuration is read-only through MCP. Read configured/enabled metadata with `settings_turnstile_status`; change environment through the deployment path.
- `operations_retry_job`: arbitrary historical serialized jobs cannot prove duplicate-safety. Failed-job metadata is readable, but payloads/exceptions are not exposed or deserialized. Use the explicit reviewed resend operation where appropriate.
- Legacy payment routes remain read-only; only supported YiPay configuration can be created/edited. Card/product/coupon deletion uses safe archive/restore rather than destructive deletion. No credential administration, arbitrary shell/SQL/URLs, app restarts or environment writer.

## Verification and deployment handoff

`tests/run-mcp.sh <security-test-image>` runs isolated MariaDB concurrency, actual CLI bootstrap, and the official SDK against a real HTTP server on an internal Docker network. It is wired into both existing native amd64/arm64 image-test jobs. The synthetic database is named `dujiaoka_mcp_test` and fixture creation refuses other names/environments. Nothing contacts production or a real customer/provider.

Local evidence for this implementation is in [shop-mcp-verification.md](shop-mcp-verification.md). Image publication, exact-head CI, deployment, production migrations and production MCP credential provisioning belong to the downstream release task; none were performed by implementation.
