<?php /** @var array<string,mixed> $p */ /** @var list<array<string,mixed>> $blocks */ /** @var array<string,array<string,mixed>> $types */ /** @var list<array<string,mixed>> $media */ /** @var list<array<string,mixed>> $reusable */ /** @var bool $canEdit */ /** @var bool $canPublish */ /** @var ?int $editIndex */ /** @var string $addType */
$ogMedia = ''; foreach ($media as $m) { if ((int) $m['id'] === (int) $p['og_media_id']) { $ogMedia = $m['public_id']; } } ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1><?= e($p['title']) ?> <span class="badge badge-sys-<?= $p['status'] === 'published' ? 'ok' : 'warn' ?>"><?= e($p['status']) ?></span></h1></div>
    <div><a class="btn btn-ghost" href="/admin/cms/<?= e($p['public_id']) ?>/preview" target="_blank" rel="noopener">Preview</a> <a class="btn btn-ghost" href="/admin/cms/<?= e($p['public_id']) ?>/versions">History (v<?= (int) $p['current_version'] ?>)</a> <a class="btn btn-ghost" href="/admin/cms">All pages</a></div></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<?php if ($p['status'] === 'published' && !$canPublish) : ?><div class="alert alert-error" role="status"><p>This page is live. Changing it needs publish rights.</p></div><?php endif; ?>

<?php if ($canPublish) : ?><div class="panel"><h2>Publishing</h2>
    <form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/status" class="inline-form"><?= csrf_field() ?>
        <?php if ($p['status'] !== 'published') : ?><button class="btn btn-primary" name="action" value="publish" type="submit">Publish now</button><?php endif; ?></form>
    <?php if ($p['status'] === 'published' || $p['status'] === 'scheduled') : ?><form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/status" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><input name="confirm_password" type="password" required placeholder="Your password" aria-label="Your password" autocomplete="current-password"><button class="btn btn-ghost" type="submit">Unpublish (back to draft)</button></form><?php endif; ?>
    <form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/status" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="schedule"><label for="sch">Publish automatically at</label><input id="sch" name="publish_at" type="datetime-local" required><button class="btn btn-ghost" type="submit">Schedule</button></form>
    <?php if ($p['status'] === 'scheduled' && $p['publish_at']) : ?><p class="muted">Scheduled for <?= e(local_time($p['publish_at'])) ?>.</p><?php endif; ?>
    <?php if ($p['status'] !== 'archived') : ?><form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/status" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input name="confirm_password" type="password" required placeholder="Your password" aria-label="Your password" autocomplete="current-password"><button class="btn btn-ghost" type="submit">Archive</button></form><?php endif; ?></div><?php endif; ?>

<div class="panel"><h2>Details and search settings</h2><form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/details" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="d-title">Page title</label><input id="d-title" name="title" required maxlength="200" value="<?= e(old('title', (string) $p['title'])) ?>"></div>
    <div class="field"><label for="d-slug">Address</label><input id="d-slug" name="slug" required maxlength="119" value="<?= e(old('slug', (string) $p['slug'])) ?>"><p class="hint">Changing the address of a live page leaves a redirect from the old one.</p></div>
    <div class="field"><label for="d-st">Search-engine title</label><input id="d-st" name="seo_title" maxlength="160" value="<?= e(old('seo_title', (string) $p['seo_title'])) ?>"></div>
    <div class="field"><label for="d-sd">Search-engine description</label><textarea id="d-sd" name="seo_description" rows="3" maxlength="320"><?= e(old('seo_description', (string) $p['seo_description'])) ?></textarea></div>
    <div class="field"><label for="d-og">Sharing picture</label><select id="d-og" name="og_image"><option value="">— none —</option><?php foreach ($media as $m) : ?><option value="<?= e($m['public_id']) ?>"<?= old('og_image', $ogMedia) === $m['public_id'] ? ' selected' : '' ?>><?= e($m['original_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="d-cn">Canonical address (optional)</label><input id="d-cn" name="canonical_url" maxlength="255" placeholder="https://" value="<?= e(old('canonical_url', (string) $p['canonical_url'])) ?>"></div>
    <div class="field"><label class="check"><input type="checkbox" name="noindex" value="1"<?= old('noindex', $p['noindex'] ? '1' : '') === '1' ? ' checked' : '' ?>> Ask search engines not to list this page</label></div>
    <?php if ($canEdit) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save details</button></div><?php endif; ?></form></div>

<div class="panel"><h2>Content blocks</h2>
<?php foreach ($blocks as $i => $b) : $def = $types[$b['type']] ?? null; ?>
    <div class="cms-edit-block" id="block-<?= $i ?>"><div class="toolbar"><strong><?= $i + 1 ?>.</strong> <?= e(\Vertex\Services\CmsBlocks::summary($b)) ?>
        <?php if ($canEdit) : ?><form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/blocks/<?= $i ?>/action" class="inline-form"><?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" name="action" value="up" type="submit"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Move up">↑</button><button class="btn btn-ghost btn-sm" name="action" value="down" type="submit"<?= $i === count($blocks) - 1 ? ' disabled' : '' ?> aria-label="Move down">↓</button>
            <button class="btn btn-ghost btn-sm" name="action" value="delete" type="submit">Remove</button> <a class="btn btn-ghost btn-sm" href="?block=<?= $i ?>#block-<?= $i ?>">Edit</a></form><?php endif; ?></div>
    <?php if ($canEdit && $editIndex === $i && $def !== null) : ?><form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/blocks/<?= $i ?>" class="form-grid"><?= csrf_field() ?>
        <?= \Vertex\Core\View::partial('cms-block-fields', ['def' => $def, 'values' => $b, 'media' => $media, 'reusable' => $reusable, 'prefix' => 'b' . $i]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save block</button></div></form><?php endif; ?></div>
<?php endforeach; ?>
<?php if ($blocks === []) : ?><p class="muted">No blocks yet. Add the first one below.</p><?php endif; ?></div>

<?php if ($canEdit) : ?><div class="panel" id="add-block"><h2>Add a block</h2>
    <form method="get" action="/admin/cms/<?= e($p['public_id']) ?>#add-block" class="toolbar"><div class="field"><label for="ab-type">Type</label><select id="ab-type" name="add"><?php foreach ($types as $k => $t) : ?><option value="<?= e($k) ?>"<?= $addType === $k ? ' selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select></div><div class="field"><button class="btn btn-ghost" type="submit">Choose</button></div></form>
    <?php if (isset($types[$addType])) : ?><p class="muted"><?= e($types[$addType]['help']) ?></p>
    <form method="post" action="/admin/cms/<?= e($p['public_id']) ?>/blocks" class="form-grid"><?= csrf_field() ?><input type="hidden" name="type" value="<?= e($addType) ?>">
        <?= \Vertex\Core\View::partial('cms-block-fields', ['def' => $types[$addType], 'values' => [], 'media' => $media, 'reusable' => $reusable, 'prefix' => 'new']) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Add block</button></div></form><?php endif; ?></div><?php endif; ?>
