<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Referral partners</p><h1>Partners</h1></div><a class="btn btn-ghost" href="/referrals/dashboard">Dashboard</a></div>
<form method="get" action="/referrals/partners" class="toolbar" role="search"><div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $filters['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option></select></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Partner</th><th>Name</th><th>Status</th><th>Referrals</th><th>Since</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/referrals/partners/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a></td><td><?= e($r['name']) ?><br><span class="muted"><?= e($r['email']) ?></span></td>
        <td><span class="badge badge-ps-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td><td><?= e($r['referrals']) ?></td><td><?= e(local_time($r['created_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="5" class="muted">No partners.</td></tr><?php endif; ?></tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
