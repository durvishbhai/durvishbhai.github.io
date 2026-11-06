<?php /** @var array<string,list<array<string,mixed>>> $groups */
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('team', 'header');
$labels = ['leadership' => 'Leadership', 'creative' => 'Creative Team', 'development' => 'Development Team', 'operations' => 'Operations and Support']; ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Our team</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container">
<?php if ($groups === []) : ?><div class="empty-card"><p><?= st(SiteSections::get('team', 'empty')['body']) ?></p></div>
<?php else : foreach ($labels as $key => $label) : if (empty($groups[$key])) { continue; } ?>
    <h2 class="group-title"><?= e($label) ?></h2>
    <div class="team-grid"><?php foreach ($groups[$key] as $m) : $im = SiteContent::image($m, 'photo_media_id'); ?>
        <article class="team-card"><?php if ($im) : ?><img src="<?= e($im['url']) ?>" alt="<?= e($im['alt'] !== '' ? $im['alt'] : (string) $m['full_name']) ?>" loading="lazy" width="<?= $im['w'] ?>" height="<?= $im['h'] ?>"><?php else : ?><div class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $m['full_name'], 0, 1))) ?></div><?php endif; ?>
            <h3><?= e($m['full_name']) ?></h3><p class="role"><?= e($m['designation']) ?></p>
            <?php if ($m['bio']) : ?><div class="bio"><?= SiteText::rich((string) $m['bio']) ?></div><?php endif; ?>
            <?php if ($m['skills']) : ?><p class="chip-row"><?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $m['skills']))), 0, 8) as $sk) : ?><span class="pub-chip"><?= e($sk) ?></span><?php endforeach; ?></p><?php endif; ?>
            <p class="social"><?php foreach (['linkedin_url' => 'LinkedIn', 'x_url' => 'X', 'website_url' => 'Website'] as $col => $lab) : if ($m[$col]) : ?><a href="<?= shref((string) $m[$col]) ?>" rel="noopener noreferrer" target="_blank"><?= $lab ?></a> <?php endif; endforeach; ?></p></article>
    <?php endforeach; ?></div>
<?php endforeach; endif; ?></div></section>
<?php $c = SiteSections::get('team', 'cta'); if (SiteSections::enabled('team', 'cta') && $c['button_label'] !== '') : ?>
<section class="pub-cta"><div class="container narrow center"><h2><?= st($c['text']) ?></h2><div class="hero-actions center-actions"><a class="btn btn-primary" href="<?= shref($c['button_url']) ?>"><?= st($c['button_label']) ?></a></div></div></section><?php endif; ?>
