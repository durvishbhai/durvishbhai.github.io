<?php /** @var array<string,mixed> $a */ /** @var array<string,string> $statuses */ /** @var bool $canManage */ /** @var ?array<string,mixed> $resume */ ?>
<div class="page-head"><div><p class="eyebrow">Application <?= e($a['reference']) ?></p><h1><?= e($a['full_name']) ?></h1></div><a class="btn btn-ghost" href="/admin/careers">All applications</a></div>
<div class="panel"><dl class="kv">
    <dt>Role</dt><dd><?= e($a['role_applied']) ?></dd><dt>Email</dt><dd><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></dd><dt>Phone</dt><dd><?= e((string) ($a['phone'] ?: '—')) ?></dd>
    <dt>LinkedIn</dt><dd><?= !empty($a['linkedin_url']) ? '<a href="' . e((string) $a['linkedin_url']) . '" rel="noopener noreferrer" target="_blank">' . e((string) $a['linkedin_url']) . '</a>' : '—' ?></dd>
    <dt>Experience</dt><dd><?= e((string) ($a['experience'] ?: '—')) ?></dd><dt>Available to start</dt><dd><?= e((string) ($a['availability'] ?: '—')) ?></dd>
    <dt>Portfolio</dt><dd><?= $a['portfolio_url'] ? '<a href="' . e((string) $a['portfolio_url']) . '" rel="noopener noreferrer" target="_blank">' . e((string) $a['portfolio_url']) . '</a>' : '—' ?></dd>
    <dt>Received</dt><dd><?= e(local_time((string) $a['created_at'])) ?></dd><dt>Consent given</dt><dd><?= e(local_time((string) $a['consent_at'])) ?></dd>
    <dt>File</dt><dd><?= $resume ? '<a href="/admin/careers/' . e($a['public_id']) . '/file">' . e((string) $resume['original_name']) . '</a> (' . e(\Vertex\Services\FileStore::humanSize((int) $resume['size_bytes'])) . ')' : '—' ?></dd></dl>
    <h2>Skills and experience</h2><p class="prewrap"><?= e($a['skills_experience']) ?></p><h2>Introduction</h2><p class="prewrap"><?= e($a['intro']) ?></p></div>
<div class="panel"><h2>Review</h2>
    <form method="post" action="/admin/careers/<?= e($a['public_id']) ?>" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="st">Status</label><select id="st" name="status"<?= $canManage ? '' : ' disabled' ?>><?php foreach ($statuses as $k => $l) : ?><option value="<?= e($k) ?>"<?= $a['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="notes">Internal notes (never shown to the applicant)</label><textarea id="notes" name="internal_notes" rows="4" maxlength="3000"<?= $canManage ? '' : ' disabled' ?>><?= e(old('internal_notes', (string) ($a['internal_notes'] ?? ''))) ?></textarea></div>
        <?php if ($canManage) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div><?php endif; ?></form></div>
