<?php /** @var string|null $ref */ ?>
<section class="auth"><div class="auth-card">
    <p class="eyebrow">Thank you</p><h1>Application received</h1>
    <?php if ($ref) : ?><p>Your application reference is <strong><?= e($ref) ?></strong>. Please quote it if you contact us.</p><?php endif; ?>
    <p class="muted">Our team will review your application. Nothing has been activated yet and we have not sent an automatic confirmation email. If you applied before, we already have your earlier application.</p>
    <p><a class="btn btn-ghost" href="/">Back to home</a></p>
</div></section>
