<?php

declare(strict_types=1);

use Vertex\Core\Config;
use Vertex\Core\Csrf;
use Vertex\Core\Env;
use Vertex\Core\Session;

if (!function_exists('e')) {
    /** HTML-escape for output. Use on EVERY dynamic value in templates. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** Public asset URL with a cache-busting query based on file mtime. */
    function asset(string $path): string
    {
        $file = VERTEX_ROOT . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? (string) filemtime($file) : '0';

        return '/assets/' . ltrim($path, '/') . '?v=' . $v;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('flash')) {
    function flash(string $key): mixed
    {
        return Session::pullFlash($key);
    }
}

if (!function_exists('old')) {
    /** Re-populate a form field after failed validation (never used for passwords). */
    function old(string $key, string $default = ''): string
    {
        $old = Session::get('_old', []);

        return is_array($old) && isset($old[$key]) && is_string($old[$key]) ? $old[$key] : $default;
    }
}

if (!function_exists('uuid_hex')) {
    function uuid_hex(): string
    {
        return bin2hex(random_bytes(16));
    }
}
