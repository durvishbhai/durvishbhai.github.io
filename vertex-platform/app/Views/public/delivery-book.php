<?php
/** @var array<string,mixed> $project */ /** @var array<string,mixed> $plan */ /** @var ?array<string,mixed> $appt */ /** @var ?array<string,mixed> $cal */ /** @var string $selected */ /** @var string $base */ /** @var ?string $start */ /** @var bool $startFromUser */ /** @var ?array<string,mixed> $client */
use Vertex\Core\View;
use Vertex\Services\DeliveryService;
$booked = $appt !== null && $appt['status'] !== 'cancelled';
?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Project delivery</p><h1>Book your delivery date</h1>
    <div class="panel"><div class="detail-grid">
        <div><span class="muted">Project</span><br><?= e($project['title']) ?></div>
        <div><span class="muted">Project ID</span><br><?= e($project['code']) ?></div>
        <div><span class="muted">Client</span><br><?= e($client['contact_name'] ?? '') ?></div>
        <div><span class="muted">Estimated time</span><br><?= e($plan['duration']) ?></div>
        <div><span class="muted">Project start date</span><br><?= $plan['start'] ? e(local_date($plan['start'], 'j F Y')) : 'Not chosen yet' ?></div>
        <div><span class="muted">First delivery date you can choose</span><br><?= $plan['eligible_from'] ? e(local_date($plan['eligible_from'], 'j F Y')) : '—' ?></div>
    </div>
    <?php if (!$booked) : ?><p class="<?= $plan['state'] === 'open' ? 'panel-note' : 'muted' ?>"><?= e($plan['message']) ?></p><?php endif; ?></div>

    <?php if ($booked) : ?>
    <div class="panel confirm-card"><h2><?= $appt['status'] === 'completed' ? 'Delivery completed' : 'Your Delivery Appointment Is Confirmed!' ?></h2>
        <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?></dd><dt>Project ID</dt><dd><?= e($project['code']) ?></dd>
            <dt>Selected Delivery Date</dt><dd><strong><?= e(DeliveryService::nice((string) $appt['appointment_date'])) ?></strong></dd><dt>Booking Reference</dt><dd><strong><?= e($appt['booking_reference']) ?></strong></dd><dt>Status</dt><dd><?= e(ucfirst((string) $appt['status'])) ?></dd></dl>
        <p class="muted">To change or cancel this appointment, <a href="/login">sign in to your dashboard</a> or contact our team and quote the reference.</p></div>
    <?php elseif ($plan['state'] === 'need_start') : ?>
    <div class="panel" id="start"><h2>Step 1 · Choose your project start date</h2>
        <p class="muted">Our team estimates this project at <strong><?= e($plan['duration']) ?></strong>. Your delivery date must be <strong>after</strong> that time has passed, counted from the day the project starts.</p>
        <form method="get" action="<?= e($base) ?>#calendar" class="form-grid">
            <div class="field"><label for="sd">Project start date</label><input id="sd" name="start" type="date" required min="<?= e($plan['start_min']) ?>" max="<?= e($plan['start_max']) ?>"></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Show delivery dates</button></div></form>
    </div>
    <?php elseif ($cal !== null) : ?>
    <div class="panel" id="calendar"><h2>Step 2 · Choose your delivery date</h2>
        <p class="muted"><?= e($plan['message']) ?> Earlier dates, and dates that are fully booked or unavailable, cannot be selected.
            <?php if ($startFromUser) : ?><a href="<?= e($base) ?>#start">Change the start date</a><?php endif; ?></p>
        <?= View::partial('delivery-calendar', ['cal' => $cal, 'base' => $base, 'selected' => $selected, 'query' => $startFromUser ? ['start' => (string) $start] : []]) ?>
        <?php if ($selected !== '') : ?>
        <div class="review" id="review"><h3>Review your booking</h3>
            <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?> (<?= e($project['code']) ?>)</dd><dt>Project start date</dt><dd><?= e(local_date((string) $plan['start'], 'j F Y')) ?></dd><dt>Estimated time</dt><dd><?= e($plan['duration']) ?></dd></dl>
            <form method="post" action="<?= e($base) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($selected) ?>"><input type="hidden" name="start_date" value="<?= e((string) $plan['start']) ?>">
                <div class="field"><label for="cn">Anything we should know? (optional)</label><textarea id="cn" name="client_notes" rows="2" maxlength="500"></textarea></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Confirm delivery appointment · <?= e(local_date($selected, 'j M Y')) ?></button></div></form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="post" action="/delivery-appointment/exit"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Leave this page</button></form>
</div></section>
