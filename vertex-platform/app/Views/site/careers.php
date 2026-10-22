<?php /** @var list<array<string,mixed>> $jobs */ /** @var array<string,string> $roles */ /** @var string $preselect */ /** @var string $limit */ /** @var string $extensions */
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('careers', 'header'); $f = static fn (string $k, string $d = ''): string => old($k, $d);
$types = ['freelance' => 'Freelance assignment', 'contract' => 'Contract', 'part_time' => 'Part-time', 'full_time' => 'Full-time', 'internship' => 'Internship', 'other' => 'Other']; ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow"><?= st($h['title']) ?></p><h1><?= st($h['hero_heading']) ?></h1><div class="prose lead"><?= sr($h['intro']) ?></div></div></section>
<?php if (SiteSections::enabled('careers', 'categories')) : $c = SiteSections::get('careers', 'categories'); ?>
<section class="pub-section"><div class="container narrow"><h2><?= st($c['heading']) ?></h2><p class="muted"><?= st($c['note']) ?></p><div class="chip-row"><?php foreach (SiteText::items($c['items']) as $i) : ?><span class="pub-chip"><?= e($i) ?></span><?php endforeach; ?></div></div></section><?php endif; ?>
<?php if (SiteSections::enabled('careers', 'openings')) : $o = SiteSections::get('careers', 'openings'); ?>
<section class="pub-section pub-alt" id="openings"><div class="container"><h2><?= st($o['heading']) ?></h2>
    <?php if ($jobs === []) : ?><p class="empty-state"><?= st($o['empty_text']) ?></p>
    <?php else : ?><div class="job-list"><?php foreach ($jobs as $j) : ?>
        <a class="job-card" href="/careers/<?= e($j['slug']) ?>"><div><h3><?= e($j['title']) ?></h3><p class="muted"><?= e(implode(' · ', array_filter([(string) $j['department'], $types[$j['engagement_type']] ?? '', $j['deadline'] ? 'Apply by ' . local_date((string) $j['deadline']) : '']))) ?></p></div><span class="more">View details <?= sicon('arrow') ?></span></a>
    <?php endforeach; ?></div><?php endif; ?></div></section><?php endif; ?>
<?php if (SiteSections::enabled('careers', 'form')) : $fm = SiteSections::get('careers', 'form'); $sel = $f('role', $preselect); ?>
<section class="pub-section" id="apply"><div class="container narrow"><h2><?= st($fm['heading']) ?></h2>
    <div class="panel hire-form-panel"><?= \Vertex\Core\View::partial('form-errors', ['errors' => $errors ?? []]) ?><form method="post" action="/careers/apply" enctype="multipart/form-data" class="form-grid public-form" data-hire-form novalidate><?= csrf_field() ?>
        <?= \Vertex\Core\View::partial('apply-fields', ['roles' => array_map(static fn (string $t): array => ['title' => $t, 'family' => stripos($t, 'video') !== false || stripos($t, 'edit') !== false ? 'video' : 'dev'], array_values($roles)), 'sel' => $sel, 'limit' => $limit, 'extensions' => $extensions]) ?>
        <p class="muted"><?= st($fm['note']) ?></p>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-hire-submit><?= st(SiteSections::get('hiring', 'fields')['submit_label']) ?></button></div></form></div></div></section><?php endif; ?>
