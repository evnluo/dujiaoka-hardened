# Shop MCP implementation verification

Task: `t_2c04e4f8`. Branch: `feature/shop-admin-mcp`, based on `bbad08900e1aecd385abea2c779a5df5e03b366c`. Implementation only; production was not accessed, no real customer notifications/payment mutations occurred, and nothing was published or deployed.

## Implemented coverage

73 semantic tools across catalog/categories/media, inventory, coupons, orders/delivery, payment configuration/query/recovery, external refund recording, settings, email templates/test email, and operational summaries/audit/receipts. Every existing scoped Filament action/field is inventoried in the three `shop-mcp-*-matrix.md` documents. `shop-mcp.md` is the operator/client guide, including exact scope names, bootstrap stdin interface, mutation protocol and limitations.

Official packages: `laravel/mcp` 1.x (locked by Composer), TypeScript `@modelcontextprotocol/sdk` 1.32.1 (locked in `tests/mcp-client/package-lock.json`). The HTTP endpoint is `/api/admin/mcp`. No custom protocol stack, local-only fake MCP server, arbitrary model dispatcher, SQL or shell tool.

## Final executed checks

| Check | Observed result |
|---|---|
| `php vendor/bin/phpunit tests/Feature` | 184 tests, 3,126 assertions, all passed |
| `php tests/security.php` | 30 security regressions, 0 failures |
| `node --test tests/turnstile-checkout.test.mjs` | 19 tests passed |
| `php tests/database-security.php` on real local MariaDB | 15 integration/concurrency cases, 0 failures |
| `php tests/admin-inventory-concurrency.php` on real local MariaDB | 48 inventory races, 0 failures with legacy snapshot semantics (see caveat) |
| `php tests/mcp-concurrency.php` on real local MariaDB | 3 cases passed: same-key replay, conflicting-key payload, cross-actor stale settings rejection |
| `php tests/mcp-cli-smoke.php` | Actual stdin CLI import/hash/revoke/invalid-scope rejection passed; no bearer printed |
| Official TypeScript SDK `tests/mcp-sdk-smoke.mjs` against real loopback HTTP | Initialize and paginated discovery: 73 unique tools; 11 preview/apply/replay mutation lifecycles passed, plus representative reads/output-schema validation; anonymous HTTP 401 |
| `pnpm run build` | Vite production build passed |
| `composer validate --no-check-publish` | Manifest valid |
| `sh -n tests/run-mcp.sh` | Passed |

Local runtime: aarch64 static PHP 8.5.8, local socket-only MariaDB 11.8.6, synthetic databases and localhost-only HTTP. No production mounts, customer data, credentials or external provider notifications were used. Provider/SMTP tests use controlled fakes; the SDK harness itself exercises actual Laravel HTTP and actual DB writes.

## Bugs reproduced and corrected during integration

- Auth/session fallback and HTTP-method boundaries: unauthenticated requests always fail, including unsupported verbs, and authenticated protocol errors remain distinct from authorization.
- Cross-actor settings race: a default repeatable-read view established by auth could outlive a waited-on settings lock. The deterministic MariaDB test failed before the fix, then passed with scoped READ COMMITTED and isolation restoration.
- External review boundary: a reserved receipt alone was not enough to bind mutable state at the eventual side effect. A failing regression now passes because the state guard runs inside the canonical settlement/mail capture transaction.
- Sensitive-read audit accountability: a regression failed until target IDs and a keyed payload hash were retained without card/message/credential content.
- SMTP test DNS pinning: transport regression failed before implementation; the final transport pins the validated public IP while retaining TLS peer identity. Bark and product hooks share public-IP pinning, verified TLS and no-redirect delivery.
- Official output schemas/structured results are tested by the actual SDK, not merely JSON parsing in service tests.

Domain tests include canonical inventory duplicate/lock behavior, sold/looping edits, safe archive/restore, exact decimals, historical delivery preservation, notification-off behavior, provider identity/amount/trade checks, expired-order recovery, insufficient-stock behavior, refunds versus money movement, image decode/re-encode, write-only settings and rejected URL targets. Existing storefront/admin/payment/Turnstile regression tests remain green.

## Honest limitations / downstream gates

- Cash refunds are explicitly unsupported without a verified provider contract; recording an externally completed refund does not move money. Turnstile environment updates and arbitrary legacy serialized-job retries are also explicit unsupported tools, not silent missing features.
- Email/queue submissions may become indeterminate across crashes. There is no transactional outbox, no inbox-delivery guarantee, and no blind external retry. Operations require receipt/local/provider inspection after uncertainty.
- Conservative outbound network policy supports public IPv4 and valid HTTPS/TLS only. Existing HTTP/private/self-signed Bark/product hooks need operator migration before enabling them; IPv6-only destinations are unsupported. SMTP test host changes require a deployment allowlist.
- The host Docker socket was inaccessible. Therefore local native container builds, Nginx/supervisor smoke, exact-head amd64+arm64 CI and immutable image publication were NOT claimed. `tests/run-mcp.sh` is wired into both native image-test jobs; the downstream release card must run and verify those exact-head jobs before publication/deployment.
- Local MariaDB 11.8 enables `innodb_snapshot_isolation` by default. The inherited inventory suite initially reported 25 `Record has changed since last read` failures under that different DB behavior. Re-running the isolated local instance with `innodb_snapshot_isolation=OFF` yielded 48/48 passes; no production DB setting was changed. The release card must validate on the repository's pinned MariaDB image and must not silently upgrade to different snapshot semantics.
- Independent release/security review is owned by pre-created child `t_d15ed125`; this report is implementation verification, not a claim of independent approval. Implementation did not change Hermes configuration or provision production MCP credentials.

## Reproduction

Local feature/security/build commands above use `.tools/php/php` in place of system `php`. Feature tests set `APP_ENV=testing`, a synthetic APP_KEY, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and array cache/session drivers.

For container CI: build the existing `security-test` image, then run `sh tests/run-mcp.sh <image>`. It provisions an isolated internal-network synthetic database, runs concurrent/CLI checks, resets fixtures, starts real PHP HTTP and runs the official client. The fixture bootstraps only `APP_ENV=testing`, `MCP_SYNTHETIC=1`, database `dujiaoka_mcp_test`.

Local socket reproduction uses DB_SOCKET pointing to the isolated fixture socket and the same named synthetic database. The production release path must use normal protected credentials and additive migrations, never these known test identities or fixture scripts.
