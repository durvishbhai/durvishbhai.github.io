<?php
/** @var array<string,mixed> $r */ /** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $files */ /** @var list<array<string,mixed>> $changes */ /** @var array<string,string> $moves */
/** @var bool $canDownload */ /** @var list<array<string,mixed>> $notes */ /** @var array<string,mixed> $terms */ /** @var array<string,mixed> $snapshot */ /** @var list<array<string,mixed>> $versions */
/** @var bool $canClassify */ /** @var bool $canOverride */ /** @var list<array<string,mixed>> $people */ /** @var bool $overAllowance */
use Vertex\Core\View;
use Vertex\Services\FieldRules;
use Vertex\Services\FileStore;
$base = '/revisions/' . $r['public_id'];
$v = static fn (string $k): string => old($k);
?>
<div class="page-head"><div><p class="eyebrow">Revision request</p><h1><?= e($r['reference']) ?></h1></div><a class="btn btn-ghost" href="/revisions">&larr; Queue</a></div>
<div class="panel">
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><?= View::partial('dstatus', ['s' => $r['status'], 'kind' => 'rstatus']) ?></div>
        <div><span class="muted">Classification</span><br><?= e(status_label((string) $r['scope_decision'])) ?></div>
        <div><span class="muted">Deliverable</span><br><a href="/deliverables/<?= e($r['deliverable_public_id']) ?>"><?= e($r['deliverable_title']) ?></a> · V<?= e($r['version_no']) ?></div>
        <div><span class="muted">Project</span><br><a href="/projects/<?= e($r['project_public_id']) ?>"><?= e($r['project_code']) ?></a></div>
        <div><span class="muted">Requested by</span><br><?= e($r['requester_name'] ?? '—') ?></div>
        <div><span class="muted">Submitted</span><br><?= e(local_time($r['created_at'])) ?></div>
        <div><span class="muted">Reviewer</span><br><?= e($r['assignee_name'] ?? 'Unassigned') ?></div>
        <div><span class="muted">Completed</span><br><?= $r['completed_at'] ? e(local_time($r['completed_at'])) : '—' ?></div>
    </div>
    <h3>Client's request</h3><p class="prewrap"><?= e($r['description']) ?></p>
    <?php foreach ($files as $f) : ?><p><?php if ($canDownload) : ?><a href="<?= e($base) ?>/files/<?= e($f['public_id']) ?>"><?= e($f['original_name']) ?></a><?php else : ?><?= e($f['original_name']) ?><?php endif; ?> <span class="muted"><?= e(FileStore::humanSize((int) $f['size_bytes'])) ?></span></p><?php endforeach; ?>
    <?php if ($r['client_response']) : ?><h3>Response shown to the client</h3><p class="prewrap"><?= e($r['client_response']) ?></p><?php endif; ?>
    <?php if ($r['resolution']) : ?><h3>Resolution</h3><p class="prewrap"><?= e($r['resolution']) ?></p><?php endif; ?>
</div>

