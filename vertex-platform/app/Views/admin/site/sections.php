<?php /** @var string $page */ /** @var array<string,mixed> $def */ /** @var array<string,array{enabled:bool,position:int,data:array<string,string>}> $rows */ /** @var list<array<string,mixed>> $media */ /** @var bool $canEdit */ /** @var bool $canPublish */
$n = 0; ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1><?= e($def['label']) ?></h1></div><a class="btn btn-ghost" href="<?= e($def['path']) ?>" target="_blank" rel="noopener">View page</a></div>
<?= \Vertex\Core\View::partial('cms-nav') ?>
<p class="muted">Use <code>{company}</code> in your text to show the company name. Paragraphs, lists, **bold** and [links](https://…) work in the longer text boxes. Button addresses may be a page like <code>/contact</code> or a full <code>https://</code> address.</p>
<form method="post" action="/admin/site/sections/<?= e($page) ?>" class="form-grid"><?= csrf_field() ?>
<?php foreach ($rows as $key => $row) : $s = $def['sections'][$key]; $n++; ?>
    <div class="panel"><h2><?= e($s['label']) ?></h2>
        <div class="toolbar"><label class="check"><input type="checkbox" name="s[<?= e($key) ?>][enabled]" value="1"<?= $row['enabled'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> Show this section</label>
            <label class="inline-label">Order <input type="number" min="1" max="999" name="s[<?= e($key) ?>][position]" value="<?= (int) $row['position'] ?>" class="narrow-input"<?= $canEdit ? '' : ' disabled' ?>></label></div>
        <?php foreach ($s['fields'] as $name => $f) : $id = 's-' . $key . '-' . $name; $v = $row['data'][$name] ?? ''; ?>
            <div class="field"><label for="<?= e($id) ?>"><?= e($f['label']) ?></label>
            <?php if ($f['kind'] === 'textarea' || $f['kind'] === 'lines') : ?><textarea id="<?= e($id) ?>" name="s[<?= e($key) ?>][f][<?= e($name) ?>]" rows="<?= $f['kind'] === 'lines' ? 6 : 4 ?>" maxlength="<?= (int) $f['max'] ?>"<?= $canEdit ? '' : ' disabled' ?>><?= e($v) ?></textarea>
            <?php elseif ($f['kind'] === 'media') : ?><select id="<?= e($id) ?>" name="s[<?= e($key) ?>][f][<?= e($name) ?>]"<?= $canEdit ? '' : ' disabled' ?>><option value="">— none —</option><?php foreach ($media as $m) : ?><option value="<?= e($m['public_id']) ?>"<?= $v === $m['public_id'] ? ' selected' : '' ?>><?= e($m['original_name']) ?></option><?php endforeach; ?></select>
            <?php else : ?><input id="<?= e($id) ?>" name="s[<?= e($key) ?>][f][<?= e($name) ?>]" maxlength="<?= (int) $f['max'] ?>" value="<?= e($v) ?>"<?= $canEdit ? '' : ' disabled' ?>><?php endif; ?>
            <?php if (!empty($f['help'])) : ?><p class="hint"><?= e($f['help']) ?></p><?php endif; ?></div>
        <?php endforeach; ?></div>
<?php endforeach; ?>
<?php if ($canEdit) : ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div><?php endif; ?></form>
<?php if ($canPublish) : ?><div class="panel"><h2>Start again</h2><p class="muted">Puts the original wording back for this page (your changes here are discarded).</p>
    <form method="post" action="/admin/site/sections/<?= e($page) ?>/reset" class="inline-form"><?= csrf_field() ?><input name="confirm_password" type="password" required placeholder="Your password" aria-label="Your password" autocomplete="current-password"><button class="btn btn-ghost" type="submit">Use the original wording</button></form></div><?php endif; ?>
