<?php /** @var bool $ok */ /** @var ?string $email */ /** @var string $token */ /** @var bool $done */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Email</p><h1><?= $done ? 'You are unsubscribed' : 'Unsubscribe from marketing email' ?></h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="panel">
<?php if (!$ok) : ?><p>This unsubscribe link is not valid. Write to us and we will remove you by hand.</p><p><a href="/contact-and-grievance">Contact information</a></p>
<?php elseif ($done) : ?><p><strong><?= e($email) ?></strong> will no longer receive marketing email from us.</p><p class="muted">You will still receive messages we must send you about your account, payments, quotations, security and legal notices: those are not marketing.</p>
<?php else : ?><p>Stop marketing email to <strong><?= e($email) ?></strong>?</p><p class="muted">This only affects marketing. Account, payment, security and legal messages are not marketing and are not affected.</p>
    <form method="post" action="/unsubscribe/<?= e($token) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Unsubscribe</button></form><?php endif; ?></div></div></section>
