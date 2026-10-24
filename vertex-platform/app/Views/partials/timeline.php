<?php
/** @var list<array<string,mixed>> $items rows with type|event_type, summary, body?, created_at, actor_name */
?>
<?php if ($items === []) : ?>
    <p class="muted">No activity yet.</p>
<?php else : ?>
    <ol class="timeline">
        <?php foreach ($items as $it) : $type = (string) ($it['type'] ?? $it['event_type'] ?? ''); ?>
            <li class="timeline-item type-<?= e(preg_replace('/[^a-z_]/', '', explode('.', $type)[0])) ?>">
                <div class="timeline-head">
                    <strong><?= e($it['summary']) ?></strong>
                    <span class="muted"><?= e(local_time($it['created_at'])) ?> &middot; <?= e($it['actor_name'] ?? 'System') ?></span>
                </div>
                <?php if (!empty($it['body'])) : ?><p class="timeline-body"><?= nl2br(e($it['body'])) ?></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
