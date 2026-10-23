<?php
/**
 * The fields of the job application form, used by the Careers page. Every field is required; the wording and the two drop-down lists are edited in
 * Administration -> Website -> Page wording -> Careers page. @var list<array{title:string,family:string}> $roles @var string $sel @var string $limit @var string $extensions
 */
use Vertex\Services\SiteContent;
use Vertex\Services\SiteHiring;
use Vertex\Services\SiteSections;

$L = SiteSections::get('hiring', 'fields');
$f = static fn (string $k, string $d = ''): string => old($k, $d);
$fam = 'dev';
foreach ($roles as $r) {
    if ($r['title'] === $sel) {
        $fam = $r['family'];
    }
}
$opt = static function (string $which, string $label, string $name) use ($f): void { ?>
    <div><label for="<?= e($name) ?>"><?= st($label) ?></label><select id="<?= e($name) ?>" name="<?= e($name) ?>" required><option value="">Choose…</option><?php foreach (SiteHiring::options($which) as $o) : ?><option value="<?= e($o) ?>"<?= $f($name) === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
<?php }; ?>
<div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
<div class="field"><label for="role"><?= st($L['role_label']) ?></label><select id="role" name="role" required data-hire-role><option value="">Choose a role…</option><?php foreach ($roles as $r) : ?><option value="<?= e($r['title']) ?>" data-family="<?= e($r['family']) ?>"<?= $sel === $r['title'] ? ' selected' : '' ?>><?= e($r['title']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="full_name"><?= st($L['name_label']) ?></label><input id="full_name" name="full_name" required maxlength="120" autocomplete="name" value="<?= e($f('full_name')) ?>"></div>
<div class="field"><label for="email"><?= st($L['email_label']) ?></label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($f('email')) ?>"></div>
<div class="field"><label for="phone"><?= st($L['phone_label']) ?></label><input id="phone" name="phone" type="tel" required maxlength="32" autocomplete="tel" value="<?= e($f('phone')) ?>"></div>
<div class="field two"><?php $opt('experience', $L['experience_label'], 'experience'); $opt('availability', $L['availability_label'], 'availability'); ?></div>
<div class="field"><label for="skills_experience" data-hire-skills-label data-dev="<?= e(\Vertex\Services\SiteText::tokens($L['skills_label_dev'])) ?>" data-video="<?= e(\Vertex\Services\SiteText::tokens($L['skills_label_video'])) ?>"><?= st($L['skills_label_' . $fam]) ?></label>
    <textarea id="skills_experience" name="skills_experience" rows="5" required minlength="20" maxlength="4000" data-hire-count="4000" data-ph-dev="<?= e($L['skills_hint_dev']) ?>" data-ph-video="<?= e($L['skills_hint_video']) ?>" placeholder="<?= e($L['skills_hint_' . $fam]) ?>"><?= e($f('skills_experience')) ?></textarea><p class="hint"><span data-hire-count-out>0</span> / 4000</p></div>
<div class="field"><label for="portfolio_url" data-hire-link-label data-dev="<?= e($L['link_label_dev']) ?>" data-video="<?= e($L['link_label_video']) ?>"><?= st($L['link_label_' . $fam]) ?></label><input id="portfolio_url" name="portfolio_url" type="url" required maxlength="300" placeholder="https://" value="<?= e($f('portfolio_url')) ?>"></div>
<div class="field"><label for="linkedin_url"><?= st($L['linkedin_label']) ?></label><input id="linkedin_url" name="linkedin_url" type="url" required maxlength="300" placeholder="https://www.linkedin.com/in/your-name" autocomplete="url" value="<?= e($f('linkedin_url')) ?>"></div>
<div class="field"><label for="resume"><?= st($L['resume_label']) ?> <span class="muted">(up to <?= e($limit) ?>)</span></label><label class="drop" for="resume" data-hire-drop><span data-hire-file-name>Choose a file or drop it here</span></label><input id="resume" name="resume" type="file" class="sr-only" required><p class="hint">Allowed: <?= e($extensions) ?>. Files are stored privately and only seen by the people reviewing applications.</p></div>
<div class="field"><label for="intro"><?= st($L['intro_label']) ?></label><textarea id="intro" name="intro" rows="4" required minlength="20" maxlength="2000" data-hire-count="2000"><?= e($f('intro')) ?></textarea><p class="hint"><span data-hire-count-out>0</span> / 2000</p></div>
<label class="check"><input type="checkbox" name="consent" value="1" required><span><?= st($L['consent_text']) ?> <?= SiteContent::publishedBySlug('policies', 'privacy-policy') ? '<a href="/privacy-policy">privacy policy</a>' : 'privacy notice' ?>.</span></label>
