<?php

declare(strict_types=1);

use Vertex\Core\Env;

return [
    'name' => 'vertex_sid',
    'idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 60),
    // Advanced: overrides idle_minutes with an exact number of seconds (used by the automated timeout test).
    'idle_seconds' => Env::get('SESSION_IDLE_SECONDS') !== null ? (int) Env::get('SESSION_IDLE_SECONDS') : null,
    'absolute_minutes' => (int) Env::get('SESSION_ABSOLUTE_MINUTES', 720),
    'force_secure' => (bool) Env::get('SESSION_FORCE_SECURE', false),
    'login_max_attempts' => (int) Env::get('LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) Env::get('LOGIN_LOCKOUT_MINUTES', 15),
];
