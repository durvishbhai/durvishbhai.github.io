<?php
/** @var array<string,int> $stats */ /** @var array<string,mixed> $cal */ /** @var string $date */ /** @var list<array<string,mixed>> $dateRows */ /** @var ?array<string,mixed> $dateInfo */
/** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */
/** @var bool $canManage */ /** @var bool $canConfigure */ /** @var list<array<string,mixed>> $projects */ /** @var array<string,string> $sources */ /** @var array<string,string> $statuses */
use Vertex\Core\View;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
$v = static fn (string $k): string => old($k);
$cards = [['today', "Today's appointments"], ['upcoming', 'Upcoming'], ['confirmed', 'Total confirmed'], ['requests', 'Client requests to answer'], ['full_dates', 'Fully booked / blocked dates'], ['reserved_dates', 'Dates with reserved capacity'], ['offline', 'Offline bookings'], ['completed', 'Completed'], ['cancelled', 'Cancelled']];
?>
<div class="page-head"><div><p class="eyebrow">Projects &amp; delivery</p><h1>Delivery appointments</h1></div>
    <?php if ($canConfigure) : ?><a class="btn btn-ghost" href="/admin/delivery/capacity">Capacity, blocked dates &amp; settings</a><?php endif; ?></div>
<div class="stat-grid"><?php foreach ($cards as [$k, $l]) : ?><a class="stat" href="<?= $k === 'requests' ? '/admin/delivery?requests=1' : '#appointments' ?>"><span class="stat-n"><?= e($stats[$k]) ?></span><span class="stat-l"><?= e($l) ?></span></a><?php endforeach; ?></div>

<div class="panel"><h2>Calendar</h2>
    <?= View::partial('delivery-calendar', ['cal' => $cal, 'base' => '/admin/delivery', 'selected' => $date, 'query' => [], 'admin' => true]) ?>
    <?php if ($date !== '') : ?>
    <div id="day"><h3><?= e(local_date($date, 'l, j F Y')) ?></h3>
        <p class="muted"><?= e($dateInfo['used']) ?> booked · <?= e($dateInfo['reserved']) ?> reserved · capacity <?= e($dateInfo['cap']) ?> ·
            <?= $dateInfo['blocked'] ? '<strong>marked fully booked</strong>' . ($dateInfo['reason'] ? ' (' . e($dateInfo['reason']) . ')' : '') : e($dateInfo['remaining']) . ' slot(s) left for clients' ?></p>
        <?php foreach ($dateRows as $r) : ?><p><a href="/admin/delivery/appointments/<?= e($r['id']) ?>"><strong><?= e($r['project_code']) ?></strong></a> · <?= e($r['project_title']) ?> · <?= e($r['company_name'] ?: $r['contact_name']) ?> · <span class="badge badge-d-<?= e($r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span> · <?= e($sources[$r['booking_source']] ?? $r['booking_source']) ?></p><?php endforeach; ?>
        <?php if ($dateRows === []) : ?><p class="muted">No appointments on this date.</p><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManage) : ?>
<div class="panel"><h2>Create a manual / offline booking</h2>
    <p class="muted">For a client who asked by phone or WhatsApp. It uses the same daily capacity and the same rules as an online booking, and the client is emailed.</p>
    <form method="post" action="/admin/delivery/manual" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="mp">Client and project</label><select id="mp" name="project" required><option value="">Choose a project…</option>
            <?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $sel($v('project'), $p['public_id']) ?>><?= e($p['code']) ?> · <?= e($p['title']) ?> · <?= e($p['company_name'] ?: $p['contact_name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="mdt">Delivery date</label><input id="mdt" name="date" type="date" required value="<?= e($v('date')) ?>"></div>
        <div class="field"><label for="msr">Booking type</label><select id="msr" name="source"><option value="manual">Manual / Offline Booking</option><option value="admin_reserved"<?= $sel($v('source'), 'admin_reserved') ?>>Admin Reserved Slot (uses a reserved slot on that date)</option></select></div>
        <div class="field"><label for="mnt">Internal note</label><textarea id="mnt" name="internal_notes" rows="2" maxlength="2000"><?= e($v('internal_notes')) ?></textarea></div>
        <?php if ($canConfigure) : ?><div class="field"><label class="check"><input type="checkbox" name="override" value="1"> Override the project's preparation time</label></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-confirm="Create this booking and email the client?">Create booking</button></div></form>
</div>
<?php endif; ?>

<div class="panel" id="appointments"><h2>All appointments</h2>
    <form method="get" action="/admin/delivery" class="toolbar" role="search">
        <div class="field"><label for="fp">Project</label><input id="fp" name="project" maxlength="100" placeholder="Project ID or title" value="<?= e($filters['project']) ?>"></div>
        <div class="field"><label for="fc">Client</label><input id="fc" name="client" maxlength="100" placeholder="Name, company or email" value="<?= e($filters['client']) ?>"></div>
        <div class="field"><label for="ff">From</label><input id="ff" name="from" type="date" value="<?= e($filters['from']) ?>"></div>
        <div class="field"><label for="ft">To</label><input id="ft" name="to" type="date" value="<?= e($filters['to']) ?>"></div>
        <div class="field"><label for="fs">Status</label><select id="fs" name="status"><option value="">All</option><?php foreach ($statuses as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="fo">Source</label><select id="fo" name="source"><option value="">All</option><?php foreach ($sources as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['source'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="check"><input type="checkbox" name="requests" value="1"<?= $filters['requests'] ? ' checked' : '' ?>> Client requests only</label></div>
        <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Project</th><th>Client</th><th>Status</th><th>Source</th><th>Reference</th></tr></thead><tbody>
        <?php foreach ($rows as $r) : ?><tr><td><a href="/admin/delivery/appointments/<?= e($r['id']) ?>"><?= e(local_date((string) $r['appointment_date'])) ?></a></td>
            <td><strong><?= e($r['project_code']) ?></strong> · <?= e($r['project_title']) ?></td><td><?= e($r['company_name'] ?: $r['contact_name']) ?></td>
            <td><span class="badge badge-d-<?= e($r['status']) ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span><?php if (!empty($r['change_request_kind']) && $r['status'] === 'confirmed') : ?> <span class="badge badge-d-request">asks to <?= e($r['change_request_kind']) ?></span><?php endif; ?></td>
            <td><?= e($sources[$r['booking_source']] ?? $r['booking_source']) ?></td><td><?= e($r['booking_reference']) ?></td></tr><?php endforeach; ?>
        <?php if ($rows === []) : ?><tr><td colspan="6" class="muted">No appointments match.</td></tr><?php endif; ?></tbody></table></div>
    <?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
</div>
