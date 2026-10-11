<?php /** @var array<string,mixed> $r */ /** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $files */ /** @var list<array<string,mixed>> $changes */ /** @var array<string,string> $moves */ /** @var bool $canDownload */
use Vertex\Core\View;
use Vertex\Services\FileStore;
$label = (string) config("workflow.revision_client_labels.{$r['status']}", $r['status']);
?>
<div class="page-head"><div><p class="eyebrow">Revision request</p><h1><?= e($r['reference']) ?></h1></div><a class="btn btn-ghost" href="/revisions">&larr; My requests</a></div>
<div class="panel">
    <p><span class="badge"><?= e($label) ?></span> <span class="muted">on <a href="/deliverables/<?= e($r['deliverable_public_id']) ?>"><?= e($r['deliverable_title']) ?></a> · V<?= e($r['version_no']) ?> · sent <?= e(local_time($r['created_at'])) ?></span></p>
    <h3>Your request</h3><p class="prewrap"><?= e($r['description']) ?></p>
    <?php foreach ($files as $f) : ?><p><?php if ($canDownload) : ?><a href="/revisions/<?= e($r['public_id']) ?>/files/<?= e($f['public_id']) ?>"><?= e($f['original_name']) ?></a><?php else : ?><?= e($f['original_name']) ?><?php endif; ?> <span class="muted"><?= e(FileStore::humanSize((int) $f['size_bytes'])) ?></span></p><?php endforeach; ?>
    <?php if ($r['client_response']) : ?><h3>Our response</h3><p class="prewrap"><?= e($r['client_response']) ?></p><?php endif; ?>
    <?php if ($r['resolution']) : ?><h3>Resolution</h3><p class="prewrap"><?= e($r['resolution']) ?></p><?php endif; ?>
    <?php if ($r['resolved_version_no']) : ?><p>The updated work is <strong>V<?= e($r['resolved_version_no']) ?></strong>. Please check the deliverable for it.</p><?php endif; ?>
</div>
<?php foreach ($changes as $c) : ?>
    <div class="panel panel-attention"><h2>Additional cost proposal <?= e($c['reference']) ?></h2>
        <p>Status: <strong><?= e(status_label((string) $c['status'])) ?></strong>. <?php if ($c['status'] === 'pending_client') : ?>This work will not start, and nothing will be charged, unless you approve it.<?php endif; ?></p>
        <a class="btn btn-primary btn-sm" href="/changes/<?= e($c['public_id']) ?>"><?= $c['status'] === 'pending_client' ? 'Review and decide' : 'View' ?></a></div>
<?php endforeach; ?>
<?php if ($moves !== []) : ?>
    <div class="panel"><h2>Your options</h2>
        <form method="post" action="/revisions/<?= e($r['public_id']) ?>/status" class="form-grid" novalidate><?= csrf_field() ?>
            <div class="field"><label for="m">Action</label><select id="m" name="status"><?php foreach ($moves as $c => $l) : ?><option value="<?= e($c) ?>"><?= $c === 'cancelled' ? 'Cancel this request' : ($c === 'resolved' ? 'Confirm it is resolved' : 'Not fixed yet: send it back') ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="n">Comment (required when sending it back)</label><textarea id="n" name="client_note" rows="3" maxlength="3000"><?= e(old('client_note')) ?></textarea></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Submit</button></div></form></div>
<?php endif; ?>
<div class="panel"><h2>Progress</h2><?= View::partial('revision-history', ['history' => $history, 'staff' => false]) ?></div>
