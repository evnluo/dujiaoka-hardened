# Content/image controls — release notes

## Changes

- Goods now have a `buy_prompt_enabled` boolean. New goods default off. Disabling it preserves editable HTML. All three storefront themes require both the flag and meaningful content; empty rich-text wrappers, nonbreaking spaces and zero-width characters produce no heading/modal/popup. Valid image-only prompts count as content.
- Product descriptions, purchase prompts and announcements share the installed Filament 5 image attachment dialog, alt text and proportional resize handles. Only JPEG/PNG/WebP, up to 4096 KB, are accepted. Random filenames are stored on the existing public `admin` disk in `images/rich-content`. No new mount or disk is needed.
- Attachment IDs are checked against persisted records. The settings editor explicitly derives its allowed IDs from the persisted notice because settings are not an Eloquent record. Uploads do not delete legacy files.
- Public authored HTML is sanitized at rendering, not rewritten in storage. Safe image widths and alt text survive; scripts, events, unsafe URLs and arbitrary inline image CSS do not. Images keep intrinsic ratio at narrow widths. Scope is authored content, not catalog crops, logos, CAPTCHA or QR images. The editor also overrides stale fixed image heights when its width is clamped on phones.
- Operational row actions use Filament outlined button components, including Edit, Add stock, Archive and Restore. Navigation still uses anchors where appropriate, but with actual button presentation, no hover/focus underline and native keyboard focus. No bulk-operation or payment behavior changed.
- Luna catalog JSON no longer includes rich descriptions, retained purchase prompts or integration data, and uses Laravel's HTML-safe JS serialization.

## Required migration / deployment order

1. Wait for the payment hotfix dependency `t_c29b15fc`. The release worker must integrate its completed hotfix/current main and verify the combined SHA. Do not deploy this branch over the payment fix.
2. Run exact-head native image CI (both architectures) and build assets on CI/nonproduction infrastructure. This implementation was verified locally using native ARM PHP 8.5.8 and the locked dependencies; it has not published an image or deployed production.
3. In the existing production Compose project only: enter maintenance, gracefully stop workers and check queues, then take a fresh protected database/settings/config/uploads backup. Preserve the existing environment and mounts. Do not reimport settings or create production build/test siblings.
4. Switch to the exact verified image and run only the additive migration, once:

   `php artisan migrate --force --path=database/migrations/2026_10_08_000001_add_buy_prompt_enabled_to_goods.php`

   Use the application user. The migration adds `goods.buy_prompt_enabled` with default false, then backfills meaningful existing prompts (including archived products) true in bounded ID chunks. It does not alter prompt HTML. Laravel records successful execution; verify migration status and column presence before reopening traffic. If DDL succeeds but the data backfill fails, remain in maintenance and reconcile the partial migration explicitly rather than blindly rerunning `up()`.
5. Pre-open application-user page/Blade smoke checks must occur after migration. Verify settings equality, persistent upload URLs, workers, image revision/digest and canonical storefront/admin HTTP before reopening.

## Rollback

Roll back the image/config to the appropriate prior release that includes the payment hotfix, never restore an old database over new orders. Leave the additive boolean column and uploaded files in place; old code ignores the column. Do not automatically run `migrate:rollback`: `down()` drops the flag and loses operator choices, although prompt HTML is preserved. An explicit later schema rollback requires maintenance and a fresh backup of flag values. Old UI code may show nonempty prompts regardless of the new flag, so assess that behavior before an image rollback.

## Verification

- TDD failures were observed for semantic-empty prompts, toggle persistence, migration/backfill, editor image configuration, announcement attachment reopening, sanitized rendering and native row-button presentation.
- Final full suite: 53 tests, 1262 assertions, all passing. This includes the existing bulk order/inventory/notification regressions and subprocess security/backend/dependency checks.
- Assets: `pnpm@12.6.0 run build` through npm exec succeeded. No dependency files changed.
- Actual browser: existing remote Camofox Firefox drove a local `/opt/data` synthetic app with its own SQLite/storage and no project environment, production data, live payment routes or mail transport. In each of the three editors, PNG bytes went through the native attachment dialog and real Livewire HTTP upload, alt text was entered, native resize-handle events changed dimensions, Save was invoked, and reopening retained public URLs and sizes. No HTML insertion workaround was used for these browser flows.
- Reopened synthetic sizes: description 360×120 from 1200×400; prompt 120×360 from 400×1200; notice 480×160 from 1200×400. The mobile editor initially exposed a real 290×120 distortion; the scoped CSS correction produced 290×96.67.
- Bounded desktop/mobile capture covered product list actions, editor, homepage notice and purchase page. Effective iframe viewports were measured at 1440 and 390 pixels (Camofox's outer viewport API does not change its spoofed layout width). All eight captures had zero document horizontal overflow and no failed resources. Independently scrollable tables retained their behavior.
- Actual `:hover` and keyboard `:focus-visible` were observed at both widths. Computed text decoration was `none`; keyboard focus had the native 2px ring. The purchase dialog close interaction completed with no captured JS errors/unhandled rejections. The browser service does not expose a full historical console buffer; failed-resource checks and the interaction error probe are the stated scope, not a claim of full historical console capture.
- Read-only production inspection was used for comparison. The confirmed distortion was the saved fixed-height/clamped-width case, reproduced on the synthetic narrow editor and covered by the shared public rendering correction. No live product records were edited.

The implementation task's attached evidence archive contains final screenshots, measured DOM results and the full-suite output. Production deployment/read-back belongs to the existing release child `t_d9601fcf`; this document does not claim the feature is live.
