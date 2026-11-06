<?php /** @var list<array<string,mixed>> $rows */ /** @var ?array<string,mixed> $editing */ /** @var array<string,array<string,mixed>> $types */ /** @var string $newType */ /** @var list<array<string,mixed>> $media */ /** @var bool $canEdit */
$type = $editing['block_type'] ?? (isset($types[$newType]) ? $newType : ''); ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Reusable blocks</h1></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">Write a block once (a call to action, an address box, a banner) and place it on many pages with the "Reusable block" type. Editing it here changes every page that uses it.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Type</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $b) : ?><tr><td><?= e($b['name']) ?></td><td><?= e($types[$b['block_type']]['label'] ?? $b['block_type']) ?></td><td><?= e(local_time($b['updated_at'])) ?></td><td><a class="btn btn-ghost btn-sm" href="/admin/cms/reusable/<?= e($b['public_id']) ?>">Edit</a></td></tr><?php endforeach; ?>
<?php if ($rows === []) : ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canEdit) : ?><div class="panel"><h2><?= $editing ? 'Edit: ' . e($editing['name']) : 'New reusable block' ?></h2>
<?php if ($type === '') : ?><form method="get" action="/admin/cms/reusable" class="toolbar"><div class="field"><label for="rb-type">Type</label><select id="rb-type" name="type"><?php foreach ($types as $k => $t) : ?><option value="<?= e($k) ?>"><?= e($t['label']) ?></option><?php endforeach; ?></select></div><div class="field"><button class="btn btn-ghost" type="submit">Choose</button></div></form>
<?php else : ?><form method="post" action="/admin/cms/reusable<?= $editing ? '/' . e($editing['public_id']) : '' ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="block_type" value="<?= e($type) ?>">
    <div class="field"><label for="rb-name">Name (only you see it)</label><input id="rb-name" name="name" required minlength="3" maxlength="120" value="<?= e(old('name', (string) ($editing['name'] ?? ''))) ?>"></div>
    <?= \Vertex\Core\View::partial('cms-block-fields', ['def' => $types[$type], 'values' => $editing['data'] ?? [], 'media' => $media, 'reusable' => [], 'prefix' => 'rb']) ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button><?php if ($editing) : ?> <button class="btn btn-ghost" name="action" value="delete" type="submit">Delete</button><?php endif; ?></div></form><?php endif; ?></div><?php endif; ?>
