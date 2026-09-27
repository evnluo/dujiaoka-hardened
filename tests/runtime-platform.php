<?php
require __DIR__.'/../vendor/autoload.php';
$checks = [
    'PHP 8.5 runtime' => PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5,
    'Laravel 13 framework' => str_starts_with(Illuminate\Foundation\Application::VERSION, '13.'),
    'Filament 5 panel builder' => str_starts_with(ltrim(Composer\InstalledVersions::getPrettyVersion('filament/filament'), 'v'), '5.'),
    'Dcat removed' => !Composer\InstalledVersions::isInstalled('dcat/laravel-admin') && !Composer\InstalledVersions::isInstalled('dcat-x/laravel-admin'),
];
foreach (['pdo_mysql','bcmath','mbstring','gd','intl','zip','pcntl','redis'] as $extension) {
    $checks['extension '.$extension] = extension_loaded($extension);
}
$failed=0;
foreach($checks as $name=>$ok){echo ($ok?'PASS: ':'FAIL: ').$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
