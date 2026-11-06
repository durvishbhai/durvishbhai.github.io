<?php /** @var array<string,mixed> $d */ /** @var array<string,mixed> $state */ /** @var string $action */ /** @var string $token */ /** @var bool $canDownload */
use Vertex\Services\FileStore;
$cur = $state['version'];
$approve = $action === 'approved';
$v = static fn (string $k): string => old($k);
?>
<div class="page-head"><div><p class="eyebrow">Please confirm</p><h1><?= $approve ? 'Approve this version?' : 'Reject this version?' ?></h1></div><a class="btn btn-ghost" href="/deliverables/<?= e($d['public_id']) ?>">&larr; Back</a></div>
<?php if ($cur === null) : ?><div class="panel"><p class="muted">This deliverable is not currently open for a response.</p></div><?php else : ?>
<div class="panel">
    <dl class="detail-list">
        <dt>Deliverable</dt><dd><?= e($d['title']) ?> <span class="muted">(<?= e($d['code']) ?>)</span></dd>
        <dt>Version</dt><dd>V<?= e($cur['version_no']) ?><?= $cur['label'] ? ' · ' . e($cur['label']) : '' ?>, published <?= e(local_time($cur['published_at'])) ?></dd>
        <?php if ($cur['file_id'] !== null) : ?><dt>File</dt><dd><?= e($cur['original_name']) ?> · <?= e(FileStore::humanSize((int) $cur['size_bytes'])) ?></dd><?php endif; ?>
    </dl>
    <?php if ($approve) : ?><div class="alert alert-info" role="status">Approving records your acceptance of <strong>V<?= e($cur['version_no']) ?> only</strong>. If we publish a new version later, it will need your approval again. This is not a payment or an invoice.</div>
    <?php else : ?><div class="alert alert-info" role="status">Rejecting records your reason and tells our team. To ask for specific changes instead, use "Request a revision".</div><?php endif; ?>
    <form method="post" action="/deliverables/<?= e($d['public_id']) ?>/respond" class="form-grid" novalidate data-busy="Saving…"><?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="version" value="<?= e($cur['public_id']) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="fb"><?= $approve ? 'Comments (optional)' : 'Your reason (required)' ?></label><textarea id="fb" name="feedback" rows="4" maxlength="3000"<?= $approve ? '' : ' required' ?>><?= e($v('feedback')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $approve ? 'Yes, approve V' . e($cur['version_no']) : 'Reject V' . e($cur['version_no']) ?></button> <a class="btn btn-ghost" href="/deliverables/<?= e($d['public_id']) ?>">Cancel</a></div></form>
</div>
<?php endif; ?>
