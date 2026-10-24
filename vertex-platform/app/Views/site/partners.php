<?php /** @var list<array<string,mixed>> $partners */
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('partners', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Our partners</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<?php if (SiteSections::enabled('partners', 'areas')) : $a = SiteSections::get('partners', 'areas'); ?>
<section class="pub-section"><div class="container narrow"><h2><?= st($a['heading']) ?></h2><ul class="check-list two"><?php foreach (SiteText::items($a['items']) as $i) : ?><li><?= e($i) ?></li><?php endforeach; ?></ul></div></section><?php endif; ?>
<?php if ($partners !== []) : ?><section class="pub-section pub-alt"><div class="container"><h2>Our partners</h2>
    <div class="partner-grid"><?php foreach ($partners as $p) : $im = SiteContent::image($p, 'logo_media_id'); $tag = $p['website_url'] ? 'a' : 'div'; ?>
        <<?= $tag ?> class="partner-card"<?= $p['website_url'] ? ' href="' . shref((string) $p['website_url']) . '" rel="noopener noreferrer" target="_blank"' : '' ?>>
            <?php if ($im) : ?><img src="<?= e($im['url']) ?>" alt="<?= e($im['alt'] !== '' ? $im['alt'] : (string) $p['name']) ?>" loading="lazy" width="<?= $im['w'] ?>" height="<?= $im['h'] ?>"><?php endif; ?>
            <h3><?= e($p['name']) ?></h3><?php if ($p['category']) : ?><span class="tag"><?= e($p['category']) ?></span><?php endif; ?><?php if ($p['description']) : ?><p><?= e($p['description']) ?></p><?php endif; ?></<?= $tag ?>>
    <?php endforeach; ?></div></div></section><?php endif; ?>
<?php if (SiteSections::enabled('partners', 'apply')) : $b = SiteSections::get('partners', 'apply'); ?>
<section class="pub-cta"><div class="container narrow center"><h2><?= st($b['heading']) ?></h2><p class="lead"><?= st($b['body']) ?></p><div class="hero-actions center-actions"><a class="btn btn-primary" href="<?= shref($b['button_url']) ?>"><?= st($b['button_label']) ?></a></div></div></section><?php endif; ?>
