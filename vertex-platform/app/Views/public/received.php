<?php /** @var string|null $ref */ ?>
<section class="auth"><div class="auth-card">
    <p class="eyebrow">Thank you</p>
    <h1>Request received</h1>
    <?php if ($ref) : ?><p>Your reference number is <strong><?= e($ref) ?></strong>. Please quote it if you contact us.</p><?php endif; ?>
    <p class="muted">A member of the team will review your request and get in touch. We have not sent you an automatic confirmation email.</p>
    <p><a class="btn btn-ghost" href="/">Back to home</a></p>
</div></section>
