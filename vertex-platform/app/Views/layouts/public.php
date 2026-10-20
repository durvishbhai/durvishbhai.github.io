<?php
/** @var string $content */
/** @var string|null $title */
$meta = isset($meta) && is_array($meta) ? $meta : [];
$siteDesc = (string) config('site.default_seo_description', '');
$metaDesc = (string) ($meta['description'] ?? '') !== '' ? (string) $meta['description'] : $siteDesc;
$pageTitle = isset($title) && $title !== '' && $title !== config('app.name') ? $title . ' · ' . config('app.name') : (string) config('app.name');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#050818">
    <meta name="robots" content="<?= isset($noindex) && $noindex ? 'noindex,nofollow' : 'index,follow' ?>">
    <?php if ($metaDesc !== '') : ?><meta name="description" content="<?= e($metaDesc) ?>">
    <meta property="og:description" content="<?= e($metaDesc) ?>"><?php endif; ?>
    <?php if (!empty($meta['canonical'])) : ?><link rel="canonical" href="<?= e((string) $meta['canonical']) ?>"><?php endif; ?>
    <meta property="og:type" content="website"><meta property="og:site_name" content="<?= e((string) config('app.name')) ?>"><meta property="og:title" content="<?= e((string) ($meta['ogTitle'] ?? $pageTitle)) ?>">
    <?php if (!empty($meta['ogImage'])) : ?><meta property="og:image" content="<?= e((string) $meta['ogImage']) ?>"><?php endif; ?>
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if (!empty($jsonld)) : ?><script type="application/ld+json"><?= $jsonld ?></script><?php endif; ?>
</head>
<body class="site">
<a class="skip-link" href="#main">Skip to content</a>
<?= \Vertex\Core\View::partial('site-announce') ?>
<?= \Vertex\Core\View::partial('site-header') ?>
<main id="main">
    <div class="container flash-wrap"><?= \Vertex\Core\View::partial('flash', ['errors' => $errors ?? [], 'notice' => $notice ?? null]) ?></div>
    <?= $content ?>
</main>
<?= \Vertex\Core\View::partial('site-footer') ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
