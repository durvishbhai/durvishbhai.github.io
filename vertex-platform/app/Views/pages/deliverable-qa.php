<?php /** @var array{review:list<array<string,mixed>>,publish:list<array<string,mixed>>} $queue */ use Vertex\Services\FileStore; ?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>QA review queue</h1></div><a class="btn btn-ghost" href="/deliverables">All deliverables</a></div>
<p class="muted">Internal only. Nothing here is visible to clients. Approving internally does not publish; publishing is a separate, deliberate step.</p>
<?php foreach ([['review', 'Waiting for internal review', 'No versions are waiting for review.', 'submitted_at', 'Submitted'], ['publish', 'Approved: waiting to be published', 'Nothing is waiting to be published.', 'approved_at', 'Approved']] as [$key, $title, $empty, $col, $when]) : ?>
<div class="panel"><h2><?= e($title) ?> <span class="muted">(<?= e(count($queue[$key])) ?>)</span></h2>
    <?php if ($queue[$key] === []) : ?><p class="muted"><?= e($empty) ?></p><?php else : ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Deliverable</th><th>Project</th><th>Version</th><th>File</th><th>Uploaded by</th><th><?= e($when) ?></th></tr></thead><tbody>
        <?php foreach ($queue[$key] as $r) : ?>
            <tr><td><a href="/deliverables/<?= e($r['public_id']) ?>"><strong><?= e($r['title']) ?></strong></a><br><span class="muted"><?= e($r['code']) ?> · <?= e(config("workflow.deliverable_categories.{$r['category']}", $r['category'])) ?></span></td>
                <td><?= e($r['project_code']) ?></td><td>V<?= e($r['version_no']) ?><?= $r['label'] ? ' · ' . e($r['label']) : '' ?></td>
                <td><?= $r['original_name'] ? e($r['original_name']) . '<br><span class="muted">' . e(FileStore::humanSize((int) $r['size_bytes'])) . '</span>' : '<span class="muted">External link</span>' ?></td>
                <td><?= e($r['uploader_name'] ?? '—') ?></td><td><?= $r[$col] ? e(local_time($r[$col], 'd M Y, H:i')) : '—' ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endforeach; ?>
