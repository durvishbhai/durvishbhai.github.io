<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,string> $filters */ ?>
<div class="page-head"><div><p class="eyebrow">Referral partner</p><h1>My referrals</h1></div><a class="btn btn-primary" href="/partner/referrals/create">Submit a referral</a></div>
<p class="muted">You can see only the referrals you submitted.</p>
<form method="get" action="/partner/referrals" class="toolbar" role="search"><div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div><div class="field"><button class="btn btn-ghost" type="submit">Search</button></div></form>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Reference</th><th>Person / business</th><th>Service</th><th>Status</th><th>Submitted</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : $st = $r['stage_kind'] === 'won' ? 'Accepted' : ($r['stage_kind'] === 'lost' ? 'Not proceeding' : 'In progress'); ?>
        <tr><td><a href="/partner/referrals/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a></td><td><?= e($r['contact_name']) ?><br><span class="muted"><?= e($r['company_name'] ?? '') ?></span></td>
            <td><?= e(config("workflow.services.{$r['service']}", $r['service'])) ?></td><td><span class="badge"><?= e($st) ?></span></td><td><?= e(local_time($r['created_at'], 'd M Y')) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="5" class="muted">You have not submitted any referrals yet.</td></tr><?php endif; ?>
    </tbody></table></div>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $filters]) ?>
