<?php
/** @var list<array<string,mixed>> $people */ /** @var list<array<string,mixed>> $unstaffed */
?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>Team &amp; assignments</h1></div><a class="btn btn-ghost" href="/projects">Projects</a></div>
<p class="muted">Active people with at least one project assignment, and the open work they hold. To add, move or remove someone, open the project (only administrators can change assignments).</p>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Person</th><th>Projects</th><th>Open tasks</th><th>Overdue</th><th>Blocked</th></tr></thead>
    <tbody>
    <?php foreach ($people as $p) : ?>
        <tr><td><strong><?= e($p['name']) ?></strong><br><span class="muted"><?= e($p['roles'] ?? '') ?></span></td>
            <td><?php foreach ($p['projects'] as $i => $pr) : ?><?= $i > 0 ? '<br>' : '' ?><a href="/projects/<?= e($pr['public_id']) ?>"><?= e($pr['code']) ?></a> <?= e($pr['title']) ?> <span class="muted">(<?= e(status_label((string) $pr['project_role'])) ?>)</span><?php endforeach; ?>
                <?php if ($p['projects'] === []) : ?><span class="muted">None on active projects</span><?php endif; ?></td>
            <td><a href="/tasks?assignee=<?= e($p['public_id']) ?>"><?= e($p['open_tasks']) ?></a></td>
            <td><?= (int) $p['overdue_tasks'] > 0 ? '<span class="overdue">' . e($p['overdue_tasks']) . '</span>' : '0' ?></td>
            <td><?= e($p['blocked_tasks']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($people === []) : ?><tr><td colspan="5" class="muted">Nobody is assigned to a project yet.</td></tr><?php endif; ?>
    </tbody></table></div>

<div class="panel"><h2>Active projects with nobody assigned <span class="muted">(<?= e(count($unstaffed)) ?>)</span></h2>
    <?php if ($unstaffed === []) : ?><p class="muted">Every active project has at least one team member.</p><?php else : ?>
        <ul class="plain-list"><?php foreach ($unstaffed as $p) : ?><li><a href="/projects/<?= e($p['public_id']) ?>"><strong><?= e($p['title']) ?></strong></a> <span class="muted"><?= e($p['code']) ?> · <?= e(config("workflow.project_statuses.{$p['status']}", $p['status'])) ?><?= $p['target_date'] ? ' · target ' . e($p['target_date']) : '' ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
