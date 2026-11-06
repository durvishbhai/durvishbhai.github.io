<?php
/** @var array<string,mixed> $filters */ /** @var string $action */ /** @var list<array<string,mixed>> $projects */ /** @var list<array<string,mixed>> $people */
/** @var bool $withStatus */ /** @var bool $withProject */
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<form method="get" action="<?= e($action) ?>" class="toolbar" role="search">
    <div class="field"><label for="tq">Search</label><input id="tq" name="q" type="search" maxlength="100" placeholder="Task title" value="<?= e($filters['q']) ?>"></div>
    <?php if ($withProject) : ?>
        <div class="field"><label for="tp">Project</label><select id="tp" name="project"><option value="">All</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $sel($filters['project'], $p['public_id']) ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($withStatus) : ?>
        <div class="field"><label for="ts">Status</label><select id="ts" name="status"><option value="">All</option><?php foreach (config('workflow.task_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field"><label for="tpr">Priority</label><select id="tpr" name="priority"><option value="">All</option><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['priority'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="ta">Assignee</label><select id="ta" name="assignee"><option value="">Anyone</option><option value="me"<?= $sel($filters['assignee'], 'me') ?>>Me</option><option value="none"<?= $sel($filters['assignee'], 'none') ?>>Unassigned</option><?php foreach ($people as $u) : ?><option value="<?= e($u['public_id']) ?>"<?= $sel($filters['assignee'], $u['public_id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check"><input type="checkbox" name="overdue" value="1"<?= $filters['overdue'] ? ' checked' : '' ?>> Overdue only</label></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
