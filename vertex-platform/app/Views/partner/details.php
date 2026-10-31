<?php /** @var ?string $hint */ /** @var ?string $updated */ $v = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Partner portal</p><h1>Payout details</h1></div><a class="btn btn-ghost" href="/partner/payouts">&larr; My payouts</a></div>
<div class="panel"><p>Currently on file: <strong><?= $hint ? e($hint) : 'nothing yet' ?></strong><?= $updated ? ' (updated ' . e(local_time($updated, 'd M Y')) . ')' : '' ?>.</p>
    <p class="muted">Your details are encrypted when saved. After saving, only a masked hint is shown to anyone, including you. Only the person who makes your payment can see the full details, and every view is logged.</p>
    <form method="post" action="/partner/payout-details" class="form-grid" novalidate autocomplete="off"><?= csrf_field() ?>
        <div class="field"><label for="method">Method</label><select id="method" name="method"><?php foreach (['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'other' => 'Other'] as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('method') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="account_name">Account holder name</label><input id="account_name" name="account_name" required maxlength="120" value="<?= e($v('account_name')) ?>"></div>
        <div class="field"><label for="identifier">Account number or UPI ID</label><input id="identifier" name="identifier" required maxlength="80" autocomplete="off"></div>
        <div class="field"><label for="bank_name">Bank name and branch / IFSC <span class="muted">(bank transfer)</span></label><input id="bank_name" name="bank_name" maxlength="160" value="<?= e($v('bank_name')) ?>"></div>
        <div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save payout details</button></div></form></div>
