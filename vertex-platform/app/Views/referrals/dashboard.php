<?php /** @var array<string,mixed> $filters */ /** @var ?array<string,int> $applications */ /** @var ?array<string,int> $referrals */ /** @var array<string,int> $commissions */ /** @var array<string,int> $payouts */ /** @var ?array<string,int> $payments */
$q = http_build_query(array_filter($filters, static fn ($v) => $v !== '')); $q = $q === '' ? '' : '&' . $q; ?>
<div class="page-head"><div><p class="eyebrow">Referral partners</p><h1>Partner programme dashboard</h1></div>
    <div class="action-row"><?php if (can('referrals.review')) : ?><a class="btn btn-ghost" href="/referrals/applications">Applications</a><a class="btn btn-ghost" href="/referrals">Referrals</a><?php endif; ?><?php if (can('commissions.view_all')) : ?><a class="btn btn-ghost" href="/commissions">Commissions</a><?php endif; ?><?php if (can('payouts.view_all') || can('payouts.approve') || can('payouts.record')) : ?><a class="btn btn-ghost" href="/payouts">Payouts</a><?php endif; ?></div></div>
<form method="get" action="/referrals/dashboard" class="toolbar"><div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($filters['from']) ?>"></div><div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($filters['to']) ?>"></div><div class="field"><button class="btn btn-ghost" type="submit">Apply date range</button></div></form>
<?php if ($applications) : ?><div class="panel"><h2>Partner applications</h2><div class="stat-grid">
    <a class="stat" href="/referrals/applications?status=received"><span class="stat-n"><?= e($applications['pending']) ?></span><span class="stat-l">Pending</span></a>
    <a class="stat" href="/referrals/applications?status=approved<?= e($q) ?>"><span class="stat-n"><?= e($applications['approved']) ?></span><span class="stat-l">Approved</span></a>
    <a class="stat" href="/referrals/applications?status=rejected<?= e($q) ?>"><span class="stat-n"><?= e($applications['rejected']) ?></span><span class="stat-l">Rejected</span></a></div></div><?php endif; ?>
<?php if ($referrals) : ?><div class="panel"><h2>Referrals</h2><div class="stat-grid">
    <a class="stat" href="/referrals?status=received<?= e($q) ?>"><span class="stat-n"><?= e($referrals['new']) ?></span><span class="stat-l">New</span></a>
    <a class="stat" href="/referrals?duplicates=1"><span class="stat-n"><?= e($referrals['duplicates']) ?></span><span class="stat-l">Duplicate claims</span></a>
    <a class="stat" href="/referrals"><span class="stat-n"><?= e($referrals['in_progress']) ?></span><span class="stat-l">In progress</span></a>
    <a class="stat" href="/referrals?status=converted<?= e($q) ?>"><span class="stat-n"><?= e($referrals['converted']) ?></span><span class="stat-l">Converted</span></a>
    <a class="stat" href="/referrals?status=not_converted<?= e($q) ?>"><span class="stat-n"><?= e($referrals['not_converted']) ?></span><span class="stat-l">Not converted / rejected</span></a></div></div><?php endif; ?>
<?php if ($commissions) : ?><div class="panel"><h2>Commissions</h2><div class="stat-grid">
    <a class="stat" href="/commissions?review=1"><span class="stat-n"><?= e($commissions['awaiting_review']) ?></span><span class="stat-l">Awaiting review</span></a>
    <div class="stat"><span class="stat-n"><?= e(money($commissions['eligible_balance_minor'])) ?></span><span class="stat-l">Payable balances (unreserved)</span></div>
    <a class="stat" href="/commissions?status=on_hold"><span class="stat-n"><?= e($commissions['on_hold']) ?></span><span class="stat-l">On hold</span></a>
    <a class="stat" href="/commissions?milestone=adjustment"><span class="stat-n"><?= e($commissions['adjustments']) ?></span><span class="stat-l">Adjustment lines</span></a>
    <a class="stat" href="/commissions?status=reversed"><span class="stat-n"><?= e($commissions['reversals']) ?></span><span class="stat-l">Reversals</span></a></div></div><?php endif; ?>
<?php if ($payouts) : ?><div class="panel"><h2>Payouts</h2><div class="stat-grid">
    <a class="stat" href="/payouts?status=requested"><span class="stat-n"><?= e($payouts['awaiting_review']) ?></span><span class="stat-l">Awaiting review</span></a>
    <a class="stat" href="/payouts?status=approved"><span class="stat-n"><?= e($payouts['awaiting_payment']) ?></span><span class="stat-l">Approved, awaiting payment</span></a>
    <a class="stat" href="/payouts?status=paid"><span class="stat-n"><?= e($payouts['paid_count']) ?></span><span class="stat-l">Payments recorded</span></a>
    <div class="stat"><span class="stat-n"><?= e(money($payouts['paid_minor'])) ?></span><span class="stat-l">Total recorded as paid</span></div></div></div><?php endif; ?>
<?php if ($payments) : ?><div class="panel"><h2>Client payments</h2><a class="stat" href="/client-payments?status=pending_verification"><span class="stat-n"><?= e($payments['to_verify']) ?></span><span class="stat-l">Awaiting verification</span></a></div><?php endif; ?>
