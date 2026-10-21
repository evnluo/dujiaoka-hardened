# PHP 8.5.12 security runtime

This release fixes CVE-2026-109618 and CVE-2026-109619 by rebuilding PHP,
not by relaxing `memory_limit` or relying on configuration workarounds.
Application code, dependencies, runtime limits, FPM configuration and data are unchanged.

## Provenance

- Release: https://www.php.net/releases/index.php?json&version=8.5.12
- Source: https://www.php.net/distributions/php-8.5.12.tar.xz
- Source SHA256: `08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209`
- Advisories: https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w
  and https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp
- Upstream Alpine FPM recipe:
  https://github.com/docker-library/php/blob/f6048b5f6e3c2202ee082665a4ccc0d90de291a3/8.5/alpine3.23/fpm/Dockerfile
- Upstream recipe SHA256: `d4e9dc4ef64eb93b8d7b4beb9e20433174aab7c6a6fc731c557ef449d2e7abd4`

On 2026-10-21, both official `8.5.12-fpm-alpine` and
`8.5.12-fpm-alpine3.23` tags returned an empty OCI index
(`sha256:eb37f58646a901dc7727cf448cae36daaefaba79de33b5058dab79aa4c04aefb`).
That index cannot supply an amd64 or arm64 runtime.

The Dockerfile retains the prior immutable Alpine PHP base solely as a bootstrap.
`docker/build-php.sh` replaces its source archive, CLI, FPM, PHP tools and sodium
module from the checksum-verified 8.5.12 release before building any application
extensions. The new archive remains available to `docker-php-ext-install`.
The original upstream FPM configuration and Docker PHP helper scripts are preserved.
This is an application-built PHP runtime, not an assertion that the bootstrap digest
itself contains 8.5.12. Historical base layers still contain the superseded runtime.

The script preserves upstream compiler/linker hardening and configure options for
the two supported native architectures. It uses two compile jobs, the application's
build-provider URL, and the pinned SHA256 rather than a live keyserver dependency.
Build dependencies are removed using upstream's runtime-library scan pattern.

## Verification and publication

The existing `image.yml` workflow builds natively on `ubuntu-24.04` (amd64) and
`ubuntu-24.04-arm` (arm64). Dispatch it on the PR branch; it does not require a merge.
Each platform must pass runtime/extension checks, synthetic payment checks,
application/admin tests, MariaDB transaction and inventory races, MCP checks,
Nginx access boundaries and the real entrypoint before publication.

`tests/runtime-platform.php` rejects PHP older than 8.5.12 and checks the binary
against the declared source version. `tests/container-entrypoint.sh` checks an
actual Nginx-to-FPM request, FPM's binary version and supervisor process health.
All fixtures are disposable; no production data or financial operations are used.
The existing workflow publishes commit-specific tags and the combined immutable
manifest without changing package visibility. Deployment remains a separate,
independent review gate. No database migration or configuration workaround is needed.
