<?php
/** @var array<string,mixed> $user */
/** @var list<string> $roles */
/** @var list<string> $permissions */
?>
<div class="container-wide">
    <p class="eyebrow">Signed in</p>
    <h1>Welcome, <?= e($user['name']) ?></h1>

    <div class="alert alert-info">
        <p>Project, billing and support modules are not built yet. This page shows your account's roles and effective
            permissions so the access-control foundation can be verified.</p>
    </div>

    <div class="panel">
        <h2>Roles</h2>
        <p>
            <?php foreach ($roles as $r) : ?>
                <span class="chip"><?= e($r) ?></span>
            <?php endforeach; ?>
            <?php if ($roles === []) : ?><span class="muted">No roles assigned.</span><?php endif; ?>
        </p>
    </div>

    <div class="panel">
        <h2>Effective permissions <span class="muted">(<?= e(count($permissions)) ?>)</span></h2>
        <p class="perm-list">
            <?php foreach ($permissions as $p) : ?>
                <code><?= e($p) ?></code>
            <?php endforeach; ?>
        </p>
    </div>
</div>
