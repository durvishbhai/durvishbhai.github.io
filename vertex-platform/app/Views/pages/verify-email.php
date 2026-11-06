<?php /** @var bool $valid */ /** @var string $token */ ?>
<section class="auth">
    <div class="auth-card">
        <h1>Confirm your email</h1>
        <?php if ($valid) : ?>
            <p class="muted">Press the button to confirm this email address.</p>
            <form method="post" action="/verify-email/<?= e($token) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit">Confirm email</button></form>
        <?php else : ?>
            <p class="muted">This confirmation link is invalid, already used, or has expired.</p>
            <p><a class="btn btn-ghost" href="/verify-email">Request a new link</a></p>
        <?php endif; ?>
    </div>
</section>
