<?php /** @var string|null $ref */ ?>
<section class="auth"><div class="auth-card"><p class="eyebrow">Thank you</p><h1>Application received</h1>
    <?php if ($ref) : ?><p>Your reference number is <strong><?= e($ref) ?></strong>. Please quote it if you contact us.</p><?php endif; ?>
    <p class="muted">Your application will be reviewed. Receiving it does not guarantee employment, payment or selection, and we may not be able to reply to every application.</p>
    <p><a class="btn btn-ghost" href="<?= e($back ?? '/careers') ?>">Back</a></p></div></section>
