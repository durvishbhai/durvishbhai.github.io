<?php /** @var array<string,mixed> $doc */ /** @var string $email */ /** @var string $whatsapp */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1><?= e($doc['title']) ?></h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="prose">
    <p>This document is being finalised and has not been published yet. Please contact us if you need to know how we handle this matter in the meantime.</p>
    <p><?php if ($email !== '') : ?>Email: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.<?php endif; ?> <?php if ($whatsapp !== '') : ?>WhatsApp: <?= e($whatsapp) ?>.<?php endif; ?>
        <a href="/contact">Contact form</a>.</p></div></div></section>
