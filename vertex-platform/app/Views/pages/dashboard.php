<?php
/** @var array<string,mixed> $user */
/** @var list<string> $roles */
/** @var list<string> $permissions */
/** @var int $waiting */ /** @var list<array{title:string,svg:string,link:string}> $charts */
?>
<p class="eyebrow">Signed in</p>
<h1>Welcome, <?= e($user['name']) ?></h1>

<?php if ($waiting > 0) : ?><div class="alert alert-info" role="status"><p><a href="/approvals"><strong><?= (int) $waiting ?></strong> item(s) are waiting for your decision</a></p></div><?php endif; ?>
<?php if ($charts !== []) : ?><div class="chart-grid"><?php foreach ($charts as $c) : ?><div class="panel chart-panel"><h2><a href="<?= e($c['link']) ?>"><?= e($c['title']) ?></a></h2><?= $c['svg'] ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card-grid">
    <?php if (can('projects.view_all') || can('projects.view_assigned') || can('projects.view_own')) : ?>
        <a class="card tile" href="/projects"><h3>Projects</h3><p>Projects you can access.</p></a>
    <?php endif; ?>
    <?php if (can('invoices.view_all')) : ?><a class="card tile" href="/invoices"><h3>Invoices</h3><p>Billing and payments.</p></a><?php endif; ?>
    <?php if (can('invoices.view_own')) : ?><a class="card tile" href="/my/invoices"><h3>My invoices</h3><p>What you owe and have paid.</p></a><?php endif; ?>
    <?php if (can('reports.financial') || can('reports.operational')) : ?><a class="card tile" href="/reports"><h3>Reports</h3><p>Charts and tables.</p></a><?php endif; ?>
    <?php if (can('users.view')) : ?><a class="card tile" href="/admin/users"><h3>Users</h3><p>Accounts, roles and access.</p></a><?php endif; ?>
    <?php if (can('roles.view')) : ?><a class="card tile" href="/admin/roles"><h3>Roles &amp; permissions</h3><p>Who can do what.</p></a><?php endif; ?>
    <?php if (can('audit.view')) : ?><a class="card tile" href="/admin/audit"><h3>Audit log</h3><p>Security-relevant history.</p></a><?php endif; ?>
</div>

<div class="panel">
    <h2>Roles</h2>
    <p><?php foreach ($roles as $r) : ?><span class="chip"><?= e($r) ?></span><?php endforeach; ?>
    <?php if ($roles === []) : ?><span class="muted">No roles assigned.</span><?php endif; ?></p>
</div>
<div class="panel">
    <h2>Effective permissions <span class="muted">(<?= e(count($permissions)) ?>)</span></h2>
    <p class="perm-list"><?php foreach ($permissions as $p) : ?><code><?= e($p) ?></code><?php endforeach; ?></p>
</div>
