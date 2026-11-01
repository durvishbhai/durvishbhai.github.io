<?php /** @var bool $ok */ /** @var string $ref */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1><?= $ok ? 'Request confirmed' : 'This link did not work' ?></h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="panel">
    <?php if ($ok) : ?><p>Thank you. Request <strong><?= e($ref) ?></strong> is confirmed and with our team. We have emailed you a private link to follow it.</p>
    <?php else : ?><p>The link is invalid, has expired (they last 7 days) or was already used. You can <a href="/data-deletion">submit the request again</a>, or <a href="/data-deletion/status">check an existing one</a>.</p><?php endif; ?></div></div></section>
