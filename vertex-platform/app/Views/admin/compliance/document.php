<?php
/** @var array<string,mixed> $doc */ /** @var list<array<string,mixed>> $versions */ /** @var ?array<string,mixed> $work */ /** @var ?array<string,mixed> $live */ /** @var array<string,string> $form */
/** @var list<string> $blockers */ /** @var list<array<string,mixed>> $reviews */ /** @var bool $reviewed */ /** @var array<string,array{label:string,value:string,required:bool}> $tokens */
$f = static fn (string $k): string => old($k, $form[$k] ?? '');
$scheduled = $work !== null && $work['status'] === 'scheduled';
$base = '/admin/compliance/documents/' . $doc['doc_key'];
?>
<div class="page-head"><div><p class="eyebrow">Compliance &amp; legal</p><h1><?= e($doc['title']) ?></h1></div><a class="btn btn-ghost" href="/admin/compliance">&larr; All documents</a></div>
<p class="muted">Public page: <a href="/<?= e($doc['doc_key']) ?>">/<?= e($doc['doc_key']) ?></a> ·
    <?= $live ? 'live version ' . e($live['version_no']) . ', published ' . e(local_time($live['published_at'])) : 'not published yet' ?>
    <?= $scheduled ? ' · <strong>version ' . e($work['version_no']) . ' is scheduled for ' . e(local_time($work['publish_at'])) . '</strong>' : '' ?></p>

