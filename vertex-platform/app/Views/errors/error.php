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
        <p><a class="btn btn-ghost" href="/">Back to home</a></p>
        <?php if (!empty($trace)) : ?>
            <pre class="trace"><?= e($trace) ?></pre>
        <?php endif; ?>
    </div>
</section>
