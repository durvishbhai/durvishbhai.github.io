<?php
/** @var array<string,mixed> $d */ /** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $versions */ /** @var array<string,list<string>> $actions */
/** @var array<string,mixed> $state */ /** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $reviews */ /** @var list<array<string,mixed>> $revisions */
/** @var bool $canUpload */ /** @var bool $canArchive */ /** @var bool $canDownload */ /** @var list<array<string,mixed>> $tasks */ /** @var list<array<string,mixed>> $milestones */ /** @var string $limit */ /** @var string $types */
use Vertex\Core\View;
use Vertex\Services\FileStore;
$base = '/deliverables/' . $d['public_id'];
$v = static fn (string $k, string $dflt = ''): string => old($k, $dflt);
?>
<div class="page-head"><div><p class="eyebrow">Deliverable <?= e($d['code']) ?></p><h1><?= e($d['title']) ?></h1></div><a class="btn btn-ghost" href="/projects/<?= e($d['project_public_id']) ?>/deliverables">&larr; Project deliverables</a></div>
<?= View::partial('project-tabs', ['project' => $project, 'tab' => 'deliverables']) ?>

<div class="panel">
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><?= View::partial('dstatus', ['s' => $d['status'], 'kind' => 'dstatus']) ?></div>
        <div><span class="muted">Category</span><br><?= e(config("workflow.deliverable_categories.{$d['category']}", $d['category'])) ?></div>
        <div><span class="muted">Client sees</span><br><?= $state['version'] ? 'V' . e($state['version']['version_no']) . ' · ' . e($state['label']) : '<span class="muted">Nothing published</span>' ?></div>
        <div><span class="muted">First published</span><br><?= $d['first_published_at'] ? e(local_time($d['first_published_at'])) : '—' ?></div>
        <div><span class="muted">Task</span><br><?= $d['task_public_id'] ? '<a href="/tasks/' . e($d['task_public_id']) . '">' . e($d['task_title']) . '</a>' : '—' ?></div>
        <div><span class="muted">Milestone</span><br><?= e($d['milestone_title'] ?? '—') ?></div>
        <div><span class="muted">Created by</span><br><?= e($d['creator_name'] ?? '—') ?></div>
    </div>
    <?php if ($d['description']) : ?><p class="prewrap"><?= e($d['description']) ?></p><?php endif; ?>
    <p class="muted">Internal approval is not client acceptance, and a client's approval applies only to the exact version they approved.</p>
    <?php if (count($versions) > 1) : ?><a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/compare">Compare versions</a><?php endif; ?>
</div>

<div class="panel"><h2>Versions</h2>
    <?php foreach ($versions as $ver) : $pid = $ver['public_id']; $isLive = (int) $d['published_version_id'] === (int) $ver['id']; $acts = $actions[$pid] ?? []; ?>
        <div class="milestone<?= $isLive ? ' is-current' : '' ?>">
            <div class="milestone-head">
                <strong>V<?= e($ver['version_no']) ?><?= $ver['label'] ? ' · ' . e($ver['label']) : '' ?></strong>
                <?= View::partial('dstatus', ['s' => $ver['status'], 'kind' => 'vstatus']) ?>
                <?php if ($isLive) : ?><span class="badge badge-active">Current client version</span><?php endif; ?>
                <span class="muted"><?= e(local_time($ver['created_at'])) ?> · <?= e($ver['uploader_name'] ?? 'Former user') ?></span>
            </div>
            <p class="muted"><?php if ($ver['file_id'] !== null) : ?><?= e($ver['original_name']) ?> · <?= e(FileStore::humanSize((int) $ver['size_bytes'])) ?> · <?= e($ver['mime']) ?> · scan: <?= e(str_replace('_', ' ', (string) $ver['scan_status'])) ?>
                <?php else : ?>External link: <a href="<?= e($ver['external_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($ver['external_url']) ?></a><?php endif; ?>
                <?= $ver['published_at'] ? ' · published ' . e(local_time($ver['published_at'])) . ($ver['publisher_name'] ? ' by ' . e($ver['publisher_name']) : '') : '' ?>
                <?= $ver['approved_at'] ? ' · approved internally ' . e(local_time($ver['approved_at'])) . ($ver['approver_name'] ? ' by ' . e($ver['approver_name']) : '') : '' ?></p>
            <?php if ($ver['change_notes']) : ?><p class="prewrap"><span class="muted">Internal change notes:</span> <?= e($ver['change_notes']) ?></p><?php endif; ?>
            <div class="action-row">
                <?php if ($ver['file_id'] !== null && $canDownload) : ?><a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/versions/<?= e($pid) ?>/download">Download</a><?php endif; ?>
                <?php if (in_array('submit', $acts, true)) : ?><form method="post" action="<?= e($base) ?>/versions/<?= e($pid) ?>/submit" class="inline-form"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Submit for internal review</button></form><?php endif; ?>
                <?php if (in_array('publish', $acts, true)) : ?><form method="post" action="<?= e($base) ?>/versions/<?= e($pid) ?>/publish" class="inline-form" data-confirm="Publish V<?= e($ver['version_no']) ?> to the client? They will be able to see and download it."><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Publish to client</button></form><?php endif; ?>
            </div>
            <?php if (in_array('review', $acts, true)) : ?>
                <details open><summary>Internal QA review</summary>
                    <form method="post" action="<?= e($base) ?>/versions/<?= e($pid) ?>/review" class="form-grid"><?= csrf_field() ?>
                        <div class="field"><label for="rn<?= e($pid) ?>">Review notes (internal; required when sending back)</label><textarea id="rn<?= e($pid) ?>" name="notes" rows="2" maxlength="3000"><?= e($v('notes')) ?></textarea></div>
                        <div class="form-actions"><button class="btn btn-primary btn-sm" name="decision" value="approved" type="submit">Approve internally</button>
                            <button class="btn btn-ghost btn-sm" name="decision" value="changes_requested" type="submit">Request changes</button></div></form></details>
            <?php endif; ?>
            <?php if (in_array('withdraw', $acts, true)) : ?>
                <details><summary>Withdraw from client</summary>
                    <form method="post" action="<?= e($base) ?>/versions/<?= e($pid) ?>/withdraw" class="inline-form" data-confirm="Withdraw this version? The client will no longer see it."><?= csrf_field() ?>
                        <input name="reason" required minlength="5" maxlength="500" placeholder="Why is it being withdrawn?" aria-label="Reason"><button class="btn btn-danger btn-sm" type="submit">Withdraw</button></form></details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($versions === []) : ?><p class="muted">No versions.</p><?php endif; ?>
