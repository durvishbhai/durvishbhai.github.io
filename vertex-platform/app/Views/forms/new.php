<?php /** @var array<string,array<string,mixed>> $templates */ /** @var array<string,string> $access */ ?>
<p class="eyebrow"><a href="/forms">Forms</a></p><h1>New form</h1>
<form method="post" action="/forms" class="panel form-grid" novalidate><?= csrf_field() ?>
    <div class="field"><label for="template">Start from</label><select id="template" name="template"><option value="">A blank form</option><?php foreach ($templates as $k => $t) : ?><option value="<?= e($k) ?>"<?= old('template') === $k ? ' selected' : '' ?>><?= e($t['title']) ?>: <?= e($t['description']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="title">Title</label><input id="title" name="title" maxlength="160" value="<?= e(old('title')) ?>"><small class="field-help">Leave empty with a template to use the template's title.</small></div>
    <div class="field"><label for="slug">Web address <span class="muted">(/f/...)</span></label><input id="slug" name="slug" maxlength="60" value="<?= e(old('slug')) ?>"><small class="field-help">Letters, digits and dashes. Empty = made from the title.</small></div>
    <div class="field"><label for="access">Who can fill it in</label><select id="access" name="access"><?php foreach ($access as $k => $l) : ?><option value="<?= e($k) ?>"<?= old('access', 'public') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="description">Introduction <span class="muted">(optional)</span></label><textarea id="description" name="description" rows="3" maxlength="2000"><?= e(old('description')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create form</button></div></form>
