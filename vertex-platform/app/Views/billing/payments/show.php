<?php /** @var array<string,mixed> $o */ /** @var array<string,mixed> $inv */ /** @var bool $mine */ /** @var bool $live */ /** @var string $svg */ /** @var string $statusUrl */ /** @var int $expiresAt */ /** @var string $invoiceUrl */
$st = (string) $o['status']; $amount = money((int) $o['amount_minor']); ?>
<div class="page-head"><div><p class="eyebrow">Razorpay</p><h1><?= $mine ? 'Pay ' . e($amount) : 'Payment request' ?></h1></div>
    <div class="actions"><a class="btn btn-ghost" href="<?= e($invoiceUrl) ?>">&larr; Invoice <?= e($inv['reference']) ?></a><?php if (!$mine) : ?><a class="btn btn-ghost" href="/payment-requests">All requests</a><?php endif; ?></div></div>
<div class="pay-card" data-pay-poll="<?= e($statusUrl) ?>" data-pay-expires="<?= (int) $expiresAt ?>" data-pay-state="<?= e($live ? 'live' : $st) ?>">
    <div class="pay-amount"><span class="muted">Amount</span><strong><?= e($amount) ?></strong><span class="muted">Invoice <?= e($inv['reference']) ?> · <?= e($inv['client_name']) ?></span><?php if (!empty($o['note']) && !$mine) : ?><span class="muted">Note: <?= e($o['note']) ?></span><?php endif; ?></div>
    <?php if ($o['flow'] === 'checkout') : ?>
    <div class="pay-qr" data-pay-live<?= $live ? '' : ' hidden' ?>>
        <?php if ($mine) : ?>
        <p class="pay-help">Pay exactly <strong><?= e($amount) ?></strong> securely with Razorpay: UPI, cards, net banking or wallets. The amount is set by our team and cannot be changed.</p>
        <button class="btn btn-primary btn-lg" type="button" data-checkout-start="/my/pay/<?= e($o['public_id']) ?>" data-checkout-verify="/my/pay/<?= e($o['public_id']) ?>/verify">Pay <?= e($amount) ?> with Razorpay</button>
        <p class="pay-error" role="alert" aria-live="assertive" data-checkout-error hidden></p>
        <?php else : ?>
        <p class="pay-help">The client pays <strong><?= e($amount) ?></strong> in Razorpay's checkout window on their own page (they are notified). Share the link below if you need to.</p>
        <?php endif; ?>
        <p class="pay-timer" aria-live="polite">Open for <span data-pay-left>--:--</span></p>
    </div>
    <?php else : ?>
    <div class="pay-qr" data-pay-live<?= $live ? '' : ' hidden' ?>>
        <div class="qr-box"><?= $svg ?></div>
        <p class="pay-help"><?php if ($o['flow'] === 'upi_qr') : ?>Scan with any UPI app. The amount <strong><?= e($amount) ?></strong> is fixed in this QR and nothing else can be paid with it.<?php else : ?>Scan with your phone camera or any UPI app. It opens Razorpay's secure payment page for exactly <strong><?= e($amount) ?></strong> (UPI, cards, net banking, wallets).<?php endif; ?></p>
        <p class="pay-timer" aria-live="polite">Valid for <span data-pay-left>--:--</span></p>
        <p class="muted small">Waiting for Razorpay's confirmation. This page updates by itself once the payment is received.</p>
    </div>
    <?php endif; ?>
    <div class="pay-done" data-pay-paid<?= $st === 'completed' ? '' : ' hidden' ?>><span class="pay-tick" aria-hidden="true">✓</span><h2>Payment received</h2><p>Razorpay confirmed <?= e($amount) ?> and it has been recorded on the invoice.</p></div>
    <div class="pay-done is-bad" data-pay-over<?= (!$live && $st !== 'completed') ? '' : ' hidden' ?>><h2><?= $st === 'cancelled' ? 'This request was withdrawn' : ($st === 'failed' ? 'The payment did not go through' : 'This request has expired') ?></h2><p><?= $mine ? 'Please ask the team for a new payment request.' : 'Create a new request from the invoice if the client still needs to pay.' ?></p></div>
</div>
<?php if (!$mine && $live) : ?><div class="panel pay-share"><h2>Send it to the client</h2><p class="muted">The client sees this same QR (and is notified) after signing in to their account. You can also share this link with them:</p>
    <div class="copy-row"><input type="text" readonly value="<?= e(rtrim((string) \Vertex\Core\Config::get('app.url'), '/') . '/my/pay/' . $o['public_id']) ?>" aria-label="Link for the client" data-copy-source><button class="btn btn-ghost btn-sm" type="button" data-copy-button>Copy link</button></div></div><?php endif; ?>
<?php if (!$mine && $live) : ?><form method="post" action="/payment-requests/<?= e($o['public_id']) ?>/cancel" class="inline-form" data-confirm="Withdraw this request? Its QR will stop being shown."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Withdraw this request</button></form><?php endif; ?>
