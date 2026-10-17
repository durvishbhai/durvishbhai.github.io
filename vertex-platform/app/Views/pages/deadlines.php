<?php
/** @var array<string,mixed> $dl */
$labels = (array) config('workflow.task_statuses');
$section = static function (string $title, string $empty, array $rows, callable $row): void { ?>
    <div class="panel"><h2><?= e($title) ?> <span class="muted">(<?= e(count($rows)) ?>)</span></h2>
        <?php if ($rows === []) : ?><p class="muted"><?= e($empty) ?></p><?php else : ?><ul class="plain-list"><?php foreach ($rows as $r) : ?><li><?php $row($r); ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php };
$taskRow = static function (array $t) use ($labels): void { ?>
    <a href="/tasks/<?= e($t['public_id']) ?>"><strong><?= e($t['title']) ?></strong></a>
    <span class="muted"><?= e($t['project_code']) ?> · <?= e($t['assignee_name'] ?? 'Unassigned') ?> · <?= e($labels[$t['status']] ?? $t['status']) ?><?= $t['due_date'] ? ' · due ' . e($t['due_date']) : '' ?></span>
<?php };
?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>Deadlines</h1></div>
    <div class="action-row"><a class="btn btn-ghost" href="/board">Kanban board</a><a class="btn btn-ghost" href="/tasks">All tasks</a></div></div>
<p class="muted">As of <?= e($dl['today']) ?> (<?= e(config('app.timezone', 'UTC')) ?>). "Upcoming" means due by <?= e($dl['horizon']) ?>. Only projects you can access are included.</p>

<?php $section('Overdue tasks', 'No overdue tasks.', $dl['overdue_tasks'], $taskRow); ?>
<?php $section('Overdue milestones', 'No overdue milestones.', $dl['overdue_milestones'], static function (array $m): void { ?>
    <strong><?= e($m['title']) ?></strong> <span class="muted"><a href="/projects/<?= e($m['project_public_id']) ?>/milestones"><?= e($m['project_code']) ?></a> · target <?= e($m['target_date']) ?></span>
<?php }); ?>
<?php $section('Blocked tasks', 'No blocked tasks.', $dl['blocked_tasks'], $taskRow); ?>
<?php $section('Awaiting review', 'Nothing is waiting for review.', $dl['review_tasks'], $taskRow); ?>
<?php $section('Tasks due soon', 'No tasks due in this window.', $dl['upcoming_tasks'], $taskRow); ?>
<?php $section('Milestones due soon', 'No milestones due in this window.', $dl['upcoming_milestones'], static function (array $m): void { ?>
    <strong><?= e($m['title']) ?></strong> <span class="muted"><a href="/projects/<?= e($m['project_public_id']) ?>/milestones"><?= e($m['project_code']) ?></a> · target <?= e($m['target_date']) ?></span>
<?php }); ?>
<?php $section('Projects near or past their target date', 'No projects are near their target date.', $dl['projects'], static function (array $p): void { ?>
    <a href="/projects/<?= e($p['public_id']) ?>"><strong><?= e($p['title']) ?></strong></a>
    <span class="muted"><?= e($p['code']) ?> · <?= e(config("workflow.project_statuses.{$p['status']}", $p['status'])) ?> · target <?= e($p['target_date']) ?></span><?= (int) $p['is_overdue'] === 1 ? ' <span class="overdue">past target</span>' : '' ?>
<?php }); ?>
