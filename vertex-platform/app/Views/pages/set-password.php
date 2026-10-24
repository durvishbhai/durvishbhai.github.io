<?php /** @var bool $invite */ /** @var bool $valid */ /** @var string $token */ ?>
<section class="auth">
    <div class="auth-card">
        <h1><?= $invite ? 'Set your password' : 'Choose a new password' ?></h1>
        <?php if ($valid) : ?>
            <form method="post" action="/<?= $invite ? 'invite' : 'reset-password' ?>/<?= e($token) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="field"><label for="password">New password <span class="muted">(12+ characters)</span></label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" autofocus></div>
                <div class="field"><label for="password_confirmation">Repeat password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
                <button class="btn btn-primary btn-block" type="submit">Save password</button>
            </form>
        <?php else : ?>
            <p class="muted">This link is invalid, already used, or has expired.</p>
            <p><a class="btn btn-ghost" href="/forgot-password">Request a new link</a></p>
        <?php endif; ?>
    </div>
</section>
