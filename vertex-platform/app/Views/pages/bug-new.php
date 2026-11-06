<?php /** @var list<array<string,mixed>> $projects */ /** @var array<string,mixed>|null $t */ /** @var string $token */ /** @var string $limit */ /** @var string $types */
$draft = $t !== null && ($t['status'] ?? '') === 'draft';
$v = static fn (string $k, string $d = ''): string => old($k, $d);
$val = static fn (string $k, string $col) => $v($k, $draft ? (string) ($t[$col] ?? '') : '');
$action = $draft ? '/tickets/' . $t['public_id'] . '/draft' : '/support/bugs';
?>
<div class="page-head"><div><p class="eyebrow">Support Centre</p><h1><?= $draft ? 'Your draft bug report' : 'Report a bug' ?></h1></div><a class="btn btn-ghost" href="/support">&larr; Support Centre</a></div>
<div class="panel">
    <p class="muted">Tell us what went wrong so we can reproduce it. A report is not an automatic warranty claim: our team reviews each one and tells you how it is handled. You can save a draft and finish later; nobody on our team sees a draft until you submit it.</p>
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?>
        <?php if (!$draft) : ?><input type="hidden" name="token" value="<?= e($token) ?>"><?php endif; ?>
        <div class="field"><label for="bp">Project</label><select id="bp" name="project" required>
            <option value="">Choose a project</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $v('project', $draft ? (string) $t['project_public_id'] : '') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="bt">Issue title</label><input id="bt" name="title" required minlength="5" maxlength="200" value="<?= e($val('title', 'subject')) ?>"></div>
        <div class="field"><label for="bu">Affected page address (optional)</label><input id="bu" name="affected_url" type="url" maxlength="500" placeholder="https://" value="<?= e($val('affected_url', 'affected_url')) ?>"></div>
        <div class="field"><label for="bd">Description</label><textarea id="bd" name="description" rows="4" required maxlength="5000"><?= e($val('description', 'description')) ?></textarea></div>
        <div class="field"><label for="bs">Steps to reproduce</label><textarea id="bs" name="steps" rows="4" required maxlength="3000"><?= e($val('steps', 'steps_to_reproduce')) ?></textarea></div>
        <div class="field"><label for="be">Expected behaviour</label><textarea id="be" name="expected" rows="2" required maxlength="2000"><?= e($val('expected', 'expected_behaviour')) ?></textarea></div>
        <div class="field"><label for="ba">Actual behaviour</label><textarea id="ba" name="actual" rows="2" required maxlength="2000"><?= e($val('actual', 'actual_behaviour')) ?></textarea></div>
        <div class="field"><label for="bb">Browser and version (optional)</label><input id="bb" name="browser" maxlength="255" value="<?= e($val('browser', 'browser_details')) ?>"></div>
        <div class="field"><label for="bv">Device and operating system (optional)</label><input id="bv" name="device" maxlength="160" value="<?= e($val('device', 'device_details')) ?>"></div>
        <div class="field"><label for="bsev">How serious is it for you?</label><select id="bsev" name="severity"><?php foreach (config('workflow.bug_severities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('severity', $draft ? (string) $t['severity'] : 'normal') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <p class="hint">This is your own assessment. Our team may adjust it and will tell you why.</p></div>
        <?php if (!$draft) : ?><div class="field"><label for="bf">Screenshots or files (optional, up to 3)</label><input id="bf" type="file" name="files[]" multiple>
            <p class="hint">Up to <?= e($limit) ?> each. Allowed: <?= e($types) ?>.</p></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit" name="action" value="<?= $draft ? 'submit' : 'submit' ?>">Submit bug report</button>
            <button class="btn btn-ghost" type="submit" name="action" value="draft">Save as draft</button></div>
    </form>
</div>
