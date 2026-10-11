<?php
/** @var array<string,mixed> $project */
/** @var list<array<string,mixed>> $members */
/** @var bool $canAssign */
/** @var list<array<string,mixed>> $candidates */
$base = '/projects/' . $project['public_id'];
?>
<div class="page-head">
    <div><p class="eyebrow">Project</p><h1><?= e($project['title']) ?></h1></div>
    <a class="btn btn-ghost" href="/projects">&larr; Projects</a>
</div>
<div class="panel">
    <p>Status: <span class="badge"><?= e(status_label((string) $project['status'])) ?></span> &middot; Client: <?= e($project['company_name'] ?: $project['client_name']) ?> &middot; Created <?= e(local_time($project['created_at'], 'd M Y')) ?></p>
</div>
<div class="panel">
    <h2>Team</h2>
    <?php if ($members === []) : ?><p class="muted">No team members assigned yet.</p><?php endif; ?>
    <ul class="plain-list">
        <?php foreach ($members as $m) : ?>
            <li><?= e($m['name']) ?> <span class="chip"><?= e($m['project_role']) ?></span>
                <?php if ($canAssign) : ?>
                    <form method="post" action="<?= e($base) ?>/assignments/<?= e($m['public_id']) ?>/revoke" class="inline-form" data-confirm="Remove <?= e($m['name']) ?> from this project?">
                        <?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Unassign</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($canAssign) : ?>
        <form method="post" action="<?= e($base) ?>/assignments" class="inline-form">
            <?= csrf_field() ?>
            <select name="user" required aria-label="Team member"><option value="">Choose team member…</option>
                <?php foreach ($candidates as $c) : ?><option value="<?= e($c['public_id']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="project_role" aria-label="Project role"><option value="member">Member</option><option value="lead">Lead</option></select>
            <button class="btn btn-primary btn-sm" type="submit">Assign</button>
        </form>
    <?php endif; ?>
</div>
