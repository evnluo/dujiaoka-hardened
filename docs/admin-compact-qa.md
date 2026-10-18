# Compact shop admin — local QA handoff

Task: `t_79263d88`. Base: `a9bfd7dbf8d7d0f5c07034a8c82739f53347163f` (`feat/inventory-card-delete`). Implementation and local review only. No push, deployment, production login or production-data mutation.

## Scope and reference

Refined the existing Filament theme, keeping typography, semantic colors, factual copy, navigation, product grouping and every existing action. The dashboard remains the Orders workbench, not a new analytics screen. Only inventory preview presentation changed in PHP; permissions, payments, inventory transitions and APIs are unchanged.

Reference consulted: Stripe's actual [Web Dashboard documentation](https://docs.stripe.com/dashboard/basics) and [Dashboard search documentation](https://docs.stripe.com/dashboard/search). These describe operational navigation, transaction filtering, contextual tasks and search—not marketing-page styling. No authenticated Stripe screenshot or pixel-equivalence claim. Local initialized admin screenshots establish the visual baseline.

## Changes

- Shared 8/12/16px tight/field/section spacing roles; 48px topbar and 32px fine-pointer desktop navigation/actions.
- Remove the double layer of table action padding; keep labels and 13px table text, with 20px desktop line height. Tighten horizontal cell/action gaps so the laptop order table fits with all actions visible.
- Align section headers with fields; 16px body padding, 12px field-row gaps, left-aligned settings tabs and a 12px tab-to-panel gap. Product content/pricing/fulfillment and publishing rail remain in place.
- Preserve 44px mobile/coarse-pointer buttons, navigation, tabs and dropdown items; increase mobile text/select inputs from 36px to 44px. Tables and tabs scroll independently; mobile product names have room to wrap naturally instead of one character per line.
- Fix the inventory preview's inherited `white-space: pre-wrap`: it was rendering Blade indentation as blank lines. Only the escaped, 160-character-limited value now preserves whitespace. Four-line clamping and full copy/view/edit contents remain intact.

## Measured comparison

Counts are fully visible data rows in the initial viewport, excluding partially visible rows. Same synthetic first-viewport records and browser dimensions for each comparison.

| Viewport | Surface | Before rows | After rows | First-row height before → after |
|---|---|---:|---:|---|
| 1440×900 | Orders | 7 | 10 | 79px → 58.14px |
| 1366×768 | Orders | 4 | 8 | 103.28px → 58.14px |
| 390×844 | Orders | 3 | 7 | 119.28px → 62.14px |
| 1440×900 | Inventory | 3 | 11 | 213px → 57px |
| 1366×768 | Inventory | 2 | 9 | 213px → 57px |
| 390×844 | Inventory | 1 | 5 | 229px → 89px |

Other measurements:

- Topbar: 52px → 48px. Desktop sidebar rows: 34px → 32px. Mobile navigation remains 44px.
- Desktop product section header: `14px 24px` → `12px 16px` padding; 52px → 48px height.
- SMTP desktop content: 392px → 352px height; 24px → 12px row gaps; input height unchanged at 36px.
- SMTP mobile content: 756px → 720px despite input height increasing from 36px to 44px.
- Main text/secondary text/active navigation samples retain contrast above 4.5:1. Palette unchanged; this was a targeted contrast check, not a whole-application accessibility audit.

## Verification performed

- Baseline full PHPUnit: 219 tests, 4,347 assertions.
- Final full PHPUnit: 220 tests, 4,352 assertions.
- Focused inventory rendering/deletion: 15 tests, 234 assertions.
- Security suite: 30 tests, zero failures.
- Backend integration: 13 tests, zero failures.
- Checkout JS: 19 tests, zero failures.
- Vite production build passed. Final CSS: `theme-DSfGq-e1.css`.
- Changed PHP syntax, browser-script syntax, `git diff --check` passed.
- Impeccable final detector: no findings.

Browser: real Chromium + local PHP 8.5.8/Laravel/Livewire with in-memory synthetic business fixtures. Forty baseline captures; one after inspection, one consolidated correction, then 43 final captures at 1440×900, 1366×768 and 390×844. Final main matrix has zero page/HTTP errors, with no errors suppressed. Twenty additional route/viewport geometry checks cover Orders, Inventory, Product Edit and Settings at 320/640/768/1024/1280px with coarse-pointer emulation; no document overflow and visible buttons retain 44px targets. The 640/768px widths also cover the effective layout space of a zoomed laptop; browser zoom itself was not emulated.

