<?php
/** @var array<string,mixed> $target */
/** @var list<array{slug:string,name:string,level:int}> $targetRoles */
/** @var array<string,string> $assignable */
/** @var list<string> $timezones */
/** @var bool $self */ /** @var bool $manageable */ /** @var bool $mailEnabled */
$status = (string) $target['status'];
$held = array_column($targetRoles, 'slug');
$tz = old('timezone', (string) ($target['timezone'] ?? ''));
$u = '/admin/users/' . $target['public_id'];
?>
<div class="page-head">
    <div><p class="eyebrow">User</p><h1><?= e($target['name']) ?></h1></div>
    <a class="btn btn-ghost" href="/admin/users">&larr; All users</a>
</div>

<?php if ($self) : ?>
    <div class="alert alert-info"><p>This is your own account. You cannot change your own roles or status here; use <a href="/account">My account</a> for your profile and password.</p></div>
<?php elseif (!$manageable) : ?>
    <div class="alert alert-info"><p>This user holds a role at or above your own level, so you can view but not change them.</p></div>
<?php endif; ?>

<div class="panel">
    <h2>Status</h2>
    <p><span class="badge badge-<?= e($status) ?>"><?= e(status_label($status)) ?></span>
       &middot; Email verified: <?= $target['email_verified_at'] ? e(local_time($target['email_verified_at'])) : 'No' ?>
       &middot; Last login: <?= e(local_time($target['last_login_at'])) ?>
       &middot; Created: <?= e(local_time($target['created_at'])) ?></p>
    <?php if ($status === 'deactivated') : ?>
        <p class="muted">Deactivated <?= e(local_time($target['deactivated_at'])) ?>. Reason: <?= e($target['deactivation_reason']) ?></p>
    <?php endif; ?>

    <?php if ($manageable) : ?>
        <div class="action-row">
        <?php if (can('users.deactivate') && in_array($status, ['active', 'pending'], true)) : ?>
            <form method="post" action="<?= e($u) ?>/deactivate" class="inline-form" data-confirm="Deactivate <?= e($target['name']) ?>? They will lose access immediately.">
                <?= csrf_field() ?>
                <input name="reason" placeholder="Reason (required)" maxlength="255" required aria-label="Reason">
                <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
                <button class="btn btn-danger btn-sm" type="submit">Deactivate</button>
            </form>
        <?php endif; ?>
        <?php if (can('users.deactivate') && $status === 'deactivated') : ?>
            <form method="post" action="<?= e($u) ?>/reactivate" class="inline-form" data-confirm="Reactivate this account?">
                <?= csrf_field() ?>
                <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
                <button class="btn btn-primary btn-sm" type="submit">Reactivate</button>
            </form>
        <?php endif; ?>
        <?php if (can('users.archive') && $status !== 'archived') : ?>
            <form method="post" action="<?= e($u) ?>/archive" class="inline-form" data-confirm="Archive this account? It can no longer sign in.">
                <?= csrf_field() ?>
                <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
                <button class="btn btn-danger btn-sm" type="submit">Archive</button>
            </form>
        <?php endif; ?>
        <?php if (can('users.reset_password') && $status === 'active' && $mailEnabled) : ?>
            <form method="post" action="<?= e($u) ?>/send-reset" class="inline-form" data-confirm="Email a password-reset link to this user?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Send password reset</button></form>
        <?php endif; ?>
        <?php if (can('users.create') && $status === 'pending' && $mailEnabled) : ?>
            <form method="post" action="<?= e($u) ?>/resend-invite" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Resend invitation</button></form>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>Profile</h2>
    <form method="post" action="<?= e($u) ?>" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <?php $ro = ($manageable && can('users.edit')) ? '' : ' disabled'; ?>
        <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" value="<?= e(old('name', (string) $target['name'])) ?>"<?= $ro ?>></div>
        <div class="field"><label for="email">Email <span class="muted">(changing it resets verification)</span></label><input id="email" name="email" type="email" required value="<?= e(old('email', (string) $target['email'])) ?>"<?= $ro ?>></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?= e(old('phone', (string) ($target['phone'] ?? ''))) ?>"<?= $ro ?>></div>
        <div class="field"><label for="timezone">Time zone</label>
            <select id="timezone" name="timezone"<?= $ro ?>><option value="">Default</option>
                <?php foreach ($timezones as $z) : ?><option value="<?= e($z) ?>"<?= $z === $tz ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
            </select></div>
        <?php if ($ro === '') : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save profile</button></div><?php endif; ?>
    </form>
</div>

<div class="panel">
    <h2>Roles</h2>
    <p><?php foreach ($targetRoles as $r) : ?><span class="chip"><?= e($r['name']) ?></span><?php endforeach; ?></p>
    <?php if ($manageable && can('users.assign_roles')) : ?>
        <form method="post" action="<?= e($u) ?>/roles" data-confirm="Change this user's roles? Their access changes immediately.">
            <?= csrf_field() ?>
            <fieldset class="field"><legend>Assign roles</legend>
                <?php foreach ($assignable as $slug => $name) : ?>
                    <label class="check"><input type="checkbox" name="roles[]" value="<?= e($slug) ?>"<?= in_array($slug, $held, true) ? ' checked' : '' ?>> <?= e($name) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="inline-form">
                <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password">
                <button class="btn btn-primary btn-sm" type="submit">Save roles</button>
            </div>
        </form>
    <?php endif; ?>
</div>
