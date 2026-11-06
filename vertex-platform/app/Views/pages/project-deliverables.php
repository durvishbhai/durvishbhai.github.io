<?php
/** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */
/** @var bool $canUpload */ /** @var list<array<string,mixed>> $tasks */ /** @var list<array<string,mixed>> $milestones */ /** @var string $limit */ /** @var string $types */
use Vertex\Core\View;
$base = '/projects/' . $project['public_id'];
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects">&larr; Projects</a></div>
<?= View::partial('project-tabs', ['project' => $project, 'tab' => 'deliverables']) ?>
<div class="panel"><h2>Deliverables</h2>
    <form method="get" action="<?= e($base) ?>/deliverables" class="toolbar" role="search">
        <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>"></div>
        <div class="field"><label for="c">Category</label><select id="c" name="category"><option value="">All</option><?php foreach (config('workflow.deliverable_categories') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['category'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><?php foreach (config('workflow.deliverable_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label></div>
        <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
    </form>
    <?= View::partial('deliverable-table', ['rows' => $rows, 'showProject' => false]) ?>
    <?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
</div>
<?php if ($canUpload) : ?>
<div class="panel"><h2>Add a deliverable</h2>
    <p class="muted">It starts as a <strong>draft</strong>, visible only to your team. The client sees nothing until it is approved internally and published.</p>
    <form method="post" action="<?= e($base) ?>/deliverables" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Uploading…"><?= csrf_field() ?>
        <?= View::partial('deliverable-content-fields', ['withMeta' => true, 'limit' => $limit, 'types' => $types, 'tasks' => $tasks, 'milestones' => $milestones]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Upload as draft</button></div></form></div>
<?php endif; ?>
