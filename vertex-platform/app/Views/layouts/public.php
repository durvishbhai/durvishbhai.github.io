<?php
/** @var string $content */
/** @var string|null $title */
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . config('app.name') : (string) config('app.name');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#05070d">
    <meta name="robots" content="<?= isset($noindex) && $noindex ? 'noindex' : 'index,follow' ?>">
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="site">
<a class="skip-link" href="#main">Skip to content</a>
<?= \Vertex\Core\View::partial('site-header') ?>
<main id="main">
    <div class="container flash-wrap"><?= \Vertex\Core\View::partial('flash', ['errors' => $errors ?? [], 'notice' => $notice ?? null]) ?></div>
    <?= $content ?>
</main>
<?= \Vertex\Core\View::partial('site-footer') ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
