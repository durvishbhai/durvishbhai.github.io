<?php use Vertex\Core\Config; use Vertex\Services\Mailer; ?>
<section class="auth">
    <div class="auth-card reveal">
        <img class="auth-mark" src="<?= e(asset('img/krazy-mark.png')) ?>" alt="" width="170" height="70">
        <h1>Sign in</h1>
        <p class="muted">Access your Krazy portal.</p>

        <form method="post" action="/login" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= e(old('email')) ?>">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
<?php if (\Vertex\Services\GoogleAuth::enabled()) : ?>
        <p class="auth-or"><span>or</span></p>
        <a class="btn btn-ghost btn-block btn-google" href="/auth/google"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4.1 7.1-10.1 7.1-17.5z"/><path fill="#FBBC05" d="M10.5 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7l-7.9-6.1A24 24 0 0 0 0 24c0 3.9.9 7.5 2.6 10.8l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.8 2.3-8.4 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg> Continue with Google</a>
        <?php endif; ?>
        <p class="auth-links">
            <?php if (Mailer::enabled()) : ?><a href="/forgot-password">Forgot password?</a><?php endif; ?>
            <?php if (\Vertex\Services\Registration::enabled()) : ?><a href="/register">New here? Sign up</a><?php endif; ?>
        </p>
    </div>
</section>
