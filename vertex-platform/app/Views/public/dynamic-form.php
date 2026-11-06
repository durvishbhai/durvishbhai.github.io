<?php use Vertex\Core\View; ?>
<?php /** @var array<string,mixed> $form */ /** @var list<array<string,mixed>> $fields */ /** @var bool $closed */ /** @var ?string $token */ /** @var string $submitToken */ /** @var bool $guest */
$prev = \Vertex\Core\Session::get('_old', []); $prev = is_array($prev) && is_array($prev['a'] ?? null) ? $prev['a'] : [];
$val = static fn (string $k): string => isset($prev[$k]) && is_string($prev[$k]) ? $prev[$k] : '';
$has = static fn (string $k, string $o): bool => isset($prev[$k]) && ((is_array($prev[$k]) && in_array($o, $prev[$k], true)) || $prev[$k] === $o); ?>
<section class="section"><div class="container narrow">
    <p class="eyebrow">Form</p><h1><?= e($form['title']) ?></h1>
    <?php if ($form['description']) : ?><p class="prewrap"><?= e($form['description']) ?></p><?php endif; ?>
    <?php if ($closed) : ?><div class="alert alert-info" role="status"><p>This form is no longer accepting answers.</p></div>
    <?php else : ?>
    <div class="panel">
        <form method="post" action="/f/<?= e($form['slug']) ?><?= $token !== null ? '?k=' . e(rawurlencode($token)) : '' ?>" class="form-grid public-form" data-dynamic-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="submit_token" value="<?= e($submitToken) ?>">
            <div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
            <?php foreach ($fields as $f) : $k = $f['field_key']; $id = 'q_' . $k; $t = $f['field_type']; $req = (int) $f['required'] === 1;
                $attrs = ' data-key="' . e($k) . '"' . ($f['show_if_field'] !== null ? ' data-show-field="' . e($f['show_if_field']) . '" data-show-op="' . e($f['show_if_op']) . '" data-show-value="' . e((string) $f['show_if_value']) . '"' : ''); ?>
                <?php if ($t === 'heading') : ?><h2<?= $attrs ?>><?= e($f['label']) ?></h2>
                <?php elseif ($t === 'info') : ?><p class="muted prewrap"<?= $attrs ?>><?= e($f['label']) ?></p>
                <?php elseif ($t === 'checkbox') : ?><div class="field"<?= $attrs ?>><label class="check"><input id="<?= e($id) ?>" type="checkbox" name="a[<?= e($k) ?>]" value="1"<?= $has($k, '1') ? ' checked' : '' ?>> <span><?= e($f['label']) ?><?= $req ? ' <span class="muted">(required)</span>' : '' ?></span></label><?php if ($f['help_text']) : ?><small class="field-help"><?= e($f['help_text']) ?></small><?php endif; ?></div>
                <?php elseif (in_array($t, ['radio', 'multiselect'], true)) : ?><fieldset class="field"<?= $attrs ?>><legend><?= e($f['label']) ?><?= $req ? ' <span class="muted">(required)</span>' : '' ?></legend>
                    <?php foreach ($f['options'] as $n => $o) : ?><label class="check"><input type="<?= $t === 'radio' ? 'radio' : 'checkbox' ?>" name="a[<?= e($k) ?>]<?= $t === 'multiselect' ? '[]' : '' ?>" value="<?= e($o) ?>"<?= $has($k, $o) ? ' checked' : '' ?>> <?= e($o) ?></label><?php endforeach; ?>
                    <?php if ($f['help_text']) : ?><small class="field-help"><?= e($f['help_text']) ?></small><?php endif; ?></fieldset>
                <?php else : ?><div class="field"<?= $attrs ?>><label for="<?= e($id) ?>"><?= e($f['label']) ?><?= $req ? '' : ' <span class="muted">(optional)</span>' ?></label>
                    <?php if ($t === 'textarea') : ?><textarea id="<?= e($id) ?>" name="a[<?= e($k) ?>]" rows="5" maxlength="5000" placeholder="<?= e((string) $f['placeholder']) ?>"><?= e($val($k)) ?></textarea>
                    <?php elseif ($t === 'select') : ?><select id="<?= e($id) ?>" name="a[<?= e($k) ?>]"><option value="">Choose…</option><?php foreach ($f['options'] as $o) : ?><option value="<?= e($o) ?>"<?= $val($k) === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
                    <?php else : ?><input id="<?= e($id) ?>" name="a[<?= e($k) ?>]" type="<?= ['email' => 'email', 'phone' => 'tel', 'number' => 'text', 'date' => 'date'][$t] ?? 'text' ?>"<?= $t === 'number' ? ' inputmode="decimal"' : '' ?> maxlength="300" placeholder="<?= e((string) $f['placeholder']) ?>" value="<?= e($val($k)) ?>"><?php endif; ?>
                    <?php if ($f['help_text']) : ?><small class="field-help"><?= e($f['help_text']) ?></small><?php endif; ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ((int) $form['create_enquiry'] === 1) : ?><?= View::partial('public-form-consent') ?><?php endif; ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e($form['submit_label']) ?></button></div>
        </form>
    </div>
    <?php endif; ?>
</div></section>
