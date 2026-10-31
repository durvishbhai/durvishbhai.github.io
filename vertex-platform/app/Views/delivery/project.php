<?php
/** @var array<string,mixed> $project */ /** @var string $role */ /** @var array<string,mixed> $plan */ /** @var ?array<string,mixed> $appt */ /** @var ?array<string,mixed> $cal */ /** @var string $mode */ /** @var string $selected */ /** @var string $base */
/** @var array<string,string> $sources */
use Vertex\Core\View;
$v = static fn (string $k, string $d = ''): string => old($k, $d);
$row = $plan['row'];
$staff = $role === 'staff';
$statusClass = ['confirmed' => 'ok', 'completed' => 'ok', 'cancelled' => 'bad'];
?>
<div class="page-head"><div><p class="eyebrow"><?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects/<?= e($project['public_id']) ?>">&larr; Project</a></div>
<?= View::partial('project-tabs', ['project' => $project, 'tab' => 'delivery']) ?>

<div class="panel"><h2>Project delivery appointment</h2>
    <div class="detail-grid">
        <div><span class="muted">Project</span><br><?= e($project['title']) ?></div>
        <div><span class="muted">Project ID</span><br><?= e($project['code']) ?></div>
        <div><span class="muted">Preparation time</span><br><?= $row ? e($row['preparation_days']) . ' day(s)' : 'Not set yet' ?></div>
        <div><span class="muted">First date you can choose</span><br><?= $plan['eligible_from'] ? e(local_date($plan['eligible_from'], 'j F Y')) : '—' ?></div>
        <div><span class="muted">Appointment date</span><br><?= $appt && $appt['status'] !== 'cancelled' ? e(local_date((string) $appt['appointment_date'], 'j F Y')) : 'Not booked' ?></div>
        <div><span class="muted">Status</span><br><?php if ($appt) : ?><span class="badge badge-d-<?= e($appt['status']) ?>"><?= e(ucfirst((string) $appt['status'])) ?></span><?php else : ?>Not booked<?php endif; ?></div>
    </div>
    <?php if ($appt === null || $appt['status'] === 'cancelled') : ?><p class="<?= $plan['state'] === 'open' ? 'panel-note' : 'muted' ?>"><?= e($plan['message']) ?></p><?php endif; ?>
</div>

<?php if ($appt !== null && $appt['status'] !== 'cancelled') : ?>
<div class="panel confirm-card"><h2><?= $appt['status'] === 'completed' ? 'Delivery completed' : 'Your Delivery Appointment Is Confirmed!' ?></h2>
    <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?></dd><dt>Project ID</dt><dd><?= e($project['code']) ?></dd>
        <dt>Selected Delivery Date</dt><dd><strong><?= e(\Vertex\Services\DeliveryService::nice((string) $appt['appointment_date'])) ?></strong></dd>
        <dt>Booking Reference</dt><dd><strong><?= e($appt['booking_reference']) ?></strong></dd><dt>Status</dt><dd><?= e(ucfirst((string) $appt['status'])) ?></dd>
        <?php if ($staff) : ?><dt>Booking source</dt><dd><?= e($sources[$appt['booking_source']] ?? $appt['booking_source']) ?></dd><?php endif; ?></dl>
    <?php if (!$staff && !empty($appt['change_request_kind'])) : ?><p class="panel-note">You asked our team to <?= e($appt['change_request_kind'] === 'cancel' ? 'cancel' : 'reschedule') ?> this appointment. We will contact you.</p><?php endif; ?>
    <?php if ($staff) : ?><p><a class="btn btn-ghost btn-sm" href="/admin/delivery/appointments/<?= e($appt['id']) ?>">Manage this appointment</a></p><?php endif; ?>
    <?php if (!$staff && $appt['status'] === 'confirmed' && !empty($may)) : ?>
        <h3>Need to change it?</h3>
        <?php if ($may['reschedule']['self']) : ?><p><a class="btn btn-ghost" href="<?= e($base) ?>?mode=reschedule#calendar">Choose a different date</a>
            <span class="muted">You can move it yourself until <?= e((int) config('delivery.client_cutoff_days', 3)) ?> day(s) before the date.</span></p>
        <?php else : ?><p class="muted"><?= e($may['reschedule']['reason']) ?></p><?php endif; ?>
        <?php if ($may['cancel']['self']) : ?>
        <form method="post" action="<?= e($base) ?>/cancel" data-confirm="Cancel this delivery appointment? The date will be released."><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($appt['booking_reference']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Cancel appointment</button></form>
        <?php endif; ?>
        <?php if (!$may['reschedule']['self'] || !$may['cancel']['self']) : ?>
        <form method="post" action="<?= e($base) ?>/request-change" class="form-grid"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($appt['booking_reference']) ?>">
            <div class="field"><label for="rk">What do you need?</label><select id="rk" name="kind"><option value="reschedule">Move my appointment</option><option value="cancel">Cancel my appointment</option></select></div>
            <div class="field"><label for="rn">Tell us briefly</label><textarea id="rn" name="note" rows="3" maxlength="500" required><?= e($v('note')) ?></textarea></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Send request to our team</button></div></form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cal !== null) : ?>
