<?php
/** @var array<string,mixed> $cal */ /** @var list<array<string,mixed>> $overrides */ /** @var list<array<string,mixed>> $reservations */ /** @var list<array<string,mixed>> $log */ /** @var int $defaultCapacity */ /** @var array<string,mixed> $values */
use Vertex\Core\View;
$v = static fn (string $k, string $d = ''): string => old($k, $d);
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head"><div><p class="eyebrow">Projects &amp; delivery</p><h1>Delivery capacity &amp; settings</h1></div><a class="btn btn-ghost" href="/admin/delivery">&larr; Delivery appointments</a></div>

<div class="panel"><h2>Settings</h2>
    <form method="post" action="/admin/delivery/capacity/defaults" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="dc">Default daily capacity</label><input id="dc" name="default_capacity" inputmode="numeric" value="<?= e($v('default_capacity', (string) $defaultCapacity)) ?>"><p class="hint">Confirmed delivery appointments allowed per day (default 2). Lowering it never removes a confirmed appointment; the date just shows no free slots.</p></div>
        <div class="field"><label for="ne">Team notification address</label><input id="ne" name="notify_email" type="email" maxlength="190" value="<?= e($v('notify_email', (string) $values['notify_email'])) ?>"><p class="hint">Receives a copy of every booking, change and blocked date. Empty = the sender address of the email settings.</p></div>
        <div class="field"><label for="pm">Booking without signing in</label><select id="pm" name="public_mode">
            <?php foreach (['off' => 'Off', 'otp' => 'Project ID + client name, then a code emailed to the client', 'token' => 'Private links created by the team only', 'both' => 'Both'] as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($v('public_mode', (string) $values['public_mode']), $c) ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <p class="hint">Public page: <a href="/delivery-appointment">/delivery-appointment</a>. A project ID and name never unlock a project by themselves.</p></div>
        <div class="field"><label for="co">Clients can change or cancel themselves until (days before the date)</label><input id="co" name="client_cutoff_days" inputmode="numeric" value="<?= e($v('client_cutoff_days', (string) $values['client_cutoff_days'])) ?>"></div>
        <div class="field"><label for="mr">Times a client can move the appointment themselves</label><input id="mr" name="max_reschedules" inputmode="numeric" value="<?= e($v('max_reschedules', (string) $values['max_reschedules'])) ?>"></div>
        <div class="field"><label for="md">Booking window (days ahead, when a project has no last date)</label><input id="md" name="max_days_ahead" inputmode="numeric" value="<?= e($v('max_days_ahead', (string) $values['max_days_ahead'])) ?>"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save settings</button></div></form>
</div>

<div class="panel"><h2>Calendar</h2>
    <?= View::partial('delivery-calendar', ['cal' => $cal, 'base' => '/admin/delivery/capacity', 'selected' => '', 'query' => [], 'admin' => true]) ?>
</div>

<div class="panel"><h2>Change one date</h2>
    <div class="grid-2">
    <form method="post" action="/admin/delivery/capacity/date" class="form-grid"><?= csrf_field() ?><h3>Capacity of one date</h3>
        <div class="field"><label for="cd">Date</label><input id="cd" name="date" type="date" required value="<?= e($v('date')) ?>"></div>
        <div class="field"><label for="cc">Capacity (empty = default <?= e($defaultCapacity) ?>)</label><input id="cc" name="capacity" inputmode="numeric" value="<?= e($v('capacity')) ?>"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Save capacity</button></div></form>
    <form method="post" action="/admin/delivery/capacity/block" class="form-grid" data-confirm="Mark this date fully booked? Clients can no longer choose it. Confirmed appointments on it stay."><?= csrf_field() ?><h3>Mark date as fully booked</h3>
        <div class="field"><label for="bd">Date</label><input id="bd" name="date" type="date" required value="<?= e($v('date')) ?>"></div>
        <div class="field"><label for="br">Internal reason</label><input id="br" name="reason" required maxlength="255" value="<?= e($v('reason')) ?>"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Block date</button></div></form>
    <form method="post" action="/admin/delivery/capacity/reserve" class="form-grid"><?= csrf_field() ?><h3>Reserve capacity for internal planning</h3>
        <div class="field"><label for="rd">Date</label><input id="rd" name="date" type="date" required value="<?= e($v('date')) ?>"></div>
        <div class="field"><label for="rs">Slots to reserve</label><input id="rs" name="slots" inputmode="numeric" required value="<?= e($v('slots', '1')) ?>"></div>
        <div class="field"><label for="rr">Reason</label><input id="rr" name="reason" required maxlength="255" value="<?= e($v('reason')) ?>"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Reserve</button></div></form>
    </div>
</div>

<div class="panel"><h2>Blocked dates and special capacity</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Capacity</th><th>Blocked</th><th>Reason (internal)</th><th></th></tr></thead><tbody>
        <?php foreach ($overrides as $o) : ?><tr><td><?= e(local_date((string) $o['appointment_date'], 'D, j M Y')) ?></td><td><?= $o['max_capacity'] === null ? 'default' : e($o['max_capacity']) ?></td><td><?= (int) $o['blocked'] === 1 ? '<span class="badge badge-d-cancelled">Fully booked</span>' : '—' ?></td><td><?= e($o['block_reason'] ?? '') ?></td>
            <td><?php if ((int) $o['blocked'] === 1) : ?><form method="post" action="/admin/delivery/capacity/unblock"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($o['appointment_date']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Open again</button></form><?php endif; ?>
                <?php if ($o['max_capacity'] !== null) : ?><form method="post" action="/admin/delivery/capacity/date"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($o['appointment_date']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Use default</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if ($overrides === []) : ?><tr><td colspan="5" class="muted">None.</td></tr><?php endif; ?></tbody></table></div>
</div>

<div class="panel"><h2>Reserved capacity</h2>
    <p class="muted">Reserved slots lower what clients can book. They are internal planning, not client appointments.</p>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Slots</th><th>Reason</th><th>By</th><th></th></tr></thead><tbody>
        <?php foreach ($reservations as $r) : ?><tr><td><?= e(local_date((string) $r['appointment_date'], 'D, j M Y')) ?></td><td><?= e($r['reserved_slots']) ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['user_name'] ?? '') ?></td>
            <td><form method="post" action="/admin/delivery/capacity/reservations/<?= e($r['id']) ?>/release" data-confirm="Release this reservation?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Release</button></form></td></tr><?php endforeach; ?>
        <?php if ($reservations === []) : ?><tr><td colspan="5" class="muted">Nothing reserved.</td></tr><?php endif; ?></tbody></table></div>
</div>

<div class="panel"><h2>Recent capacity and settings changes</h2>
    <?php foreach ($log as $h) : ?><p><strong><?= e(ucfirst(str_replace('_', ' ', (string) $h['action']))) ?></strong> <?= $h['appointment_date'] ? '· ' . e(local_date((string) $h['appointment_date'])) : '' ?> <span class="muted">· <?= e(local_time($h['created_at'])) ?> · <?= e($h['user_name'] ?? '') ?> <?= e($h['new_value'] ?? '') ?></span></p><?php endforeach; ?>
    <?php if ($log === []) : ?><p class="muted">Nothing yet.</p><?php endif; ?>
</div>
