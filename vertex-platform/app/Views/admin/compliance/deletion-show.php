<?php /** @var array<string,mixed> $r */ /** @var array<string,mixed> $plan */ /** @var list<array<string,mixed>> $events */ /** @var ?array<string,mixed> $result */ /** @var array<string,string> $statuses */
$st = (string) $r['status']; $base = '/admin/compliance/deletion/' . $r['id']; ?>
<div class="page-head"><div><p class="eyebrow">Data deletion request</p><h1><?= e($r['reference']) ?></h1></div><a class="btn btn-ghost" href="/admin/compliance/deletion">&larr; All requests</a></div>
<div class="panel"><div class="detail-grid">
    <div><span class="muted">Requester</span><br><?= e($r['requester_name']) ?><br><span class="muted small"><?= e($r['requester_email']) ?></span></div>
    <div><span class="muted">Status</span><br><strong><?= e($statuses[$st] ?? $st) ?></strong></div>
    <div><span class="muted">Identity</span><br><?= $r['verified_at'] ? 'Verified (' . e(str_replace('_', ' ', (string) $r['verified_method'])) . ') ' . e(local_time($r['verified_at'])) : 'Not verified yet' ?></div>
    <div><span class="muted">Received</span><br><?= e(local_time($r['created_at'])) ?></div>
    <div><span class="muted">Handled by</span><br><?= e($r['handler_name'] ?? '—') ?></div>
    <div><span class="muted">Completed</span><br><?= $r['completed_at'] ? e(local_time($r['completed_at'])) : '—' ?></div></div>
    <?php if ($r['reason']) : ?><p><strong>Reason given:</strong> <?= e($r['reason']) ?></p><?php endif; ?>
    <?php if ($plan['blocked'] !== '') : ?><p class="panel-warn"><?= e($plan['blocked']) ?></p><?php endif; ?></div>

<div class="panel"><h2>What processing would do</h2>
    <?php if ($plan['nothing']) : ?><p class="muted">We hold no account or data under this email address.</p><?php endif; ?>
    <h3>Deleted</h3><ul><?php foreach ($plan['delete'] as $k => $n) : if ($n > 0) : ?><li><?= e($k) ?> (<?= e($n) ?>)</li><?php endif; endforeach; ?></ul>
    <h3>Anonymised</h3><ul><?php foreach ($plan['anonymise'] as $k => $n) : if ($n > 0) : ?><li><?= e($k) ?><?= $n > 1 ? ' (' . e($n) . ')' : '' ?></li><?php endif; endforeach; ?></ul>
    <h3>Kept: legally or contractually required</h3><ul><?php foreach ($plan['retain'] as $x) : ?><li><strong><?= e($x['what']) ?><?= $x['count'] ? ' (' . e($x['count']) . ')' : '' ?>:</strong> <?= e($x['reason']) ?></li><?php endforeach; ?></ul>
    <p class="muted small">Retention periods are placeholders until confirmed by your accountant and lawyer (Compliance &amp; legal → Data deletion &amp; retention).</p></div>

<?php if (!in_array($st, ['completed', 'rejected', 'withdrawn'], true)) : ?>
<div class="panel"><h2>Actions</h2>
    <?php if (in_array($st, ['awaiting_verification', 'needs_clarification'], true)) : ?><form method="post" action="<?= e($base) ?>/verify" class="form-grid"><?= csrf_field() ?><div class="field"><label for="vn">I confirmed the identity another way: how?</label><input id="vn" name="note" maxlength="500" required></div><div class="form-actions"><button class="btn btn-ghost" type="submit">Mark identity verified</button></div></form><?php endif; ?>
    <?php if ($st === 'verified') : ?>
    <form method="post" action="<?= e($base) ?>/clarify" class="form-grid"><?= csrf_field() ?><div class="field"><label for="cn">Ask the requester for more information</label><textarea id="cn" name="note" rows="2" maxlength="1000" required></textarea></div><div class="form-actions"><button class="btn btn-ghost" type="submit">Send the question</button></div></form>
    <form method="post" action="<?= e($base) ?>/approve" class="form-grid" data-confirm="Approve this request? Nothing is deleted until you process it."><?= csrf_field() ?><div class="field"><label for="an">Note to the requester (optional)</label><textarea id="an" name="note" rows="2" maxlength="1000"></textarea></div><div class="form-actions"><button class="btn btn-primary" type="submit"<?= $plan['blocked'] !== '' ? ' disabled' : '' ?>>Approve</button></div></form><?php endif; ?>
    <?php if ($st === 'approved') : ?><form method="post" action="<?= e($base) ?>/process" class="form-grid" data-confirm="Delete and anonymise now? This cannot be undone. Invoices, receipts, quotations and agreements are never touched."><?= csrf_field() ?>
        <p>Processing deletes and anonymises as shown above and cannot be undone. It never deletes invoices, receipts, quotations, payments or agreements.</p>
        <div class="field"><label for="pp">Your password</label><input id="pp" name="confirm_password" type="password" required autocomplete="current-password"></div><div class="form-actions"><button class="btn btn-primary" type="submit">Process the deletion</button></div></form><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/reject" class="form-grid" data-confirm="Reject this request?"><?= csrf_field() ?><div class="field"><label for="rj">Reject: the reason the requester will read</label><textarea id="rj" name="note" rows="2" maxlength="1000" required></textarea></div><div class="form-actions"><button class="btn btn-ghost" type="submit">Reject</button></div></form>
    <form method="post" action="<?= e($base) ?>/note" class="form-grid"><?= csrf_field() ?><div class="field"><label for="nn">Internal note (not shown to the requester)</label><input id="nn" name="note" maxlength="1000" required></div><div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form></div>
<?php endif; ?>

<?php if ($result) : ?><div class="panel"><h2>Recorded outcome</h2><h3>Deleted</h3><ul><?php foreach ($result['deleted'] as $k => $v) : ?><li><?= e($k) ?> (<?= e($v) ?>)</li><?php endforeach; ?></ul>
    <h3>Anonymised</h3><ul><?php foreach ($result['anonymised'] as $k => $v) : ?><li><?= e($k) ?></li><?php endforeach; ?></ul>
    <h3>Retained</h3><ul><?php foreach ($result['retained'] as $x) : ?><li><?= e($x['what']) ?>: <?= e($x['reason']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($r['internal_note']) : ?><div class="panel"><h2>Internal notes</h2><p class="prewrap"><?= e($r['internal_note']) ?></p></div><?php endif; ?>
<div class="panel"><h2>History</h2><?php foreach ($events as $e) : ?><p><strong><?= e(ucfirst(str_replace('_', ' ', (string) $e['event']))) ?></strong> <span class="muted small"><?= e(local_time($e['created_at'])) ?> · <?= e($e['actor_name'] ?? 'requester/system') ?><?= $e['visible_to_requester'] ? '' : ' · internal' ?></span><?= $e['note'] ? '<br>' . e($e['note']) : '' ?></p><?php endforeach; ?></div>