<div class="panel" id="calendar"><h2><?= $mode === 'reschedule' ? 'Choose a new delivery date' : 'Choose your delivery date' ?></h2>
    <p class="muted"><?= $mode === 'reschedule' ? 'Your current date stays until you confirm a new one.' : e($plan['message']) ?> Dates that are fully booked or unavailable cannot be selected.</p>
    <?= View::partial('delivery-calendar', ['cal' => $cal, 'base' => $base, 'selected' => $selected, 'query' => $mode === 'reschedule' ? ['mode' => 'reschedule'] : []]) ?>
    <?php if ($selected !== '') : ?>
    <div class="review" id="review"><h3>Review your booking</h3>
        <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?> (<?= e($project['code']) ?>)</dd><dt>Delivery date</dt><dd><strong><?= e(\Vertex\Services\DeliveryService::nice($selected)) ?></strong></dd>
            <?php if ($mode === 'reschedule' && $appt) : ?><dt>Replaces</dt><dd><?= e(local_date((string) $appt['appointment_date'], 'j F Y')) ?></dd><?php endif; ?></dl>
        <?php if ($staff && !$canManage) : ?><p class="muted">You can view this project's delivery calendar. Booking for clients needs the "manage delivery appointments" permission.</p><?php else : ?>
        <form method="post" action="<?= e($base) ?>/<?= $staff ? 'manual' : ($mode === 'reschedule' ? 'reschedule' : 'book') ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($selected) ?>"><?php if ($staff) : ?><input type="hidden" name="source" value="manual"><?php endif; ?>
            <?php if ($mode === 'reschedule' && $appt) : ?><input type="hidden" name="reference" value="<?= e($appt['booking_reference']) ?>"><?php endif; ?>
            <?php if ($mode !== 'reschedule' && !$staff) : ?><div class="field"><label for="cn">Anything we should know? (optional)</label><textarea id="cn" name="client_notes" rows="2" maxlength="500"></textarea></div><?php endif; ?>
            <?php if ($staff) : ?><div class="field"><label for="cin">Internal note (optional)</label><textarea id="cin" name="internal_notes" rows="2" maxlength="2000"></textarea></div><?php endif; ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $staff ? 'Book for the client' : ($mode === 'reschedule' ? 'Confirm new date' : 'Confirm delivery appointment') ?></button></div></form><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($staff) : ?>
<div class="panel"><h2>Delivery settings for this project</h2>
    <?php if (!$canConfigure) : ?><p class="muted">Only the Owner and Administrators can change the preparation time.</p><?php endif; ?>
    <p class="muted">The client can choose a delivery date only after the preparation time is over. Preparation time counts from the start date (today when you first save). Example: start 1 November + 60 days = <?= e(local_date('2026-12-31', 'j F Y')) ?>; set "Earliest selectable date" if you want a later first day (for example 1 January). Changing the time never touches an appointment that is already confirmed.</p>
    <form method="post" action="<?= e($base) ?>/plan" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="pd">Preparation time (days)</label><input id="pd" name="preparation_days" inputmode="numeric" required value="<?= e($v('preparation_days', (string) ($row['preparation_days'] ?? ''))) ?>"<?= $canConfigure ? '' : ' disabled' ?>><p class="hint">For example 7, 15, 30 or 60.</p></div>
        <div class="field"><label for="ps">Preparation starts on</label><input id="ps" name="prep_start_date" type="date" value="<?= e($v('prep_start_date', (string) ($row['prep_start_date'] ?? ''))) ?>"<?= $canConfigure ? '' : ' disabled' ?>><p class="hint">Leave empty to use today (or keep the saved date).</p></div>
        <div class="field"><label>Booking eligibility date</label><input value="<?= e($row ? local_date((string) $row['eligibility_date'], 'j F Y') : 'Calculated when you save') ?>" disabled></div>
        <div class="field"><label for="pe">Earliest selectable delivery date (optional)</label><input id="pe" name="earliest_date" type="date" value="<?= e($v('earliest_date', (string) ($row['earliest_date'] ?? ''))) ?>"<?= $canConfigure ? '' : ' disabled' ?>></div>
        <div class="field"><label for="pl">Latest selectable delivery date (optional)</label><input id="pl" name="latest_date" type="date" value="<?= e($v('latest_date', (string) ($row['latest_date'] ?? ''))) ?>"<?= $canConfigure ? '' : ' disabled' ?>></div>
        <div class="field"><label class="check"><input type="checkbox" name="booking_enabled" value="1"<?= !$row || (int) $row['booking_enabled'] === 1 ? ' checked' : '' ?><?= $canConfigure ? '' : ' disabled' ?>> Booking is open for this project</label>
            <?php if ($isOwner) : ?><label class="check"><input type="checkbox" name="allow_multiple" value="1"<?= $row && (int) $row['allow_multiple'] === 1 ? ' checked' : '' ?>> Allow more than one active appointment (Owner only)</label><?php endif; ?></div>
        <?php if ($canConfigure) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save delivery settings</button></div><?php endif; ?>
    </form>
    <p>Status: <strong><?= e(ucfirst(str_replace('_', ' ', $plan['state']))) ?></strong> &middot; first selectable date <?= e(local_date($plan['earliest'], 'j F Y')) ?> &middot; last <?= e(local_date($plan['latest'], 'j F Y')) ?></p>