Covered states: populated Orders and Inventory, empty search, filters, order details, product form and real server validation, bottom Save focus/reachability, settings/default and Mail tabs, contextual inventory menu, single/bulk permanent-delete confirmations, selection counts, cancellation, keyboard focus ring/trap, mobile navigation, long four-line card preview and full clipboard copy. Literal HTML-like card text remains text, not executable markup. Long inventory/pagination screenshot confirms bottom actions remain visible. Card 501 was extended with synthetic long content for the final stress check; it is below the initial viewport and does not affect the comparison above.

On mobile, the long filter panel continues below the fold in normal page flow. An additional DOM interaction verified the final Apply button is reachable by ordinary scrolling (44px high, fully within the viewport) and clickable; it is not clipped by an ancestor. Existing Filament behavior leaves the panel open after Apply. The table/tab horizontal scroll is intentional. Product field widths, separate SMTP test/global Save placement and existing helper copy were deliberately preserved rather than following unrelated screenshot-review suggestions.

Persistence, deletion guards and notification behavior are verified by PHPUnit, not by the browser's per-request reset database. Browser upload submission was not exercised. No business-service changes required another MariaDB race run; the predecessor's 55-case race verification is recorded on `t_fc6ec56a`, not claimed as rerun here. No native-image or deployed-release verification was performed in this local-only task.

## Evidence

Under `/opt/data/projects/dujiaoka-filament/.tools/admin-compact/`:

- `before/`: baseline screenshots and `results.json`.
- `confirm/`: final screenshots, `results.json`, and `boundaries.json`.
- `final-probe.json`: filter scroll/reachability and computed color samples.
- `phpunit.log`, `security.log`, `backend.log`, `checkout.log`.
- `orders-comparison.png`, `inventory-comparison.png`, `mobile-comparison.png`: before on left, after on right.
- `evidence.zip`: downloadable report, comparison images, all baseline/final captures and metrics; open `index.html` after extracting.

## Reproduce locally

Dependencies: installed Composer/vendor dependencies, project-pinned pnpm, PHP 8.5, a local Chromium binary and Playwright Core. No production credentials are needed or read.

```sh
pnpm run build
php -r 'require "vendor/autoload.php"; $bootstrap = new class { use \Tests\CreatesApplication; }; $bootstrap->createApplication(); Illuminate\Support\Facades\Artisan::call("filament:assets");'
mkdir -p .tools/admin-compact/sessions
SHOP_LAYOUT_QA=1 php -S 127.0.0.1:18776 -t public tests/fixtures/admin-layout-router.php
```

In a second terminal, set `PLAYWRIGHT_MODULE` to the installed module (or leave unset for normal module resolution), and `CHROMIUM_PATH` to the browser executable:

```sh
QA_PHASE=confirm node tests/admin-layout.browser.mjs
php vendor/bin/phpunit
php tests/security.php
php tests/backend.php
node --test tests/turnstile-checkout.test.mjs
git diff --check
```

The test router refuses non-CLI-server requests, requires `SHOP_LAYOUT_QA=1`, and rejects non-loopback clients. Its schema bootstrap refuses non-isolated databases. It does not belong in production routing. Stop the owned loopback server and remove its synthetic session directory after QA. Published local Filament assets are generated verification output, not commit contents.

Cleanup completed for this run: the owned loopback listener was stopped and port closure verified; generated Filament assets and synthetic session files were removed. Evidence remains under the ignored `.tools/admin-compact/` directory.

## Review and future release boundary

This task hands off a local commit for same-card review. No duplicate task graph was created. The parent inventory-delete implementation is preserved in ancestry. Before any future publication, integrate current main, including the already deployed footer/hover commit `ca4dfe0b706f2dfaedb9c9a32402d8de7aab89c6` or its successor; this local branch predates that separate release. Re-run exact combined-head native amd64/arm64 image gates and deploy only an independently reviewed immutable digest through the existing workflow.
