<?php /** @var array<string,mixed> $form */ /** @var ?string $ref */ ?>
<section class="section"><div class="container narrow"><div class="panel panel-ok">
    <h1>Thank you</h1>
    <p><?= e($form['success_message'] ?: 'Your answers were received.') ?></p>
    <?php if ($ref) : ?><p class="muted">Your reference: <strong><?= e($ref) ?></strong></p><?php endif; ?>
    <p><a class="btn btn-ghost" href="/">Back to the home page</a></p>
</div></div></section>
