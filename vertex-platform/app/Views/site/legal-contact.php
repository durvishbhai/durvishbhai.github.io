<?php /** @var string $email */ /** @var string $whatsapp */ /** @var string $gname */ /** @var string $gemail */ /** @var string $gdays */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1>Contact and Grievance Information</h1></div></section>
<section class="pub-section"><div class="container narrow"><div class="prose">
    <h2>General and project contact</h2>
    <ul><?php if ($email !== '') : ?><li>Business email: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
        <?php if ($whatsapp !== '') : ?><li>WhatsApp (our main channel for project messages): <?= e($whatsapp) ?></li><?php endif; ?>
        <li><a href="/contact">Website contact form</a></li></ul>
    <h2>Privacy requests and grievances</h2>
    <?php if ($gemail !== '' || $email !== '') : ?><p>Write to <?= $gname !== '' ? e($gname) . ' at ' : '' ?><a href="mailto:<?= e($gemail !== '' ? $gemail : $email) ?>"><?= e($gemail !== '' ? $gemail : $email) ?></a><?= $gdays !== '' ? '. We aim to acknowledge a grievance within ' . e($gdays) . ' days' : '' ?>.</p>
    <?php else : ?><p>The grievance contact has not been published yet. Please use the <a href="/contact">contact form</a>.</p><?php endif; ?>
    <ul><li><a href="/data-deletion">Ask us to delete your data</a></li><li><a href="/cookie-settings">Change your cookie choices</a></li><li><a href="/privacy-policy">Privacy Policy</a></li></ul>
    <p class="muted small"><a href="/legal-business-information">Legal business information</a></p>
</div></div></section>
