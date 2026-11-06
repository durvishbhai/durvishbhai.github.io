<?php /** @var array<string,mixed> $partner */ /** @var array<string,int> $counts */ /** @var array<string,int> $balance */ /** @var list<array<string,mixed>> $referrals */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var list<array<string,mixed>> $payouts */ /** @var bool $canSubmit */ /** @var bool $canPayout */
use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Partner <?= e($partner['reference']) ?></p><h1>Partner portal</h1></div>
    <div class="action-row"><?php if ($canSubmit) : ?><a class="btn btn-primary" href="/partner/submit">Submit a referral</a><?php endif; ?><a class="btn btn-ghost" href="/partner/commissions">My commissions</a><a class="btn btn-ghost" href="/partner/payouts">My payouts</a></div></div>
<div class="stat-grid">
    <div class="stat"><span class="stat-n"><?= e($counts['total']) ?></span><span class="stat-l">Referrals submitted</span></div>
    <div class="stat"><span class="stat-n"><?= e($counts['under_review']) ?></span><span class="stat-l">Under review</span></div>
    <div class="stat"><span class="stat-n"><?= e($counts['approved']) ?></span><span class="stat-l">Approved / in progress</span></div>
    <div class="stat"><span class="stat-n"><?= e($counts['converted']) ?></span><span class="stat-l">Converted</span></div>
    <div class="stat"><span class="stat-n"><?= e($counts['not_converted']) ?></span><span class="stat-l">Not converted</span></div>
</div>
<div class="stat-grid">
    <div class="stat"><span class="stat-n"><?= e(money($balance['eligible_for_review'] + $balance['approved'])) ?></span><span class="stat-l">Commission pending (being checked or not yet payable)</span></div>
    <div class="stat"><span class="stat-n"><?= e($balance['pending_eligibility']) ?></span><span class="stat-l">Milestones waiting for client payments</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['available'])) ?></span><span class="stat-l">Payable balance</span></div>
    <div class="stat"><span class="stat-n"><?= e($balance['pending_payouts']) ?></span><span class="stat-l">Payout requests pending</span></div>
    <div class="stat"><span class="stat-n"><?= e(money($balance['paid'])) ?></span><span class="stat-l">Paid to you</span></div>
</div>
<?php if ($balance['available'] < 0) : ?><div class="panel panel-warn"><p>Your balance is negative because a commission was adjusted after a client payment changed. It will be offset against your future commissions.</p></div><?php endif; ?>
<?php if ($canPayout) : ?><p><a class="btn btn-ghost" href="/partner/payouts/new">Request a payout</a> <a class="btn btn-ghost" href="/partner/payout-details">Payout details</a></p><?php endif; ?>
<div class="panel"><h2>Your referrals</h2>
    <form method="get" action="/partner" class="toolbar" role="search"><div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div>
        <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><?php foreach (config('referrals.referral_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $filters['status'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
    <div class="table-wrap"><table class="table"><thead><tr><th>Referral</th><th>Prospect</th><th>Status</th><th>Submitted</th></tr></thead><tbody>
        <?php foreach ($referrals as $r) : ?><tr><td><a href="/partner/r/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a></td><td><?= e($r['prospect_name']) ?><?= $r['business_name'] ? '<br><span class="muted">' . e($r['business_name']) . '</span>' : '' ?></td>
            <td><?= View::partial('pbadge', ['kind' => 'referral', 's' => $r['status']]) ?></td><td><?= e(local_time($r['created_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
        <?php if ($referrals === []) : ?><tr><td colspan="4" class="muted">No referrals yet.</td></tr><?php endif; ?></tbody></table></div>
    <?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?></div>
<div class="panel"><h2>Recent payout requests</h2>
    <?php foreach ($payouts as $p) : ?><p><a href="/partner/payouts/<?= e($p['public_id']) ?>"><?= e($p['reference']) ?></a> · <?= e(money($p['amount_minor'])) ?> · <?= View::partial('pbadge', ['kind' => 'payout', 's' => $p['status']]) ?> <span class="muted"><?= e(local_time($p['requested_at'], 'd M Y')) ?></span></p><?php endforeach; ?>
    <?php if ($payouts === []) : ?><p class="muted">No payout requests yet.</p><?php endif; ?></div>
