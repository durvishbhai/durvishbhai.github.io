<?php /** @var string $content */ /** @var string|null $title */ ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string) ($title ?? 'Document')) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-doc">
<?= $content ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
