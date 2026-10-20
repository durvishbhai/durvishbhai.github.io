<?php /** @var bool $open */ /** @var list<array{title:string,summary:string,family:string}> $roles */ /** @var string $preselect */ /** @var array<string,string> $experience */ /** @var array<string,string> $availability */ /** @var string $limit */ /** @var string $extensions */
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;
$h = SiteSections::get('hiring', 'header'); $f = static fn (string $k, string $d = ''): string => old($k, $d);
$sel = $f('role', $preselect);
$iconFor = static function (string $t): string {
    $t = strtolower($t);

    return str_contains($t, 'video') ? 'video' : (str_contains($t, 'full') ? 'layers' : (str_contains($t, 'front') ? 'web' : (str_contains($t, 'back') ? 'gear' : 'spark')));
}; ?>
<section class="pub-page-head hire-hero"><div class="container narrow">
    <p class="eyebrow"><?= $open ? st($h['eyebrow']) : 'HIRING' ?></p>
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

<?php if (SiteSections::enabled('hiring', 'terms')) : $t = SiteSections::get('hiring', 'terms'); ?>
<section class="pub-section pub-alt"><div class="container narrow"><div class="hire-terms"><h2><?= st($t['heading']) ?></h2><div class="prose"><?= sr($t['body']) ?></div></div></div></section><?php endif; ?>

<?php if (SiteSections::enabled('hiring', 'process')) : $pr = SiteSections::get('hiring', 'process'); ?>
<section class="pub-section"><div class="container narrow"><h2><?= st($pr['heading']) ?></h2>
    <ol class="steps hire-steps"><?php foreach (SiteText::rows($pr['items']) as $i => $s) : ?><li><span class="step-n"><?= $i + 1 ?></span><div><h3><?= e($s[0] ?? '') ?></h3><?php if (!empty($s[1])) : ?><p><?= e($s[1]) ?></p><?php endif; ?></div></li><?php endforeach; ?></ol></div></section><?php endif; ?>

<?php $fm = SiteSections::get('hiring', 'form'); ?>
<section class="pub-section pub-alt" id="apply"><div class="container narrow"><h2><?= st($fm['heading']) ?></h2>
    <div class="panel hire-form-panel"><div class="hire-progress" aria-hidden="true"><span data-hire-bar></span></div><p class="muted small" data-hire-progress-text aria-live="polite">0% complete</p>
    <form method="post" action="/hiring/apply" enctype="multipart/form-data" class="form-grid public-form" data-hire-form novalidate><?= csrf_field() ?>
        <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
        <div class="field"><label for="role">Role you are applying for</label><select id="role" name="role" required data-hire-role><option value="">Choose a role…</option><?php foreach ($roles as $r) : ?><option value="<?= e($r['title']) ?>" data-family="<?= e($r['family']) ?>"<?= $sel === $r['title'] ? ' selected' : '' ?>><?= e($r['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($f('full_name')) ?>"></div>
        <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
        <div class="field"><label for="phone">Phone number <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
        <div class="field two"><div><label for="experience">Experience <span class="muted">(optional)</span></label><select id="experience" name="experience"><option value="">Choose…</option><?php foreach ($experience as $k => $v) : ?><option value="<?= e($k) ?>"<?= $f('experience') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div><label for="availability">Available to start <span class="muted">(optional)</span></label><select id="availability" name="availability"><option value="">Choose…</option><?php foreach ($availability as $k => $v) : ?><option value="<?= e($k) ?>"<?= $f('availability') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label for="skills_experience" data-hire-skills-label>Skills and experience</label><textarea id="skills_experience" name="skills_experience" rows="5" required minlength="20" maxlength="4000" data-hire-count="4000" placeholder="Tell us about your skills, tools and the work you have done."><?= e($f('skills_experience')) ?></textarea><p class="hint"><span data-hire-count-out>0</span> / 4000</p></div>
        <div class="field"><label for="portfolio_url" data-hire-link-label>Portfolio or GitHub link <span class="muted">(optional, https://…)</span></label><input id="portfolio_url" name="portfolio_url" type="url" maxlength="300" placeholder="https://" value="<?= e($f('portfolio_url')) ?>"></div>
        <div class="field"><label for="resume">Résumé or work sample <span class="muted">(optional, up to <?= e($limit) ?>)</span></label><label class="drop" for="resume" data-hire-drop><span data-hire-file-name>Choose a file or drop it here</span></label><input id="resume" name="resume" type="file" class="sr-only"><p class="hint">Allowed: <?= e($extensions) ?>. Files are stored privately and only seen by the people reviewing applications.</p></div>
        <div class="field"><label for="intro">Short introduction</label><textarea id="intro" name="intro" rows="4" required minlength="20" maxlength="2000" data-hire-count="2000"><?= e($f('intro')) ?></textarea><p class="hint"><span data-hire-count-out>0</span> / 2000</p></div>
        <label class="check"><input type="checkbox" name="consent" value="1" required><span>I agree that my details may be stored and used to review my application, as described in the <?= \Vertex\Services\SiteContent::publishedBySlug('policies', 'privacy-policy') ? '<a href="/privacy-policy">privacy policy</a>' : 'privacy notice' ?>.</span></label>
        <p class="muted"><?= st($fm['note']) ?></p>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-hire-submit>Submit application</button></div></form></div></div></section>
<?php endif; ?>
