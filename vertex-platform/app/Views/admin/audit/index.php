<?php
/** @var list<array<string,mixed>> $rows */
/** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,string> $filters */
/** @var list<string> $entities */
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);

    return $d === null ? $json : (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$qs = ['q' => $filters['q'], 'action' => $filters['action'], 'entity' => $filters['entity'], 'from' => $filters['from_date'], 'to' => $filters['to_date']];
?>
<div class="page-head">
    <div><p class="eyebrow">Administration</p><h1>Audit log</h1></div>
    <form method="post" action="/admin/audit/verify"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Verify integrity</button></form>
</div>
<p class="muted">Append-only and hash-chained. Entries cannot be edited or deleted from this application. Times are shown in your time zone.</p>

<form method="get" action="/admin/audit" class="toolbar" role="search">
    <div class="field"><label for="q">Actor / IP / request id</label><input id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="action">Action contains</label><input id="action" name="action" maxlength="60" placeholder="e.g. login" value="<?= e($filters['action']) ?>"></div>
    <div class="field"><label for="entity">Record type</label>
        <select id="entity" name="entity"><option value="">All</option>
            <?php foreach ($entities as $en) : ?><option value="<?= e($en) ?>"<?= $filters['entity'] === $en ? ' selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($filters['from_date']) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($filters['to_date']) ?>"></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>

<div class="table-wrap">
<table class="table">
    <thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Record</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr>
            <td><?= e($r['id']) ?></td>
            <td><?= e(local_time($r['created_at'], 'd M Y, H:i:s')) ?></td>
            <td><?= $r['actor_id'] ? e($r['actor_name']) . '<br><span class="muted">' . e($r['actor_email']) . '</span>' : '<span class="muted">system / anonymous</span>' ?></td>
            <td><code><?= e($r['action']) ?></code></td>
            <td><?= e($r['entity_type'] ?? '—') ?><?= $r['entity_id'] ? ' #' . e($r['entity_id']) : '' ?></td>
            <td><?= e($r['ip'] ?? '') ?></td>
        </tr>
        <?php if ($r['before_json'] || $r['after_json'] || $r['meta_json']) : ?>
        <tr class="detail-row"><td></td><td colspan="5">
            <details><summary>Details &middot; request <code><?= e($r['request_id']) ?></code></summary>
                <?php foreach (['before_json' => 'Before', 'after_json' => 'After', 'meta_json' => 'Meta'] as $k => $label) : if ($r[$k]) : ?>
                    <p class="muted"><?= e($label) ?></p><pre class="trace"><?= e($pretty($r[$k])) ?></pre>
                <?php endif; endforeach; ?>
            </details>
        </td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="6" class="muted">No entries match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
