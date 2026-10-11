<?php /** @var list<array<string,mixed>> $clients */
$v = static fn (string $k): string => old($k); ?>
<p class="eyebrow">Projects</p><h1>New project</h1>
<p class="muted">Creates a <strong>Draft</strong> project. If this work came from an enquiry, convert the Won enquiry instead so the history stays linked.</p>
<div class="panel"><form method="post" action="/projects" class="form-grid" novalidate><?= csrf_field() ?>
    <div class="field"><label for="client">Client</label><select id="client" name="client" required><option value="">Choose a client…</option><?php foreach ($clients as $c) : ?><option value="<?= e($c['public_id']) ?>"<?= $v('client') === $c['public_id'] ? ' selected' : '' ?>><?= e($c['company_name'] ? $c['company_name'] . ' — ' . $c['contact_name'] : $c['contact_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="title">Project name</label><input id="title" name="title" required maxlength="200" value="<?= e($v('title')) ?>"></div>
    <div class="field"><label for="service_category">Service category</label><select id="service_category" name="service_category"><option value="">—</option><?php foreach (config('workflow.services') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('service_category') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="description">Description</label><textarea id="description" name="description" rows="4" maxlength="5000"><?= e($v('description')) ?></textarea></div>
    <div class="field"><label for="scope">Scope</label><textarea id="scope" name="scope" rows="4" maxlength="10000"><?= e($v('scope')) ?></textarea></div>
    <div class="field"><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" value="<?= e($v('start_date')) ?>"></div>
    <div class="field"><label for="target_date">Target delivery date</label><input id="target_date" name="target_date" type="date" value="<?= e($v('target_date')) ?>"></div>
    <div class="field"><label for="priority">Project priority <span class="muted">(affects pricing; separate from task priority)</span></label><select id="priority" name="priority"><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= ($v('priority') ?: 'normal') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="budget">Budget estimate (₹, optional)</label><input id="budget" name="budget" inputmode="decimal" placeholder="e.g. 50000" value="<?= e($v('budget')) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create draft project</button> <a class="btn btn-ghost" href="/projects">Cancel</a></div>
</form></div>
