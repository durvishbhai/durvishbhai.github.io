<?php
/** @var array<string,string> $assignable */
/** @var bool $mailEnabled */
?>
<p class="eyebrow">Administration</p>
<h1>Create user</h1>
<?php if (!$mailEnabled) : ?>
    <div class="alert alert-info"><p>Email is not configured, so no invitation can be sent. The account will be created as pending; use the console command <code>user:set-password</code> to let the person in.</p></div>
<?php endif; ?>
<div class="panel">
    <form method="post" action="/admin/users" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" value="<?= e(old('name')) ?>"></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= e(old('email')) ?>"></div>
        <div class="field"><label for="phone">Phone <span class="muted">(required for client, freelancer, editor, developer, referral partner)</span></label><input id="phone" name="phone" type="tel" value="<?= e(old('phone')) ?>"></div>
        <fieldset class="field">
            <legend>Roles</legend>
            <?php foreach ($assignable as $slug => $name) : ?>
                <label class="check"><input type="checkbox" name="roles[]" value="<?= e($slug) ?>"> <?= e($name) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <p class="muted">The person receives an invitation to set their own password. Administrators never see or choose passwords.</p>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create user</button> <a class="btn btn-ghost" href="/admin/users">Cancel</a></div>
    </form>
</div>
