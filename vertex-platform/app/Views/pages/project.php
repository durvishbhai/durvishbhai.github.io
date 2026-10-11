<?php
/** @var array<string,mixed> $project */ /** @var string $level */ /** @var bool $staff */
/** @var list<array<string,mixed>> $members */ /** @var bool $canAssign */ /** @var list<array<string,mixed>> $candidates */
/** @var array<string,string> $transitions */ /** @var array{done:int,total:int,percent:int} $progress */
/** @var list<array<string,mixed>> $notes */ /** @var list<array<string,mixed>> $milestones */
/** @var bool $canEdit */ /** @var bool $canArchive */ /** @var bool $financeVisible */ /** @var bool $canBudget */ /** @var int $openTasks */
$base = '/projects/' . $project['public_id'];
$today = \Vertex\Services\AppTime::today();
$tab = 'overview';
$late = $project['target_date'] !== null && $project['target_date'] < $today && !in_array($project['status'], ['completed', 'cancelled'], true);
$values = [];
foreach (['title', 'service_category', 'description', 'scope', 'start_date', 'target_date'] as $f) { $values[$f] = old($f, (string) ($project[$f] ?? '')); }
$services = (array) config('workflow.services');
$internalNotes = array_filter($notes, static fn ($n) => $n['kind'] === 'internal');
$sharedNotes = array_filter($notes, static fn ($n) => $n['kind'] !== 'internal');
?>
<div class="page-head">
    <div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div>
    <a class="btn btn-ghost" href="/projects">&larr; Projects</a>
</div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => $tab]) ?>

<?php if ($project['archived_at']) : ?><div class="alert alert-info"><p>This project is archived and read-only.</p></div><?php endif; ?>

<div class="grid-2">
<div class="panel">
    <h2>Overview</h2>
    <dl class="kv">
        <dt>Status</dt><dd><span class="badge badge-pstatus-<?= e($project['status']) ?>"><?= e(config("workflow.project_statuses.{$project['status']}", $project['status'])) ?></span></dd>
        <dt>Client</dt><dd><?= $staff || $level === 'own' ? '<a href="/clients/' . e($project['client_public_id']) . '">' . e($project['company_name'] ?: $project['client_name']) . '</a>' : e($project['company_name'] ?: $project['client_name']) ?></dd>
        <dt>Project priority</dt><dd><span class="badge badge-prio-<?= e($project['priority']) ?>"><?= e(status_label((string) $project['priority'])) ?></span> <span class="muted">(task priorities are separate)</span></dd>
        <dt>Service</dt><dd><?= e($services[$project['service_category']] ?? '—') ?></dd>
        <dt>Start date</dt><dd><?= e($project['start_date'] ?? '—') ?></dd>
        <dt>Target delivery</dt><dd><?= $project['target_date'] ? '<span' . ($late ? ' class="overdue"' : '') . '>' . e($project['target_date']) . ($late ? ' (date has passed)' : '') . '</span>' : '—' ?></dd>
        <?php if ($financeVisible) : ?>
            <dt>Budget estimate</dt><dd><?= $project['budget_minor'] !== null ? '₹' . e(\Vertex\Services\FieldRules::formatMinor((int) $project['budget_minor'])) : '—' ?></dd>
            <dt>Approved quotation</dt><dd><?= $project['approved_quotation_ref'] ? e($project['approved_quotation_ref']) . ' · ₹' . e(\Vertex\Services\FieldRules::formatMinor((int) $project['approved_amount_minor'])) : 'None recorded' ?></dd>
        <?php endif; ?>
        <dt>Payment status</dt><dd class="muted">Not tracked on this page. It will come from verified payment records once billing is connected; the project status never implies payment.</dd>
        <dt>Created</dt><dd><?= e(local_time($project['created_at'])) ?></dd>
        <?php if ($project['completed_at']) : ?><dt>Completed</dt><dd><?= e(local_time($project['completed_at'])) ?></dd><?php endif; ?>
    </dl>
    <h3>Progress</h3>
    <?php if ($progress['total'] === 0) : ?><p class="muted">No tasks yet.</p><?php else : ?>
        <p><span class="progress" role="img" aria-label="<?= e($progress['percent']) ?> percent of tasks done"><span class="progress-bar" data-pct="<?= e($progress['percent']) ?>"></span></span> <?= e($progress['done']) ?> of <?= e($progress['total']) ?> tasks done (<?= e($progress['percent']) ?>%)</p>
    <?php endif; ?>
    <p class="muted">Finishing every task never completes a project by itself: an authorised person must confirm completion.</p>
    <?php if ($project['description']) : ?><h3>Description</h3><p class="prewrap"><?= e($project['description']) ?></p><?php endif; ?>
    <?php if ($project['scope']) : ?><h3>Scope</h3><p class="prewrap"><?= e($project['scope']) ?></p><?php endif; ?>
