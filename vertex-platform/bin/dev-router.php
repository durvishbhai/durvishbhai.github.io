<?php

declare(strict_types=1);

/*
 * Router script for PHP's built-in web server (local development ONLY, never production):
 *   php -S 127.0.0.1:8000 -t public bin/dev-router.php        (or: composer serve)
 * Mirrors public/.htaccess: serve real static files, send everything else to the front controller.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath(__DIR__ . '/../public' . $path);

if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, realpath(__DIR__ . '/../public') . DIRECTORY_SEPARATOR) && !str_ends_with($file, '.php')) {
    return false; // let the built-in server serve the static file
}

require __DIR__ . '/../public/index.php';
