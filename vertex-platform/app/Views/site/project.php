<?php /** @var array<string,mixed> $p */ /** @var ?array{url:string,alt:string,w:int,h:int} $cover */ /** @var list<array{url:string,alt:string,w:int,h:int}> $gallery */ ?>
<section class="pub-page-head"><div class="container narrow"><nav class="crumbs" aria-label="Breadcrumb"><a href="/portfolio">Portfolio</a> <span aria-hidden="true">/</span> <span><?= e($p['title']) ?></span></nav>
    <?php if ($p['category']) : ?><span class="tag"><?= e($p['category']) ?></span><?php endif; ?><h1><?= e($p['title']) ?></h1><p class="lead"><?= e($p['summary']) ?></p>
    <?php if ($p['services']) : ?><p class="chip-row"><?php foreach (array_filter(array_map('trim', explode(',', (string) $p['services']))) as $sv) : ?><span class="pub-chip"><?= e($sv) ?></span><?php endforeach; ?></p><?php endif; ?></div></section>
<section class="pub-section"><div class="container narrow">
    <?php if ($cover) : ?><img class="rounded wide" src="<?= e($cover['url']) ?>" alt="<?= e($cover['alt']) ?>" width="<?= $cover['w'] ?>" height="<?= $cover['h'] ?>"><?php endif; ?>
    <?php if ($p['case_study']) : ?><div class="prose"><?= sr((string) $p['case_study']) ?></div><?php endif; ?>
    <?php if ($gallery !== []) : ?><div class="gallery"><?php foreach ($gallery as $g) : ?><img src="<?= e($g['url']) ?>" alt="<?= e($g['alt']) ?>" loading="lazy" width="<?= $g['w'] ?>" height="<?= $g['h'] ?>"><?php endforeach; ?></div><?php endif; ?>
</div></section>
<section class="pub-cta"><div class="container narrow center"><h2>Have a project in mind?</h2><div class="hero-actions center-actions"><a class="btn btn-primary" href="/request-quote">Start Your Project</a><a class="btn btn-ghost" href="/portfolio">More work</a></div></div></section>
