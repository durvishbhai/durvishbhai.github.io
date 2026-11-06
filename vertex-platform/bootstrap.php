<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, the console and the tests.
 * Works with or without `composer install` (a PSR-4 fallback autoloader is registered).
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Vertex requires PHP 8.2 or newer. Current version: ' . PHP_VERSION . "\n");
}

define('VERTEX_ROOT', __DIR__);

if (is_file(VERTEX_ROOT . '/vendor/autoload.php')) {
    require VERTEX_ROOT . '/vendor/autoload.php';
} else {
    require VERTEX_ROOT . '/app/Core/helpers.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Vertex\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = VERTEX_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Vertex\Core\Config;
use Vertex\Core\Env;
use Vertex\Core\ErrorHandler;

Env::load(VERTEX_ROOT . '/' . (getenv('VERTEX_ENV_FILE') ?: '.env'));
Config::load(VERTEX_ROOT . '/config');
\Vertex\Services\Settings::apply(); // values saved in Administration -> Integrations & settings (never throws)
date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');
ErrorHandler::register();
