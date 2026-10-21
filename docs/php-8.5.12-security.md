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

### Redis extension source

PECL's Redis 6.3.0 download returned HTTP 504 during both protected-PR CI attempts.
The extension remains 6.3.0, built from the official `phpredis/phpredis` release:

- Annotated tag `6.3.0`: `aa4302d0e86d2cae3713cd91ff232efdafd799e9`
- Release commit: `df4fab2de7fc327c54c94a13af2b9542e4fbd720`
- Archive: https://codeload.github.com/phpredis/phpredis/tar.gz/df4fab2de7fc327c54c94a13af2b9542e4fbd720
- Archive SHA256: `79ecabd899e50a6efa56d9fc28a987a25d78deecc32fdd0fa9840b1b3d83740e`

The build verifies the archive before extraction, retains default optional serializer/
compression settings, checks the installed extension version, and removes sources
and build dependencies. No unverified mirror or floating release tag is used.

### Request-scoped media test

The successful small-image roundtrip test runs in a fresh PHP process with global
state preservation disabled. This prevents earlier feature tests' retained heap
from consuming the request's image budget. The test explicitly asserts the 128 MiB
limit; production limits and `AdminMediaService` remain unchanged. The existing
oversized-image memory-budget rejection test remains in the required suite.

## Verification and publication

The existing `image.yml` workflow builds natively on `ubuntu-24.04` (amd64) and
`ubuntu-24.04-arm` (arm64). Branch pushes trigger it before merge and attach checks
to the protected PR. Manual dispatch remains available, but a commit-only manual
run is not a substitute for the PR's required checks.
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
