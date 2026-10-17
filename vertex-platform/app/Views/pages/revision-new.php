<?php /** @var array<string,mixed> $d */ /** @var array<string,mixed>|null $version */ /** @var string $token */ /** @var string $limit */ /** @var string $types */
$v = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Revision request</p><h1><?= e($d['title']) ?></h1></div><a class="btn btn-ghost" href="/deliverables/<?= e($d['public_id']) ?>">&larr; Back</a></div>
<?php if ($version === null) : ?><div class="panel"><p class="muted">This deliverable is not currently open for revision requests.</p></div><?php else : ?>
<div class="panel">
    <p>You are asking for changes to <strong>V<?= e($version['version_no']) ?></strong> (published <?= e(local_time($version['published_at'], 'd M Y')) ?>).</p>
    <p class="muted">Tell us exactly what should change. Our team will review it and reply. If the change goes beyond what your agreement includes, we will send you a proposal with the scope, price and timeline, and nothing extra is done or charged unless you approve it.</p>
    <form method="post" action="/deliverables/<?= e($d['public_id']) ?>/revisions" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= e($version['public_id']) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="rd">What would you like changed?</label><textarea id="rd" name="description" rows="6" required minlength="10" maxlength="3000"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label for="rf">Attachments (optional, up to 3)</label><input id="rf" type="file" name="files[]" multiple>
            <p class="hint">Up to <?= e($limit) ?> each. Allowed: <?= e($types) ?>.</p></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Send revision request</button></div></form>
</div>
<?php endif; ?>
