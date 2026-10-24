<?php
/**
 * The step-by-step project requirements form (website and client dashboard). Without JavaScript every step is simply shown one under another; with it, one
 * step at a time with a progress bar and a review before sending. Fields keep the names the server already validates (EnquiryExtras::requirements).
 *
 * @var string $action @var bool $identity ask for name/email/phone/organization (visitors) @var bool $public honeypot + consent (visitors) @var string $preselect
 * @var array<string,string> $categories $timelines $budgets $serviceChoices @var string $limit $extensions
 */
use Vertex\Services\EnquiryExtras;

$f = static fn (string $k): string => old($k);
$preselect = $preselect ?? '';
$opts = static function (array $items, string $current, bool $keys = true): string {
    $h = '';
    foreach ($items as $k => $l) {
        $v = $keys ? (string) $k : (string) $l;
        $h .= '<option value="' . e($v) . '"' . ($current === $v ? ' selected' : '') . '>' . e((string) $l) . '</option>';
    }

    return $h;
};
$steps = ['Type &amp; Basics' => 'M3 6h18M3 12h18M3 18h18', 'Project Details' => '', 'Features' => '', 'Roles' => '', 'Review' => ''];
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="wizard" data-wizard novalidate><?= csrf_field() ?>
    <?php if (!empty($public)) : ?><div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div><?php endif; ?>

    <ol class="wiz-steps" aria-label="Progress">
        <?php foreach (['Type & Basics', 'Project Details', 'Features', 'Roles', 'Review'] as $i => $label) : ?>
            <li class="wiz-dot<?= $i === 0 ? ' is-current' : '' ?>" data-step-dot="<?= $i ?>"><button type="button" class="wiz-dot-btn" data-step-go="<?= $i ?>" aria-label="Step <?= $i + 1 ?>: <?= e($label) ?>"><span class="wiz-num"><?= $i + 1 ?></span></button><span class="wiz-label"><?= e($label) ?></span></li>
        <?php endforeach; ?>
    </ol>

    <section class="wiz-step" data-step="0" aria-labelledby="ws0">
        <h2 id="ws0">Project type &amp; basic info</h2><p class="muted">First choose what you want to build, then tell us a little about yourself.</p>
        <div class="field"><label for="project_category">Project type / category <span class="req">*</span></label><select id="project_category" name="project_category" required data-label="Project type"><option value="">Select a project type</option><?= $opts($categories, $f('project_category')) ?></select></div>
        <fieldset class="field" data-require-one data-label="Services you need"><legend>Services you need <span class="req">*</span></legend><div class="check-grid"><?php foreach ($serviceChoices as $slug => $name) : ?><label class="check"><input type="checkbox" name="services[]" value="<?= e($slug) ?>"<?= $preselect === $slug ? ' checked' : '' ?> data-text="<?= e($name) ?>"> <span><?= e($name) ?></span></label><?php endforeach; ?></div><p class="hint wiz-err" hidden>Choose at least one service.</p></fieldset>
        <?php if (!empty($identity)) : ?>
        <div class="grid-2">
            <div class="field"><label for="contact_name">Your full name <span class="req">*</span></label><input id="contact_name" name="contact_name" required maxlength="120" autocomplete="name" placeholder="Enter your full name" value="<?= e($f('contact_name')) ?>" data-label="Name"></div>
            <div class="field"><label for="email">Email address <span class="req">*</span></label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" placeholder="Enter your email" value="<?= e($f('email')) ?>" data-label="Email"></div>
            <div class="field"><label for="phone">Phone / WhatsApp number <span class="muted">(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" placeholder="Enter your phone number" value="<?= e($f('phone')) ?>" data-label="Phone"></div>
            <div class="field"><label for="company_name">Company / organization <span class="muted">(optional)</span></label><input id="company_name" name="company_name" maxlength="160" autocomplete="organization" placeholder="Company name, if applicable" value="<?= e($f('company_name')) ?>" data-label="Company"></div>
        </div>
        <?php endif; ?>
        <div class="field"><label for="project_name">Project name <span class="req">*</span></label><input id="project_name" name="project_name" required minlength="2" maxlength="160" placeholder="Enter a name for your project" value="<?= e($f('project_name')) ?>" data-label="Project name"></div>
    </section>

    <section class="wiz-step" data-step="1" aria-labelledby="ws1">
        <h2 id="ws1">Project details</h2><p class="muted">Describe what your project should accomplish.</p>
        <div class="field"><label for="problem_statement">What problem does this solve? <span class="req">*</span></label><textarea id="problem_statement" name="problem_statement" rows="5" required minlength="20" maxlength="3000" placeholder="Describe the problem or challenge this project will address for you or your business" data-label="Problem"><?= e($f('problem_statement')) ?></textarea></div>
        <div class="field"><label for="expected_outcome">Expected primary outcome <span class="req">*</span></label><select id="expected_outcome" name="expected_outcome" required data-label="Expected outcome"><option value="">Select an expected outcome</option><?= $opts(EnquiryExtras::OUTCOMES, $f('expected_outcome'), false) ?></select></div>
        <div class="field"><label for="outcome_note">Anything to add about the outcome? <span class="muted">(optional)</span></label><textarea id="outcome_note" name="outcome_note" rows="2" maxlength="1500" data-label="About the outcome"><?= e($f('outcome_note')) ?></textarea></div>
        <div class="field"><label for="preferred_tech">Preferred technology <span class="muted">(optional)</span></label><select id="preferred_tech" name="preferred_tech" data-label="Technology"><option value="">Select a technology</option><?= $opts(EnquiryExtras::TECHNOLOGIES, $f('preferred_tech'), false) ?></select></div>
        <div class="grid-2">
            <div class="field"><label for="budget_range">Budget range <span class="muted">(optional)</span></label><select id="budget_range" name="budget_range" data-label="Budget"><option value="">Prefer not to say</option><?= $opts($budgets, $f('budget_range')) ?></select></div>
            <div class="field"><label for="timeline">Delivery timeline <span class="muted">(optional)</span></label><select id="timeline" name="timeline" data-label="Timeline"><option value="">Not decided</option><?= $opts($timelines, $f('timeline')) ?></select></div>
        </div>
        <?php if (!empty($deadline)) : ?><div class="field"><label for="expected_deadline">Needed by <span class="muted">(optional)</span></label><input id="expected_deadline" name="expected_deadline" type="date" value="<?= e($f('expected_deadline')) ?>" data-label="Needed by"></div><?php endif; ?>
    </section>

    <section class="wiz-step" data-step="2" aria-labelledby="ws2">
        <h2 id="ws2">Features</h2><p class="muted">Tick what you would like. You can add anything else below.</p>
        <fieldset class="field" data-label="Features"><legend class="sr-only">Features</legend><div class="check-grid"><?php foreach (EnquiryExtras::FEATURES as $ft) : ?><label class="check"><input type="checkbox" name="feature_list[]" value="<?= e($ft) ?>" data-text="<?= e($ft) ?>"> <span><?= e($ft) ?></span></label><?php endforeach; ?></div></fieldset>
        <div class="field"><label for="features">Anything else you need? <span class="muted">(optional)</span></label><textarea id="features" name="features" rows="4" maxlength="3000" data-label="Other features"><?= e($f('features')) ?></textarea></div>
    </section>

    <section class="wiz-step" data-step="3" aria-labelledby="ws3">
        <h2 id="ws3">Who will use it?</h2><p class="muted">Different people often need different access.</p>
        <fieldset class="field" data-label="Who will use it"><legend class="sr-only">Who will use it</legend><div class="check-grid"><?php foreach (EnquiryExtras::ROLES as $ro) : ?><label class="check"><input type="checkbox" name="roles[]" value="<?= e($ro) ?>" data-text="<?= e($ro) ?>"> <span><?= e($ro) ?></span></label><?php endforeach; ?></div></fieldset>
        <div class="field"><label for="user_count">Approximate number of users <span class="muted">(optional)</span></label><select id="user_count" name="user_count" data-label="Number of users"><option value="">Not sure</option><?= $opts(EnquiryExtras::USER_COUNTS, $f('user_count'), false) ?></select></div>
        <div class="field"><label for="role_notes">What should each kind of user be able to do? <span class="muted">(optional)</span></label><textarea id="role_notes" name="role_notes" rows="3" maxlength="1000" data-label="Notes about users"><?= e($f('role_notes')) ?></textarea></div>
    </section>

    <section class="wiz-step" data-step="4" aria-labelledby="ws4">
        <h2 id="ws4">Review &amp; send</h2><p class="muted">Check your answers. Use the numbers above to go back and change anything.</p>
        <dl class="kv wiz-review" data-review></dl>
        <div class="field"><label for="attachments">Attachments <span class="muted">(optional, up to 3 files, <?= e($limit) ?> each)</span></label><input id="attachments" name="attachments[]" type="file" multiple><p class="hint">Allowed: <?= e($extensions) ?>. Stored privately; only the team handling your request can open them.</p></div>
        <div class="field"><label for="notes">Additional notes <span class="muted">(optional)</span></label><textarea id="notes" name="notes" rows="3" maxlength="2000"><?= e($f('notes')) ?></textarea></div>
        <?php if (!empty($public)) : ?><?= \Vertex\Core\View::partial('public-form-consent') ?><?php endif; ?>
    </section>

    <div class="wiz-nav">
        <button class="btn btn-ghost" type="button" data-prev hidden>&larr; Previous</button>
        <button class="btn btn-primary" type="button" data-next>Next &rarr;</button>
        <button class="btn btn-primary" type="submit" data-submit><?= e($submitLabel ?? 'Send request') ?></button>
    </div>
</form>
