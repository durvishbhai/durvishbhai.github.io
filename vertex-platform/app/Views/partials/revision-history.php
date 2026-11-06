<?php /** @var list<array<string,mixed>> $history */ /** @var bool $staff */ ?>
<ol class="timeline">
    <?php foreach ($history as $h) : ?>
        <li><strong><?= e($staff ? config("workflow.revision_statuses.{$h['to_status']}", $h['to_status']) : config("workflow.revision_client_labels.{$h['to_status']}", $h['to_status'])) ?></strong>
            <span class="muted"><?= e(local_time($h['created_at'])) ?><?= !empty($h['actor_name']) ? ' · ' . e($h['actor_name']) : '' ?><?= $staff && $h['visibility'] === 'internal' ? ' · internal' : '' ?></span>
            <?php if ($h['note']) : ?><p class="prewrap"><?= e($h['note']) ?></p><?php endif; ?></li>
    <?php endforeach; ?>
</ol>
