<?php /** @var string|null $ref */ /** @var string $kind */ $kind = $kind ?? ''; ?>
<section class="auth"><div class="auth-card">
    <?php if ($kind === 'appointment_request') : ?>
    <p class="eyebrow">Appointment request received</p>
    <h1>Thank you. Your appointment is not booked yet.</h1>
    <?php if ($ref) : ?><p>Your reference number is <strong><?= e($ref) ?></strong>. Please quote it if you contact us.</p><?php endif; ?>
    <div class="alert alert-info" role="status"><p><strong>This is a request, not a confirmed appointment.</strong> Our team will contact you to check that the request is genuine and to confirm the date, time and place. Until you hear from us, please treat the time as not yet booked.</p></div>
    <p><a class="btn btn-ghost" href="/">Back to home</a></p>
    <?php else : ?>
    <p class="eyebrow">Thank you</p>
    <h1>Request received</h1>
    <?php if ($ref) : ?><p>Your reference number is <strong><?= e($ref) ?></strong>. Please quote it if you contact us.</p><?php endif; ?>
    <p class="muted">A member of the team will review your request and get in touch. We have not sent you an automatic confirmation email.</p>
    <p><a class="btn btn-ghost" href="/">Back to home</a></p>
    <?php endif; ?>
</div></section>
