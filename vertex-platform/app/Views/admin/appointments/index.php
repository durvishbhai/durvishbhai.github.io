<?php
/** @var list<array<string,mixed>> $slots */ /** @var list<array<string,mixed>> $places */ /** @var array<int,string> $dayNames */ /** @var list<array<string,mixed>> $requests */ /** @var string $host */
$time = static fn (string $t): string => substr($t, 0, 5);
?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Appointments</h1></div><a class="btn btn-ghost" href="/book-appointment">See the booking form</a></div>
<p class="muted">Visitors and clients ask to meet <strong><?= e($host) ?></strong> at <a href="/book-appointment">/book-appointment</a> (signed in or not). They choose one of the times and places below. A request is <strong>never a confirmed booking</strong>: the team contacts the person first.</p>

<div class="panel" id="times"><h2>Available timings</h2>
    <p class="muted">Each timing is a <strong>window</strong>, for example 11:00 AM to 11:00 PM. The visitor chooses their own start and end inside it (in steps of the <em>step</em> you set, within the shortest and longest meeting length), for example 11 AM to 12 PM or 5 PM to 11 PM. Once a request holds a range on a date, nobody else can book an overlapping range that day; the rest of the window stays open. Nothing is offered until you add at least one window.</p>
    <?php if ($slots === []) : ?><p class="empty-state">No times yet: the booking form tells visitors that no times are open.</p><?php endif; ?>
    <?php foreach ($slots as $s) : ?>
    <form method="post" action="/admin/appointments/slots/<?= e((string) $s['id']) ?>" class="slot-row"><?= csrf_field() ?>
        <div class="field"><label>Name <span class="muted">(optional)</span></label><input name="label" maxlength="80" value="<?= e((string) ($s['label'] ?? '')) ?>"></div>
        <div class="field"><label>From</label><input name="start_time" type="time" required value="<?= e($time((string) $s['start_time'])) ?>"></div>
        <div class="field"><label>To</label><input name="end_time" type="time" required value="<?= e($time((string) $s['end_time'])) ?>"></div>
        <fieldset class="field days"><legend>Weekdays</legend><?php foreach ($dayNames as $n => $d) : ?><label class="check"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= ((int) $s['weekdays'] & (1 << ($n - 1))) ? ' checked' : '' ?>> <?= e($d) ?></label><?php endforeach; ?></fieldset>
        <div class="field"><label>Step (min)</label><select name="step_minutes"><?php foreach ([5, 10, 15, 20, 30, 60] as $st) : ?><option value="<?= $st ?>"<?= (int) $s['step_minutes'] === $st ? ' selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Shortest (min)</label><input name="min_minutes" type="number" min="5" max="1440" value="<?= e((string) $s['min_minutes']) ?>"></div>
        <div class="field"><label>Longest (min, 0 = no limit)</label><input name="max_minutes" type="number" min="0" max="1440" value="<?= e((string) $s['max_minutes']) ?>"></div>
        <div class="field"><label>Order</label><input name="sort_order" type="number" min="0" max="9999" value="<?= e((string) $s['sort_order']) ?>"></div>
        <div class="field"><label class="check"><input type="checkbox" name="active" value="1"<?= (int) $s['active'] === 1 ? ' checked' : '' ?>> Offered</label></div>
        <div class="row-actions"><button class="btn btn-ghost btn-sm" type="submit">Save</button>
            <button class="btn btn-ghost btn-sm" type="submit" formaction="/admin/appointments/slots/<?= e((string) $s['id']) ?>/delete" data-confirm="Delete the time <?= e((string) $s['text']) ?>? Earlier requests keep their own text.">Delete</button></div>
    </form>
    <?php endforeach; ?>
    <h3>Add a time</h3>
    <form method="post" action="/admin/appointments/slots" class="slot-row"><?= csrf_field() ?>
        <div class="field"><label for="n-label">Name <span class="muted">(optional)</span></label><input id="n-label" name="label" maxlength="80" value="<?= e(old('label')) ?>" placeholder="e.g. Morning"></div>
        <div class="field"><label for="n-from">From</label><input id="n-from" name="start_time" type="time" required value="<?= e(old('start_time')) ?>"></div>
        <div class="field"><label for="n-to">To</label><input id="n-to" name="end_time" type="time" required value="<?= e(old('end_time')) ?>"></div>
        <fieldset class="field days"><legend>Weekdays</legend><?php foreach ($dayNames as $n => $d) : ?><label class="check"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= $n <= 5 ? ' checked' : '' ?>> <?= e($d) ?></label><?php endforeach; ?></fieldset>
        <div class="field"><label for="n-step">Step (min)</label><select id="n-step" name="step_minutes"><?php foreach ([5, 10, 15, 20, 30, 60] as $st) : ?><option value="<?= $st ?>"<?= $st === 30 ? ' selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="n-min">Shortest (min)</label><input id="n-min" name="min_minutes" type="number" min="5" max="1440" value="30"></div>
        <div class="field"><label for="n-max">Longest (min, 0 = no limit)</label><input id="n-max" name="max_minutes" type="number" min="0" max="1440" value="0"></div>
        <div class="field"><label for="n-ord">Order</label><input id="n-ord" name="sort_order" type="number" min="0" max="9999" value="0"></div>
        <div class="field"><label class="check"><input type="checkbox" name="active" value="1" checked> Offered</label></div>
        <div class="row-actions"><button class="btn btn-primary btn-sm" type="submit">Add time</button></div>
    </form>
