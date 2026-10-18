<?php /** @var list<array<string,mixed>> $services */
use Vertex\Services\SiteSections;
$h = SiteSections::get('services', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">What we do</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container">
    <?php if ($services === []) : ?><div class="empty-card"><p><?= st(SiteSections::get('services', 'empty')['body']) ?></p><p><a class="btn btn-primary" href="/contact">Contact us</a></p></div>
    <?php else : ?><div class="service-grid"><?php foreach ($services as $s) : ?>
        <a class="service-card" href="/services/<?= e($s['slug']) ?>"><span class="service-icon"><?= sicon((string) $s['icon']) ?></span><h2><?= st((string) $s['name']) ?></h2><p><?= st((string) $s['summary']) ?></p>
            <?php if ((int) $s['show_price'] === 1 && $s['starting_price_minor'] !== null) : ?><p class="price">From <?= e(money((int) $s['starting_price_minor'])) ?></p><?php endif; ?><span class="more">View details <?= sicon('arrow') ?></span></a>
    <?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php if ($services !== [] && SiteSections::enabled('services', 'cta')) : $c = SiteSections::get('services', 'cta'); ?>
<section class="pub-cta"><div class="container narrow center"><h2><?= st($c['heading']) ?></h2><div class="hero-actions center-actions"><a class="btn btn-primary" href="/request-quote"><?= st($c['button_label']) ?></a></div></div></section><?php endif; ?>
