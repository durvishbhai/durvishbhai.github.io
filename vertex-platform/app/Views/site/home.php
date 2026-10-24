<?php
/** @var list<array<string,mixed>> $services */ /** @var list<array<string,mixed>> $projects */ /** @var list<string> $order */
use Vertex\Services\SiteSections;
use Vertex\Services\SiteContent;
$btn = static fn (string $label, string $url, string $cls): string => $label !== '' ? '<a class="btn ' . $cls . '" href="' . shref($url) . '">' . st($label) . '</a>' : '';
?>
<?php foreach ($order as $key) : $d = SiteSections::get('home', $key); ?>
    <?php if ($key === 'hero') : $img = $d['image'] !== '' ? \Vertex\Services\CmsService::mediaByPublicId($d['image']) : null; ?>
        <section class="pub-hero"><div class="container hero-grid">
            <div class="hero-copy">
                <?php if ($d['eyebrow'] !== '') : ?><p class="eyebrow"><?= st($d['eyebrow']) ?></p><?php endif; ?>
                <h1><?= st($d['heading']) ?></h1>
                <p class="lead"><?= st($d['subheading']) ?></p>
                <div class="hero-actions"><?= $btn($d['primary_label'], $d['primary_url'], 'btn-primary') ?><?= $btn($d['secondary_label'], $d['secondary_url'], 'btn-ghost') ?><?= $btn($d['tertiary_label'], $d['tertiary_url'], 'btn-link') ?></div>
            </div>
            <div class="hero-art" aria-hidden="<?= $img ? 'false' : 'true' ?>">
                <?php if ($img) : ?><img src="<?= e(\Vertex\Services\CmsService::mediaUrl($img)) ?>" alt="<?= e((string) $img['alt_text']) ?>" width="<?= (int) $img['width'] ?>" height="<?= (int) $img['height'] ?>">
                <?php else : ?><img src="<?= e(asset('img/krazy-logo.png')) ?>" alt="" width="720" height="479"><?php endif; ?>
            </div>
        </div></section>
    <?php elseif ($key === 'intro') : ?>
        <section class="pub-section"><div class="container narrow"><h2><?= st($d['heading']) ?></h2><div class="prose"><?= sr($d['body']) ?></div>
            <?php if ($d['cta_label'] !== '') : ?><p><a class="btn btn-ghost" href="<?= shref($d['cta_url']) ?>"><?= st($d['cta_label']) ?></a></p><?php endif; ?></div></section>
    <?php elseif ($key === 'services') : ?>
        <section class="pub-section pub-alt" id="services"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2><p class="lead"><?= st($d['intro']) ?></p></div>
            <?php if ($services === []) : ?><p class="empty-state">Our services will be listed here soon.</p><?php else : ?>
            <div class="service-grid"><?php foreach ($services as $s) : ?>
                <a class="service-card" href="/services/<?= e($s['slug']) ?>"><span class="service-icon"><?= sicon((string) $s['icon']) ?></span><h3><?= st((string) $s['name']) ?></h3><p><?= st((string) $s['summary']) ?></p><span class="more">Learn more <?= sicon('arrow') ?></span></a>
            <?php endforeach; ?></div>
            <p class="center"><a class="btn btn-ghost" href="/services"><?= st($d['button_label']) ?></a></p><?php endif; ?></div></section>
    <?php elseif ($key === 'why') : ?>
        <section class="pub-section"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div>
            <div class="principle-grid"><?php foreach (\Vertex\Services\SiteText::rows($d['items']) as $i => $r) : ?>
                <div class="principle"><span class="num"><?= $i + 1 ?></span><h3><?= e($r[0] ?? '') ?></h3><p><?= e($r[1] ?? '') ?></p></div><?php endforeach; ?></div></div></section>
    <?php elseif ($key === 'how') : ?>
        <section class="pub-section pub-alt"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2></div>
            <ol class="steps"><?php foreach (\Vertex\Services\SiteText::rows($d['items']) as $r) : ?><li><h3><?= e($r[0] ?? '') ?></h3><p><?= e($r[1] ?? '') ?></p></li><?php endforeach; ?></ol></div></section>
    <?php elseif ($key === 'portfolio') : ?>
        <section class="pub-section"><div class="container"><div class="pub-head"><h2><?= st($d['heading']) ?></h2><?php if ($projects !== []) : ?><p class="lead"><?= st($d['intro']) ?></p><?php endif; ?></div>
            <?php if ($projects === []) : ?><div class="empty-card"><p><?= st($d['empty_text']) ?></p><p><a class="btn btn-primary" href="/request-quote">Start Your Project</a></p></div>
            <?php else : ?><div class="work-grid"><?php foreach ($projects as $p) : $im = SiteContent::image($p, 'cover_media_id'); ?>
                <a class="work-card" href="/portfolio/<?= e($p['slug']) ?>"><?php if ($im) : ?><img src="<?= e($im['url']) ?>" alt="<?= e($im['alt']) ?>" loading="lazy" width="<?= $im['w'] ?>" height="<?= $im['h'] ?>"><?php else : ?><div class="work-ph"><?= sicon('image') ?></div><?php endif; ?>
                    <div class="work-body"><?php if ($p['category']) : ?><span class="tag"><?= e($p['category']) ?></span><?php endif; ?><h3><?= e($p['title']) ?></h3><p><?= e($p['summary']) ?></p></div></a>
            <?php endforeach; ?></div><p class="center"><a class="btn btn-ghost" href="/portfolio">View all work</a></p><?php endif; ?></div></section>
    <?php elseif ($key === 'cta') : ?>
        <section class="pub-cta"><div class="container narrow center"><h2><?= st($d['heading']) ?></h2><p class="lead"><?= st($d['body']) ?></p>
            <div class="hero-actions center-actions"><?= $btn($d['primary_label'], $d['primary_url'], 'btn-primary') ?><?= $btn($d['secondary_label'], $d['secondary_url'], 'btn-ghost') ?></div></div></section>
    <?php endif; ?>
<?php endforeach; ?>
