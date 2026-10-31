<?php
/** @var array<string,mixed> $project */ /** @var array<string,mixed> $plan */ /** @var ?array<string,mixed> $appt */ /** @var ?array<string,mixed> $cal */ /** @var string $selected */ /** @var string $base */ /** @var ?array<string,mixed> $client */
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
        <div><span class="muted">First date you can choose</span><br><?= $plan['eligible_from'] ? e(local_date($plan['eligible_from'], 'j F Y')) : '—' ?></div>
    </div>
    <?php if (!$booked) : ?><p class="<?= $plan['state'] === 'open' ? 'panel-note' : 'muted' ?>"><?= e($plan['message']) ?></p><?php endif; ?></div>

    <?php if ($booked) : ?>
    <div class="panel confirm-card"><h2><?= $appt['status'] === 'completed' ? 'Delivery completed' : 'Your Delivery Appointment Is Confirmed!' ?></h2>
        <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?></dd><dt>Project ID</dt><dd><?= e($project['code']) ?></dd>
            <dt>Selected Delivery Date</dt><dd><strong><?= e(DeliveryService::nice((string) $appt['appointment_date'])) ?></strong></dd><dt>Booking Reference</dt><dd><strong><?= e($appt['booking_reference']) ?></strong></dd><dt>Status</dt><dd><?= e(ucfirst((string) $appt['status'])) ?></dd></dl>
        <p class="muted">To change or cancel this appointment, <a href="/login">sign in to your dashboard</a> or contact our team and quote the reference.</p></div>
    <?php elseif ($cal !== null) : ?>
    <div class="panel" id="calendar"><h2>Choose your delivery date</h2>
        <?= View::partial('delivery-calendar', ['cal' => $cal, 'base' => $base, 'selected' => $selected, 'query' => []]) ?>
        <?php if ($selected !== '') : ?>
        <div class="review" id="review"><h3>Review your booking</h3>
            <dl class="detail-list"><dt>Project</dt><dd><?= e($project['title']) ?> (<?= e($project['code']) ?>)</dd><dt>Delivery date</dt><dd><strong><?= e(DeliveryService::nice($selected)) ?></strong></dd></dl>
            <form method="post" action="<?= e($base) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($selected) ?>">
                <div class="field"><label for="cn">Anything we should know? (optional)</label><textarea id="cn" name="client_notes" rows="2" maxlength="500"></textarea></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Confirm delivery appointment</button></div></form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="post" action="/delivery-appointment/exit"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Leave this page</button></form>
</div></section>
