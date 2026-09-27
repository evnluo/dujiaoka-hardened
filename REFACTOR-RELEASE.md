# Filament refactor — release contract

## Scope

Replace Dcat with the full Filament core admin suite, modernize PHP/Laravel, and deploy the completed refactor to shop.oknice.ca. The admin uses the user-approved compact, neutral operations interface documented in PRODUCT.md and .impeccable/surfaces/app-filament.md. Keep the storefront identity and working customer purchase flow. Retaining old admin compatibility is not required.

## Observed production constraints (read-only)

- Current release f274682c1a6da77f2ff04eacbb267863447b0c07, PHP7.4.33/Laravel6.20.45.
- MariaDB10.7.3, Redis queue and cache, one administrator.
- Admin path /evansadmin; storefront template hyper.
- Active payment path: Yipay only. Other gateway records exist but are disabled.
- Image CAPTCHA enabled; Geetest disabled; lookup password disabled.
- System settings exist only in the Redis system-setting cache (37 keys), while admin_settings table is empty. Preserve/export the legacy settings on-host before cutover; never print values or commit them.
- Runtime mounts: .env, storage, uploads, favicon variants and default product image. Preserve these.

## Gates

1. Resolve supported dependencies with Dcat removed; boot and publish Filament assets with no production config.
2. Retain focused callback/payment and real MariaDB concurrency tests. Add real Laravel/admin/checkout checks with synthetic fixtures. Verify denied unauthorized access and safe secret handling.
3. Build the source-only image with compiled custom theme. Test real container startup and PHP/upload access boundaries.
4. One bounded UI check, with desktop/mobile layout evidence and material fixes only. One security/correctness review for payment/auth/data changes; no repeated review loop.
5. Publish verified commit via native amd64/arm64 builds; read back immutable digest anonymously.
6. Quiesce shop briefly; host-local protected backups of database, config, legacy settings and queue recovery state as needed. Apply only explicit reversible data/schema operations. Do not flush shared Redis or mutate genuine orders for testing.
7. Deploy exact digest, read back versions/image/processes, smoke-check actual ingress and admin entry. On failure roll back application; reverse only known migration changes while shop remains quiescent. Never blindly restore an old database over newly accepted orders.

## Runtime build ownership

Parent owns Dockerfile, docker/, .dockerignore, image workflow, runtime/HTTP tests, remote build/deployment helpers. Core worker owns application/dependencies/migrations; admin worker owns Filament provider/resources/theme/assets package setup. Shared worktree: /opt/data/projects/dujiaoka-filament. No superseded PHP8.5 migration branch changes are accepted without verification.
