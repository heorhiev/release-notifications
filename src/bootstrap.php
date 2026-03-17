<?php

declare(strict_types=1);

spl_autoload_register(static function (string $className): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/';

    if (strncmp($prefix, $className, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    App\Env::load($envFile);
}

