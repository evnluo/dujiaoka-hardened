# Settings / templates / operations parity inventory

Source inventory completed before implementation: `app/Filament/Pages/Settings.php` (all tabs/actions), `EmailtplResource.php` and its Manage page, `OrderQueue.php`, existing jobs, `ShopSettings`, `Turnstile`, safe local console commands.

| Existing operation/fields | MCP | Scope | Test |
|---|---|---|---|
| Shop title/text_logo/keywords/description/template/language/img_logo | settings_get / settings_update | settings | McpSettingsTest lifecycle + validation |
| Announcement notice and footer HTML | settings_get / settings_update (sanitized, no scripts) | settings | XSS test |
| manage_email/order_expire_time/search password/anti-red/Google translate toggles | settings_get / settings_update | settings | full fields |
| Legacy image captcha/geetest enabled + ID + write-only key; preserve dormant settings when Turnstile on | settings_get / settings_update | settings | Turnstile preservation |
| SMTP driver/host/port/username/encryption/from address/name/password | settings_get / settings_update (password write-only, exact deployment host allowlist for changes) | settings | redaction / host rejection |
| Telegram enable/userid/token, Server Jiang enable/token, Bark enable/URL switch/server/token, WeChat enable/key | settings_get / settings_update (all tokens write-only) | settings | full allowlist / omit preservation |
| Environment-owned Turnstile enabled/configured/site/hostname metadata | settings_turnstile_status | settings | no secret returned |
| Turnstile change | settings_turnstile_update returns environment_managed; no UI writable fields exist | settings | explicit unsupported, preserves env |
| Explicit saved SMTP test email recipient/subject/body | email_send_test (external receipt; no blind resend on uncertainty) | settings | fake-mail lifecycle and replay |
| Template list/search/get/create/update, immutable tpl_token, HTML source | email_templates_list/get/create/update | settings | template lifecycle |
| Template archive/restore/delete | Not exposed: existing UI prohibits these | — | source parity |
| Order queue counts | orders_status_counts (orders domain) | read | cross-domain tests |
| Sales summaries by bounded date interval, completed/paid distinction/refunds separately | operations_sales_summary | read | exact decimal aggregation |
| Automatic/manual low stock incl published filter | operations_low_stock | read | computed stock |
| Failed queue records / pending queue counts | operations_queue_status / operations_failed_jobs | operations | no serialized payload/exception/email leakage |
| Failed exact-job retry | operations_retry_job: actionable unsupported; legacy jobs do not carry trustworthy duplicate-safe external receipts. Never deserialize or requeue arbitrary serialized payload. Use explicit order resend only after inspecting uncertain delivery. | operations | no dispatch negative test |
| Action history + exact receipt lookup | operations_audit / operations_receipt | operations | scoped history and cross-scope receipt denial |
| Token import/rotation/revocation | Local-only shop:mcp-token CLI; not remotely exposed | operator | CLI tests |
| Import legacy settings / shell / raw SQL | Intentionally not remotely exposed; migration/bootstrap operator commands are not shop CRUD | — | tool catalog |

Network policy: MCP cannot alter deployment allowlists. Existing SMTP settings are read internally but new hosts must be in `SHOP_MCP_ALLOWED_HOSTS`; URLs require HTTPS and no userinfo/fragments/nondefault ports. Body text, recipient, secrets and signed URLs are never in audit/preview/result receipts. Settings public reads expose only safe UI fields plus secret-presence booleans; URL fields may contain credentials and are presence-only. All descriptive content is untrusted data.
