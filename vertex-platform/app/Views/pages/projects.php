<?php
/** @var list<array<string,mixed>> $projects */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,mixed> $filters */ /** @var array<string,string> $qs */
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
$today = \Vertex\Services\AppTime::today();
?>
<div class="page-head"><div><p class="eyebrow">Projects</p><h1>Projects</h1></div>
    <div class="action-row">
        <?php if (can('tasks.view')) : ?><a class="btn btn-ghost" href="/board">Kanban board</a><a class="btn btn-ghost" href="/deadlines">Deadlines</a><?php endif; ?>
        <?php if (can('projects.create')) : ?><a class="btn btn-primary" href="/projects/create">New project</a><?php endif; ?>
    </div></div>
<p class="muted">Only projects you are assigned to, or that belong to you, are listed.</p>
<form method="get" action="/projects" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Name, code, client" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach (config('workflow.project_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><option value="">All</option><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['priority'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="service">Service</label><select id="service" name="service"><option value="">All</option><?php foreach (config('workflow.services') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['service'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if (can('projects.view_all')) : ?><div class="field"><label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label></div><?php endif; ?>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Project</th><th>Client</th><th>Status</th><th>Priority</th><th>Target date</th><th>Progress</th></tr></thead>
    <tbody>
    <?php foreach ($projects as $p) : $late = $p['target_date'] !== null && $p['target_date'] < $today && !in_array($p['status'], ['completed', 'cancelled'], true); $pct = (int) $p['task_total'] === 0 ? 0 : (int) round($p['task_done'] * 100 / $p['task_total']); ?>
        <tr><td><a href="/projects/<?= e($p['public_id']) ?>"><strong><?= e($p['title']) ?></strong></a><br><span class="muted"><?= e($p['code']) ?></span></td>
            <td><?= e($p['company_name'] ?: $p['client_name']) ?></td>
            <td><span class="badge badge-pstatus-<?= e($p['status']) ?>"><?= e(config("workflow.project_statuses.{$p['status']}", $p['status'])) ?></span></td>
            <td><span class="badge badge-prio-<?= e($p['priority']) ?>"><?= e(status_label((string) $p['priority'])) ?></span></td>
            <td><?= $p['target_date'] ? '<span' . ($late ? ' class="overdue"' : '') . '>' . e($p['target_date']) . ($late ? ' (past)' : '') . '</span>' : '—' ?></td>
            <td><?php if ((int) $p['task_total'] === 0) : ?><span class="muted">No tasks</span><?php else : ?><span class="progress" role="img" aria-label="<?= e($pct) ?> percent"><span class="progress-bar" data-pct="<?= e($pct) ?>"></span></span> <span class="muted"><?= e($p['task_done']) ?>/<?= e($p['task_total']) ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if ($projects === []) : ?><tr><td colspan="6" class="muted">No projects to show.</td></tr><?php endif; ?>
    </tbody></table></div>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
