<?php /** @var ?array<string,mixed> $t */ /** @var string $text */ /** @var array<string,string> $services */ $u = $t === null ? '/project-templates' : '/project-templates/' . $t['public_id']; ?>
<p class="eyebrow"><a href="/project-templates">Project templates</a></p><h1><?= $t === null ? 'New template' : e($t['name']) ?></h1>
<form method="post" action="<?= e($u) ?>" class="panel form-grid" novalidate><?= csrf_field() ?>
    <div class="field"><label for="n">Name</label><input id="n" name="name" required maxlength="160" value="<?= e(old('name', (string) ($t['name'] ?? ''))) ?>"></div>
    <div class="field"><label for="s">Service</label><select id="s" name="service_category"><option value="">Any</option><?php foreach ($services as $k => $l) : ?><option value="<?= e($k) ?>"<?= old('service_category', (string) ($t['service_category'] ?? '')) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="d">Description</label><input id="d" name="description" maxlength="500" value="<?= e(old('description', (string) ($t['description'] ?? ''))) ?>"></div>
    <div class="field"><label for="i">Lines</label><textarea id="i" name="items" rows="16" required><?= e(old('items', $text)) ?></textarea>
        <small class="field-help">One per line. <code>M: Milestone title</code>, <code>T: Task title | +3 | 120 | high</code> (days after the project start, minutes, priority: all optional; the task goes in the milestone above), <code>C: Checklist point</code>.</small></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save template</button></div></form>
<?php if ($t !== null) : ?><form method="post" action="<?= e($u) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="retire"><button class="btn btn-ghost btn-sm" type="submit"><?= $t['status'] === 'active' ? 'Retire template' : 'Reactivate template' ?></button></form><?php endif; ?>
