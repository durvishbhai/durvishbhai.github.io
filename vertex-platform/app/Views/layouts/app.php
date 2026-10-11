<?php
use Vertex\Services\Auth;

/** @var string $content */
/** @var string|null $title */
$user = Auth::user();
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . config('app.name') : (string) config('app.name');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$nav = [
    ['/dashboard', 'Dashboard', true],
    ['/projects', 'Projects', can('projects.view_all') || can('projects.view_assigned') || can('projects.view_own')],
    ['/admin/users', 'Users', can('users.view')],
    ['/admin/roles', 'Roles', can('roles.view')],
    ['/admin/audit', 'Audit log', can('audit.view')],
    ['/account', 'My account', true],
];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#05070d">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="portal">
<a class="skip-link" href="#main">Skip to content</a>
<header class="portal-bar">
    <a class="brand" href="/dashboard" aria-label="Vertex dashboard">
        <img src="<?= e(asset('img/vertex-mark.png')) ?>" alt="" width="40" height="35">
        <span class="brand-word">Vertex</span>
    </a>
    <nav class="portal-nav" aria-label="Main">
        <?php foreach ($nav as [$href, $label, $show]) : ?>
            <?php if ($show) : ?>
                <a href="<?= e($href) ?>"<?= str_starts_with($path, $href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="portal-user">
        <span class="portal-name"><?= e($user['name'] ?? '') ?></span>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main id="main" class="portal-main">
    <div class="container-wide">
        <?= \Vertex\Core\View::partial('flash', ['errors' => $errors ?? [], 'notice' => $notice ?? null]) ?>
        <?= $content ?>
    </div>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
