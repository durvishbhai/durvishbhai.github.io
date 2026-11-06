<?php /** @var int $maxLevel */ /** @var list<array<string,mixed>> $copyable */ ?>
<p class="eyebrow"><a href="/admin/roles">Roles &amp; permissions</a></p>
<h1>New custom role</h1>
<p class="muted">A custom role holds exactly the permissions you tick on the next page. You can only give a role permissions that you hold yourself, and a role can never outrank you. Record-level limits (for example "only assigned projects") come from the permissions you choose.</p>
<form method="post" action="/admin/roles" class="panel form-grid" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="name">Role name</label><input id="name" name="name" required minlength="3" maxlength="60" value="<?= e(old('name')) ?>"></div>
    <div class="field"><label for="description">What is it for? <span class="muted">(optional)</span></label><input id="description" name="description" maxlength="255" value="<?= e(old('description')) ?>"></div>
    <div class="field"><label for="level">Level <span class="muted">(10 to <?= (int) $maxLevel ?>; higher can manage lower)</span></label><input id="level" name="level" type="number" min="10" max="<?= (int) $maxLevel ?>" required value="<?= e(old('level', '40')) ?>"></div>
    <div class="field"><label for="copy_from">Start from an existing role <span class="muted">(optional)</span></label>
        <select id="copy_from" name="copy_from"><option value="">Start empty</option><?php foreach ($copyable as $c) : ?><option value="<?= e($c['slug']) ?>"<?= old('copy_from') === $c['slug'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="confirm_password">Your password</label><input id="confirm_password" name="confirm_password" type="password" required autocomplete="current-password"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create role</button></div>
</form>
