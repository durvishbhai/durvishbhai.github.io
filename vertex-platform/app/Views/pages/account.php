<?php
/** @var array<string,mixed> $user */
/** @var list<string> $roles */
/** @var list<string> $timezones */
$tz = old('timezone', (string) ($user['timezone'] ?? ''));
?>
<p class="eyebrow">Account</p>
<h1>My account</h1>

<div class="panel">
    <h2>Profile</h2>
    <p class="muted">Email: <strong><?= e($user['email']) ?></strong> &middot; Roles: <?php foreach ($roles as $r) : ?><span class="chip"><?= e($r) ?></span><?php endforeach; ?></p>
    <p class="muted">Your email and roles can only be changed by an administrator.</p>
    <form method="post" action="/account" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" value="<?= e(old('name', (string) $user['name'])) ?>"></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?= e(old('phone', (string) ($user['phone'] ?? ''))) ?>"></div>
        <div class="field"><label for="timezone">Time zone <span class="muted">(times are shown in this zone)</span></label>
            <select id="timezone" name="timezone">
                <option value="">Default (<?= e(config('app.timezone')) ?>)</option>
                <?php foreach ($timezones as $z) : ?><option value="<?= e($z) ?>"<?= $z === $tz ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
</div>

<div class="panel" id="mobile-app">
    <h2>Mobile app &amp; notifications</h2>
    <p class="muted">Install <?= e((string) config('app.name')) ?> on your phone so it opens like an app, and get payment, project and request updates as notifications.</p>
    <p><button type="button" class="btn btn-primary btn-sm" data-install hidden>Install the app</button> <span class="muted" data-install-state></span></p>
    <p class="muted small" data-install-ios hidden>On iPhone: tap the Share button, then <strong>Add to Home Screen</strong>. Open the app from your Home Screen to turn notifications on.</p>
    <?php if (\Vertex\Services\PushChannel::enabled()) : ?><p><button type="button" class="btn btn-ghost btn-sm" data-push-toggle hidden>Turn notifications on</button> <button type="button" class="btn btn-ghost btn-sm" data-push-test hidden>Send me a test notification</button></p>
    <p class="muted" data-push-status></p><?php endif; ?>
</div>

<div class="panel">
    <h2>Account activity</h2>
    <p class="muted">See your recent sign-ins and what happened on your account. <a href="/account/activity">Open my activity</a></p>
</div>

<div class="panel">
    <h2>Two-factor login</h2>
    <p class="muted">Protect your account with a code from an authenticator app. <a href="/account/two-factor">Manage two-factor login</a></p>
</div>

<div class="panel" id="change-password">
    <h2>Change password</h2>
    <form method="post" action="/account/password" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="password">New password <span class="muted">(12+ characters)</span></label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"></div>
        <div class="field"><label for="password_confirmation">Repeat new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
    </form>
    <p class="muted">Changing your password signs out all your other sessions.</p>
</div>
