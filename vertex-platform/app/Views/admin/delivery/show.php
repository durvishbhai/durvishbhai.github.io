<?php
/** @var array<string,mixed> $a */ /** @var array<string,mixed> $project */ /** @var array<string,mixed> $plan */ /** @var list<array<string,mixed>> $history */ /** @var array<string,mixed> $day */
/** @var bool $canManage */ /** @var bool $canConfigure */ /** @var array<string,string> $sources */ /** @var array<string,string> $statuses */
$open = $a['status'] === 'confirmed';
$before = $plan['eligible_from'] !== null && (string) $a['appointment_date'] < $plan['eligible_from'];
?>
<div class="page-head"><div><p class="eyebrow">Delivery appointment</p><h1><?= e($a['booking_reference']) ?></h1></div><a class="btn btn-ghost" href="/admin/delivery">&larr; All appointments</a></div>
<div class="panel"><div class="detail-grid">
    <div><span class="muted">Project</span><br><a href="/projects/<?= e($project['public_id']) ?>/delivery"><?= e($a['project_code']) ?></a> · <?= e($a['project_title']) ?></div>
    <div><span class="muted">Client</span><br><?= e($a['company_name'] ?: $a['contact_name']) ?><br><span class="muted"><?= e($a['client_email']) ?></span></div>
    <div><span class="muted">Delivery date</span><br><strong><?= e(local_date((string) $a['appointment_date'], 'l, j F Y')) ?></strong></div>
    <div><span class="muted">Status</span><br><span class="badge badge-d-<?= e($a['status']) ?>"><?= e($statuses[$a['status']] ?? $a['status']) ?></span></div>
    <div><span class="muted">Source</span><br><?= e($sources[$a['booking_source']] ?? $a['booking_source']) ?></div>
    <div><span class="muted">Booked</span><br><?= e(local_time($a['created_at'])) ?></div>
    <div><span class="muted">Times moved</span><br><?= e($a['reschedule_count']) ?></div>
    <div><span class="muted">That day</span><br><?= e($day['used']) ?> booked · <?= e($day['reserved']) ?> reserved · capacity <?= e($day['cap']) ?></div>
</div>
<?php if ($before && $open) : ?><p class="panel-warn">This date is earlier than the project's current first selectable date (<?= e(local_date($plan['eligible_from'], 'j F Y')) ?>). It was left as it is.</p><?php endif; ?>
<?php if (!empty($a['client_notes'])) : ?><h3>Client note</h3><p class="prewrap"><?= e($a['client_notes']) ?></p><?php endif; ?>
<?php if (!empty($a['change_request_kind']) && $open) : ?><div class="panel-note"><strong>The client asks to <?= e($a['change_request_kind']) ?> this appointment</strong> (<?= e(local_time($a['change_requested_at'])) ?>)<p class="prewrap"><?= e($a['change_request_note']) ?></p>
    <?php if ($canManage) : ?><form method="post" action="/admin/delivery/appointments/<?= e($a['id']) ?>/dismiss"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Close the request without changing anything</button></form><?php endif; ?></div><?php endif; ?>
</div>

<?php if ($canManage && $open) : ?>
<div class="panel"><h2>Reschedule</h2>
    <form method="post" action="/admin/delivery/appointments/<?= e($a['id']) ?>/reschedule" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="rd">New delivery date</label><input id="rd" name="date" type="date" required value="<?= e(old('date')) ?>"><p class="hint">The new date is checked first (preparation time, blocked dates, capacity). The old date is released only when the move succeeds.</p></div>
        <?php if ($canConfigure) : ?><div class="field"><label class="check"><input type="checkbox" name="override" value="1"> Override the preparation time</label></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-confirm="Move this appointment and email the client?">Reschedule</button></div></form>
</div>
<div class="panel"><h2>Complete or cancel</h2>
    <form method="post" action="/admin/delivery/appointments/<?= e($a['id']) ?>/complete" class="inline-form" data-confirm="Mark this delivery as completed?"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Mark as completed</button></form>
    <form method="post" action="/admin/delivery/appointments/<?= e($a['id']) ?>/cancel" class="form-grid" data-confirm="Cancel this appointment? The date will be free again and the client is emailed."><?= csrf_field() ?>
        <div class="field"><label for="cr">Reason (shown to the client, optional)</label><input id="cr" name="reason" maxlength="300" value="<?= e(old('reason')) ?>"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Cancel appointment</button></div></form>
</div>
<?php endif; ?>

<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">internal</span></h2>
    <?php if (!empty($a['internal_notes'])) : ?><p class="prewrap"><?= e($a['internal_notes']) ?></p><?php else : ?><p class="muted">No notes yet.</p><?php endif; ?>
    <?php if ($canManage) : ?><form method="post" action="/admin/delivery/appointments/<?= e($a['id']) ?>/note" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="nt">Add a note</label><textarea id="nt" name="note" rows="2" maxlength="1000" required><?= e(old('note')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form><?php endif; ?>
</div>
<div class="panel"><h2>History</h2>
    <?php foreach ($history as $h) : ?><div class="note"><p><strong><?= e(ucfirst(str_replace('_', ' ', (string) $h['action']))) ?></strong> <span class="muted"><?= e(local_time($h['created_at'])) ?> · <?= e($h['user_name'] ?? $h['performer_label'] ?? 'System') ?></span></p>
        <?php if ($h['previous_value'] !== null || $h['new_value'] !== null) : ?><p class="muted"><?= e($h['previous_value'] ?? '—') ?> → <?= e($h['new_value'] ?? '—') ?></p><?php endif; ?></div><?php endforeach; ?>
</div>
