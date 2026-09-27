<?php

return [
    'default' => env('MAIL_MAILER', env('MAIL_DRIVER', 'smtp')),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_ENCRYPTION') === 'ssl' ? 'smtps' : 'smtp',
            'host' => env('MAIL_HOST', 'localhost'),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'require_tls' => env('MAIL_ENCRYPTION', 'tls') === 'tls',
            'timeout' => 20,
        ],
        'sendmail' => ['transport' => 'sendmail', 'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i')],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS'), 'name' => env('MAIL_FROM_NAME', 'Dujiaoka')],
];
