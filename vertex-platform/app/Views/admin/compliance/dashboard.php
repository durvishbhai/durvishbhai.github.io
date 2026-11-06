<?php /** @var list<array<string,mixed>> $items */ /** @var array<string,int> $counts */
use Vertex\Services\ComplianceDashboard;
$tone = ['complete' => 'confirmed', 'pending' => 'request', 'needs_attention' => 'cancelled', 'legal_review' => 'request', 'not_applicable' => 'completed'];
?>
<div class="page-head"><div><p class="eyebrow">Compliance &amp; legal</p><h1>Compliance checklist</h1></div><a class="btn btn-ghost" href="/admin/compliance">&larr; Compliance &amp; legal</a></div>
<p class="muted">Owner only. You set each status yourself. The system adds the facts it can check; it never marks an item Complete for you and shows no score. <strong>Complete</strong> means <em>you</em> have checked it, not that a lawyer has approved it.</p>
<div class="panel"><ul class="plain-list inline-list"><?php foreach (ComplianceDashboard::STATUSES as $k => $l) : ?><li><strong><?= e((string) $counts[$k]) ?></strong> <?= e($l) ?></li><?php endforeach; ?></ul></div>
<?php foreach ($items as $i) : $id = 'i-' . $i['item_key']; ?>
<details class="panel check-item" id="<?= e($i['item_key']) ?>"<?= in_array($i['status'], ['needs_attention', 'legal_review'], true) ? ' open' : '' ?>>
    <summary><strong><?= e((string) $i['no']) ?>. <?= e($i['title']) ?></strong> <span class="badge badge-d-<?= e($tone[$i['status']] ?? 'request') ?>"><?= e(ComplianceDashboard::STATUSES[$i['status']]) ?></span>
        <span class="muted small"><?= $i['last_reviewed_at'] ? ' · reviewed ' . e(local_date((string) $i['last_reviewed_at'])) : ' · not reviewed yet' ?></span></summary>
    <ul class="plain-list"><?php foreach ($i['checks'] as $c) : ?><li><?= $c['ok'] === true ? '<span aria-hidden="true">✔</span><span class="sr-only">OK:</span>' : ($c['ok'] === false ? '<span aria-hidden="true">✖</span><span class="sr-only">Needs work:</span>' : '<span aria-hidden="true">ℹ</span><span class="sr-only">Note:</span>') ?> <?= e($c['text']) ?></li><?php endforeach; ?></ul>
    <p><a href="<?= e($i['link']) ?>">Open where this is managed</a></p>
    <form method="post" action="/admin/compliance/checklist/<?= e($i['item_key']) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="<?= e($id) ?>-s">Status</label><select id="<?= e($id) ?>-s" name="status"><?php foreach (ComplianceDashboard::STATUSES as $k => $l) : ?><option value="<?= e($k) ?>"<?= $i['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="<?= e($id) ?>-r">Responsible person</label><input id="<?= e($id) ?>-r" name="responsible" maxlength="120" value="<?= e((string) ($i['responsible'] ?? '')) ?>"></div>
        <div class="field"><label for="<?= e($id) ?>-n">Next action</label><input id="<?= e($id) ?>-n" name="next_action" maxlength="255" value="<?= e((string) ($i['next_action'] ?? '')) ?>"></div>
        <div class="field"><label for="<?= e($id) ?>-t">Notes (private)</label><textarea id="<?= e($id) ?>-t" name="notes" rows="2" maxlength="4000"><?= e((string) ($i['notes'] ?? '')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save review</button></div>
    </form>
</details>
<?php endforeach; ?>
