<?php

declare(strict_types=1);

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) require_once $vendor;

spl_autoload_register(static function (string $class): void {
    $maps = [
        'AML\\Data\\MongoDB\\' => dirname(__DIR__) . '/src/',
        'AML\\Data\\' => dirname(__DIR__, 2) . '/phpaml-data/src/',
    ];
    foreach ($maps as $prefix => $directory) if (str_starts_with($class, $prefix)) { require $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; return; }
});
