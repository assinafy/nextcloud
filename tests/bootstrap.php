<?php

declare(strict_types=1);

require_once __DIR__ . '/OcpStubs.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\Assinafy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
