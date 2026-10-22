<?php /** @var array<string,mixed> $i */ /** @var int $due */ /** @var array{ready:bool,reason:string} $ready */ /** @var list<array<string,mixed>> $live */
$url = '/invoices/' . $i['public_id']; ?>
<div class="page-head"><div><p class="eyebrow">PhonePe</p><h1>Request a payment</h1></div><a class="btn btn-ghost" href="<?= e($url) ?>">&larr; Invoice <?= e($i['reference']) ?></a></div>
<div class="panel"><div class="detail-grid"><div><span class="muted">Invoice</span><br><strong><?= e($i['reference']) ?></strong> · <?= e($i['title']) ?></div><div><span class="muted">Client</span><br><?= e($i['client_name']) ?></div><div><span class="muted">Still due</span><br><strong><?= e(money($due)) ?></strong></div></div></div>
<?php if (!$ready['ready']) : ?><div class="alert alert-warn" role="alert"><p><?= e($ready['reason']) ?> Payments cannot be requested until this is fixed in Administration → Integrations &amp; settings → PhonePe.</p></div><?php endif; ?>
<?php if ($due < 100) : ?><div class="panel"><p>Nothing can be requested on this invoice: it is paid, void, or a payment the client reported is waiting to be verified.</p></div>
<?php else : ?>
<div class="panel"><h2>Amount to collect</h2>
    <p class="muted">Type the amount the client should pay now. The QR is created for exactly this amount, nothing else is generated, and the client cannot change it. You can collect less than the balance (a part payment) but not more.</p>
    <form method="post" action="<?= e($url) ?>/phonepe" class="form-grid" data-confirm-amount><?= csrf_field() ?>
        <div class="field"><label for="amount">Amount (₹)</label><input id="amount" name="amount" required inputmode="decimal" autocomplete="off" value="<?= e(old('amount', number_format($due / 100, 2, '.', ''))) ?>" data-max="<?= (int) $due ?>"><p class="hint">Between ₹1.00 and <?= e(money($due)) ?>.</p></div>
        <div class="field"><label for="note">Note for the team <span class="muted">(optional)</span></label><input id="note" name="note" maxlength="200" value="<?= e(old('note')) ?>" placeholder="For example: advance, milestone 1"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"<?= $ready['ready'] ? '' : ' disabled' ?>>Generate QR for this amount</button></div></form></div>
<?php endif; ?>
<?php if ($live !== []) : ?><div class="panel"><h2>Requests still open for this invoice</h2><ul class="plain-list"><?php foreach ($live as $o) : ?><li><a href="/phonepe-requests/<?= e($o['public_id']) ?>"><?= e(money((int) $o['amount_minor'])) ?></a> · expires <?= e(local_time((string) $o['expires_at'])) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