</div>

<div class="panel" id="places"><h2>Places for in-person meetings</h2>
    <p class="muted">Shown when the visitor chooses an in-person meeting. <strong>"<?= e(\Vertex\Services\AppointmentConfig::LATER_LABEL) ?>"</strong> is always offered as the last option and cannot be edited or removed.</p>
    <?php foreach ($places as $p) : ?>
    <form method="post" action="/admin/appointments/places/<?= e((string) $p['id']) ?>" class="slot-row"><?= csrf_field() ?>
        <div class="field"><label>Place</label><input name="name" required maxlength="160" value="<?= e((string) $p['name']) ?>"></div>
        <div class="field"><label>Details <span class="muted">(optional)</span></label><input name="details" maxlength="300" value="<?= e((string) ($p['details'] ?? '')) ?>"></div>
        <div class="field"><label>Order</label><input name="sort_order" type="number" min="0" max="9999" value="<?= e((string) $p['sort_order']) ?>"></div>
        <div class="field"><label class="check"><input type="checkbox" name="active" value="1"<?= (int) $p['active'] === 1 ? ' checked' : '' ?>> Offered</label></div>
        <div class="row-actions"><button class="btn btn-ghost btn-sm" type="submit">Save</button>
            <button class="btn btn-ghost btn-sm" type="submit" formaction="/admin/appointments/places/<?= e((string) $p['id']) ?>/delete" data-confirm="Delete this place?">Delete</button></div>
    </form>
    <?php endforeach; ?>
    <div class="slot-row permanent"><div class="field"><label>Permanent option</label><input value="<?= e(\Vertex\Services\AppointmentConfig::LATER_LABEL) ?>" disabled></div></div>
    <h3>Add a place</h3>
    <form method="post" action="/admin/appointments/places" class="slot-row"><?= csrf_field() ?>
        <div class="field"><label for="p-name">Place</label><input id="p-name" name="name" required maxlength="160" value="<?= e(old('name')) ?>" placeholder="e.g. KIS office"></div>
        <div class="field"><label for="p-det">Details <span class="muted">(optional)</span></label><input id="p-det" name="details" maxlength="300" value="<?= e(old('details')) ?>"></div>
        <div class="field"><label for="p-ord">Order</label><input id="p-ord" name="sort_order" type="number" min="0" max="9999" value="0"></div>
        <div class="field"><label class="check"><input type="checkbox" name="active" value="1" checked> Offered</label></div>
        <div class="row-actions"><button class="btn btn-primary btn-sm" type="submit">Add place</button></div>
    </form>
</div>

<div class="panel"><h2>Latest appointment requests</h2>
    <?php if ($requests === []) : ?><p class="muted">No requests yet.</p><?php else : ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Person</th><th>When</th><th>Type and place</th><th>Status</th><th>Time lock</th></tr></thead><tbody>
    <?php foreach ($requests as $r) : $d = $r['d']; ?>
        <tr><td><?= can('enquiries.view_all') ? '<a href="/crm/enquiries/' . e($r['public_id']) . '">' . e($r['reference']) . '</a>' : e($r['reference']) ?></td>
            <td><strong><?= e($r['contact_name']) ?></strong><br><span class="muted"><?= e($r['email']) ?> · <?= e((string) $r['phone']) ?></span></td>
            <td><?= e((string) ($d['Meeting date'] ?? '')) ?><br><span class="muted"><?= e((string) ($d['Meeting time'] ?? '')) ?></span></td>
            <td><?= e((string) ($d['Meeting type'] ?? '')) ?><?= isset($d['Meeting place']) ? '<br><span class="muted">' . e((string) $d['Meeting place']) . '</span>' : '' ?></td>
            <td><?= e((string) $r['stage']) ?></td>
            <td><?php if ($r['booking_id'] === null) : ?><span class="muted">none</span><?php elseif ($r['released_at'] !== null || $r['stage_kind'] === 'lost') : ?><span class="muted">Free again</span><?php else : ?>Held <form method="post" action="/admin/appointments/bookings/<?= e((string) $r['booking_id']) ?>/release" class="inline-form" data-confirm="Release this time so someone else can book it?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Release</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="muted">Confirm, move or decline a request from its page in the CRM (call or message the person first to check it is genuine). A held time is freed automatically when the request is marked lost or archived, or when you press Release.</p><?php endif; ?>
</div>
