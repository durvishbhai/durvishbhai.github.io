<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Your projects</p><h1>My revision requests</h1></div><a class="btn btn-ghost" href="/my/deliverables">My deliverables</a></div>
<form method="get" action="/revisions" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label class="check"><input type="checkbox" name="open" value="1"<?= $filters['open'] ? ' checked' : '' ?>> Open requests only</label></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Request</th><th>Deliverable</th><th>Status</th><th>Sent</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/revisions/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a></td><td><?= e($r['deliverable_title']) ?> <span class="muted">V<?= e($r['version_no']) ?></span></td>
        <td><span class="badge"><?= e(config("workflow.revision_client_labels.{$r['status']}", $r['status'])) ?></span></td><td><?= e(local_time($r['created_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="4" class="muted">You have not sent any revision requests. Open a deliverable and choose "Request a revision".</td></tr><?php endif; ?>
</tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
