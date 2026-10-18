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
    <div class="panel"><form method="post" action="/careers/apply" enctype="multipart/form-data" class="form-grid public-form" novalidate><?= csrf_field() ?>
        <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
        <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($f('full_name')) ?>"></div>
        <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
        <div class="field"><label for="phone">Phone number <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
        <div class="field"><label for="role">Role applied for</label><select id="role" name="role" required><option value="">Choose…</option><?php foreach ($roles as $r) : ?><option value="<?= e($r) ?>"<?= $sel === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="skills_experience">Skills and experience</label><textarea id="skills_experience" name="skills_experience" rows="5" required minlength="20" maxlength="4000"><?= e($f('skills_experience')) ?></textarea></div>
        <div class="field"><label for="portfolio_url">Portfolio address <span class="muted">(optional, https://…)</span></label><input id="portfolio_url" name="portfolio_url" type="url" maxlength="300" value="<?= e($f('portfolio_url')) ?>"></div>
        <div class="field"><label for="resume">Résumé or work sample <span class="muted">(optional, up to <?= e($limit) ?>)</span></label><input id="resume" name="resume" type="file"><p class="hint">Allowed: <?= e($extensions) ?>. Files are stored privately and only seen by the people reviewing applications.</p></div>
        <div class="field"><label for="intro">Short introduction</label><textarea id="intro" name="intro" rows="4" required minlength="20" maxlength="2000"><?= e($f('intro')) ?></textarea></div>
        <label class="check"><input type="checkbox" name="consent" value="1" required><span>I agree that my details may be stored and used to review my application, as described in the <?= \Vertex\Services\SiteContent::publishedBySlug('policies', 'privacy-policy') ? '<a href="/privacy-policy">privacy policy</a>' : 'privacy notice' ?>.</span></label>
        <p class="muted"><?= st($fm['note']) ?></p>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Submit application</button></div></form></div></div></section><?php endif; ?>
