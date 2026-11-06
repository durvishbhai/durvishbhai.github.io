<?php /** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $rows */ /** @var array<string,mixed> $states */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/my/deliverables">&larr; My deliverables</a></div>
<?= View::partial('project-tabs', ['project' => $project, 'tab' => 'deliverables']) ?>
<div class="panel"><h2>Deliverables</h2>
    <?php if ($rows === []) : ?><p class="muted">No deliverables published yet.</p><?php else : ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Deliverable</th><th>Category</th><th>Version</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rows as $r) : $s = $states[$r['public_id']] ?? null; ?>
            <tr><td><a href="/deliverables/<?= e($r['public_id']) ?>"><strong><?= e($r['title']) ?></strong></a><br><span class="muted"><?= e($r['code']) ?></span></td>
                <td><?= e(config("workflow.deliverable_categories.{$r['category']}", $r['category'])) ?></td>
                <td><?= $s && $s['version'] ? 'V' . e($s['version']['version_no']) : '—' ?></td>
                <td><?= $s ? View::partial('client-state', ['key' => $s['key'], 'label' => $s['label']]) : '' ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
