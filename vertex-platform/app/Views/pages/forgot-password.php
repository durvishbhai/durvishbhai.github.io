<?php /** @var bool $mailEnabled */ ?>
<section class="auth">
    <div class="auth-card">
        <h1>Reset password</h1>
        <?php if ($mailEnabled) : ?>
            <p class="muted">Enter your email and we will send a reset link.</p>
            <form method="post" action="/forgot-password" novalidate>
                <?= csrf_field() ?>
                <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autofocus autocomplete="email"></div>
                <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
            </form>
        <?php else : ?>
            <p class="muted">Email is not configured on this system, so password reset by email is unavailable. Please contact an administrator.</p>
        <?php endif; ?>
        <p class="auth-links"><a href="/login">Back to sign in</a></p>
    </div>
</section>
