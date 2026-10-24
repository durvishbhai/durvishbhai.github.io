<?php
/** @var list<array<string,mixed>> $rows */
/** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,string> $filters */
/** @var array<string,array<string,mixed>> $roles */
/** @var int $actorLevel */
?>
<div class="page-head">
    <div><p class="eyebrow">Administration</p><h1>Users</h1></div>
    <?php if (can('users.create')) : ?><a class="btn btn-primary" href="/admin/users/create">Create user</a><?php endif; ?>
</div>

<form method="get" action="/admin/users" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" placeholder="Name or email" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="role">Role</label>
        <select id="role" name="role"><option value="">All roles</option>
            <?php foreach ($roles as $slug => $def) : ?><option value="<?= e($slug) ?>"<?= $filters['role'] === $slug ? ' selected' : '' ?>><?= e($def['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any status</option>
            <?php foreach (['pending', 'active', 'deactivated', 'archived'] as $s) : ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>

<div class="table-wrap">
<table class="table">
    <thead><tr><th>Name</th><th>Roles</th><th>Status</th><th>Email verified</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr>
            <td><strong><?= e($r['name']) ?></strong><br><span class="muted"><?= e($r['email']) ?></span></td>
            <td><?= e($r['role_names'] ?? '—') ?></td>
            <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(status_label((string) $r['status'])) ?></span></td>
            <td><?= $r['email_verified_at'] ? 'Yes' : 'No' ?></td>
            <td><?= e(local_time($r['last_login_at'])) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="/admin/users/<?= e($r['public_id']) ?>">Open</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="6" class="muted">No users match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= \Vertex\Core\View::partial('pagination', compact('page', 'perPage', 'total', 'filters')) ?>
