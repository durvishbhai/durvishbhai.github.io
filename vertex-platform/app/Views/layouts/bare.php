<?php /** @var string $content */ /** @var string|null $title */ ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string) ($title ?? config('app.name'))) ?> · <?= e((string) config('app.name')) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#050818">
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="site">
<main id="main"><?= $content ?></main>
</body>
</html>
