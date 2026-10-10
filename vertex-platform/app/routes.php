<?php

declare(strict_types=1);

use Vertex\Core\Router;
use Vertex\Http\Controllers\AuthController;
use Vertex\Http\Controllers\DashboardController;
use Vertex\Http\Controllers\HealthController;
use Vertex\Http\Controllers\HomeController;

/*
 * Route table. Phase 0 only registers routes backed by working code.
 * Later phases add their own route groups here (projects, billing, payouts, ...), each with 'auth' + 'can:' middleware.
 */
return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/health', [HealthController::class, 'show']);

    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
};
