<?php /** @var list<array<string,mixed>> $items */ ?>
<div class="page-head"><div><p class="eyebrow">Projects &amp; delivery</p><h1>Delivery appointments</h1></div></div>
<p class="muted">When your project has been prepared, you choose the day we deliver it. Open a project below.</p>
<?php foreach ($items as $i) : $p = $i['project']; $a = $i['appt']; ?>
<div class="panel"><h2><?= e($p['title']) ?> <span class="muted">· <?= e($p['code']) ?></span></h2>
    <?php if ($a !== null && $a['status'] !== 'cancelled') : ?>
        <p><span class="badge badge-d-<?= e($a['status']) ?>"><?= e(ucfirst((string) $a['status'])) ?></span> <strong><?= e(\Vertex\Services\DeliveryService::nice((string) $a['appointment_date'])) ?></strong> · reference <?= e($a['booking_reference']) ?></p>
    <?php else : ?><p><?= e($i['plan']['message']) ?></p><?php endif; ?>
    <p><a class="btn btn-<?= $i['plan']['state'] === 'open' && $a === null ? 'primary' : 'ghost' ?> btn-sm" href="/projects/<?= e($p['public_id']) ?>/delivery"><?= $i['plan']['state'] === 'open' && ($a === null || $a['status'] === 'cancelled') ? 'Book delivery appointment' : 'Open' ?></a></p></div>
<?php endforeach; ?>
<?php if ($items === []) : ?><div class="panel"><p class="muted">You have no projects yet.</p></div><?php endif; ?>
