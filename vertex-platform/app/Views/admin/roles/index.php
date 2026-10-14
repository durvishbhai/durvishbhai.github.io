<?php /** @var list<array<string,mixed>> $roles */ /** @var int $actorLevel */ ?>
<p class="eyebrow">Administration</p>
<div class="page-head"><div><h1>Roles &amp; permissions</h1></div><?php if (can('roles.create')) : ?><div class="actions"><a class="btn btn-primary" href="/admin/roles/new">New custom role</a></div><?php endif; ?></div>
<p class="muted">Each role's grants are stored in the database and edited here. Sensitive permissions can only be changed by the owner. A role at or above your own level is read-only for you.</p>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Role</th><th>Level</th><th>Users</th><th>Permissions</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r) : ?>
        <tr>
            <td><strong><?= e($r['name']) ?></strong><?= (int) $r['is_system'] === 0 ? ' <span class="badge">custom</span>' : '' ?><br><span class="muted"><?= e($r['description']) ?></span></td>
            <td><?= e($r['level']) ?><?= $r['is_locked'] ? ' <span class="badge">locked</span>' : '' ?></td>
            <td><?= e($r['user_count']) ?></td>
            <td><?= e($r['permission_count']) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="/admin/roles/<?= e($r['slug']) ?>"><?= (int) $r['level'] < $actorLevel && !$r['is_locked'] && can('roles.manage') ? 'Edit' : 'View' ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