<?php if ($blockers !== []) : ?><div class="panel panel-warn"><h2>Before this can go live</h2><ul><?php foreach ($blockers as $b) : ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if (($doc['flags'] ?? []) !== []) : ?>
<div class="panel<?= $reviewed ? '' : ' panel-warn' ?>"><h2>Legal review <span class="badge badge-d-<?= $reviewed ? 'confirmed' : 'cancelled' ?>"><?= $reviewed ? 'Review recorded for this text' : 'Requires Legal Review' ?></span></h2>
    <p>An Indian lawyer must review these points before this document is published. <strong>The system cannot judge whether the text is lawful</strong>: it only keeps your record that a lawyer reviewed this exact text.</p>
    <ul><?php foreach ($doc['flags'] as $fl) : ?><li><strong><?= e($fl['clause']) ?>.</strong> <?= e($fl['why']) ?></li><?php endforeach; ?></ul>
    <?php if ($work !== null && !$scheduled) : ?>
    <form method="post" action="<?= e($base) ?>/review" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="lr-n">Lawyer (name and firm)</label><input id="lr-n" name="reviewer" maxlength="160" required value="<?= e(old('reviewer')) ?>"></div>
        <div class="field"><label for="lr-d">Date of the review</label><input id="lr-d" name="reviewed_on" type="date" required value="<?= e(old('reviewed_on')) ?>"></div>
        <div class="field"><label for="lr-t">Note (optional, private)</label><input id="lr-t" name="note" maxlength="500" value="<?= e(old('note')) ?>"></div>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Record that this text was reviewed</button></div>
    </form>
    <?php endif; ?>
    <?php if ($reviews !== []) : ?><h3>Recorded reviews</h3><ul class="plain-list"><?php foreach ($reviews as $rv) : ?><li>Version <?= e((string) $rv['version_no']) ?> · <?= e(local_date((string) $rv['reviewed_on'])) ?> · <?= e($rv['reviewer']) ?><?= $rv['note'] ? ' · ' . e($rv['note']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($scheduled) : ?>
<div class="panel"><h2>Scheduled</h2><p>Version <?= e($work['version_no']) ?> goes live automatically at <?= e(local_time($work['publish_at'])) ?>. To edit it, cancel the schedule first.</p>
    <form method="post" action="<?= e($base) ?>/cancel-schedule"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Cancel the schedule</button></form></div>
<?php else : ?>
<div class="panel"><h2><?= $work ? 'Draft · version ' . e($work['version_no']) : 'New draft' ?></h2>
    <form method="post" action="<?= e($base) ?>/save" class="form-grid" novalidate id="legal-form"><?= csrf_field() ?>
        <div class="field"><label for="lt">Title</label><input id="lt" name="title" required maxlength="160" value="<?= e($f('title')) ?>"></div>
        <div class="field"><label for="lb">Text</label>
            <textarea id="lb" name="body_html" rows="24" data-rich-editor data-tokens='<?= e((string) json_encode(array_map(static fn ($t) => $t['label'], $tokens))) ?>'><?= e($f('body_html')) ?></textarea>
            <p class="hint">Use the toolbar (headings, lists, links). Insert business facts with the "Insert fact" list: they are filled in from Business identity when the page is shown, so a change there updates every document. Required sections cannot be removed; unsafe code is stripped when you save.</p></div>
        <div class="field"><label for="lcs">What changed? (shown to clients for material changes)</label><input id="lcs" name="change_summary" maxlength="500" value="<?= e($f('change_summary')) ?>"></div>
        <div class="field"><label for="led">Effective date</label><input id="led" name="effective_date" type="date" value="<?= e($f('effective_date')) ?>"><p class="hint">Empty = the day it is published.</p></div>
        <?php if ($doc['requires_acceptance']) : ?>
        <div class="field"><label class="check"><input type="checkbox" name="is_material" value="1"<?= $f('is_material') === '1' ? ' checked' : '' ?>> This is a <strong>material</strong> change: ask clients to review and accept it</label></div>
        <div class="field"><label for="lad">Days clients have to accept (empty = default)</label><input id="lad" name="acceptance_days" inputmode="numeric" value="<?= e($f('acceptance_days')) ?>"></div>
        <?php endif; ?>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save draft</button>
            <button class="btn btn-ghost" type="submit" formaction="<?= e($base) ?>/preview" formtarget="_blank" formnovalidate>Preview</button>
        </div>
        <fieldset class="field"><legend>Publish</legend>
            <div class="field"><label for="lpw">Your password (to publish or schedule)</label><input id="lpw" name="confirm_password" type="password" autocomplete="current-password"></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit" formaction="<?= e($base) ?>/publish" data-confirm="Publish this version on the public website now?">Publish now</button></div>
            <div class="field"><label for="lat">…or schedule it</label><input id="lat" name="publish_at" type="datetime-local"></div>
            <div class="form-actions"><button class="btn btn-ghost" type="submit" formaction="<?= e($base) ?>/schedule">Schedule publication</button></div>
        </fieldset>
    </form>
    <?php if ($work) : ?><form method="post" action="<?= e($base) ?>/discard" data-confirm="Discard this draft? The published version is not affected."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Discard draft</button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($live && $doc['unpublishable']) : ?>
<div class="panel"><h2>Take offline</h2><p class="muted">Visitors will see "being finalised". Mandatory documents cannot be taken offline.</p>
    <form method="post" action="<?= e($base) ?>/unpublish" class="form-grid" data-confirm="Take this document offline?"><?= csrf_field() ?>
        <div class="field"><label for="ur">Reason</label><input id="ur" name="reason" maxlength="300" required></div><div class="field"><label for="upw">Your password</label><input id="upw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-ghost" type="submit">Unpublish</button></div></form></div>
<?php endif; ?>

<?php if ($doc['flags'] !== []) : ?>
<div class="panel panel-warn"><h2>Needs review by an Indian lawyer</h2><ul><?php foreach ($doc['flags'] as $fl) : ?><li><strong><?= e($fl['clause']) ?>:</strong> <?= e($fl['why']) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="panel"><h2>Required sections</h2><p class="muted">Every version must keep these headings:</p><ul class="plain-list"><?php foreach ($doc['sections'] as $sec) : ?><li><?= e($sec['title']) ?></li><?php endforeach; ?></ul></div>

<div class="panel"><h2>Version history</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Version</th><th>Status</th><th>Effective</th><th>Published</th><th>By</th><th>Summary</th><th></th></tr></thead><tbody>
    <?php foreach ($versions as $v) : ?><tr><td>v<?= e($v['version_no']) ?></td><td><?= e(ucfirst((string) $v['status'])) ?><?= $v['is_material'] ? ' · material' : '' ?></td><td><?= $v['effective_date'] ? e(local_date((string) $v['effective_date'])) : '—' ?></td>
        <td><?= $v['published_at'] ? e(local_time($v['published_at'])) : ($v['publish_at'] ? 'scheduled ' . e(local_time($v['publish_at'])) : '—') ?></td><td><?= e($v['published_by_name'] ?? $v['created_by_name'] ?? '—') ?></td><td><?= e((string) $v['change_summary']) ?></td>
        <td><a href="<?= e($base) ?>/versions/<?= e($v['version_no']) ?>">View</a></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<script src="<?= e(asset('js/legal-editor.js')) ?>" defer></script>
