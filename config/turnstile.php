<?php

return [
    // Enabled only by an explicit, atomic server configuration change. Never fall back on errors.
    'enabled' => env('TURNSTILE_ENABLED', false),
    'site_key' => env('TURNSTILE_SITE_KEY', ''),
    'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    'hostname' => env('TURNSTILE_HOSTNAME', parse_url(env('APP_URL', ''), PHP_URL_HOST) ?: ''),
];
