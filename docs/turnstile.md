# Checkout verification

Turnstile is a server-configured, exclusive checkout provider in all three storefront themes. Set these names together in the existing protected runtime environment file, not in source, an image, or the admin form:

- `TURNSTILE_ENABLED=true`
- `TURNSTILE_SITE_KEY` — public widget key
- `TURNSTILE_SECRET_KEY` — server-only secret
- `TURNSTILE_HOSTNAME` — exact public hostname, without scheme or path

Use a Cloudflare Managed or visible Non-Interactive widget with the shop hostname allowed. The UI always displays a full-size flexible widget; Managed may complete without a checkbox click. Invisible widgets are not supported by the intended checkout experience.

Activate only after verifying the candidate image and both keys. Restart the application using its deployment supervisor so the environment is reloaded. Incomplete enabled configuration blocks orders: it never switches to an image challenge or disables verification. Official dummy keys are rejected outside local/testing. When explicitly disabled, the existing image/Geetest settings apply unchanged; while enabled, the admin shows provider status and hides their dormant controls. Existing legacy values are retained for image/config rollback.

The server sends each bounded token once to Cloudflare Siteverify, with a two-second connect and six-second total timeout. HTTP/JSON failures, expired/replayed tokens, non-boolean success, wrong hostname or any action other than `create_order` are rejected before order writes. No forwarded IP is sent. No token cache or automatic verification retry exists. CSRF, rate limits and all payment/stock checks remain unchanged.

Checkout uses JSON responses while enabled. Failures remain inline with all customer fields untouched; the widget resets and the submit button stays disabled until another token is ready. A network failure is ambiguous: an order may have been accepted before the response was lost. The UI does not automatically resubmit.

Tests: `php vendor/bin/phpunit` and `node --test tests/turnstile-checkout.test.mjs`. Server integration tests fake only the external provider and run the actual order route against an isolated database. Official visible test widgets can be used on nonproduction pages. Cloudflare's dummy Siteverify response does not guarantee the production action/hostname contract, so do not weaken those checks to make dummy keys pass. Never use dummy keys on the live shop or create real customer orders as release probes.

Existing storefront CSP only upgrades insecure requests; no CSP wildcard or relaxed policy is added. The only new external script/frame origin is `https://challenges.cloudflare.com`. A deployment that adds restrictive CSP must allow that origin for script and frame sources.
