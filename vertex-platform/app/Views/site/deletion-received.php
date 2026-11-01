<?php /** @var string $ref */ /** @var bool $signedIn */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1>Request received</h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="panel">
    <?php if ($ref !== '' && $ref !== 'DEL-PENDING') : ?><p>Your reference number is <strong><?= e($ref) ?></strong>. Please keep it.</p><?php endif; ?>
    <p><?= $signedIn ? 'Because you are signed in, your identity is already confirmed. We have emailed you a private link to follow the request.' : 'We have sent a confirmation link to the email address you entered. <strong>Open it to confirm that the request is yours:</strong> we cannot act on it until you do.' ?></p>
    <p class="muted">If you do not see the email, check your spam folder. For privacy, this page does not say whether we hold any data about you; you will be told when we have reviewed the request.</p>
    <p><a class="btn btn-ghost" href="/data-deletion/status">Check a request</a></p></div></div></section>
