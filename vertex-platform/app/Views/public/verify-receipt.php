<?php /** @var bool $searched */ /** @var bool $limited */ /** @var ?array<string,mixed> $r */ /** @var string $number */ /** @var string $code */
$state = $r === null ? null : ($r['effective'] === 'void' ? 'void' : (!$r['sealOk'] ? 'tampered' : ($r['effective'] === 'reversed' ? 'reversed' : 'ok'))); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Receipt verification</p><h1>Verify a payment receipt</h1><p class="lead">Check that a Krazy Integrated Solutions receipt is genuine and that the payment on it was received. Scan the QR code on the receipt, or enter its number and verification code.</p></div></section>
<section class="pub-section"><div class="container narrow">
<?php if ($limited) : ?><div class="alert alert-warn" role="alert"><p>Too many checks from your network. Please wait a few minutes and try again.</p></div>
<?php elseif ($searched && $r === null) : ?><div class="verify-card verify-bad" role="alert"><h2>No matching receipt found</h2><p>We could not find a receipt with those details. Check the receipt number and code against the paper or PDF and try again. If they match what is printed and you still see this, contact us.</p></div>
<?php elseif ($r !== null) : ?>
    <div class="verify-card <?= $state === 'ok' ? 'verify-ok' : 'verify-bad' ?>" role="status">
        <?php if ($state === 'ok') : ?><span class="verify-mark" aria-hidden="true">✓</span><h2>Verified: this payment was received</h2><p>This is a genuine receipt issued by <?= e($r['by']) ?>, and the payment on it was received and recorded.<?= $r['effective'] === 'refunded' ? ' Part of it (' . e(money($r['refunded'])) . ') has since been refunded.' : '' ?></p>
        <?php elseif ($state === 'void') : ?><span class="verify-mark" aria-hidden="true">!</span><h2>This receipt has been voided</h2><p>It was cancelled by us and is no longer valid proof of payment.<?= $r['voidReason'] !== '' ? ' Reason: ' . e($r['voidReason']) : '' ?></p>
        <?php elseif ($state === 'reversed') : ?><span class="verify-mark" aria-hidden="true">!</span><h2>The payment on this receipt was reversed</h2><p>The payment was returned, so this receipt is no longer valid proof of payment.</p>
        <?php else : ?><span class="verify-mark" aria-hidden="true">!</span><h2>This receipt could not be verified</h2><p>The record does not match its seal. Please contact us before relying on it.</p><?php endif; ?>
        <dl class="detail-list"><dt>Receipt number</dt><dd><strong><?= e($r['number']) ?></strong></dd><dt>Amount</dt><dd><strong><?= e(money($r['amount'])) ?></strong></dd><dt>Payment date</dt><dd><?= e($r['paidDate']) ?>, <?= e($r['paidTime']) ?></dd>
            <dt>Method</dt><dd><?= e($r['method']) ?></dd><dt>Paid by</dt><dd><?= e($r['client']) ?></dd><?php if ($r['against'] !== '') : ?><dt>Against</dt><dd><?= e($r['against']) ?></dd><?php endif; ?><dt>Transaction</dt><dd><?= e($r['txn']) ?></dd><dt>Receipt issued</dt><dd><?= e($r['issuedDate']) ?></dd></dl>
        <p class="muted small">Check that the amount and date above match your receipt. Contact details and other private information are never shown here.</p></div>
<?php endif; ?>
<form method="get" action="/verify" class="panel form-grid" autocomplete="off"><h2>Enter the details printed on the receipt</h2>
    <div class="field"><label for="vn">Receipt number</label><input id="vn" name="number" required maxlength="30" placeholder="KIS-PR-2026-0001" value="<?= e($number) ?>"></div>
    <div class="field"><label for="vc">Verification code</label><input id="vc" name="code" required maxlength="20" placeholder="K7Q2-9XMD-4PFA" value="<?= $searched && $r === null ? '' : e($code) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Verify receipt</button></div></form>
</div></section>
