<?php /** @var string $message */ ?>
<section class="auth maintenance">
    <div class="auth-card">
        <img class="maintenance-logo" src="<?= e(asset('img/krazy-mark.png')) ?>" alt="<?= e((string) config('app.name')) ?>" width="120">
        <p class="eyebrow">Scheduled maintenance</p>
        <h1>We'll be back soon</h1>
        <p class="muted"><?= e($message !== '' ? $message : 'We are making improvements to ' . (string) config('app.name') . '. The site is briefly unavailable. Please check back in a little while. Thank you for your patience.') ?></p>
        <p class="muted small">Team members can <a href="/login">sign in here</a>.</p>
    </div>
</section>
