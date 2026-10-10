<?php

declare(strict_types=1);

use Vertex\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'Vertex Integrated Solutions'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'key' => (string) Env::get('APP_KEY', ''),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
];
