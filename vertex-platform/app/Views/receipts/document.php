<?php /** @var array<string,mixed> $v */ /** @var string $back */ /** @var string $pdf */ /** @var string $qr */ /** @var bool $staff */ /** @var bool $canVoid */ /** @var string $rid */
$biz = \Vertex\Services\BusinessProfile::get();
$name = strtoupper((string) (($biz['trade_name'] ?? '') !== '' ? $biz['trade_name'] : 'Krazy Integrated Solutions'));
$c = (array) $v['client'];
$web = (string) (($biz['website'] ?? '') !== '' ? $biz['website'] : (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
$statusText = ['valid' => 'SUCCESSFUL', 'void' => 'VOID', 'reversed' => 'REVERSED', 'refunded' => 'SUCCESSFUL (PART REFUNDED)'][$v['effective']] ?? 'SUCCESSFUL'; ?>
<div class="doc-actions no-print"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">&larr; Back</a> <a class="btn btn-ghost btn-sm" href="<?= e($pdf) ?>">Download PDF</a> <button class="btn btn-primary btn-sm" type="button" data-print>Print</button>
    <a class="btn btn-ghost btn-sm" href="<?= e($v['verifyUrl']) ?>" rel="noopener">Open verification page</a></div>
<?php if (!$v['sealOk']) : ?><div class="alert alert-error no-print" role="alert"><p><strong>This receipt's record does not match its seal.</strong> It may have been altered. Do not rely on it and tell the Owner.</p></div><?php endif; ?>
<article class="rcpt">
    <header class="rcpt-head"><img src="<?= e(asset('img/krazy-logo.png')) ?>" alt="" width="150" height="100"><div><strong><?= e($name) ?></strong><span>Your Ideas. Our Integrated Solutions.</span></div></header>
    <div class="rcpt-title"><div><h1>PAYMENT <em>RECEIPT</em></h1><p class="rcpt-sub"><?= $v['manual'] ? 'Acknowledgement of payment issued by the KIS team.' : 'System-generated acknowledgement of payment.' ?></p></div>
        <div class="rcpt-no"><span>RECEIPT NUMBER</span><strong><?= e($v['number']) ?></strong><span>Date: <b><?= e($v['issuedDate']) ?></b> &nbsp; Time: <b><?= e($v['issuedTime']) ?></b></span>
            <?php if ($v['effective'] !== 'valid') : ?><span class="rcpt-badge"><?= e(['void' => 'VOID', 'reversed' => 'PAYMENT REVERSED', 'refunded' => 'PART REFUNDED'][$v['effective']] ?? '') ?></span><?php endif; ?></div></div>
    <div class="rcpt-boxes">
        <section class="rcpt-box"><h2>CLIENT DETAILS</h2><dl>
            <div><dt>Client ID:</dt><dd><?= e($c['client_ref'] ?? '-') ?></dd></div><div><dt>Client Name:</dt><dd><?= e($c['name'] ?? '') ?></dd></div>
            <div><dt>Email:</dt><dd><?= e(($c['email'] ?? '') !== '' ? $c['email'] : '-') ?></dd></div><div><dt>Phone:</dt><dd><?= e(($c['phone'] ?? '') !== '' ? $c['phone'] : '-') ?></dd></div>
            <div><dt>Address:</dt><dd><?= e(($c['address'] ?? '') !== '' ? $c['address'] : '-') ?></dd></div></dl></section>
        <section class="rcpt-box"><h2>PAYMENT DETAILS</h2><dl>
            <div><dt>Method:</dt><dd><?= e($v['methodLabel']) ?></dd></div><div><dt>Transaction ID:</dt><dd><?= e($v['txn']) ?></dd></div>
            <div><dt>Payment Date:</dt><dd><?= e($v['paidDate']) ?></dd></div><div><dt>Payment Time:</dt><dd><?= e($v['paidTime']) ?></dd></div>
            <div><dt>Status:</dt><dd><?= e($statusText) ?></dd></div></dl></section>
    </div>
    <table class="rcpt-items"><thead><tr><th class="n">#</th><th>DESCRIPTION</th><th class="a">AMOUNT (₹)</th></tr></thead><tbody>
        <?php foreach ($v['lines'] as $k => $ln) : ?><tr><td class="n"><?= (int) $k + 1 ?></td><td><strong><?= e($ln['description']) ?></strong></td><td class="a"><?= e(money((int) $ln['amount_minor'])) ?></td></tr><?php endforeach; ?></tbody></table>
    <table class="rcpt-totals"><tbody>
        <tr><td>Subtotal</td><td><?= e(money($v['subtotal'])) ?></td></tr><tr><td>Discount</td><td><?= e(money($v['discount'])) ?></td></tr><tr><td>Tax</td><td><?= e(money($v['tax'])) ?></td></tr>
        <tr><td>Payment Received</td><td><?= e(money($v['paidMinor'])) ?></td></tr><tr class="total"><td>TOTAL PAID</td><td><?= e(money($v['paidMinor'])) ?></td></tr>
        <?php if ($v['refunded'] > 0) : ?><tr class="refund"><td>Refunded since</td><td><?= e(money($v['refunded'])) ?></td></tr><?php endif; ?></tbody></table>
    <div class="rcpt-words"><strong>AMOUNT IN WORDS</strong><p><?= e($v['words']) ?></p><?php if ($v['against']) : ?><p class="muted">Against: <?= e($v['against']) ?></p><?php endif; ?><?php if ($v['note']) : ?><p class="muted">Note: <?= e($v['note']) ?></p><?php endif; ?></div>
    <aside class="rcpt-ack"><strong>Payment acknowledgement</strong><p>This payment has been received and recorded in our system.</p><p class="muted"><?= $v['manual'] ? 'This receipt was issued by the KIS team. It does not require a signature.' : 'This system-generated receipt does not require a signature.' ?></p></aside>
    <footer class="rcpt-foot"><div class="rcpt-thanks"><strong>Thank You!</strong><span>for choosing <?= e($name === 'KRAZY INTEGRATED SOLUTIONS' ? 'KRAZY Integrated Solutions' : $name) ?>.</span></div>
        <div class="rcpt-verify"><div class="rcpt-qr"><?= $qr ?></div><div><strong>VERIFY THIS RECEIPT</strong><span><?= e($v['verifyUrl']) ?></span><span>Code: <b><?= e(implode('-', str_split((string) $v['code'], 4))) ?></b></span></div></div>
        <div class="rcpt-sys"><strong><?= $v['manual'] ? 'Issued by the KIS team' : 'System-Generated Receipt' ?></strong><span>No signature required.</span></div></footer>
    <div class="rcpt-contact"><?php foreach (array_filter([(string) ($biz['email'] ?? ''), (string) ($biz['phone'] ?? ''), $web]) as $cell) : ?><span><?= e($cell) ?></span><?php endforeach; ?></div>
</article>
<?php if ($canVoid) : ?><form method="post" action="/receipts/<?= e($rid) ?>/void" class="panel no-print form-grid" data-confirm="Void this receipt? It stays on record and the verification page will say it is void."><?= csrf_field() ?>
    <h2>Void this receipt</h2><p class="muted">Use this only if the receipt was issued by mistake. It is never deleted: the verification page will show it as void with your reason kept in the audit log.</p>
    <div class="field"><label for="vr">Reason (required)</label><input id="vr" name="reason" required minlength="10" maxlength="255"></div>
    <div class="field"><label for="vp">Your password</label><input id="vp" type="password" name="confirm_password" required autocomplete="current-password"></div>
    <div class="form-actions"><button class="btn btn-danger" type="submit">Void receipt</button></div></form><?php endif; ?>
