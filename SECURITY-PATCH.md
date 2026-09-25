# Payment-verification patch and container delivery

This fork preserves the Oknice storefront customizations and patches the legacy Dujiaoka Yipay callback and fulfilment path. It is **not a complete security audit or a supported-PHP upgrade**.

## Scope

- Require string-shaped callback fields, an MD5 signature of the expected shape, constant-time signature comparison, and successful trade status.
- Bind merchant ID, payment method, and signed product name to the stored order/gateway. Only enabled Yipay gateways are accepted.
- Reject malformed/sub-cent amounts and validate the amount against the locked order before delivering.
- Lock the order and selected stock rows inside the transaction. A retry with the same amount and provider transaction acknowledges an already-paid order without repeating fulfilment.
- Expiration uses an atomic prior-state condition; a lost expiration/payment race does not return a coupon or overwrite paid status.
- Fulfilment collects mail and model events locally and submits them only after commit. Rollback/commit failure submits no delivery. Ambient transactions are rejected to avoid an early dispatch before an outer commit.
- The image serves PHP only through Laravel's `index.php`; uploaded PHP files cannot execute via Nginx. Hidden files are denied.

The failing baseline is demonstrable in a disposable PHP 7.4.26 container: a JSON boolean signature is accepted by the old loose comparison; a correctly signed non-success trade is also accepted. These are verified vulnerabilities, **not proof of which route caused any particular historical incident**.

## Verification

```sh
docker build --target security-test -t dujiaoka-security-test .
docker run --rm --network none dujiaoka-security-test
sh tests/run-database.sh dujiaoka-security-test
```

The first suite executes real controllers/services with synthetic dependencies at external seams. The MariaDB suite uses real transactions and parallel PHP processes with synthetic orders/stock, a disposable database on an internal Docker network, and intercepted notifications. Neither suite uses production credentials or stock.

## Publishing

GitHub Actions runs both suites before publishing `ghcr.io/evnluo/dujiaoka-hardened:sha-<full-git-sha>` for amd64 and arm64. Production should pin the returned image digest, not `latest`. GHCR package visibility is configured independently of repository visibility; verify an unauthenticated pull before declaring public delivery complete.

The build context admits only app/public/resources/docker/tests. No deployment `.env`, database dump, customer data, uploads, sessions or private keys belong in the repository or image.

## Deployment and rollback

1. Preserve a restricted backup of the existing Compose configuration and environment file. Retain the original source folders and image ID. Back up the database using its existing trusted credentials without printing them.
2. Pull the tested image by digest before changing the service.
3. Replace only the application image and remove the broad `/dujiaoka/app`, `/dujiaoka/public`, and `/dujiaoka/resources` source bind mounts. Those customizations are already in the image.
4. Keep the existing database, Redis, `.env`, storage, uploads and branding-file mounts unchanged, and preserve the loopback-only port mapping.
5. Recreate only the application service (`docker compose up -d --no-deps faka`). Verify PHP workers, storefront, effective code hashes, image revision/digest, and mount list. Do not submit synthetic payment callbacks against production.
6. Roll back by restoring the saved Compose file and recreating only `faka`. No database schema migration is required for this patch.

## Known limitations

- The pinned upstream base still contains legacy PHP 7.4, Laravel 6 and dependencies. This release closes specific application defects; runtime modernization remains separate work.
- A callback arriving after an order is marked expired is rejected rather than reviving an order whose coupon may have been returned. Real payments in that state require operator reconciliation/refund; do not silently mark them paid.
- Disabling a gateway or rotating its signing key while payments are in flight can reject legitimate callbacks. Keep the old configuration until in-flight payments settle; reconcile rejected paid orders with the provider and refund or fulfil manually only after independent payment verification. Never bypass callback validation.
- Notifications are not backed by a transactional outbox. A notification failure after commit does not undo stock delivery, and an idempotent callback retry does not replay all notification side effects.
- Only the active Yipay integration is the callback hardening scope. Other payment integrations have not been comprehensively audited.
- Existing public order-number lookup semantics and administrative access remain unchanged.
- The application's stored amount remains the authority. These changes do not establish that older orders were legitimate or repair past compromise.

## Provenance

Application: assimon/dujiaoka. Runtime image: Apocalypsor/dujiaoka-docker, pinned in the Dockerfile. Storefront customizations: Evan's existing tracked deployment at commit `3fe4437`. Upstream licenses and attribution are retained.
