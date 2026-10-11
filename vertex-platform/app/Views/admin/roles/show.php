<?php
/** @var array<string,mixed> $role */
/** @var array<string,int> $granted */
/** @var array<string,list<array{perm_key:string,description:string,is_sensitive:bool}>> $catalogue */
/** @var bool $canEdit */ /** @var bool $canSensitive */
/** @var array<string,true> $actorPermissions */
?>
<div class="page-head">
    <div><p class="eyebrow">Role</p><h1><?= e($role['name']) ?></h1></div>
    <a class="btn btn-ghost" href="/admin/roles">&larr; All roles</a>
</div>
<p class="muted"><?= e($role['description']) ?></p>
<?php if ($role['is_locked']) : ?>
    <div class="alert alert-info"><p>This role is locked: it always holds every permission and cannot be edited.</p></div>
<?php elseif (!$canEdit) : ?>
    <div class="alert alert-info"><p>You can view this role but not change it (it is at or above your level, or you lack the permission).</p></div>
<?php endif; ?>

<form method="post" action="/admin/roles/<?= e($role['slug']) ?>" data-confirm="Save permission changes for <?= e($role['name']) ?>? They apply immediately to everyone with this role.">
    <?= csrf_field() ?>
    <?php foreach ($catalogue as $module => $perms) : ?>
        <div class="panel">
            <h2><?= e(ucfirst($module)) ?></h2>
            <?php foreach ($perms as $p) :
                $key = $p['perm_key'];
                $isGranted = isset($granted[$key]);
                $disabled = !$canEdit
                    || ($p['is_sensitive'] && !$canSensitive)
                    || (!$isGranted && !isset($actorPermissions[$key]));
                ?>
                <label class="check perm<?= $disabled ? ' is-disabled' : '' ?>">
                    <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"<?= $isGranted ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                    <code><?= e($key) ?></code> <span class="muted"><?= e($p['description']) ?></span>
                    <?php if ($p['is_sensitive']) : ?><span class="badge badge-sensitive">sensitive</span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($canEdit) : ?>
        <div class="panel inline-form">
            <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
            <button class="btn btn-primary" type="submit">Save permissions</button>
        </div>
    <?php endif; ?>
</form>
