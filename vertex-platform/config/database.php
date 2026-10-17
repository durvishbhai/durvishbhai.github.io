<?php

declare(strict_types=1);

use Vertex\Core\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', ''),
    'username' => (string) Env::get('DB_USERNAME', ''),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
