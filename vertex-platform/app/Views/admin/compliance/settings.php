<?php /** @var string $group */ /** @var array<string,array<string,string>> $fields */ /** @var array<string,string> $groups */ ?>
<div class="page-head"><div><p class="eyebrow">Compliance &amp; legal</p><h1><?= e($groups[$group]) ?></h1></div><a class="btn btn-ghost" href="/admin/compliance">&larr; Compliance &amp; legal</a></div>
<div class="panel">
    <form method="post" action="/admin/compliance/settings/<?= e($group) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <?php foreach ($fields as $k => $f) : $val = old($k, $f['value']); ?>
        <div class="field">
            <?php if ($f['type'] === 'bool') : ?><label class="check"><input type="checkbox" name="<?= e($k) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?>> <?= e($f['label']) ?></label>
            <?php else : ?><label for="f-<?= e($k) ?>"><?= e($f['label']) ?></label>
                <?php if ($f['type'] === 'textarea') : ?><textarea id="f-<?= e($k) ?>" name="<?= e($k) ?>" rows="4" maxlength="4000"><?= e($val) ?></textarea>
                <?php else : ?><input id="f-<?= e($k) ?>" name="<?= e($k) ?>" type="<?= $f['type'] === 'email' ? 'email' : ($f['type'] === 'date' ? 'date' : 'text') ?>"<?= $f['type'] === 'int' ? ' inputmode="numeric"' : '' ?> maxlength="300" value="<?= e($val) ?>"><?php endif; ?><?php endif; ?>
            <?php if ($f['help'] !== '') : ?><p class="hint"><?= e($f['help']) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
    <p class="muted small">Plain text only. Nothing secret belongs here: passwords and API keys are managed under Integrations &amp; settings.</p>
</div>
