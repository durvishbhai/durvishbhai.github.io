<?php /** @var array<string,list<array<string,mixed>>> $groups */
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSections;
$h = SiteSections::get('clients', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Our clients</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container">
<?php if ($groups === []) : ?><div class="empty-card"><p><?= st(SiteSections::get('clients', 'empty')['body']) ?></p></div>
<?php else : foreach ($groups as $country => $list) : ?>
    <?php if (count($groups) > 1 || $country !== '') : ?><h2 class="group-title"><?= e($country !== '' ? (string) $country : 'Other') ?></h2><?php endif; ?>
    <div class="partner-grid"><?php foreach ($list as $c) : $im = SiteContent::image($c, 'logo_media_id'); $tag = $c['website_url'] ? 'a' : 'div'; ?>
        <<?= $tag ?> class="partner-card client-card"<?= $c['website_url'] ? ' href="' . shref((string) $c['website_url']) . '" rel="noopener noreferrer" target="_blank"' : '' ?>>
            <?php if ($im) : ?><img src="<?= e($im['url']) ?>" alt="<?= e($im['alt'] !== '' ? $im['alt'] : (string) $c['name']) ?>" loading="lazy" width="<?= $im['w'] ?>" height="<?= $im['h'] ?>"><?php endif; ?>
            <h3><?= e($c['name']) ?></h3><?php if ($c['country']) : ?><span class="tag"><?= e($c['country']) ?></span><?php endif; ?><?php if ($c['description']) : ?><p><?= e($c['description']) ?></p><?php endif; ?></<?= $tag ?>>
    <?php endforeach; ?></div>
<?php endforeach; endif; ?></div></section>
<?php if (SiteSections::enabled('clients', 'cta')) : $b = SiteSections::get('clients', 'cta'); if ($b['button_label'] !== '') : ?>
<section class="pub-cta"><div class="container narrow center"><h2><?= st($b['heading']) ?></h2><p class="lead"><?= st($b['body']) ?></p><div class="hero-actions center-actions"><a class="btn btn-primary" href="<?= shref($b['button_url']) ?>"><?= st($b['button_label']) ?></a></div></div></section><?php endif; endif; ?>
