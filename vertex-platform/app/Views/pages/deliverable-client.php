<?php
/** @var array<string,mixed> $d */ /** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $versions */ /** @var array<string,mixed> $state */
/** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $revisions */ /** @var bool $canRespond */ /** @var bool $canRevise */ /** @var bool $canDownload */
use Vertex\Core\View;
use Vertex\Services\FileStore;
$base = '/deliverables/' . $d['public_id'];
$cur = $state['version'];
?>
<div class="page-head"><div><p class="eyebrow">Deliverable <?= e($d['code']) ?></p><h1><?= e($d['title']) ?></h1></div><a class="btn btn-ghost" href="/my/deliverables">&larr; My deliverables</a></div>
<div class="panel">
    <p><?= View::partial('client-state', ['key' => $state['key'], 'label' => $state['label']]) ?> <span class="muted"><?= e(config("workflow.deliverable_categories.{$d['category']}", $d['category'])) ?> · <?= e($d['project_title']) ?></span></p>
    <?php if ($d['description']) : ?><p class="prewrap"><?= e($d['description']) ?></p><?php endif; ?>
    <?php if ($cur !== null) : ?>
        <h2>Current version: V<?= e($cur['version_no']) ?><?= $cur['label'] ? ' · ' . e($cur['label']) : '' ?></h2>
        <p class="muted">Published <?= e(local_time($cur['published_at'])) ?><?php if ($cur['file_id'] !== null) : ?> · <?= e($cur['original_name']) ?> · <?= e(FileStore::humanSize((int) $cur['size_bytes'])) ?><?php endif; ?></p>
        <div class="action-row">
            <?php if ($cur['file_id'] !== null && $canDownload) : ?><a class="btn btn-ghost" href="<?= e($base) ?>/versions/<?= e($cur['public_id']) ?>/download">Download</a><?php endif; ?>
            <?php if ($cur['external_url']) : ?><a class="btn btn-ghost" href="<?= e($cur['external_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Open the resource</a><?php endif; ?>
        </div>
        <?php if ($state['key'] === 'approved') : ?>
            <p>You approved this version on <?= e(local_time($state['latest']['created_at'])) ?>. A new version would need your approval again.</p>
        <?php elseif ($canRespond || $canRevise) : ?>
            <h3>Your decision</h3>
            <p class="muted">Viewing or downloading does not approve anything. Choose what to do:</p>
            <div class="action-row">
                <?php if ($canRespond) : ?><a class="btn btn-primary" href="<?= e($base) ?>/respond?action=approve">Approve this version</a><a class="btn btn-ghost" href="<?= e($base) ?>/respond?action=reject">Reject with a reason</a><?php endif; ?>
                <?php if ($canRevise) : ?><a class="btn btn-ghost" href="<?= e($base) ?>/revisions/new">Request a revision</a><?php endif; ?></div>
        <?php endif; ?>
    <?php else : ?><p class="muted">This deliverable is not currently available.</p><?php endif; ?>
</div>

<div class="panel"><h2>Versions you can see</h2>
    <?php if ($versions === []) : ?><p class="muted">None.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($versions as $v) : ?>
        <li><strong>V<?= e($v['version_no']) ?><?= $v['label'] ? ' · ' . e($v['label']) : '' ?></strong> <?= $v['status'] === 'published' ? '<span class="badge badge-active">Current</span>' : '<span class="badge">Earlier version</span>' ?>
            <span class="muted">published <?= e(local_time($v['published_at'], 'd M Y')) ?><?= $v['file_id'] !== null ? ' · ' . e($v['original_name']) . ' · ' . e(FileStore::humanSize((int) $v['size_bytes'])) : '' ?></span>
            <?php if ($v['file_id'] !== null && $canDownload) : ?> <a href="<?= e($base) ?>/versions/<?= e($v['public_id']) ?>/download">Download</a><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php if (count($versions) > 1) : ?><a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/compare">Compare versions</a><?php endif; ?>
</div>

<div class="panel"><h2>Your approvals and feedback</h2>
    <?php if ($history === []) : ?><p class="muted">You have not responded yet.</p><?php endif; ?>
    <?php foreach ($history as $h) : ?><div class="note"><p><strong>V<?= e($h['version_no']) ?>: <?= e(str_replace('_', ' ', (string) $h['action'])) ?></strong> <span class="muted"><?= e(local_time($h['created_at'])) ?><?= $h['revision_reference'] ? ' · ' . e($h['revision_reference']) : '' ?></span></p><?php if ($h['feedback']) : ?><p class="prewrap"><?= e($h['feedback']) ?></p><?php endif; ?></div><?php endforeach; ?>
</div>

<div class="panel"><h2>Your revision requests</h2>
    <?php if ($revisions === []) : ?><p class="muted">None.</p><?php else : ?><ul class="plain-list"><?php foreach ($revisions as $r) : ?>
        <li><a href="/revisions/<?= e($r['public_id']) ?>"><?= e($r['reference']) ?></a> <span class="muted">on V<?= e($r['version_no']) ?></span> <span class="badge"><?= e(config("workflow.revision_client_labels.{$r['status']}", $r['status'])) ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
</div>
