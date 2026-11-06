<?php
/** @var list<array<string,mixed>> $tasks */ /** @var bool $showProject */
?>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Task</th><?php if ($showProject) : ?><th>Project</th><?php endif; ?><th>Status</th><th>Priority</th><th>Assignee</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t) : ?>
        <tr><td><a href="/tasks/<?= e($t['public_id']) ?>"><strong><?= e($t['title']) ?></strong></a>
                <?php if ((int) $t['open_deps'] > 0) : ?><br><span class="muted">Waiting on <?= e($t['open_deps']) ?> task<?= (int) $t['open_deps'] === 1 ? '' : 's' ?></span><?php endif; ?></td>
            <?php if ($showProject) : ?><td><a href="/projects/<?= e($t['project_public_id']) ?>/tasks"><?= e($t['project_code']) ?></a></td><?php endif; ?>
            <td><span class="badge badge-tstatus-<?= e($t['status']) ?>"><?= e(config("workflow.task_statuses.{$t['status']}", $t['status'])) ?></span></td>
            <td><span class="badge badge-prio-<?= e($t['priority']) ?>"><?= e(status_label((string) $t['priority'])) ?></span></td>
            <td><?= $t['assignee_name'] ? e($t['assignee_name']) : '<span class="muted">Unassigned</span>' ?></td>
            <td><?= $t['due_date'] ? '<span' . ($t['is_overdue'] ? ' class="overdue"' : '') . '>' . e($t['due_date']) . ($t['is_overdue'] ? ' (overdue)' : '') . '</span>' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($tasks === []) : ?><tr><td colspan="<?= $showProject ? 6 : 5 ?>" class="muted">No tasks match.</td></tr><?php endif; ?>
    </tbody></table></div>
