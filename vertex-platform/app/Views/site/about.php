<?php /** @var list<string> $visible */
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('about', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow"><?= st($h['eyebrow'] ?? 'ABOUT KRAZY INTEGRATED SOLUTIONS') ?></p><h1><?= st($h['title']) ?></h1><div class="prose lead"><?= sr($h['intro']) ?></div></div></section>
<?php foreach ($visible as $key) : if ($key === 'header') { continue; } $d = SiteSections::get('about', $key); ?>
    <?php if (in_array($key, ['purpose', 'vision'], true)) : ?>
        <section class="pub-section about-pair about-<?= e($key) ?>"><div class="container narrow"><div class="about-card"><h2><?= st($d['heading']) ?></h2><p><?= st($d['body']) ?></p></div></div></section>
    <?php elseif ($key === 'mission') : ?>
        <section class="pub-section pub-alt"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div>
            <ul class="about-grid"><?php foreach (SiteText::items($d['items']) as $n => $i) : $parts = explode(':', $i, 2); $hasTitle = count($parts) === 2 && mb_strlen(trim($parts[0])) <= 40; ?>
                <li class="about-tile"><span class="about-no" aria-hidden="true"><?= sprintf('%02d', $n + 1) ?></span><?php if ($hasTitle) : ?><h3><?= e(trim($parts[0])) ?></h3><p><?= e(trim($parts[1])) ?></p><?php else : ?><p><?= e($i) ?></p><?php endif; ?></li>
            <?php endforeach; ?></ul></div></section>
    <?php elseif ($key === 'principles') : ?>
        <section class="pub-section"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div>
            <ul class="about-badges"><?php foreach (SiteText::items($d['items']) as $i) : ?><li><span class="about-dot" aria-hidden="true"></span><?= e($i) ?></li><?php endforeach; ?></ul></div></section>
    <?php elseif ($key === 'what') : ?>
        <section class="pub-section pub-alt"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2><?php if (!empty($d['intro'])) : ?><p class="lead"><?= st($d['intro']) ?></p><?php endif; ?></div>
            <?php if (!empty($d['categories'])) : ?><div class="about-services"><?php foreach (SiteText::rows($d['categories']) as $row) : $title = array_shift($row); ?>
                <article class="service-card about-service"><h3><?= e((string) $title) ?></h3><ul class="about-points"><?php foreach ($row as $pt) : if ($pt !== '') : ?><li><?= e($pt) ?></li><?php endif; endforeach; ?></ul></article>
            <?php endforeach; ?></div><?php elseif (!empty($d['body'])) : ?><div class="prose"><?= sr($d['body']) ?></div><?php endif; ?>
            <?php if (!empty($d['closing'])) : ?><p class="about-closing"><?= st($d['closing']) ?></p><?php endif; ?></div></section>
    <?php elseif ($key === 'approach') : ?>
        <section class="pub-section"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div>
            <ol class="about-steps"><?php foreach (SiteText::rows($d['items']) as $n => $row) : ?><li><span class="about-no" aria-hidden="true"><?= sprintf('%02d', $n + 1) ?></span><h3><?= e($row[0] ?? '') ?></h3><?php if (!empty($row[1])) : ?><p><?= e($row[1]) ?></p><?php endif; ?></li><?php endforeach; ?></ol></div></section>
    <?php elseif ($key === 'commitment') : ?>
        <section class="pub-section pub-alt"><div class="container narrow"><div class="about-card"><h2><?= st($d['heading']) ?></h2><div class="prose"><?= sr($d['body']) ?></div></div></div></section>
    <?php elseif ($key === 'cta') : ?>
        <section class="pub-cta"><div class="container narrow center"><h2><?= st($d['heading']) ?></h2><?php if (!empty($d['text'])) : ?><p class="lead"><?= st($d['text']) ?></p><?php endif; ?><div class="hero-actions center-actions">
            <?php if ($d['primary_label'] !== '') : ?><a class="btn btn-primary" href="<?= shref($d['primary_url']) ?>"><?= st($d['primary_label']) ?></a><?php endif; ?><?php if ($d['secondary_label'] !== '') : ?><a class="btn btn-ghost" href="<?= shref($d['secondary_url']) ?>"><?= st($d['secondary_label']) ?></a><?php endif; ?></div></div></section>
    <?php endif; ?>
<?php endforeach; ?>
