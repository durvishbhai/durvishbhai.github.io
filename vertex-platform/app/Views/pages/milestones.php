<?php
/** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $milestones */ /** @var array<string,array<string,string>> $allowed */
/** @var bool $staff */ /** @var bool $canCreate */ /** @var bool $direct */ /** @var bool $canEdit */
$tab = 'milestones'; $base = '/projects/' . $project['public_id'];
$v = static fn (string $k): string => old($k);
?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects">&larr; Projects</a></div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => $tab]) ?>

<div class="panel"><h2>Milestones</h2>
    <?php if ($milestones === []) : ?><p class="muted">No milestones yet<?= $canCreate ? '. Add the first one below.' : '.' ?></p><?php endif; ?>
    <?php foreach ($milestones as $m) : $pid = $m['public_id']; ?>
        <div class="milestone <?= $m['is_overdue'] ? 'is-overdue' : '' ?>">
            <div class="milestone-head">
                <strong><?= e($m['title']) ?></strong>
                <span class="badge badge-mstatus-<?= e($m['status']) ?>"><?= e(config("workflow.milestone_statuses.{$m['status']}", $m['status'])) ?></span>
                <?php if ($staff) : ?><span class="badge"><?= $m['visibility'] === 'client' ? 'Client-visible' : 'Internal' ?></span><?php endif; ?>
                <?php if ($m['target_date']) : ?><span class="<?= $m['is_overdue'] ? 'overdue' : 'muted' ?>">Target <?= e($m['target_date']) ?><?= $m['is_overdue'] ? ' — overdue' : '' ?></span><?php endif; ?>
                <?php if ($m['completed_at']) : ?><span class="muted">Completed <?= e(local_time($m['completed_at'], 'd M Y')) ?></span><?php endif; ?>
                <?php if ((int) $m['task_total'] > 0) : ?><span class="muted"><?= e($m['task_done']) ?>/<?= e($m['task_total']) ?> tasks</span><?php endif; ?>
            </div>
            <?php if ($m['description']) : ?><p class="prewrap muted"><?= e($m['description']) ?></p><?php endif; ?>
            <?php if (($allowed[$pid] ?? []) !== []) : ?>
                <form method="post" action="<?= e($base) ?>/milestones/<?= e($pid) ?>/status" class="inline-form"><?= csrf_field() ?>
                    <select name="status" aria-label="New status"><?php foreach ($allowed[$pid] as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($m['status'] === 'proposed' && $c === 'planned' ? 'Approve' : ($m['status'] === 'proposed' ? 'Reject' : $l)) ?></option><?php endforeach; ?></select>
                    <?php if ($m['status'] === 'proposed') : ?><select name="visibility" aria-label="Visibility on approval"><option value="internal">Internal</option><option value="client">Client-visible</option></select><?php endif; ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Apply</button></form>
            <?php endif; ?>
            <?php if ($canEdit && $m['status'] !== 'rejected') : ?>
                <details><summary>Edit</summary>
                    <form method="post" action="<?= e($base) ?>/milestones/<?= e($pid) ?>" class="form-grid"><?= csrf_field() ?>
                        <div class="field"><input name="title" required maxlength="160" value="<?= e($m['title']) ?>" aria-label="Title"></div>
                        <div class="field"><textarea name="description" rows="2" maxlength="3000" aria-label="Description"><?= e($m['description'] ?? '') ?></textarea></div>
                        <div class="field"><input name="target_date" type="date" value="<?= e($m['target_date'] ?? '') ?>" aria-label="Target date"></div>
                        <div class="field"><select name="visibility" aria-label="Visibility"><option value="internal"<?= $m['visibility'] === 'internal' ? ' selected' : '' ?>>Internal</option><option value="client"<?= $m['visibility'] === 'client' ? ' selected' : '' ?>>Client-visible</option></select></div>
                        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save</button></div></form>
                    <form method="post" action="<?= e($base) ?>/milestones/<?= e($pid) ?>/archive" class="inline-form" data-confirm="Archive this milestone? Its tasks stay."><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Archive</button></form>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($canCreate) : ?>
<div class="panel"><h2><?= $direct ? 'Add a milestone' : 'Propose a milestone' ?></h2>
    <?php if (!$direct) : ?><p class="muted">Your role proposes milestones. A proposal stays internal until someone with approval rights accepts it.</p><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/milestones" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="mt">Title</label><input id="mt" name="title" required maxlength="160" value="<?= e($v('title')) ?>"></div>
        <div class="field"><label for="md">Description</label><textarea id="md" name="description" rows="3" maxlength="3000"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label for="mdate">Target date</label><input id="mdate" name="target_date" type="date" value="<?= e($v('target_date')) ?>"></div>
        <?php if ($direct) : ?><div class="field"><label for="mv">Visibility</label><select id="mv" name="visibility"><option value="internal">Internal</option><option value="client">Visible to the client</option></select></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $direct ? 'Add milestone' : 'Propose' ?></button></div></form></div>
<?php endif; ?>
