<?php

return [
    'allowed_origins' => array_values(array_filter(explode(',', env('SHOP_MCP_ORIGINS', 'https://shop.oknice.ca')))),
    // Exact operator-reviewed hosts. No user-writable network allowlist.
    'allowed_hosts' => array_values(array_filter(explode(',', env('SHOP_MCP_ALLOWED_HOSTS', 'api.dulupay.com')))),
    'max_body_bytes' => 8 * 1024 * 1024,
    'requests_per_minute' => 120,
];
