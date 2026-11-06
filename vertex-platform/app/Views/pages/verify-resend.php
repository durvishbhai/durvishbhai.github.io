<section class="auth">
    <div class="auth-card">
        <h1>Resend confirmation</h1>
        <form method="post" action="/verify-email" novalidate>
            <?= csrf_field() ?>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email"></div>
            <button class="btn btn-primary btn-block" type="submit">Send link</button>
        </form>
        <p class="auth-links"><a href="/login">Back to sign in</a></p>
    </div>
</section>
