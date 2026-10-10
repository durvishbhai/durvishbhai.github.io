<?php
/** @var string $content */
/** @var array<string,mixed> $user */
/** @var string|null $title */
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . config('app.name') : (string) config('app.name');
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
    <div class="portal-user">
        <span class="portal-name"><?= e($user['name'] ?? '') ?></span>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main id="main" class="portal-main"><?= $content ?></main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
