<?php
/** @var array<string,mixed> $def block type definition */ /** @var array<string,mixed> $values current values */ /** @var list<array<string,mixed>> $media */ /** @var list<array<string,mixed>> $reusable */ /** @var string $prefix id prefix */
foreach ($def['fields'] as $name => $f) : $id = $prefix . '-' . $name; $v = old($name, (string) ($values[$name] ?? '')); ?>
    <div class="field"><label for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
    <?php if ($f['kind'] === 'textarea' || $f['kind'] === 'lines') : ?>
        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= $f['kind'] === 'lines' ? 6 : 5 ?>" maxlength="<?= (int) ($f['max'] ?? 2000) ?>"<?= !empty($f['required']) ? ' required' : '' ?>><?= e($v) ?></textarea>
    <?php elseif ($f['kind'] === 'media') : ?>
        <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= !empty($f['required']) ? ' required' : '' ?>><option value="">— none —</option><?php foreach ($media as $m) : ?><option value="<?= e($m['public_id']) ?>"<?= $v === $m['public_id'] ? ' selected' : '' ?>><?= e($m['original_name']) ?><?= $m['alt_text'] ? ' — ' . e($m['alt_text']) : '' ?></option><?php endforeach; ?></select>
        <?php if ($media === []) : ?><p class="hint">No pictures yet: <a href="/admin/cms/media">upload one first</a>.</p><?php endif; ?>
    <?php elseif ($f['kind'] === 'reusable') : ?>
        <select id="<?= e($id) ?>" name="<?= e($name) ?>" required><option value="">— choose —</option><?php foreach ($reusable as $b) : ?><option value="<?= e($b['public_id']) ?>"<?= $v === $b['public_id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <?php if ($reusable === []) : ?><p class="hint">No reusable blocks yet: <a href="/admin/cms/reusable">create one</a>.</p><?php endif; ?>
    <?php else : ?>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="text" maxlength="<?= (int) ($f['max'] ?? 300) ?>" value="<?= e($v) ?>"<?= !empty($f['required']) ? ' required' : '' ?>>
    <?php endif; ?>
    <?php if (!empty($f['help'])) : ?><p class="hint"><?= e($f['help']) ?></p><?php endif; ?></div>
<?php endforeach; ?>
