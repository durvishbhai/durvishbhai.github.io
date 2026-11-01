<?php /** @var string $token */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Your data</p><h1>Confirm your request</h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="panel"><p>Press the button to confirm that you asked us to delete your personal data. Our team will then review the request.</p>
    <form method="post" action="/data-deletion/verify/<?= e($token) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Yes, this request is mine</button></form></div></div></section>
