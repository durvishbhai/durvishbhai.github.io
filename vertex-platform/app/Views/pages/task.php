<?php
/** @var array<string,mixed> $task */ /** @var array<string,mixed> $project */ /** @var array<string,mixed> $detail */ /** @var array<string,string> $allowed */
/** @var list<array<string,mixed>> $unresolved */ /** @var list<array<string,mixed>> $members */ /** @var list<array<string,mixed>> $candidates */ /** @var list<array<string,mixed>> $milestones */
/** @var bool $canEdit */ /** @var bool $canAssign */ /** @var bool $canComment */ /** @var bool $canLog */ /** @var bool $canArchive */ /** @var bool $canAttach */ /** @var bool $canDownload */
$base = '/tasks/' . $task['public_id'];
$v = static fn (string $k, string $d = ''): string => old($k, $d);
$late = $task['due_date'] !== null && $task['due_date'] < \Vertex\Services\AppTime::today() && !in_array($task['status'], ['completed', 'cancelled'], true);
$reasonStatuses = \Vertex\Services\TaskWorkflow::NEEDS_REASON;
?>
<div class="page-head"><div><p class="eyebrow">Task · <?= e($task['project_code']) ?></p><h1><?= e($task['title']) ?></h1></div><a class="btn btn-ghost" href="/projects/<?= e($task['project_public_id']) ?>/tasks">&larr; Project tasks</a></div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => 'tasks']) ?>

<?php if ($task['archived_at'] !== null) : ?><div class="alert alert-info" role="status">This task is archived.</div><?php endif; ?>
<?php if ($unresolved !== [] && !in_array($task['status'], ['completed', 'cancelled'], true)) : ?>
    <div class="alert alert-info" role="status">Waiting on <?= e(count($unresolved)) ?> unfinished task<?= count($unresolved) === 1 ? '' : 's' ?>: this task cannot be started, reviewed or completed until they are done.</div>
<?php endif; ?>

