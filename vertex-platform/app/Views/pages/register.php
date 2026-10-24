<section class="auth">
    <div class="auth-card reveal">
        <h1>Sign up</h1>
        <p class="muted"><?= !empty($approval) ? 'For clients. Our team approves each new account before it can sign in.' : 'For clients. We will email you a link to confirm your address.' ?></p>
        <form method="post" action="/register" novalidate>
            <?= csrf_field() ?>
            <div class="field"><label for="name">Full name</label><input id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= e(old('email')) ?>"></div>
            <div class="field"><label for="phone">Phone number <span class="muted">(required)</span></label><input id="phone" name="phone" type="tel" required minlength="7" maxlength="20" placeholder="+91 90000 00000" autocomplete="tel" value="<?= e(old('phone')) ?>"></div>
            <div class="field"><label for="password">Password <span class="muted">(12+ characters)</span></label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password"></div>
            <div class="field"><label for="password_confirmation">Repeat password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>
<?php if (\Vertex\Services\GoogleAuth::enabled()) : ?>
        <p class="auth-or"><span>or</span></p>
        <a class="btn btn-ghost btn-block btn-google" href="/auth/google"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7l-7.9-6.1A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.8 2.3-8.4 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg> Continue with Google</a>
        <?php endif; ?>
        <p class="auth-links"><a href="/login">I already have an account</a></p>
    </div>
</section>
