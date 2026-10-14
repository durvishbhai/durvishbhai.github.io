<section class="auth">
    <div class="auth-card reveal">
        <img class="auth-mark" src="<?= e(asset('img/vertex-mark.png')) ?>" alt="" width="72" height="62">
        <h1>Verification code</h1>
        <p class="muted">Open your authenticator app and enter the 6-digit code for this account. Lost your phone? Enter one of your recovery codes instead.</p>
        <form method="post" action="/login/two-factor" novalidate>
            <?= csrf_field() ?>
            <div class="field"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="40"></div>
            <button class="btn btn-primary btn-block" type="submit">Verify and sign in</button>
        </form>
        <p class="auth-links"><a href="/login">Start again</a></p>
    </div>
</section>