</div>

<div>
<?php if ($transitions !== []) : ?>
<div class="panel"><h2>Change status</h2>
    <form method="post" action="<?= e($base) ?>/status" class="form-grid" data-confirm="Change the project status?"><?= csrf_field() ?>
        <div class="field"><label for="st">Move to</label><select id="st" name="status" required><option value="">Choose…</option><?php foreach ($transitions as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="note">Note <span class="muted">(required for On Hold, Cancelled, and for Awaiting Payment → Ready to Start)</span></label><input id="note" name="note" maxlength="500"></div>
        <?php if (isset($transitions['completed'])) : ?><label class="check"><input type="checkbox" name="confirm" value="1"> I confirm this project is complete<?= $openTasks > 0 ? " ({$openTasks} task(s) are still open)" : '' ?></label><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Update status</button></div>
    </form>
    <?php if ($project['status'] === 'awaiting_payment') : ?><p class="muted">Moving on from Awaiting Payment is a manual confirmation: the system cannot verify payments yet and records it as such on the timeline.</p><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel"><h2>Team</h2>
    <?php if ($members === []) : ?><p class="muted">No team members assigned yet.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($members as $m) : ?>
        <li><?= e($m['name']) ?> <span class="chip"><?= e($m['project_role']) ?></span>
            <?php if ($canAssign) : ?><form method="post" action="<?= e($base) ?>/assignments/<?= e($m['public_id']) ?>/revoke" class="inline-form" data-confirm="Remove <?= e($m['name']) ?> from this project? Their open tasks will be unassigned."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Unassign</button></form><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php if ($canAssign) : ?>
        <form method="post" action="<?= e($base) ?>/assignments" class="inline-form"><?= csrf_field() ?>
            <select name="user" required aria-label="Team member"><option value="">Add team member…</option><?php foreach ($candidates as $c) : ?><option value="<?= e($c['public_id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <select name="project_role" aria-label="Project role"><option value="member">Member</option><option value="lead">Lead</option></select>
            <button class="btn btn-primary btn-sm" type="submit">Assign</button></form>
        <?php if (count($members) > 0 && $candidates !== []) : ?>
        <form method="post" action="<?= e($base) ?>/assignments/reassign" class="inline-form" data-confirm="Reassign this project from one person to another?"><?= csrf_field() ?>
            <select name="from" required aria-label="Replace"><option value="">Replace…</option><?php foreach ($members as $m) : ?><option value="<?= e($m['public_id']) ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select>
            <select name="to_user" required aria-label="With"><option value="">with…</option><?php foreach ($candidates as $c) : ?><option value="<?= e($c['public_id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <label class="check"><input type="checkbox" name="move_tasks" value="1" checked> hand over open tasks</label>
            <button class="btn btn-ghost btn-sm" type="submit">Reassign</button></form>
        <?php endif; ?>
    <?php elseif ($staff) : ?><p class="muted">Only an administrator can assign or reassign the team.</p><?php endif; ?>
</div>
</div>
</div>

<?php if ($milestones !== []) : ?>
<div class="panel"><h2>Milestones</h2>
    <ul class="plain-list"><?php foreach ($milestones as $m) : ?><li><strong><?= e($m['title']) ?></strong> <span class="badge"><?= e(config("workflow.milestone_statuses.{$m['status']}", $m['status'])) ?></span> <?= $m['target_date'] ? '<span class="' . ($m['is_overdue'] ? 'overdue' : 'muted') . '">' . e($m['target_date']) . ($m['is_overdue'] ? ' (overdue)' : '') . '</span>' : '' ?></li><?php endforeach; ?></ul>
    <p><a href="<?= e($base) ?>/milestones">All milestones →</a></p></div>
<?php endif; ?>

<div class="grid-2">
<div class="panel"><h2>Shared notes &amp; client feedback</h2>
    <?php if ($sharedNotes === []) : ?><p class="muted">Nothing yet.</p><?php endif; ?>
    <?php foreach ($sharedNotes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= $n['kind'] === 'client_feedback' ? 'Client feedback' : 'Shared with client' ?> · <?= e($n['author_name'] ?? '') ?> · <?= e(local_time($n['created_at'])) ?></p></div><?php endforeach; ?>
    <?php if ($project['archived_at'] === null) : ?>
        <?php if ($level === 'own' && can('projects.give_feedback')) : ?>
            <form method="post" action="<?= e($base) ?>/notes" class="form-grid"><?= csrf_field() ?><input type="hidden" name="kind" value="client_feedback"><div class="field"><label for="fb">Your feedback</label><textarea id="fb" name="body" rows="3" maxlength="4000" required></textarea></div><div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Send feedback</button></div></form>
        <?php elseif ($staff && can('projects.notes_client')) : ?>
            <form method="post" action="<?= e($base) ?>/notes" class="form-grid"><?= csrf_field() ?><input type="hidden" name="kind" value="client_visible"><div class="field"><label for="cn">Note visible to the client</label><textarea id="cn" name="body" rows="3" maxlength="4000" required></textarea></div><div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Share note</button></div></form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($staff && can('projects.notes_internal')) : ?>
<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">staff only</span></h2>
    <?php if ($internalNotes === []) : ?><p class="muted">No internal notes.</p><?php endif; ?>
    <?php foreach ($internalNotes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= e($n['author_name'] ?? '') ?> · <?= e(local_time($n['created_at'])) ?></p></div><?php endforeach; ?>
    <?php if ($project['archived_at'] === null) : ?><form method="post" action="<?= e($base) ?>/notes" class="form-grid"><?= csrf_field() ?><input type="hidden" name="kind" value="internal"><div class="field"><textarea name="body" rows="3" maxlength="4000" required aria-label="Internal note"></textarea></div><div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add internal note</button></div></form><?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php if ($canEdit) : ?>
<div class="panel" id="edit"><h2>Edit project</h2>
    <form method="post" action="<?= e($base) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="title">Project name</label><input id="title" name="title" required maxlength="200" value="<?= e($values['title']) ?>"></div>
        <div class="field"><label for="service_category">Service category</label><select id="service_category" name="service_category"><option value="">—</option><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $values['service_category'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="description">Description</label><textarea id="description" name="description" rows="4" maxlength="5000"><?= e($values['description']) ?></textarea></div>
        <div class="field"><label for="scope">Scope</label><textarea id="scope" name="scope" rows="4" maxlength="10000"><?= e($values['scope']) ?></textarea></div>
        <div class="field"><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" value="<?= e($values['start_date']) ?>"></div>
        <div class="field"><label for="target_date">Target delivery date</label><input id="target_date" name="target_date" type="date" value="<?= e($values['target_date']) ?>"></div>
        <?php if ($canBudget) : ?><div class="field"><label for="budget">Budget estimate (₹)</label><input id="budget" name="budget" inputmode="decimal" value="<?= e($project['budget_minor'] !== null ? number_format($project['budget_minor'] / 100, 2, '.', '') : '') ?>"></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div></form></div>
<?php endif; ?>

<?php if ($canArchive && (in_array($project['status'], ['completed', 'cancelled'], true) || $project['archived_at'])) : ?>
<div class="panel"><h2>Archive</h2>
    <form method="post" action="<?= e($base) ?>/archive" class="inline-form" data-confirm="<?= $project['archived_at'] ? 'Restore' : 'Archive' ?> this project?"><?= csrf_field() ?>
        <?php if ($project['archived_at']) : ?><input type="hidden" name="restore" value="1"><?php endif; ?>
        <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
        <button class="btn btn-danger btn-sm" type="submit"><?= $project['archived_at'] ? 'Restore' : 'Archive project' ?></button></form></div>
<?php endif; ?>
