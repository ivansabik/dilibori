<?php

declare(strict_types=1);

$root = __DIR__;

require_once $root . '/config/database.php';

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'Dilibori\\ControlPlane\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

if (is_readable($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}
