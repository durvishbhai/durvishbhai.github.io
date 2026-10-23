<?php /** @var bool $open */ /** @var list<array<string,mixed>> $jobs */ /** @var list<array{title:string,summary:string,family:string}> $roles */ /** @var string $preselect */ /** @var string $limit */ /** @var string $extensions */
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('hiring', 'header'); $f = static fn (string $k, string $d = ''): string => old($k, $d);
$sel = $f('role', $preselect);
$types = ['freelance' => 'Freelance assignment', 'contract' => 'Contract', 'part_time' => 'Part-time', 'full_time' => 'Full-time', 'internship' => 'Internship', 'other' => 'Other'];
$iconFor = static function (string $t): string {
    $t = strtolower($t);

    return str_contains($t, 'video') ? 'video' : (str_contains($t, 'full') ? 'layers' : (str_contains($t, 'front') ? 'web' : (str_contains($t, 'back') ? 'gear' : 'spark')));
}; ?>
<section class="pub-page-head hire-hero"><div class="container narrow">
    <p class="eyebrow"><?= $open ? st($h['eyebrow']) : 'CAREERS' ?></p>
    <?php if ($open) : ?><p class="hire-pill"><span class="pulse-dot" aria-hidden="true"></span> Now hiring</p><?php else : ?><p class="hire-pill is-closed">Hiring closed</p><?php endif; ?>
    <h1><?= st($h['title']) ?></h1><?php if ($open) : ?><p class="lead"><?= st($h['intro']) ?></p><?php endif; ?>
    <?php if ($open && $roles !== []) : ?><p class="hero-actions"><a class="btn btn-primary" href="#apply" data-hire-scroll>Apply now</a><a class="btn btn-ghost" href="#roles">See open roles</a></p><?php endif; ?>
</div></section>

<?php if (!$open) : $c = SiteSections::get('hiring', 'closed'); ?>
<section class="pub-section"><div class="container narrow"><div class="empty-card hire-closed"><h2><?= st($c['heading']) ?></h2><p><?= st($c['body']) ?></p>
    <?php if ($c['button_label'] !== '') : ?><p><a class="btn btn-primary" href="<?= shref($c['button_url']) ?>"><?= st($c['button_label']) ?></a></p><?php endif; ?></div></div></section>
<?php else : ?>

<?php if (SiteSections::enabled('hiring', 'roles') && $roles !== []) : $ro = SiteSections::get('hiring', 'roles'); ?>
<section class="pub-section" id="roles"><div class="container"><h2><?= st($ro['heading']) ?></h2>
    <p class="muted">Select a role to start your application.</p>
    <div class="role-grid" data-hire-roles><?php foreach ($roles as $r) : ?>
        <button type="button" class="role-card<?= $sel === $r['title'] ? ' is-selected' : '' ?>" data-role="<?= e($r['title']) ?>" data-family="<?= e($r['family']) ?>" aria-pressed="<?= $sel === $r['title'] ? 'true' : 'false' ?>">
            <span class="role-ico"><?= SiteText::icon($iconFor($r['title'])) ?></span>
            <span class="role-title"><?= e($r['title']) ?></span>
            <?php if ($r['summary'] !== '') : ?><span class="role-sum"><?= e($r['summary']) ?></span><?php endif; ?>
            <span class="role-cta"><span class="when-off">Select this role</span><span class="when-on">Selected ✓</span></span>
        </button>
    <?php endforeach; ?></div></div></section><?php endif; ?>

<?php if ($jobs !== [] && SiteSections::enabled('hiring', 'openings')) : $oh = SiteSections::get('hiring', 'openings'); ?>
<section class="pub-section" id="openings"><div class="container"><h2><?= st($oh['heading']) ?></h2>
    <div class="job-list"><?php foreach ($jobs as $j) : ?>
        <a class="job-card" href="/careers/<?= e($j['slug']) ?>"><div><h3><?= e($j['title']) ?></h3><p class="muted"><?= e(implode(' · ', array_filter([(string) $j['department'], $types[$j['engagement_type']] ?? '', $j['deadline'] ? 'Apply by ' . local_date((string) $j['deadline']) : '', (string) $j['compensation']]))) ?></p></div><span class="more">View details &rarr;</span></a>
    <?php endforeach; ?></div></div></section><?php endif; ?>

<?php if (SiteSections::enabled('hiring', 'terms')) : $t = SiteSections::get('hiring', 'terms'); ?>
<section class="pub-section pub-alt"><div class="container narrow"><div class="hire-terms"><h2><?= st($t['heading']) ?></h2><div class="prose"><?= sr($t['body']) ?></div></div></div></section><?php endif; ?>

<?php if (SiteSections::enabled('hiring', 'process')) : $pr = SiteSections::get('hiring', 'process'); ?>
<section class="pub-section"><div class="container narrow"><h2><?= st($pr['heading']) ?></h2>
    <ol class="steps hire-steps"><?php foreach (SiteText::rows($pr['items']) as $i => $s) : ?><li><span class="step-n"><?= $i + 1 ?></span><div><h3><?= e($s[0] ?? '') ?></h3><?php if (!empty($s[1])) : ?><p><?= e($s[1]) ?></p><?php endif; ?></div></li><?php endforeach; ?></ol></div></section><?php endif; ?>

<?php $fm = SiteSections::get('hiring', 'form'); ?>
<section class="pub-section pub-alt" id="apply"><div class="container narrow"><h2><?= st($fm['heading']) ?></h2>
    <div class="panel hire-form-panel"><?= \Vertex\Core\View::partial('form-errors', ['errors' => $errors ?? []]) ?><div class="hire-progress" aria-hidden="true"><span data-hire-bar></span></div><p class="muted small" data-hire-progress-text aria-live="polite">0% complete</p>
    <form method="post" action="/careers/apply" enctype="multipart/form-data" class="form-grid public-form" data-hire-form novalidate><?= csrf_field() ?>
        <?= \Vertex\Core\View::partial('apply-fields', ['roles' => $roles, 'sel' => $sel, 'limit' => $limit, 'extensions' => $extensions]) ?>
        <p class="muted"><?= st($fm['note']) ?></p>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-hire-submit><?= st(SiteSections::get('hiring', 'fields')['submit_label']) ?></button></div></form></div></div></section>
<?php endif; ?>
