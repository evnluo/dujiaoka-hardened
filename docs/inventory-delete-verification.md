# Inventory card permanent deletion — implementation handoff

Task: t_fc6ec56a
Base: ca15d5dc76d5c0740a7736dfad94ac3563e6cc6e (completed homepage release).
Scope: local implementation only. No production access, deletion, deployment, push, or migration.

## Behavior

- Filament inventory now has separate row and bulk Delete actions, including sold and archived cards. Confirmation states permanent deletion, affected count and irreversibility. Existing archive/restore rules are unchanged.
- Bulk confirmation freezes exact IDs in a Livewire locked property. Records added after select-all confirmation cannot enter the deletion, and client hydration cannot replace the reviewed IDs.
- New semantic MCP tool `inventory_cards_delete`: inventory scope, 1–1,000 distinct exact IDs, standard reason/default-preview/600-second binding/apply/idempotency/audit protocol. Preview/result contain IDs, count and permanent intent, never card contents. Current catalog has 74 tools; catalog/inventory/coupon subset has 35.
- Shared InventoryOperations deletion runs in one transaction, locks parent products in ascending order before current-reading/locking cards, rejects missing records/parents, and rejects the whole batch if any selected record is active unused cycling stock. Sold cycling records and archived records are eligible.
- Deletes only selected `carmis` rows, without model deletion callbacks or a sold-to-unused transition. The SQL schema has no card-to-order foreign key/cascade; `Carmis` only belongs to Goods. Fulfillment copies delivery text to `orders.info`. Orders, payment settings, delivered text and sales counters are unchanged by deletion.
- Permanent deletion removes the inventory row, including its participation in inventory duplicate detection. It does not keep a content tombstone. Existing delivery snapshots remain on orders.

## Verification

Executed locally with static PHP 8.5.8 and synthetic data:

- Full PHPUnit: 219 tests, 4,347 assertions, passing.
- New InventoryDeletionTest: 13 tests / 141 assertions passing. Actual Filament Livewire row/bulk mount/confirm/apply and actual MCP HTTP calls; sold deletion, archived combinations, retained active stock, historical orders/payments, redaction, auth/scope denial, missing/invalid/duplicate/over-limit selections, stale previews, stable replay/key collision, SQL delete failure and post-delete receipt failure rollback, select-all freeze and locked-property tampering.
- Real MariaDB inventory suite: 55 tests, zero failures. Seven new cases cover fulfillment→delete (fresh and old RR snapshot), delete→fulfillment, delete→delete (fresh and old snapshot), cycling-enable→delete (fresh and old snapshot). The harness observes actual InnoDB lock waits, not sleep-based assumptions. All existing inventory races passed too.
- Local socket-only MariaDB 11.8.6 used the repository's established local compatibility setting `innodb_snapshot_isolation=OFF`. No production DB configuration changed. Release must also run the pinned-container native CI gates.
- Payment security suite: 30 passed.
- Backend integration suite: 13 passed (expected sanitized callback-rejection warnings).
- Checkout JS suite: 19 passed.
- pnpm/Vite production build passed.
- All eight changed PHP files passed syntax checks; git diff --check passed.

Test-first evidence: the new MCP lifecycle failed before the tool existed; row and bulk action existence assertions failed before each UI action was added. A further regression first demonstrated that select-all confirmation could absorb a new row, then passed after exact IDs were frozen. Additional adversarial tests exercised the completed shared safety paths.

## Browser evidence

Actual Filament markup/assets and Livewire HTTP on a loopback-only synthetic server, Chromium at 1440×900 and 390×900:

- Row confirmation, bulk two-record confirmation, cancel, keyboard focus and protected-stock rejection exercised at both widths.
- Final runs had no page errors or HTTP error responses.
- Desktop modal width 448px; mobile modal width 358px with 16px side margins. Document scroll width equals viewport at both sizes. Mobile confirmation button height 44px; desktop uses existing compact 34px control height.
- Settled screenshots visually checked: opaque modal, full-viewport backdrop, readable count/warnings/buttons, no clipping. Earlier captures during transitions were replaced; final evidence waits for animations.
- Browser fixture rebuilds its in-memory inventory per HTTP request; deletion persistence/rollback is proven by the real Livewire/PHPUnit and MariaDB tests, not by those browser fixture resets.

Evidence under `.tools/inventory-delete/`: phpunit.log, concurrency.log, browser.json, single-1440.png, single-390.png, bulk-1440.png, bulk-390.png, protected-1440.png, protected-390.png. Synthetic router/browser probe retained for reproducibility; no real credentials or customer data.

Cleanup: both loopback HTTP and socket-only database servers stopped; owned synthetic database/session files and generated public Filament assets removed. Port 18775 is closed. Browser processes exit in the probe's finally block. Reproduction needs `filament:assets` from the synthetic bootstrap before running the browser probe.

## Review and release instructions

1. Independently review the local commit and run the full PHPUnit suite plus `tests/run-inventory-database.sh` against the pinned security-test image. Inspect the new concurrency cases and receipt rollback coverage.
2. No schema migration is introduced. Build/publish only the reviewed head through existing native amd64/arm64 CI, including MCP SDK/security/runtime gates, then deploy its immutable digest through the existing protected shop release workflow. Preserve settings, mounts, queues and scheduled jobs; follow the prior release's exact-head/digest readback rules.
3. Refresh MCP tool discovery and verify `inventory_cards_delete` and inventory scope. Use read-only production smoke; do not use real rows to test deletion.
4. Any later historical cleanup is separate authorization: freshly enumerate exact candidate IDs, privately compare contents, preserve active reusable stock, preview exact bounded selections, apply with stable keys, inspect receipts and verify exact readback. Historical sold counts alone do not prove duplicates.
5. Downstream compact-admin task t_79263d88 should preserve these actions, confirmation count/wording and locked selection behavior. This card requests same-card review; its implementation child remains dependency-gated until approval.

No independent review, native container CI, image publication or deployment is claimed by this implementation report.
