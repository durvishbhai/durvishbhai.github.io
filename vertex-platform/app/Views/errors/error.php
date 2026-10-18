<?php
/** @var int $status */
/** @var string $message */
/** @var string $reference */
/** @var string|null $trace */
$noindex = true;
?>
<section class="auth">
    <div class="auth-card">
        <p class="eyebrow">Error <?= e($status) ?></p>
        <h1><?= e($message) ?></h1>
        <p class="muted">Reference: <code><?= e($reference) ?></code></p>
        <?php if ((int) $status === 404) : ?><p class="muted">The page may have moved or may not be published yet. These links may help:</p>
        <p class="chip-row"><a class="pub-chip" href="/services">Services</a><a class="pub-chip" href="/contact">Contact us</a><a class="pub-chip" href="/faq">FAQ</a><a class="pub-chip" href="/sitemap">Sitemap</a></p><?php endif; ?>
        <p><a class="btn btn-ghost" href="/">Back to home</a></p>
        <?php if (!empty($trace)) : ?>
            <pre class="trace"><?= e($trace) ?></pre>
        <?php endif; ?>
    </div>
</section>
