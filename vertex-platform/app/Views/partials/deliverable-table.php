<?php /** @var list<array<string,mixed>> $rows */ /** @var bool $showProject */ use Vertex\Core\View; ?>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Deliverable</th><?php if ($showProject) : ?><th>Project</th><?php endif; ?><th>Category</th><th>Status</th><th>Versions</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr><td><a href="/deliverables/<?= e($r['public_id']) ?>"><strong><?= e($r['title']) ?></strong></a><br><span class="muted"><?= e($r['code']) ?></span></td>
            <?php if ($showProject) : ?><td><a href="/projects/<?= e($r['project_public_id']) ?>/deliverables"><?= e($r['project_code']) ?></a></td><?php endif; ?>
            <td><?= e(config("workflow.deliverable_categories.{$r['category']}", $r['category'])) ?></td>
            <td><?= View::partial('dstatus', ['s' => $r['status'], 'kind' => 'dstatus']) ?></td>
            <td>V<?= e($r['latest_no']) ?> latest<?= $r['published_no'] ? '<br><span class="muted">V' . e($r['published_no']) . ' with client</span>' : '' ?></td>
            <td><?= e(local_time($r['updated_at'], 'd M Y')) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="<?= $showProject ? 6 : 5 ?>" class="muted">No deliverables match.</td></tr><?php endif; ?>
    </tbody></table></div>
