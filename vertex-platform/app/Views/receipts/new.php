<?php /** @var list<array<string,mixed>> $clients */ /** @var array<string,string> $methods */ /** @var string $today */ /** @var string $now */ ?>
<div class="page-head"><div><p class="eyebrow">Money</p><h1>Issue a receipt</h1></div><a class="btn btn-ghost" href="/receipts">&larr; Receipts</a></div>
<div class="alert alert-warn" role="note"><p>Use this only for a payment that was <strong>made outside the system</strong> and that you have checked (cash, a bank transfer, a card machine...). It records that money was received and numbers it; it does <strong>not</strong> change any invoice balance. To apply a payment to an invoice, record it on the invoice instead: its receipt is then made automatically.</p></div>
<form method="post" action="/receipts" class="panel form-grid" autocomplete="off"><?= csrf_field() ?>
    <h2>Client</h2>
    <div class="field"><label for="client">Registered client</label><select id="client" name="client"><option value="">Not a registered client (type the details below)</option><?php foreach ($clients as $c) : ?><option value="<?= e($c['public_id']) ?>"<?= old('client') === $c['public_id'] ? ' selected' : '' ?>><?= e($c['name']) ?> · <?= e($c['email']) ?></option><?php endforeach; ?></select><p class="hint">A registered client is told about the receipt and finds it in their account.</p></div>
    <div class="field"><label for="cn">Client name</label><input id="cn" name="client_name" maxlength="160" value="<?= e(old('client_name')) ?>"></div>
    <div class="field"><label for="ce">Email</label><input id="ce" name="client_email" type="email" maxlength="190" value="<?= e(old('client_email')) ?>"></div>
    <div class="field"><label for="cp">Phone</label><input id="cp" name="client_phone" maxlength="32" value="<?= e(old('client_phone')) ?>"></div>
    <div class="field"><label for="ca">Address</label><input id="ca" name="client_address" maxlength="200" value="<?= e(old('client_address')) ?>"></div>
    <h2>What was paid for</h2>
    <?php for ($i = 0; $i < 4; $i++) : ?><div class="field"><label for="ld<?= $i ?>">Line <?= $i + 1 ?> description<?= $i === 0 ? '' : ' (optional)' ?></label><input id="ld<?= $i ?>" name="lines[<?= $i ?>][description]" maxlength="200"<?= $i === 0 ? ' required' : '' ?>></div>
    <div class="field"><label for="la<?= $i ?>">Line <?= $i + 1 ?> amount (₹)</label><input id="la<?= $i ?>" name="lines[<?= $i ?>][amount]" inputmode="decimal"<?= $i === 0 ? ' required' : '' ?>></div><?php endfor; ?>
    <div class="field"><label for="dc">Discount (₹)</label><input id="dc" name="discount" inputmode="decimal" value="<?= e(old('discount', '0')) ?>"></div>
    <div class="field"><label for="tx">Tax included or added (₹)</label><input id="tx" name="tax" inputmode="decimal" value="<?= e(old('tax', '0')) ?>"><p class="hint">Total paid = subtotal − discount + tax.</p></div>
    <h2>The payment</h2>
    <div class="field"><label for="md">Method</label><select id="md" name="method" required><?php foreach ($methods as $k => $l) : if ($k === 'gateway') { continue; } ?><option value="<?= e($k) ?>"<?= old('method') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="tid">Transaction ID / reference</label><input id="tid" name="txn_id" required maxlength="120" value="<?= e(old('txn_id')) ?>"><p class="hint">For cash, a receipt-book or slip number. One transaction can have only one receipt.</p></div>
    <div class="field"><label for="pd">Payment date</label><input id="pd" name="paid_on" type="date" required max="<?= e($today) ?>" value="<?= e(old('paid_on', $today)) ?>"></div>
    <div class="field"><label for="pt">Payment time (24-hour)</label><input id="pt" name="paid_time" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" value="<?= e(old('paid_time', $now)) ?>"></div>
    <div class="field"><label for="ag">Against (invoice or project) <span class="muted">(optional)</span></label><input id="ag" name="against" maxlength="160" value="<?= e(old('against')) ?>"></div>
    <div class="field"><label for="nt">Note <span class="muted">(optional)</span></label><input id="nt" name="note" maxlength="300" value="<?= e(old('note')) ?>"></div>
    <div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"><p class="hint">A receipt is proof of payment, so issuing one asks for your password.</p></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Issue receipt</button></div></form>