<div class="panel"><h2>Included or chargeable? <span class="badge badge-sensitive">internal</span></h2>
    <?php if ($terms['policy'] === null) : ?>
        <p class="muted">No revision policy is configured for this project, so the system does not assume any allowance. Classify according to the approved quotation or contract, and say why.</p>
    <?php else : ?>
        <p>Terms from <strong><?= e($terms['source']) ?></strong> policy v<?= e($terms['policy']['version']) ?><?= $terms['policy']['source_reference'] ? ' (' . e($terms['policy']['source_reference']) . ')' : '' ?>:
            <?= $terms['limit'] !== null ? 'allowance <strong>' . e($terms['limit']) . '</strong>, already included on this project: <strong>' . e($terms['used']) . '</strong>' . ($overAllowance ? ' <span class="overdue">(allowance reached)</span>' : '') : 'no numeric allowance given' ?>.</p>
        <?php if ($terms['policy']['terms']) : ?><p class="prewrap muted"><?= e($terms['policy']['terms']) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if ($r['scope_reason']) : ?><p class="prewrap"><span class="muted">Reason recorded:</span> <?= e($r['scope_reason']) ?></p><?php endif; ?>
    <?php if ($snapshot !== []) : ?><p class="muted">Classified under: <?= e($snapshot['source'] ?? 'none') ?> policy<?= isset($snapshot['limit']) && $snapshot['limit'] !== null ? ', allowance ' . e($snapshot['limit']) . ', used before ' . e($snapshot['used_before']) : '' ?><?= !empty($snapshot['overridden']) ? ' · administrator override' : '' ?>.</p><?php endif; ?>

    <?php if ($canClassify) : ?>
        <form method="post" action="<?= e($base) ?>/classify" class="form-grid" novalidate><?= csrf_field() ?>
            <div class="field"><label for="cd">Classification</label><select id="cd" name="decision"><option value="included">Included in the agreed scope</option><option value="chargeable">Potentially chargeable change</option></select></div>
            <div class="field"><label for="cr">Why (internal, required)</label><textarea id="cr" name="reason" rows="3" required minlength="10" maxlength="3000"><?= e($v('reason')) ?></textarea></div>
            <fieldset class="field"><legend>If chargeable: proposal for the client (a draft; nothing is sent until you send it)</legend>
                <label for="cs">Proposed additional scope</label><textarea id="cs" name="scope_description" rows="3" maxlength="3000"><?= e($v('scope_description')) ?></textarea>
                <label for="cc">Why this is additional work (the client will read this)</label><textarea id="cc" name="client_reason" rows="3" maxlength="3000"><?= e($v('client_reason')) ?></textarea>
                <label for="ca">Proposed extra cost (₹)</label><input id="ca" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>">
                <label for="cdy">Extra days (optional)</label><input id="cdy" name="days" inputmode="numeric" value="<?= e($v('days')) ?>">
                <label for="cdu">Proposed delivery date (optional)</label><input id="cdu" name="due_date" type="date" value="<?= e($v('due_date')) ?>"></fieldset>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Record classification</button></div></form>
        <?php if ($terms['limit'] !== null) : ?><p class="muted">A classification that contradicts the configured allowance needs an administrator.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($canOverride) : ?>
        <details><summary>Administrator override: treat as included</summary>
            <form method="post" action="<?= e($base) ?>/override" class="form-grid" data-confirm="Override this classification to included? Any draft or pending proposal will be withdrawn."><?= csrf_field() ?>
                <div class="field"><label for="or">Reason (audited)</label><textarea id="or" name="reason" rows="2" required minlength="10" maxlength="3000"></textarea></div>
                <div class="field"><label for="opw">Your password</label><input id="opw" name="confirm_password" type="password" required autocomplete="current-password"></div>
                <div class="form-actions"><button class="btn btn-danger btn-sm" type="submit">Override to included</button></div></form></details>
    <?php endif; ?>
</div>

<?php if ($changes !== []) : ?><div class="panel"><h2>Cost proposals</h2><ul class="plain-list"><?php foreach ($changes as $c) : ?>
    <li><a href="/changes/<?= e($c['public_id']) ?>"><?= e($c['reference']) ?></a> ₹<?= e(FieldRules::formatMinor((int) $c['proposed_amount_minor'])) ?> <span class="badge"><?= e(status_label((string) $c['status'])) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($moves !== []) : ?>
<div class="panel"><h2>Move this request</h2>
    <form method="post" action="<?= e($base) ?>/status" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ms">New status</label><select id="ms" name="status"><?php foreach ($moves as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="mcn">Message to the client (required for Rejected, Resolved and sending back)</label><textarea id="mcn" name="client_note" rows="3" maxlength="3000"><?= e($v('client_note')) ?></textarea></div>
        <?php if ($versions !== []) : ?><div class="field"><label for="mrv">Updated version that addresses it (when marking Ready for Client Review)</label><select id="mrv" name="resolved_version"><option value="">None</option><?php foreach ($versions as $ver) : ?><option value="<?= e($ver['public_id']) ?>">V<?= e($ver['version_no']) ?> · <?= e(config("workflow.version_statuses.{$ver['status']}", $ver['status'])) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field"><label for="min">Internal note (optional)</label><textarea id="min" name="internal_note" rows="2" maxlength="3000"><?= e($v('internal_note')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Update status</button></div></form></div>
<?php endif; ?>

<div class="panel"><h2>Assignment and replies</h2>
    <form method="post" action="<?= e($base) ?>/assign" class="inline-form"><?= csrf_field() ?><label for="as" class="sr-only">Reviewer</label>
        <select id="as" name="assignee"><option value="">Unassigned</option><?php foreach ($people as $u) : ?><option value="<?= e($u['public_id']) ?>"<?= $r['assignee_public_id'] === $u['public_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit">Assign</button></form>
    <form method="post" action="<?= e($base) ?>/response" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="rs">Response the client can read</label><textarea id="rs" name="response" rows="3" maxlength="3000"><?= e($v('response') ?: (string) ($r['client_response'] ?? '')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Save response</button></div></form>
</div>

<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">staff only</span></h2>
    <?php foreach ($notes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= e($n['name'] ?? '') ?> · <?= e(local_time($n['created_at'])) ?></p></div><?php endforeach; ?>
    <?php if ($notes === []) : ?><p class="muted">No internal notes.</p><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/notes" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="nb">Add an internal note</label><textarea id="nb" name="body" rows="2" required maxlength="3000"><?= e($v('note')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form></div>

<div class="panel"><h2>Status history</h2><?= View::partial('revision-history', ['history' => $history, 'staff' => true]) ?></div>
