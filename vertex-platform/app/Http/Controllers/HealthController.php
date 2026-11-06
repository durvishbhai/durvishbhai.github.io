<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Throwable;
use Vertex\Core\Database;
use Vertex\Core\Logger;
use Vertex\Core\Request;
use Vertex\Core\Response;

/** Liveness/readiness for uptime monitors. Reveals only ok/degraded, never versions, paths or error text. */
final class HealthController
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        try {
            Database::one('SELECT 1 AS ok');
            $ok = true;
        } catch (Throwable $e) {
            Logger::error('Health check DB failure: ' . $e->getMessage());
            $ok = false;
        }

        return Response::json(['status' => $ok ? 'ok' : 'degraded'], $ok ? 200 : 503);
    }
}
