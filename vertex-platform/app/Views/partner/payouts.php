<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $qs */ /** @var array<string,int> $balance */ /** @var bool $hasDetails */ /** @var ?string $hint */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Partner portal</p><h1>My payouts</h1></div><div class="action-row"><a class="btn btn-primary" href="/partner/payouts/new">Request a payout</a><a class="btn btn-ghost" href="/partner/payout-details">Payout details</a></div></div>
<div class="stat-grid"><div class="stat"><span class="stat-n"><?= e(money($balance['available'])) ?></span><span class="stat-l">Available to request</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['reserved'])) ?></span><span class="stat-l">In open requests</span></div><div class="stat"><span class="stat-n"><?= e(money($balance['paid'])) ?></span><span class="stat-l">Paid</span></div></div>
<p class="muted">Payout details on file: <?= $hasDetails ? e((string) $hint) : 'none yet' ?>.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Request</th><th>Amount</th><th>Status</th><th>Requested</th><th>Paid</th></tr></thead><tbody>
    <?php foreach ($rows as $p) : ?><tr><td><a href="/partner/payouts/<?= e($p['public_id']) ?>"><strong><?= e($p['reference']) ?></strong></a></td><td><?= e(money($p['amount_minor'])) ?></td><td><?= View::partial('pbadge', ['kind' => 'payout', 's' => $p['status']]) ?></td>
        <td><?= e(local_time($p['requested_at'], 'd M Y')) ?></td><td><?= $p['paid_at'] ? e(local_time($p['paid_at'], 'd M Y')) : '—' ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="5" class="muted">No payout requests yet.</td></tr><?php endif; ?></tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
