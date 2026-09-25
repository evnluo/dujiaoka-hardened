<?php
require __DIR__.'/../vendor/autoload.php';
$checks = [
    'final PHP 7.4 patch' => PHP_VERSION === '7.4.33',
    'final Laravel 6 patch' => Illuminate\Foundation\Application::VERSION === '6.20.45',
];
foreach (['pdo_mysql','mysqli','bcmath','mbstring','gd','intl','zip','pcntl','redis','igbinary'] as $extension) {
    $checks['extension '.$extension] = extension_loaded($extension);
}
$failed=0;
foreach($checks as $name=>$ok){echo ($ok?'PASS: ':'FAIL: ').$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
