<?php /** @var list<array<string,mixed>> $clients */ /** @var list<array<string,mixed>> $projects */ /** @var list<array<string,mixed>> $items */ $v = static fn (string $k, string $d = ''): string => old($k, $d); ?>
<div class="page-head"><div><p class="eyebrow">Billing</p><h1>New invoice</h1></div><a class="btn btn-ghost" href="/invoices">&larr; Invoices</a></div>
<div class="panel"><p class="muted">No tax is added: the business is not GST-registered and invoices state "<?= e((string) \Vertex\Services\BusinessProfile::get()['tax_statement']) ?>". The invoice gets its number when you issue it.</p>
    <form method="post" action="/invoices" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="c">Client</label><select id="c" name="client" required><option value="">Choose…</option><?php foreach ($clients as $c) : ?><option value="<?= e($c['public_id']) ?>"<?= $v('client') === $c['public_id'] ? ' selected' : '' ?>><?= e($c['company_name'] ?: $c['contact_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="p">Project (optional: needed for referral commission)</label><select id="p" name="project"><option value="">None</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $v('project') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code']) ?> · <?= e($p['title']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="t">Title</label><input id="t" name="title" required maxlength="200" value="<?= e($v('title')) ?>"></div>
    <div class="field"><label for="d">Due date (default: 14 days after issue)</label><input id="d" name="due_date" type="date" value="<?= e($v('due_date')) ?>"></div>
    <?= \Vertex\Core\View::partial('billing-items', ['items' => $items]) ?>
    <div class="field"><label for="di">Discount (₹)</label><input id="di" name="discount" inputmode="decimal" value="<?= e($v('discount', '0')) ?>"></div>
    <div class="field"><label for="cn">Notes for the client</label><textarea id="cn" name="client_notes" rows="3" maxlength="3000"><?= e($v('client_notes')) ?></textarea></div>
    <div class="field"><label for="in">Internal notes (staff only)</label><textarea id="in" name="internal_notes" rows="2" maxlength="3000"><?= e($v('internal_notes')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save draft</button></div></form></div>
