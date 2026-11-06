<?php
/** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */
/** @var list<array<string,mixed>> $projects */ /** @var array{review:int,publish:int} $qaCounts */
use Vertex\Core\View;
use Vertex\Services\FileStore;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>Deliverables</h1></div>
    <div class="action-row">
        <?php if (can('deliverables.review') || can('deliverables.publish')) : ?><a class="btn btn-ghost" href="/qa">QA queue<?= ($qaCounts['review'] + $qaCounts['publish']) > 0 ? ' (' . e($qaCounts['review'] + $qaCounts['publish']) . ')' : '' ?></a><?php endif; ?>
        <?php if (can('revisions.manage')) : ?><a class="btn btn-ghost" href="/revisions">Revisions</a><?php endif; ?></div></div>
<p class="muted">Only deliverables on projects you can access are listed. Upload from a project's Deliverables tab. Uploading never publishes: a file reaches the client only after internal approval and an explicit publish.</p>
<form method="get" action="/deliverables" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Title, code, project" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="p">Project</label><select id="p" name="project"><option value="">All</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $sel($filters['project'], $p['public_id']) ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="c">Category</label><select id="c" name="category"><option value="">All</option><?php foreach (config('workflow.deliverable_categories') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['category'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><?php foreach (config('workflow.deliverable_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
<?= View::partial('deliverable-table', ['rows' => $rows, 'showProject' => true]) ?>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
