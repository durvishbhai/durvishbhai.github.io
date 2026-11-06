<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $qs */ /** @var array<string,int> $balance */ /** @var int $advanceBps */ /** @var int $fullBps */ /** @var string $mode */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Partner portal</p><h1>My commissions</h1></div><a class="btn btn-ghost" href="/partner">&larr; Partner portal</a></div>
<div class="stat-grid">
    <div class="stat"><span class="stat-n"><?= e(money($balance['eligible_for_review'])) ?></span><span class="stat-l">Being checked</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['approved'])) ?></span><span class="stat-l">Approved, not yet payable</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['available'])) ?></span><span class="stat-l">Payable balance</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['on_hold'])) ?></span><span class="stat-l">On hold</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['paid'])) ?></span><span class="stat-l">Paid</span></div>
</div>
<p class="muted">Commission is earned in two steps. <?php if ($mode === 'cumulative') : ?>The first <?= e(number_format($advanceBps / 100, 2)) ?>% is earned when the client's advance payment has been received and verified; the rest, bringing the total to <?= e(number_format(($advanceBps + $fullBps) / 100, 2)) ?>% of the verified payments received, is earned when the client has paid in full.<?php else : ?><?= e(number_format($advanceBps / 100, 2)) ?>% of the advance payments once they are received and verified, and <?= e(number_format($fullBps / 100, 2)) ?>% of the later payments once the client has paid in full.<?php endif; ?> It is never calculated on unpaid amounts.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Entry</th><th>Referral</th><th>Milestone</th><th>Base</th><th>Rate</th><th>Commission</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><strong><?= e($r['reference']) ?></strong><br><span class="muted"><?= e(local_time($r['created_at'], 'd M Y')) ?></span></td><td><?= e($r['referral_reference']) ?><br><span class="muted"><?= e($r['prospect_name']) ?></span></td>
        <td><?= e(config("referrals.milestone_labels.{$r['milestone']}", $r['milestone'])) ?></td><td><?= $r['milestone'] === 'adjustment' ? '—' : e(money($r['base_minor'])) ?></td><td><?= $r['milestone'] === 'adjustment' ? '—' : e(number_format($r['rate_bps'] / 100, 2)) . '%' ?></td>
        <td><strong><?= e(money($r['amount_minor'])) ?></strong></td><td><?= View::partial('pbadge', ['kind' => 'commission', 's' => $r['status']]) ?><br><span class="muted"><?= e($r['explanation']) ?></span></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="7" class="muted">No commission entries yet. They appear when one of your referrals is credited and converted.</td></tr><?php endif; ?></tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
