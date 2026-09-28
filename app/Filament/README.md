# Filament administration

## Integration

- Register `App\Providers\Filament\AdminPanelProvider` in application providers. It uses the `admin` guard and `App\Models\AdminUser::canAccessPanel()`; only the existing `administrator` role is allowed.
- Preserve `ADMIN_ROUTE_PREFIX=evansadmin` in the deployment environment. The panel reads `config('admin.route.prefix')`; its home redirects to orders, not a dashboard.
- Keep the existing `admin` filesystem disk for product images and logo uploads. Card imports use private temporary storage, not the public uploads directory.
- Keep `App\Support\ShopSettings` registered/resolvable. Settings load only an explicit non-secret allowlist into Livewire. Blank secret replacements preserve saved values. Import legacy cached settings before removing the old runtime/cache.
- Build after Composer dependencies exist: `corepack enable`, `pnpm install --frozen-lockfile`, `pnpm build`. The pinned package manager is in `package.json`. The theme imports Filament's installed CSS and scans `app/Filament` and `resources/views/filament`.
- Publish Filament's runtime JS assets with `php artisan filament:assets` during image construction. Ship `public/build/manifest.json` and its assets along with those published assets. No vendor files are modified.

## Workflow and safety

- Orders expose payment evidence separately from delivery state. There is no create-order, delete-order, mark-paid, fake payment, or automatic-order state override action.
- Only manual orders in pending/processing states with an existing transaction reference or an exact zero total can progress. Completion/failure requires customer-visible details; these are not private staff notes. Completion defaults to silent bookkeeping: status and details persist without customer email, push, webhook or `OrderUpdated`. The explicit “通知客户” toggle opts into the existing status email after commit. Repeated completion is rejected without a second event/job. Failure retains its explicitly labeled notification and does not mean refund. After-commit enqueueing is not a transactional outbox or an exactly-once email transport guarantee.
- Product archival deliberately suppresses the legacy cascade-delete event so card history remains intact. Nonempty categories cannot be archived, including categories containing archived products.
- Ordinary product edits never overwrite manual stock. Use the atomic "补充库存" action. Card stock is computed from unsold rows.
- Inventory import handles UTF-8, CRLF/LF, blank lines and duplicates, including sold/archived duplicates. Limits: 5 MB, 10,000 lines. One-time delivery is default; reusable content requires explicitly enabling looping. Administrators can edit all active cards, including sold and looping cards. Content edits retain sales/loop status, product association and already-delivered order details, and never notify customers. Product-level locks and current-read duplicate checks serialize edits with imports. Archive/restore remains limited to unsold, non-looping stock; archived records remain viewable, not editable, and history is never deleted.
- Card content is intentionally visible in the administrator-only inventory table, with bounded multiline previews, full-content copy, full read-only viewing and prefilled editing, without password reconfirmation. Downloads still require rate-limited password verification. Order details, lookup passwords, gateway URL/signing key and SMTP/push secrets remain protected and are not prefilled into replacement inputs.
- Only `/pay/yipay` gateways can be created/configured. Other payment rows remain read-only history. Existing channel identifiers cannot change, preserving callback association.
- Coupon availability is incremented atomically rather than replaced during edits. Coupon codes and email-template tokens become immutable after creation. Email HTML is edited as source, not rendered in an unsafe preview.

## Verification

`tests/Feature/FilamentAdminTest.php` uses the synthetic `tests/fixtures/business-schema.php` and in-memory SQLite only. Run with a throwaway testing APP_KEY, `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_DRIVER=array`, `SESSION_DRIVER=array`, and `ADMIN_ROUTE_PREFIX=evansadmin`:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Feature/FilamentAdminTest.php
```

Implemented verification covers page renders, administrator authorization (including hydration after access revocation), username login, CRUD forms, coupon relationships, protected configuration-secret state, visible/copyable inventory and sold/loop edits, blank-secret preservation, card import/archive safety, password-gated downloads, silent/opted-in completion through the real event listener and queue, post-commit/rollback/retry behavior, safe order transitions, stock/history preservation, and category/product archival behavior. `tests/admin-inventory-concurrency.php` additionally exercises import/edit races for each sales/loop state with fresh and stale repeatable-read snapshots on disposable MariaDB.

Browser/mobile visual verification and deployed asset/queue checks remain integration responsibilities. SMTP testing intentionally sends a real email only when the operator submits the explicit test action; a successful notification means server acceptance, not guaranteed delivery.
