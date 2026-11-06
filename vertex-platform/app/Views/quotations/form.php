<?php /** @var ?array<string,mixed> $q */ /** @var list<array<string,mixed>> $items */ /** @var list<array<string,mixed>> $clients */ /** @var list<array<string,mixed>> $projects */ $v = static fn (string $k, string $d = ''): string => old($k, $d); ?>
<div class="page-head"><div><p class="eyebrow">Billing</p><h1>New quotation</h1></div><a class="btn btn-ghost" href="/quotations">&larr; Quotations</a></div>
<div class="panel"><form method="post" action="/quotations" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="c">Client</label><select id="c" name="client" required><option value="">Choose…</option><?php foreach ($clients as $c) : ?><option value="<?= e($c['public_id']) ?>"<?= $v('client') === $c['public_id'] ? ' selected' : '' ?>><?= e($c['company_name'] ?: $c['contact_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="p">Project (optional)</label><select id="p" name="project"><option value="">None</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $v('project') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code']) ?> · <?= e($p['title']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="t">Title</label><input id="t" name="title" required maxlength="200" value="<?= e($v('title')) ?>"></div>
    <div class="field"><label for="vu">Valid until (optional)</label><input id="vu" name="valid_until" type="date" value="<?= e($v('valid_until')) ?>"></div>
    <?= \Vertex\Core\View::partial('billing-items', ['items' => $items]) ?>
    <div class="field"><label for="cn">Notes for the client (terms, scope)</label><textarea id="cn" name="client_notes" rows="3" maxlength="3000"><?= e($v('client_notes')) ?></textarea></div>
    <div class="field"><label for="in">Internal notes (staff only)</label><textarea id="in" name="internal_notes" rows="2" maxlength="3000"><?= e($v('internal_notes')) ?></textarea></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save draft</button></div></form></div>
