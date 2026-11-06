<?php /** @var array<string,int> $balance */ /** @var bool $hasDetails */ /** @var ?string $hint */ /** @var string $token */ /** @var int $min */ $v = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Partner portal</p><h1>Request a payout</h1></div><a class="btn btn-ghost" href="/partner/payouts">&larr; My payouts</a></div>
<div class="panel"><p>Available to request: <strong><?= e(money(max(0, $balance['available']))) ?></strong>. Approved commissions become available when they are payable.</p>
    <?php if ($balance['available'] < 0) : ?><p class="panel-warn">Your balance is negative because a commission was adjusted after a client payment changed; it is offset against future commissions.</p><?php endif; ?>
    <?php if (!$hasDetails) : ?><p>Please <a href="/partner/payout-details">add your payout details</a> first. They are stored encrypted.</p><?php else : ?>
    <p class="muted">We will pay to: <?= e((string) $hint) ?> (<a href="/partner/payout-details">change</a>).<?= $min > 0 ? ' The smallest payout is ' . e(money($min)) . '.' : '' ?></p>
    <form method="post" action="/partner/payouts" class="form-grid" novalidate data-confirm="Submit this payout request?"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="amount">Amount (₹)</label><input id="amount" name="amount" inputmode="decimal" required value="<?= e($v('amount')) ?>"></div>
        <div class="field"><label for="notes">Notes (optional)</label><input id="notes" name="notes" maxlength="500" value="<?= e($v('notes')) ?>"></div>
        <p class="muted">Submitting a request reserves that amount. Nothing is paid until our team approves it and records the payment.</p>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Request payout</button></div></form><?php endif; ?></div>
