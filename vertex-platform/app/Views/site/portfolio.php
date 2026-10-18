<?php /** @var list<array<string,mixed>> $projects */
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSections;
$h = SiteSections::get('portfolio', 'header'); $cats = array_values(array_unique(array_filter(array_column($projects, 'category')))); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Our work</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container">
<?php if ($projects === []) : $e = SiteSections::get('portfolio', 'empty'); ?><div class="empty-card"><h2><?= st($e['heading']) ?></h2><p><?= st($e['body']) ?></p><p><a class="btn btn-primary" href="/request-quote">Start Your Project</a></p></div>
<?php else : ?>
    <?php if (count($cats) > 1) : ?><p class="chip-row filter-row" data-filter-group><button class="pub-chip is-on" type="button" data-filter="">All</button><?php foreach ($cats as $c) : ?><button class="pub-chip" type="button" data-filter="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?></p><?php endif; ?>
    <div class="work-grid"><?php foreach ($projects as $p) : $im = SiteContent::image($p, 'cover_media_id'); ?>
        <a class="work-card" href="/portfolio/<?= e($p['slug']) ?>" data-category="<?= e((string) $p['category']) ?>"><?php if ($im) : ?><img src="<?= e($im['url']) ?>" alt="<?= e($im['alt']) ?>" loading="lazy" width="<?= $im['w'] ?>" height="<?= $im['h'] ?>"><?php else : ?><div class="work-ph"><?= sicon('image') ?></div><?php endif; ?>
            <div class="work-body"><?php if ($p['category']) : ?><span class="tag"><?= e($p['category']) ?></span><?php endif; ?><h2><?= e($p['title']) ?></h2><p><?= e($p['summary']) ?></p></div></a>
    <?php endforeach; ?></div><?php endif; ?></div></section>