<div class="panel">
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><span class="badge badge-tstatus-<?= e($task['status']) ?>"><?= e(config("workflow.task_statuses.{$task['status']}", $task['status'])) ?></span></div>
        <div><span class="muted">Priority</span><br><span class="badge badge-prio-<?= e($task['priority']) ?>"><?= e(status_label((string) $task['priority'])) ?></span></div>
        <div><span class="muted">Assignee</span><br><?= $task['assignee_name'] ? e($task['assignee_name']) : '<span class="muted">Unassigned</span>' ?></div>
        <div><span class="muted">Due</span><br><?= $task['due_date'] ? '<span' . ($late ? ' class="overdue"' : '') . '>' . e($task['due_date']) . ($late ? ' (overdue)' : '') . '</span>' : '—' ?></div>
        <div><span class="muted">Start</span><br><?= e($task['start_date'] ?? '—') ?></div>
        <div><span class="muted">Milestone</span><br><?= e($task['milestone_title'] ?? '—') ?></div>
        <div><span class="muted">Estimate</span><br><?= $task['estimate_minutes'] !== null ? e(format_minutes($task['estimate_minutes'])) : '—' ?></div>
        <div><span class="muted">Logged</span><br><?= e(format_minutes($detail['logged_minutes'])) ?></div>
        <div><span class="muted">Review</span><br><?= (int) $task['review_required'] === 1 ? e(status_label((string) $task['review_status'])) : 'Not required' ?></div>
        <div><span class="muted">Completed</span><br><?= $task['completed_at'] ? e(local_time($task['completed_at'])) : '—' ?></div>
    </div>
    <?php if ($task['description']) : ?><p class="prewrap"><?= e($task['description']) ?></p><?php endif; ?>

    <?php if ($allowed !== []) : ?>
        <form method="post" action="<?= e($base) ?>/status" class="inline-form" id="status-form"><?= csrf_field() ?>
            <label for="to-status" class="sr-only">Move to</label>
            <select id="to-status" name="status" data-reason-for="<?= e(implode(',', $reasonStatuses)) ?>"><?php foreach ($allowed as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
            <input type="text" name="reason" maxlength="1000" placeholder="Reason (required for Blocked / Changes Requested)" aria-label="Reason" class="reason-input">
            <button class="btn btn-primary btn-sm" type="submit">Move task</button></form>
    <?php endif; ?>
</div>

<div class="panel"><h2>Dependencies</h2>
    <?php if ($detail['depends_on'] === []) : ?><p class="muted">This task does not wait on anything.</p><?php endif; ?>
    <ul class="plain-list">
        <?php foreach ($detail['depends_on'] as $d) : ?>
            <li><a href="/tasks/<?= e($d['public_id']) ?>"><?= e($d['title']) ?></a> <span class="badge badge-tstatus-<?= e($d['status']) ?>"><?= e(config("workflow.task_statuses.{$d['status']}", $d['status'])) ?></span>
                <?php if ($canEdit) : ?><form method="post" action="<?= e($base) ?>/dependencies/<?= e($d['public_id']) ?>/remove" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($detail['blocking'] !== []) : ?><p class="muted">Blocking:
        <?php foreach ($detail['blocking'] as $i => $d) : ?><?= $i > 0 ? ', ' : ' ' ?><a href="/tasks/<?= e($d['public_id']) ?>"><?= e($d['title']) ?></a><?php endforeach; ?></p><?php endif; ?>
    <?php if ($canEdit && $candidates !== []) : ?>
        <form method="post" action="<?= e($base) ?>/dependencies" class="inline-form"><?= csrf_field() ?>
            <label for="dep" class="sr-only">Depends on</label>
            <select id="dep" name="depends_on"><?php foreach ($candidates as $c) : ?><option value="<?= e($c['public_id']) ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-ghost btn-sm" type="submit">Add prerequisite</button></form>
    <?php endif; ?>
</div>

<?php if ($canAssign) : ?>
<div class="panel"><h2>Assignment</h2>
    <form method="post" action="<?= e($base) ?>/assign" class="inline-form"><?= csrf_field() ?>
        <label for="asg" class="sr-only">Assignee</label>
        <select id="asg" name="assignee"><option value="">Unassigned</option><?php foreach ($members as $u) : ?><option value="<?= e($u['public_id']) ?>"<?= $task['assignee_public_id'] === $u['public_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit">Save</button></form>
    <p class="muted">Only members assigned to the project can be given tasks.</p></div>
<?php endif; ?>

<?php if ($canEdit) : ?>
<div class="panel"><details><summary>Edit task</summary>
    <form method="post" action="<?= e($base) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="et">Title</label><input id="et" name="title" required maxlength="200" value="<?= e($v('title', (string) $task['title'])) ?>"></div>
        <div class="field"><label for="ed">Description</label><textarea id="ed" name="description" rows="3" maxlength="5000"><?= e($v('description', (string) ($task['description'] ?? ''))) ?></textarea></div>
        <div class="field"><label for="ep">Priority</label><select id="ep" name="priority"><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $task['priority'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="es">Start date</label><input id="es" name="start_date" type="date" value="<?= e($task['start_date'] ?? '') ?>"></div>
        <div class="field"><label for="edu">Due date</label><input id="edu" name="due_date" type="date" value="<?= e($task['due_date'] ?? '') ?>"></div>
        <div class="field"><label for="ee">Estimate (minutes)</label><input id="ee" name="estimate_minutes" inputmode="numeric" maxlength="6" value="<?= e($task['estimate_minutes'] ?? '') ?>"></div>
        <div class="field"><label for="em">Milestone</label><select id="em" name="milestone"><option value="">None</option><?php foreach ($milestones as $m) : if (in_array($m['status'], ['rejected', 'cancelled'], true)) { continue; } ?><option value="<?= e($m['public_id']) ?>"<?= $task['milestone_public_id'] === $m['public_id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="check"><input type="checkbox" name="review_required" value="1"<?= (int) $task['review_required'] === 1 ? ' checked' : '' ?>> Needs review before completion</label></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div></form></details></div>
<?php endif; ?>

<div class="panel"><h2>Time</h2>
    <?php if ($detail['time'] === []) : ?><p class="muted">No time logged yet.</p><?php else : ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Who</th><th>Time</th><th>Note</th></tr></thead><tbody>
            <?php foreach ($detail['time'] as $t) : ?><tr><td><?= e($t['work_date']) ?></td><td><?= e($t['name'] ?? '—') ?></td><td><?= e(format_minutes($t['minutes'])) ?></td><td><?= e($t['note'] ?? '') ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    <?php if ($canLog) : ?>
        <form method="post" action="<?= e($base) ?>/time" class="inline-form"><?= csrf_field() ?>
            <label for="tm" class="sr-only">Minutes</label><input id="tm" name="minutes" inputmode="numeric" maxlength="4" placeholder="Minutes" required>
            <label for="twd" class="sr-only">Date</label><input id="twd" name="work_date" type="date" value="<?= e(\Vertex\Services\AppTime::today()) ?>">
            <input name="note" maxlength="255" placeholder="Note (optional)" aria-label="Note">
            <button class="btn btn-ghost btn-sm" type="submit">Log time</button></form>
    <?php endif; ?>
</div>

<div class="panel"><h2>Files</h2>
    <?php if ($detail['attachments'] === []) : ?><p class="muted">No files attached.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($detail['attachments'] as $a) : ?>
        <li><?php if ($canDownload) : ?><a href="/attachments/<?= e($a['public_id']) ?>"><?= e($a['original_name']) ?></a><?php else : ?><?= e($a['original_name']) ?><?php endif; ?> <span class="muted"><?= e(number_format((int) $a['size_bytes'] / 1024, 0)) ?> KB · <?= e($a['uploader'] ?? '—') ?> · <?= e(local_time($a['created_at'])) ?></span></li>
    <?php endforeach; ?></ul>
    <?php if ($canAttach) : ?>
        <form method="post" action="<?= e($base) ?>/attachments" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?>
            <label for="tf" class="sr-only">File</label><input id="tf" type="file" name="file" required>
            <button class="btn btn-ghost btn-sm" type="submit">Upload</button></form>
        <p class="muted">PDF, images, text, CSV, Word or Excel, up to 5 MB.</p>
    <?php endif; ?>
</div>

<div class="panel"><h2>Comments</h2>
    <?php if ($detail['comments'] === []) : ?><p class="muted">No comments yet.</p><?php endif; ?>
    <?php foreach ($detail['comments'] as $c) : ?><div class="note"><strong><?= e($c['name'] ?? 'Former user') ?></strong> <span class="muted"><?= e(local_time($c['created_at'])) ?></span><p class="prewrap"><?= e($c['body']) ?></p></div><?php endforeach; ?>
    <?php if ($canComment) : ?>
        <form method="post" action="<?= e($base) ?>/comments" class="form-grid"><?= csrf_field() ?>
            <div class="field"><label for="cb">Add a comment</label><textarea id="cb" name="body" rows="3" maxlength="3000" required><?= e($v('comment')) ?></textarea></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Post comment</button></div></form>
    <?php endif; ?>
</div>

<?php if ($canArchive) : ?>
<div class="panel"><form method="post" action="<?= e($base) ?>/archive" class="inline-form" data-confirm="<?= $task['archived_at'] === null ? 'Archive this task?' : 'Restore this task?' ?>"><?= csrf_field() ?>
    <?php if ($task['archived_at'] !== null) : ?><input type="hidden" name="restore" value="1"><?php endif; ?>
    <button class="btn btn-danger btn-sm" type="submit"><?= $task['archived_at'] === null ? 'Archive task' : 'Restore task' ?></button></form></div>
<?php endif; ?>