</div>

<?php if ($canConfigure && $row) : ?>
<div class="panel"><h2>Direct booking link</h2>
    <p class="muted">A private link that lets this client book without signing in (<?= e($publicMode === 'off' ? 'currently switched off in Delivery capacity & settings' : 'mode: ' . $publicMode) ?>). It is shown only once; links stay valid for <?= e((int) config('delivery.token_days', 30)) ?> days. Links in use now: <?= e($liveLinks) ?>.</p>
    <?php if (!empty($newLink)) : ?><p class="panel-note"><strong>New link (copy it now):</strong><br><code class="wrap"><?= e($newLink) ?></code></p><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/link" class="inline-form"><?= csrf_field() ?><label class="check"><input type="checkbox" name="send_email" value="1"> Also email it to the client</label> <button class="btn btn-ghost btn-sm" type="submit">Create a new link</button></form>
    <?php if ($liveLinks > 0) : ?><form method="post" action="<?= e($base) ?>/link/revoke" data-confirm="Switch off every booking link of this project?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Switch off all links</button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canManage && $row) : ?>
<div class="panel"><h2>Book for the client (phone, WhatsApp, in person)</h2>
    <p class="muted">Uses the same daily capacity as online bookings, and the same preparation rules.<?= $canConfigure ? ' As Owner or Administrator you may tick the override to book inside the preparation time.' : '' ?></p>
    <form method="post" action="<?= e($base) ?>/manual" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="md">Delivery date</label><input id="md" name="date" type="date" required value="<?= e($v('date')) ?>"></div>
        <div class="field"><label for="ms">Booking type</label><select id="ms" name="source"><option value="manual">Manual / Offline Booking</option><option value="admin_reserved"<?= $v('source') === 'admin_reserved' ? ' selected' : '' ?>>Admin Reserved Slot (uses a reserved slot on that date)</option></select></div>
        <div class="field"><label for="mn">Internal note</label><textarea id="mn" name="internal_notes" rows="2" maxlength="2000"><?= e($v('internal_notes')) ?></textarea></div>
        <?php if ($canConfigure) : ?><div class="field"><label class="check"><input type="checkbox" name="override" value="1"> Override the preparation time</label></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-confirm="Book this date for the client and email them?">Book this date</button></div></form>
</div>
<?php endif; ?>

<div class="panel"><h2>Appointments of this project</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Date</th><th>Status</th><th>Source</th><th></th></tr></thead><tbody>
        <?php foreach ($all as $a) : ?><tr><td><?= e($a['booking_reference']) ?></td><td><?= e(local_date((string) $a['appointment_date'])) ?></td><td><span class="badge badge-d-<?= e($a['status']) ?>"><?= e(ucfirst((string) $a['status'])) ?></span></td><td><?= e($sources[$a['booking_source']] ?? $a['booking_source']) ?></td><td><a href="/admin/delivery/appointments/<?= e($a['id']) ?>">Open</a></td></tr><?php endforeach; ?>
        <?php if ($all === []) : ?><tr><td colspan="5" class="muted">No appointments yet.</td></tr><?php endif; ?></tbody></table></div>
</div>
<?php endif; ?>
