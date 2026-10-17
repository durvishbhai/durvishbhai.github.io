<?php
/** @var string $limit */ /** @var string $types */ /** @var bool $withMeta */
/** @var list<array<string,mixed>> $tasks */ /** @var list<array<string,mixed>> $milestones */
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<?php if (!empty($withMeta)) : ?>
    <div class="field"><label for="dt">Title</label><input id="dt" name="title" required maxlength="200" value="<?= e($v('title')) ?>"></div>
    <div class="field"><label for="dd">Description</label><textarea id="dd" name="description" rows="3" maxlength="5000"><?= e($v('description')) ?></textarea></div>
    <div class="field"><label for="dc">Category</label><select id="dc" name="category"><?php foreach (config('workflow.deliverable_categories') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('category', 'other') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if (($tasks ?? []) !== []) : ?><div class="field"><label for="dtask">Related task (optional)</label><select id="dtask" name="task"><option value="">None</option><?php foreach ($tasks as $t) : ?><option value="<?= e($t['public_id']) ?>"<?= $v('task') === $t['public_id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if (($milestones ?? []) !== []) : ?><div class="field"><label for="dms">Related milestone (optional)</label><select id="dms" name="milestone"><option value="">None</option><?php foreach ($milestones as $m) : ?><option value="<?= e($m['public_id']) ?>"<?= $v('milestone') === $m['public_id'] ? ' selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php endif; ?>
<div class="field"><label for="dfile">File</label><input id="dfile" type="file" name="file">
    <p class="hint">Up to <?= e($limit) ?>. Allowed: <?= e($types) ?>. Files are stored privately and are never visible to the client until you publish them.</p></div>
<div class="field"><label for="dlink">…or a link to an external resource</label><input id="dlink" name="external_url" type="url" maxlength="500" placeholder="https://" value="<?= e($v('external_url')) ?>">
    <p class="hint">An https:// address only. Use either a file or a link.</p></div>
<div class="field"><label for="dlabel">Version label (optional)</label><input id="dlabel" name="label" maxlength="30" placeholder="e.g. Final" value="<?= e($v('label')) ?>"></div>
<div class="field"><label for="dnotes">Change notes (internal)</label><textarea id="dnotes" name="change_notes" rows="2" maxlength="3000"><?= e($v('change_notes')) ?></textarea></div>