</div>

<?php if ($canUpload) : ?>
<div class="panel"><h2>Add a new version</h2>
    <p class="muted">A new version never replaces an earlier one, and it does not inherit any review, publication or client approval.</p>
    <form method="post" action="<?= e($base) ?>/versions" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Uploading…"><?= csrf_field() ?>
        <?= View::partial('deliverable-content-fields', ['withMeta' => false, 'limit' => $limit, 'types' => $types, 'tasks' => [], 'milestones' => []]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Upload new version</button></div></form>
    <details><summary>Edit details</summary>
        <form method="post" action="<?= e($base) ?>" class="form-grid" novalidate><?= csrf_field() ?>
            <div class="field"><label for="et">Title</label><input id="et" name="title" required maxlength="200" value="<?= e($v('title', (string) $d['title'])) ?>"></div>
            <div class="field"><label for="ed">Description</label><textarea id="ed" name="description" rows="3" maxlength="5000"><?= e($v('description', (string) ($d['description'] ?? ''))) ?></textarea></div>
            <div class="field"><label for="ec">Category</label><select id="ec" name="category"><?php foreach (config('workflow.deliverable_categories') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $d['category'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="etk">Related task</label><select id="etk" name="task"><option value="">None</option><?php foreach ($tasks as $t) : ?><option value="<?= e($t['public_id']) ?>"<?= $d['task_public_id'] === $t['public_id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="em">Related milestone</label><select id="em" name="milestone"><option value="">None</option><?php foreach ($milestones as $m) : ?><option value="<?= e($m['public_id']) ?>"<?= $d['milestone_public_id'] === $m['public_id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save</button></div></form></details>
</div>
<?php endif; ?>

<div class="panel"><h2>Internal QA history <span class="badge badge-sensitive">staff only</span></h2>
    <?php if ($reviews === []) : ?><p class="muted">No internal reviews yet.</p><?php endif; ?>
    <?php foreach ($reviews as $rv) : ?><div class="note"><p><strong>V<?= e($rv['version_no']) ?>: <?= $rv['decision'] === 'approved' ? 'approved internally' : 'changes requested' ?></strong> <span class="muted"><?= e($rv['reviewer_name'] ?? '') ?> · <?= e(local_time($rv['created_at'])) ?></span></p><?php if ($rv['notes']) : ?><p class="prewrap"><?= e($rv['notes']) ?></p><?php endif; ?></div><?php endforeach; ?>
</div>

<div class="panel"><h2>Client responses</h2>
    <?php if ($history === []) : ?><p class="muted">The client has not responded yet.</p><?php endif; ?>
    <?php foreach ($history as $h) : ?><div class="note"><p><strong>V<?= e($h['version_no']) ?>: <?= e(str_replace('_', ' ', (string) $h['action'])) ?></strong> <span class="muted"><?= e($h['client_name'] ?? '') ?> · <?= e(local_time($h['created_at'])) ?><?= $h['revision_reference'] ? ' · ' . e($h['revision_reference']) : '' ?></span></p><?php if ($h['feedback']) : ?><p class="prewrap"><?= e($h['feedback']) ?></p><?php endif; ?></div><?php endforeach; ?>
</div>

<div class="panel"><h2>Revision requests</h2>
    <?php if ($revisions === []) : ?><p class="muted">None.</p><?php else : ?><ul class="plain-list"><?php foreach ($revisions as $r) : ?><li><a href="/revisions/<?= e($r['public_id']) ?>"><?= e($r['reference']) ?></a> V<?= e($r['version_no']) ?> <?= View::partial('dstatus', ['s' => $r['status'], 'kind' => 'rstatus']) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>

<?php if ($canArchive) : ?>
<div class="panel"><form method="post" action="<?= e($base) ?>/archive" class="inline-form" data-confirm="<?= $d['archived_at'] === null ? 'Archive this deliverable?' : 'Restore this deliverable?' ?>"><?= csrf_field() ?>
    <?php if ($d['archived_at'] !== null) : ?><input type="hidden" name="restore" value="1"><?php endif; ?>
    <button class="btn btn-danger btn-sm" type="submit"><?= $d['archived_at'] === null ? 'Archive deliverable' : 'Restore deliverable' ?></button></form></div>
<?php endif; ?>
