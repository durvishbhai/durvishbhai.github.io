<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var list<array<string,mixed>> $campaigns */ /** @var bool $canManage */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Sales</p><h1>Coupons &amp; campaigns</h1></div><?php if ($canManage) : ?><div class="actions"><a class="btn btn-primary" href="/coupons/new">New coupon</a></div><?php endif; ?></div>
<form method="get" action="/coupons" class="toolbar" role="search"><div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="Code, description, campaign"></div>
    <div class="field"><label for="campaign">Campaign</label><select id="campaign" name="campaign"><option value="">All</option><?php foreach ($campaigns as $g) : ?><option value="<?= e($g['public_id']) ?>"<?= $filters['campaign'] === $g['public_id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Discount</th><th>Valid</th><th>Used</th><th>Discounted</th><th>Campaign</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><a href="/coupons/<?= e($r['public_id']) ?>"><strong><?= e($r['code']) ?></strong></a><br><span class="muted"><?= e($r['description']) ?></span></td>
    <td><?= $r['discount_type'] === 'percent' ? e(rtrim(rtrim(number_format((int) $r['percent_bps'] / 100, 2), '0'), '.')) . '%' : e(money((int) $r['amount_minor'])) ?></td>
    <td><?= $r['valid_from'] ? e(local_date($r['valid_from'])) : 'any time' ?> &rarr; <?= $r['valid_until'] ? e(local_date($r['valid_until'])) : 'no end' ?></td>
    <td><?= (int) $r['used'] ?><?= $r['max_uses'] !== null ? ' / ' . (int) $r['max_uses'] : '' ?></td><td><?= e(money((int) $r['discounted_minor'])) ?></td><td><?= e($r['campaign_name'] ?? '-') ?></td><td><?= e(ucfirst((string) $r['status'])) ?></td></tr><?php endforeach; ?>
<?php if ($rows === []) : ?><tr><td colspan="7" class="muted">No coupons yet.</td></tr><?php endif; ?></tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>

<div class="panel"><h2>Campaigns</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Campaign</th><th>Dates</th><th>Coupons</th><th>Redemptions</th><th>Discount given</th><th>Invoiced with coupons</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($campaigns as $g) : ?><tr><td><strong><?= e($g['name']) ?></strong><br><span class="muted"><?= e($g['description']) ?></span></td><td><?= $g['starts_on'] ? e(local_date($g['starts_on'])) : '-' ?> &rarr; <?= $g['ends_on'] ? e(local_date($g['ends_on'])) : '-' ?></td><td><?= (int) $g['coupons'] ?></td><td><?= (int) $g['redemptions'] ?></td><td><?= e(money((int) $g['discounted_minor'])) ?></td><td><?= e(money((int) $g['invoiced_minor'])) ?></td><td><?= e(ucfirst((string) $g['status'])) ?></td></tr><?php endforeach; ?>
    <?php if ($campaigns === []) : ?><tr><td colspan="7" class="muted">No campaigns yet. A campaign groups coupons so you can see what a promotion cost and brought in.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($canManage) : ?><form method="post" action="/campaigns" class="form-grid"><?= csrf_field() ?><h3>New campaign</h3>
        <div class="field"><label for="cn">Name</label><input id="cn" name="name" required minlength="3" maxlength="120"></div>
        <div class="field"><label for="cd">Description</label><input id="cd" name="description" maxlength="500"></div>
        <div class="field"><label for="cs">Starts</label><input id="cs" name="starts_on" type="date"></div><div class="field"><label for="ce">Ends</label><input id="ce" name="ends_on" type="date"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create campaign</button></div></form><?php endif; ?>
</div>
