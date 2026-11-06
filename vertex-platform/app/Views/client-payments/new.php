<?php /** @var list<array<string,mixed>> $projects */ /** @var array<string,string> $methods */ ?>
<div class="page-head"><div><p class="eyebrow">Finance</p><h1>Record a client payment</h1></div><a class="btn btn-ghost" href="/client-payments">&larr; Payments</a></div>
<div class="panel"><p class="muted">Record only money that has actually been received. It stays "awaiting verification" and counts for nothing until a different person verifies it. Recording a payment does not change any invoice.</p>
    <form method="post" action="/client-payments" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="pj">Project</label><select id="pj" name="project" required><option value="">Choose…</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= old('project') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code']) ?> · <?= e($p['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ro">This payment is the</label><select id="ro" name="role" required><?php foreach (['advance' => 'Advance', 'balance' => 'Balance / later payment', 'other' => 'Other'] as $c => $l) : ?><option value="<?= e($c) ?>"<?= old('role') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="g">Amount received, including tax (₹)</label><input id="g" name="gross" required inputmode="decimal" value="<?= e(old('gross')) ?>"></div>
        <div class="field"><label for="t">Of which tax (₹)</label><input id="t" name="tax" inputmode="decimal" value="<?= e(old('tax', '0')) ?>"></div>
        <div class="field"><label for="m">Method</label><select id="m" name="method" required><?php foreach ($methods as $c => $l) : ?><option value="<?= e($c) ?>"<?= old('method') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="x">Bank / gateway transaction reference</label><input id="x" name="external_ref" required maxlength="120" value="<?= e(old('external_ref')) ?>"></div>
        <div class="field"><label for="i">Invoice number (optional)</label><input id="i" name="invoice_ref" maxlength="120" value="<?= e(old('invoice_ref')) ?>"></div>
        <div class="field"><label for="d">Received on</label><input id="d" name="received_on" type="date" required value="<?= e(old('received_on')) ?>"></div>
        <div class="field"><label for="n">Notes (optional)</label><input id="n" name="notes" maxlength="500" value="<?= e(old('notes')) ?>"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Record payment</button></div></form></div>
