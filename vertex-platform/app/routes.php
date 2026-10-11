<?php

declare(strict_types=1);

use Vertex\Core\Router;
use Vertex\Http\Controllers\AccountController;
use Vertex\Http\Controllers\Admin\AuditController;
use Vertex\Http\Controllers\Admin\RoleController;
use Vertex\Http\Controllers\Admin\UserController;
use Vertex\Http\Controllers\AuthController;
use Vertex\Http\Controllers\DashboardController;
use Vertex\Http\Controllers\HealthController;
use Vertex\Http\Controllers\HomeController;
use Vertex\Http\Controllers\PasswordController;
use Vertex\Http\Controllers\ProjectController;
use Vertex\Http\Controllers\RegistrationController;

/*
 * Route table. Every route is backed by working code. Middleware aliases:
 *   auth            signed-in, active account required
 *   guest           redirect signed-in users away
 *   can:a[,b]       needs ANY of the listed permissions (coarse gate; services re-check + apply row/hierarchy rules)
 *   project         resolves {project}, runs ProjectPolicy, 404 if missing or not accessible
 *   nosession       don't start a session for anonymous visitors
 * CSRF is enforced globally for every non-GET request.
 */
return static function (Router $router): void {
    // Public
    $router->get('/', [HomeController::class, 'index'], ['nosession']);
    $router->get('/health', [HealthController::class, 'show'], ['nosession']);

    // Authentication
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // Registration & email verification (404 unless enabled and email configured)
    $router->get('/register', [RegistrationController::class, 'showRegister'], ['guest']);
    $router->post('/register', [RegistrationController::class, 'register'], ['guest']);
    $router->get('/verify-email', [RegistrationController::class, 'showResend']);
    $router->post('/verify-email', [RegistrationController::class, 'resend']);
    $router->get('/verify-email/{token}', [RegistrationController::class, 'showVerify']);
    $router->post('/verify-email/{token}', [RegistrationController::class, 'verify']);

    // Password reset & invitations
    $router->get('/forgot-password', [PasswordController::class, 'showForgot'], ['guest']);
    $router->post('/forgot-password', [PasswordController::class, 'forgot'], ['guest']);
    $router->get('/reset-password/{token}', [PasswordController::class, 'showReset']);
    $router->post('/reset-password/{token}', [PasswordController::class, 'submitReset']);
    $router->get('/invite/{token}', [PasswordController::class, 'showInvite']);
    $router->post('/invite/{token}', [PasswordController::class, 'submitInvite']);

    // Signed-in self-service
    $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
    $router->get('/account', [AccountController::class, 'show'], ['auth']);
    $router->post('/account', [AccountController::class, 'update'], ['auth']);
    $router->post('/account/password', [AccountController::class, 'password'], ['auth']);

    // Projects (Phase 1: access-controlled read + Admin assignment only)
    $router->get('/projects', [ProjectController::class, 'index'], ['auth', 'can:projects.view_all,projects.view_assigned,projects.view_own']);
    $router->get('/projects/{project}', [ProjectController::class, 'show'], ['auth', 'project']);
    $router->post('/projects/{project}/assignments', [ProjectController::class, 'assign'], ['auth', 'can:projects.assign', 'project']);
    $router->post('/projects/{project}/assignments/{user}/revoke', [ProjectController::class, 'revoke'], ['auth', 'can:projects.assign', 'project']);

    // Administration
    $router->group('/admin', ['auth'], static function (Router $r): void {
        $r->get('/users', [UserController::class, 'index'], ['can:users.view']);
        $r->get('/users/create', [UserController::class, 'create'], ['can:users.create']);
        $r->post('/users', [UserController::class, 'store'], ['can:users.create']);
        $r->get('/users/{user}', [UserController::class, 'show'], ['can:users.view']);
        $r->post('/users/{user}', [UserController::class, 'update'], ['can:users.edit']);
        $r->post('/users/{user}/roles', [UserController::class, 'roles'], ['can:users.assign_roles']);
        $r->post('/users/{user}/deactivate', [UserController::class, 'deactivate'], ['can:users.deactivate']);
        $r->post('/users/{user}/reactivate', [UserController::class, 'reactivate'], ['can:users.deactivate']);
        $r->post('/users/{user}/archive', [UserController::class, 'archive'], ['can:users.archive']);
        $r->post('/users/{user}/send-reset', [UserController::class, 'sendReset'], ['can:users.reset_password']);
        $r->post('/users/{user}/resend-invite', [UserController::class, 'resendInvite'], ['can:users.create']);

        $r->get('/roles', [RoleController::class, 'index'], ['can:roles.view']);
        $r->get('/roles/{role}', [RoleController::class, 'show'], ['can:roles.view']);
        $r->post('/roles/{role}', [RoleController::class, 'update'], ['can:roles.manage']);

        $r->get('/audit', [AuditController::class, 'index'], ['can:audit.view']);
        $r->post('/audit/verify', [AuditController::class, 'verify'], ['can:audit.view']);
    });
};
