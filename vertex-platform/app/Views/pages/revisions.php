<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var list<array<string,mixed>> $projects */
use Vertex\Core\View;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head"><div><p class="eyebrow">Support</p><h1>Revision review queue</h1></div><div class="action-row"><a class="btn btn-ghost" href="/deliverables">Deliverables</a><?php if (can('revisions.policy') || can('revisions.classify')) : ?><a class="btn btn-ghost" href="/admin/revision-policies">Revision policies</a><?php endif; ?></div></div>
<form method="get" action="/revisions" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Reference, deliverable, project" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><?php foreach (config('workflow.revision_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="p">Project</label><select id="p" name="project"><option value="">All</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $sel($filters['project'], $p['public_id']) ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check"><input type="checkbox" name="open" value="1"<?= $filters['open'] ? ' checked' : '' ?>> Open only</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> Assigned to me</label></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Request</th><th>Project / deliverable</th><th>Status</th><th>Classification</th><th>Reviewer</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/revisions/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><span class="muted"><?= e($r['requester_name'] ?? '') ?></span></td>
        <td><?= e($r['project_code']) ?> · <?= e($r['deliverable_title']) ?> <span class="muted">V<?= e($r['version_no']) ?></span></td>
        <td><?= View::partial('dstatus', ['s' => $r['status'], 'kind' => 'rstatus']) ?></td><td><?= e(status_label((string) $r['scope_decision'])) ?></td>
        <td><?= e($r['assignee_name'] ?? 'Unassigned') ?></td><td><?= e(local_time($r['updated_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="6" class="muted">No revision requests match.</td></tr><?php endif; ?>
</tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
