<?php /** @var array<string,mixed> $s */ /** @var list<array<string,mixed>> $related */ /** @var ?array{url:string,alt:string,w:int,h:int} $cover */ /** @var list<array{url:string,alt:string,w:int,h:int}> $gallery */
use Vertex\Services\SiteText;
$faqs = SiteText::rows((string) $s['faqs']); ?>
<section class="pub-page-head"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="/services">Services</a> <span aria-hidden="true">/</span> <span><?= st((string) $s['name']) ?></span></nav>
    <div class="service-hero"><div><span class="service-icon big"><?= sicon((string) $s['icon']) ?></span><h1><?= st((string) ($s['hero_heading'] ?: $s['name'])) ?></h1>
        <p class="lead"><?= st((string) ($s['intro'] ?: $s['summary'])) ?></p>
        <p><a class="btn btn-primary" href="/request-quote?service=<?= e($s['slug']) ?>">Request a Project Proposal</a></p></div>
        <?php if ($cover) : ?><img class="rounded" src="<?= e($cover['url']) ?>" alt="<?= e($cover['alt']) ?>" width="<?= $cover['w'] ?>" height="<?= $cover['h'] ?>"><?php endif; ?></div></div></section>
<section class="pub-section"><div class="container two-col">
    <div><?php if ($s['description']) : ?><h2>About this service</h2><div class="prose"><?= sr((string) $s['description']) ?></div><?php endif; ?>
        <?php if ($s['use_cases']) : ?><h2>Suitable for</h2><ul class="check-list"><?php foreach (SiteText::items((string) $s['use_cases']) as $i) : ?><li><?= e($i) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
    <aside class="side-card"><?php if ($s['deliverables']) : ?><h3>What you receive</h3><ul class="check-list"><?php foreach (SiteText::items((string) $s['deliverables']) as $i) : ?><li><?= e($i) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ((int) $s['show_price'] === 1 && $s['starting_price_minor'] !== null) : ?><p class="price">Starting from <strong><?= e(money((int) $s['starting_price_minor'])) ?></strong></p><p class="muted small">The final price depends on the agreed scope.</p><?php endif; ?>
        <a class="btn btn-primary block" href="/request-quote?service=<?= e($s['slug']) ?>">Start your project</a></aside></div></section>
<?php if ($gallery !== []) : ?><section class="pub-section pub-alt"><div class="container"><h2>Gallery</h2><div class="gallery"><?php foreach ($gallery as $g) : ?><img src="<?= e($g['url']) ?>" alt="<?= e($g['alt']) ?>" loading="lazy" width="<?= $g['w'] ?>" height="<?= $g['h'] ?>"><?php endforeach; ?></div></div></section><?php endif; ?>
<?php if ($faqs !== []) : ?><section class="pub-section"><div class="container narrow"><h2>Questions about this service</h2><?php foreach ($faqs as $r) : ?><details class="faq-item"><summary><?= e($r[0] ?? '') ?></summary><p><?= e($r[1] ?? '') ?></p></details><?php endforeach; ?></div></section><?php endif; ?>
<?php if ($related !== []) : ?><section class="pub-section pub-alt"><div class="container"><h2>Related services</h2><div class="service-grid"><?php foreach ($related as $r) : ?>
    <a class="service-card" href="/services/<?= e($r['slug']) ?>"><span class="service-icon"><?= sicon((string) $r['icon']) ?></span><h3><?= st((string) $r['name']) ?></h3><p><?= st((string) $r['summary']) ?></p></a><?php endforeach; ?></div></div></section><?php endif; ?>
<section class="pub-cta"><div class="container narrow center"><h2>Request a Project Proposal</h2><p class="lead">Tell us what you need and we will review your requirements.</p><div class="hero-actions center-actions"><a class="btn btn-primary" href="/request-quote?service=<?= e($s['slug']) ?>">Start Your Project</a><a class="btn btn-ghost" href="/contact">Contact Us</a></div></div></section>
