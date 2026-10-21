<?php
require __DIR__.'/../vendor/autoload.php';
$checks = [
    'PHP 8.5 runtime with CVE-2026-109618 / CVE-2026-109619 fixes' => PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 && PHP_VERSION_ID >= 80512,
    'runtime matches declared source version' => PHP_VERSION === getenv('PHP_VERSION'),
    'Laravel 13 framework' => str_starts_with(Illuminate\Foundation\Application::VERSION, '13.'),
    'Filament 5 panel builder' => str_starts_with(ltrim(Composer\InstalledVersions::getPrettyVersion('filament/filament'), 'v'), '5.'),
    'Dcat removed' => !Composer\InstalledVersions::isInstalled('dcat/laravel-admin') && !Composer\InstalledVersions::isInstalled('dcat-x/laravel-admin'),
    'GD WebP decode and encode' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
];
foreach (['pdo_mysql','bcmath','mbstring','gd','intl','zip','pcntl','redis'] as $extension) {
    $checks['extension '.$extension] = extension_loaded($extension);
}
$failed=0;
echo 'PHP CLI: '.PHP_VERSION.PHP_EOL;
foreach($checks as $name=>$ok){echo ($ok?'PASS: ':'FAIL: ').$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
