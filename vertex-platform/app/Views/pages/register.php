<section class="auth">
    <div class="auth-card reveal">
        <h1>Create account</h1>
        <p class="muted"><?= !empty($approval) ? 'For clients. Our team approves each new account before it can sign in.' : 'For clients. We will email you a link to confirm your address.' ?></p>
        <form method="post" action="/register" novalidate>
            <?= csrf_field() ?>
            <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= e(old('email')) ?>"></div>
            <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" required autocomplete="tel" value="<?= e(old('phone')) ?>"></div>
            <div class="field"><label for="password">Password <span class="muted">(12+ characters)</span></label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"></div>
            <div class="field"><label for="password_confirmation">Repeat password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>
        <p class="auth-links"><a href="/login">I already have an account</a></p>
    </div>
</section>
