<?php
// Source-only contract; does not require an installed vendor tree or a database.
$composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = [
    'PHP 8.5 is the supported runtime' => ($composer['require']['php'] ?? null) === '^8.5',
    'Laravel 13 foundation' => ($composer['require']['laravel/framework'] ?? null) === '^13.0',
    'Filament 5 panel builder' => ($composer['require']['filament/filament'] ?? null) === '^5.0',
    'Dcat and unsupported inactive payment SDKs removed' => !array_intersect(array_keys($composer['require']), ['dcat/laravel-admin', 'dcat/easy-excel', 'fideloper/proxy', 'yansongda/pay', 'paypal/rest-api-sdk-php', 'amrshawky/laravel-currency', 'xhat/payjs-laravel', 'stripe/stripe-php']),
];
foreach ($checks as $name => $ok) {
    fwrite(STDOUT, ($ok ? 'PASS: ' : 'FAIL: ').$name.PHP_EOL);
}
exit(in_array(false, $checks, true) ? 1 : 0);
