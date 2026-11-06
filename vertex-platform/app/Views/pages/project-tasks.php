<?php
/** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $tasks */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var bool $canCreate */ /** @var list<array<string,mixed>> $members */ /** @var list<array<string,mixed>> $milestones */
$base = '/projects/' . $project['public_id'];
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects">&larr; Projects</a></div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => 'tasks']) ?>

<div class="panel"><h2>Tasks</h2>
    <p class="muted">Task priority is separate from the project's priority and never changes pricing.</p>
    <?= \Vertex\Core\View::partial('task-filters', ['filters' => $filters, 'action' => $base . '/tasks', 'projects' => [], 'people' => $members, 'withStatus' => true, 'withProject' => false]) ?>
    <?= \Vertex\Core\View::partial('task-table', ['tasks' => $tasks, 'showProject' => false]) ?>
    <?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
</div>

<?php if ($canCreate) : ?>
<div class="panel"><h2>Add a task</h2>
    <form method="post" action="<?= e($base) ?>/tasks" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="tt">Title</label><input id="tt" name="title" required maxlength="200" value="<?= e($v('title')) ?>"></div>
        <div class="field"><label for="tdsc">Description</label><textarea id="tdsc" name="description" rows="3" maxlength="5000"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label for="tpri">Priority</label><select id="tpri" name="priority"><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('priority', 'normal') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="tst">Start date</label><input id="tst" name="start_date" type="date" value="<?= e($v('start_date')) ?>"></div>
        <div class="field"><label for="tdu">Due date</label><input id="tdu" name="due_date" type="date" value="<?= e($v('due_date')) ?>"></div>
        <div class="field"><label for="test">Estimate (minutes)</label><input id="test" name="estimate_minutes" inputmode="numeric" maxlength="6" value="<?= e($v('estimate_minutes')) ?>"></div>
        <?php if ($milestones !== []) : ?><div class="field"><label for="tml">Milestone</label><select id="tml" name="milestone"><option value="">None</option><?php foreach ($milestones as $m) : if (in_array($m['status'], ['rejected', 'cancelled'], true)) { continue; } ?><option value="<?= e($m['public_id']) ?>"<?= $v('milestone') === $m['public_id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if ($members !== []) : ?><div class="field"><label for="tas">Assign to</label><select id="tas" name="assignee"><option value="">Unassigned</option><?php foreach ($members as $u) : ?><option value="<?= e($u['public_id']) ?>"<?= $v('assignee') === $u['public_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field"><label for="tstat">Start in</label><select id="tstat" name="status"><option value="backlog">Backlog</option><option value="to_do">To Do</option></select></div>
        <div class="field"><label class="check"><input type="checkbox" name="review_required" value="1" checked> Needs review before completion</label></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Add task</button></div></form></div>
<?php endif; ?>
