<?php
/** @var list<array<string,mixed>> $rows */
/** @var bool $showActor */
$showActor = $showActor ?? true;
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);

    return $d === null ? $json : (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$cats = \Vertex\Services\ActivityService::CATEGORIES;
?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>When</th><?php if ($showActor) : ?><th>Account</th><?php endif; ?><th>What happened</th><th>About</th><th>Device and address</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr>
            <td><?= e(local_time($r['created_at'], 'd M Y, H:i:s')) ?></td>
            <?php if ($showActor) : ?><td><?php if ($r['actor_id']) : ?><a href="/admin/activity/<?= e($r['actor_public']) ?>"><?= e($r['actor_name']) ?></a><br><span class="muted"><?= e($r['actor_email']) ?></span><?php else : ?><span class="muted">anonymous or system</span><?php endif; ?></td><?php endif; ?>
            <td><strong><?= e($r['label']) ?></strong><br><span class="muted small"><?= e($cats[$r['category']][0] ?? 'Other') ?> &middot; <code><?= e($r['action']) ?></code></span></td>
            <td><?php if ($r['entity_type'] === 'user' && $r['target_name']) : ?>Account: <?= e($r['target_name']) ?><?php else : ?><?= e($r['entity_type'] ?? '—') ?><?= $r['entity_id'] ? ' #' . e($r['entity_id']) : '' ?><?php endif; ?></td>
            <td><?= e($r['device'] !== '' ? $r['device'] : '—') ?><br><span class="muted small"><?= e($r['ip'] ?? '') ?></span></td>
        </tr>
        <?php if ($r['before_json'] || $r['after_json'] || $r['meta_json']) : ?>
        <tr class="detail-row"><td colspan="<?= $showActor ? 5 : 4 ?>">
            <details><summary>Details &middot; request <code><?= e($r['request_id']) ?></code></summary>
                <?php foreach (['before_json' => 'Before', 'after_json' => 'After', 'meta_json' => 'Extra details'] as $k => $label) : if ($r[$k]) : ?>
                    <p class="muted"><?= e($label) ?></p><pre class="trace"><?= e($pretty($r[$k])) ?></pre>
                <?php endif; endforeach; ?>
            </details>
        </td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="<?= $showActor ? 5 : 4 ?>" class="muted">Nothing recorded for this selection.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
