<?php

// Preserve the existing deployment URL without retaining the old admin framework.
return [
    'name' => '独角数卡',
    'route' => [
        'domain' => env('ADMIN_ROUTE_DOMAIN'),
        'prefix' => env('ADMIN_ROUTE_PREFIX', 'admin'),
    ],
];
