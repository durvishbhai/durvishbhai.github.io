<?php use Vertex\Core\Config; use Vertex\Services\Mailer; ?>
<section class="auth">
    <div class="auth-card reveal">
        <img class="auth-mark" src="<?= e(asset('img/vertex-mark.png')) ?>" alt="" width="72" height="62">
        <h1>Sign in</h1>
        <p class="muted">Access your Vertex portal.</p>

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
        <p class="auth-links">
            <?php if (Mailer::enabled()) : ?><a href="/forgot-password">Forgot password?</a><?php endif; ?>
            <?php if (Config::get('auth.registration_enabled') && Mailer::enabled()) : ?><a href="/register">Create a client account</a><?php endif; ?>
        </p>
    </div>
</section>
