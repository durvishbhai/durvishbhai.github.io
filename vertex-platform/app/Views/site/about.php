<?php /** @var list<string> $visible */
use Vertex\Services\SiteSections;
$h = SiteSections::get('about', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">About us</p><h1><?= st($h['title']) ?></h1><div class="prose lead"><?= sr($h['intro']) ?></div></div></section>
<?php foreach ($visible as $key) : if ($key === 'header') { continue; } $d = SiteSections::get('about', $key); ?>
    <?php if (in_array($key, ['purpose', 'vision'], true)) : ?>
        <section class="pub-section"><div class="container narrow"><div class="about-card"><h2><?= st($d['heading']) ?></h2><p><?= st($d['body']) ?></p></div></div></section>
    <?php elseif ($key === 'mission') : ?>
        <section class="pub-section pub-alt"><div class="container narrow"><h2><?= st($d['heading']) ?></h2><ul class="check-list"><?php foreach (\Vertex\Services\SiteText::items($d['items']) as $i) : ?><li><?= e($i) ?></li><?php endforeach; ?></ul></div></section>
    <?php elseif ($key === 'principles') : ?>
        <section class="pub-section"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div><div class="chip-row"><?php foreach (\Vertex\Services\SiteText::items($d['items']) as $i) : ?><span class="pub-chip"><?= e($i) ?></span><?php endforeach; ?></div></div></section>
    <?php elseif ($key === 'what') : ?>
        <section class="pub-section pub-alt"><div class="container narrow"><h2><?= st($d['heading']) ?></h2><div class="prose"><?= sr($d['body']) ?></div></div></section>
    <?php elseif ($key === 'cta') : ?>
        <section class="pub-cta"><div class="container narrow center"><h2><?= st($d['heading']) ?></h2><div class="hero-actions center-actions">
            <?php if ($d['primary_label'] !== '') : ?><a class="btn btn-primary" href="<?= shref($d['primary_url']) ?>"><?= st($d['primary_label']) ?></a><?php endif; ?><?php if ($d['secondary_label'] !== '') : ?><a class="btn btn-ghost" href="<?= shref($d['secondary_url']) ?>"><?= st($d['secondary_label']) ?></a><?php endif; ?></div></div></section>
    <?php endif; ?>
<?php endforeach; ?>
