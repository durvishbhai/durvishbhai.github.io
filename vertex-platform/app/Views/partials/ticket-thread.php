<?php /** @var list<array<string,mixed>> $conversation */ /** @var string $base */ /** @var bool $canDownload */ /** @var string $role */
use Vertex\Core\View; ?>
<div class="thread" aria-live="polite">
    <?php foreach ($conversation as $m) : ?>
        <article class="msg msg-<?= e($m['author_role']) ?>">
            <header><strong><?= e($m['author_name'] ?? 'Unknown') ?></strong> <span class="muted"><?= e(local_time($m['created_at'])) ?></span></header>
            <p class="prewrap"><?= e($m['body']) ?></p>
            <?= View::partial('ticket-files', ['files' => $m['files'], 'base' => $base, 'canDownload' => $canDownload]) ?>
        </article>
    <?php endforeach; ?>
    <?php if ($conversation === []) : ?><p class="muted">No messages yet.</p><?php endif; ?>
</div>
