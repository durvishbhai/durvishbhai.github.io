<?php /** @var array<string,mixed> $f */ /** @var list<array<string,mixed>> $fields */ /** @var bool $canManage */ /** @var array<string,string> $types */ /** @var array<string,string> $access */ /** @var array<string,string> $enquiryFields */ /** @var int $new */
$u = '/forms/' . $f['public_id']; $st = ['draft' => 'Draft (not live)', 'published' => 'Live', 'closed' => 'Closed'];
$link = rtrim((string) config('app.url'), '/') . '/f/' . $f['slug'] . ($f['access'] === 'private_link' ? '?k=' . $f['access_token'] : '');
$fieldForm = static function (?array $x, array $types, array $fields, array $enquiryFields): string {
    ob_start(); ?>
    <div class="field"><label>Question text</label><input name="label" required maxlength="200" value="<?= e($x['label'] ?? '') ?>"></div>
    <div class="field"><label>Type</label><select name="type"><?php foreach ($types as $k => $l) : ?><option value="<?= e($k) ?>"<?= ($x['field_type'] ?? 'text') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check"><input type="checkbox" name="required" value="1"<?= !empty($x['required']) ? ' checked' : '' ?>> Required</label></div>
    <div class="field"><label>Choices <span class="muted">(for lists and buttons: one per line)</span></label><textarea name="options" rows="3"><?= e(implode("\n", $x['options'] ?? [])) ?></textarea></div>
    <div class="field"><label>Hint under the question <span class="muted">(optional)</span></label><input name="help" maxlength="255" value="<?= e($x['help_text'] ?? '') ?>"></div>
    <div class="field"><label>Placeholder <span class="muted">(optional)</span></label><input name="placeholder" maxlength="120" value="<?= e($x['placeholder'] ?? '') ?>"></div>
    <div class="field"><label>Minimum <span class="muted">(length, number or choices)</span></label><input name="min" inputmode="numeric" value="<?= e((string) ($x['min_value'] ?? '')) ?>"></div>
    <div class="field"><label>Maximum</label><input name="max" inputmode="numeric" value="<?= e((string) ($x['max_value'] ?? '')) ?>"></div>
    <div class="field"><label>Show only if the answer to</label><select name="show_if_field"><option value="">(always show)</option><?php foreach ($fields as $o) : if (($x['field_key'] ?? '') === $o['field_key']) { continue; } ?><option value="<?= e($o['field_key']) ?>"<?= ($x['show_if_field'] ?? '') === $o['field_key'] ? ' selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>…</label><select name="show_if_op"><?php foreach (['equals' => 'is', 'not_equals' => 'is not', 'contains' => 'contains', 'filled' => 'is filled in'] as $k => $l) : ?><option value="<?= e($k) ?>"<?= ($x['show_if_op'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>…this value</label><input name="show_if_value" maxlength="190" value="<?= e($x['show_if_value'] ?? '') ?>"></div>
    <div class="field"><label>Copy into a CRM lead field <span class="muted">(optional)</span></label><select name="maps_to"><option value="">No</option><?php foreach ($enquiryFields as $k => $l) : ?><option value="<?= e($k) ?>"<?= ($x['maps_to'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php return (string) ob_get_clean();
}; ?>
<div class="page-head"><div><p class="eyebrow"><a href="/forms">Forms</a></p><h1><?= e($f['title']) ?></h1><p class="muted"><?= e($st[$f['status']] ?? $f['status']) ?> &middot; <a href="/forms/<?= e($f['public_id']) ?>/submissions">Answers<?= $new > 0 ? ' (' . (int) $new . ' new)' : '' ?></a></p></div>
    <?php if ($canManage) : ?><div class="actions">
        <?php if ($f['status'] !== 'published') : ?><form method="post" action="<?= e($u) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><button class="btn btn-primary" type="submit">Publish</button></form><?php endif; ?>
        <?php if ($f['status'] === 'published') : ?><form method="post" action="<?= e($u) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="close"><button class="btn btn-ghost" type="submit">Close</button></form><?php endif; ?>
        <?php if ($f['status'] !== 'draft') : ?><form method="post" action="<?= e($u) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="draft"><button class="btn btn-ghost" type="submit">Unpublish</button></form><?php endif; ?></div><?php endif; ?></div>
<div class="panel"><p><strong>Address:</strong> <code><?= e($link) ?></code></p><?php if ($f['access'] === 'private_link') : ?><p class="muted">Only people who have this full link (with the key) can open the form.</p><?php endif; ?></div>

<div class="panel"><h2>Questions</h2>
<?php foreach ($fields as $i => $x) : ?>
    <details class="field-block"><summary><strong><?= e($x['label']) ?></strong> <span class="muted"><?= e($types[$x['field_type']] ?? $x['field_type']) ?><?= (int) $x['required'] === 1 ? ' &middot; required' : '' ?><?= $x['show_if_field'] ? ' &middot; conditional' : '' ?><?= $x['maps_to'] ? ' &middot; &rarr; ' . e($enquiryFields[$x['maps_to']] ?? $x['maps_to']) : '' ?></span></summary>
    <?php if ($canManage) : ?>
        <form method="post" action="<?= e($u) ?>/fields/<?= (int) $x['id'] ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save">
            <?= $fieldForm($x, $types, $fields, $enquiryFields) ?>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save question</button></div></form>
        <div class="field-row-actions">
            <form method="post" action="<?= e($u) ?>/fields/<?= (int) $x['id'] ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="up"><button class="btn btn-ghost btn-sm" type="submit"<?= $i === 0 ? ' disabled' : '' ?>>Move up</button></form>
            <form method="post" action="<?= e($u) ?>/fields/<?= (int) $x['id'] ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="down"><button class="btn btn-ghost btn-sm" type="submit"<?= $i === count($fields) - 1 ? ' disabled' : '' ?>>Move down</button></form>
            <form method="post" action="<?= e($u) ?>/fields/<?= (int) $x['id'] ?>" class="inline-form" data-confirm="Remove this question? Answers already received keep it."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-sm" type="submit">Remove</button></form></div>
    <?php endif; ?></details>
<?php endforeach; ?>
<?php if ($fields === []) : ?><p class="muted">No questions yet.</p><?php endif; ?>
<?php if ($canManage) : ?><h3>Add a question</h3><form method="post" action="<?= e($u) ?>/fields" class="form-grid"><?= csrf_field() ?><?= $fieldForm(null, $types, $fields, $enquiryFields) ?><div class="form-actions"><button class="btn btn-primary" type="submit">Add question</button></div></form><?php endif; ?></div>

<?php if ($canManage) : ?><div class="panel"><h2>Settings</h2><form method="post" action="<?= e($u) ?>" class="form-grid" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="field"><label for="t">Title</label><input id="t" name="title" required maxlength="160" value="<?= e(old('title', $f['title'])) ?>"></div>
    <div class="field"><label for="d">Introduction</label><textarea id="d" name="description" rows="3" maxlength="2000"><?= e(old('description', (string) $f['description'])) ?></textarea></div>
    <div class="field"><label for="a">Who can fill it in</label><select id="a" name="access"><?php foreach ($access as $k => $l) : ?><option value="<?= e($k) ?>"<?= old('access', $f['access']) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="s">Thank-you message</label><input id="s" name="success_message" maxlength="500" value="<?= e(old('success_message', (string) $f['success_message'])) ?>"></div>
    <div class="field"><label for="b">Button text</label><input id="b" name="submit_label" maxlength="40" value="<?= e(old('submit_label', (string) $f['submit_label'])) ?>"></div>
    <div class="field"><label for="m">Stop after this many answers <span class="muted">(optional)</span></label><input id="m" name="max_submissions" inputmode="numeric" value="<?= e(old('max_submissions', (string) ($f['max_submissions'] ?? ''))) ?>"></div>
    <div class="field"><label for="c">Closing date <span class="muted">(optional)</span></label><input id="c" name="closes_on" type="date" value="<?= e(old('closes_on', (string) ($f['closes_on'] ?? ''))) ?>"></div>
    <div class="field"><label class="check"><input type="checkbox" name="create_enquiry" value="1"<?= (int) $f['create_enquiry'] === 1 ? ' checked' : '' ?>> Also create a CRM lead from each answer (needs questions copied into "Contact name" and "Email")</label></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save settings</button></div></form>
    <?php if ($f['access'] === 'private_link') : ?><form method="post" action="<?= e($u) ?>" class="inline-form" data-confirm="Create a new secret link? The old link stops working."><?= csrf_field() ?><input type="hidden" name="action" value="rotate"><input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-ghost btn-sm" type="submit">New secret link</button></form><?php endif; ?>
    <form method="post" action="<?= e($u) ?>" class="inline-form" data-confirm="Archive this form? It stops working. Its answers are kept."><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-danger btn-sm" type="submit">Archive form</button></form></div><?php endif; ?>
