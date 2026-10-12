<?php /** @var array<string,mixed> $p */ $v = static fn (string $k): string => old($k, (string) ($p[$k] ?? '')); ?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Business details</h1></div><a class="btn btn-ghost" href="/admin/payments">Payments (Google Pay)</a></div>
<div class="panel"><p class="muted">Printed on every quotation and invoice. A copy is frozen into each invoice when it is issued, so later edits never change an issued invoice. Invoices cannot be issued until the business name is set.</p>
    <form method="post" action="/admin/business" class="form-grid"><?= csrf_field() ?>
    <div class="field"><label for="tn">Business name</label><input id="tn" name="trade_name" required maxlength="160" value="<?= e($v('trade_name')) ?>"></div>
    <div class="field"><label for="ln">Legal name (optional)</label><input id="ln" name="legal_name" maxlength="160" value="<?= e($v('legal_name')) ?>"></div>
    <div class="field"><label for="ad">Address</label><textarea id="ad" name="address" rows="3" maxlength="1000"><?= e($v('address')) ?></textarea></div>
    <div class="field"><label for="em">Email</label><input id="em" name="email" type="email" maxlength="190" value="<?= e($v('email')) ?>"></div><div class="field"><label for="ph">Phone</label><input id="ph" name="phone" maxlength="40" value="<?= e($v('phone')) ?>"></div>
    <div class="field"><label for="we">Website</label><input id="we" name="website" maxlength="190" placeholder="https://" value="<?= e($v('website')) ?>"></div>
    <div class="field"><label for="tx">Tax statement printed on documents</label><input id="tx" name="tax_statement" required maxlength="255" value="<?= e($v('tax_statement')) ?>"><p class="hint">Not GST-registered: "Tax is not applicable." No tax lines are ever added.</p></div>
    <div class="field"><label for="pi">Payment instructions (bank details, UPI ID, how to pay)</label><textarea id="pi" name="payment_instructions" rows="4" maxlength="2000"><?= e($v('payment_instructions')) ?></textarea></div>
    <div class="field"><label for="ft">Footer line</label><input id="ft" name="invoice_footer" maxlength="500" value="<?= e($v('invoice_footer')) ?>"></div>
    <div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div></form></div>
