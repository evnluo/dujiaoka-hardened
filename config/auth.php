<?php

return [
    'defaults' => ['guard' => 'admin', 'passwords' => 'admins'],
    'guards' => [
        'admin' => ['driver' => 'session', 'provider' => 'admins'],
    ],
    'providers' => [
        'admins' => ['driver' => 'eloquent', 'model' => App\Models\AdminUser::class],
    ],
    // Administrators use the existing username/password records; no public signup or reset routes.
    'passwords' => [],
    'password_timeout' => 10800,
];
