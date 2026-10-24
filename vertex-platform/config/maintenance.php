<?php

declare(strict_types=1);

use Vertex\Core\Env;

/**
 * Maintenance mode. While it is on, visitors and clients see a "we'll be back soon" page (HTTP 503); signed-in team members keep full access, and the
 * sign-in pages, the health check and payment webhooks keep working. Switch it in Integrations & settings → Feature switches, or with MAINTENANCE_MODE=true in .env
 * when the database is not reachable.
 */
return [
    'enabled' => filter_var(Env::get('MAINTENANCE_MODE', false), FILTER_VALIDATE_BOOL),
    'message' => (string) Env::get('MAINTENANCE_MESSAGE', ''),
    'retry_after_seconds' => 3600,
    // Still answered while maintenance is on (exact paths, or prefixes ending in /)
    'open_paths' => ['/health', '/offline', '/sw.js', '/login', '/login/two-factor', '/logout', '/forgot-password', '/auth/google', '/auth/google/callback', '/webhooks/', '/reset-password/', '/assets/'],
];
