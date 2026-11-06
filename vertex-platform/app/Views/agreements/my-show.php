<?php /** @var array<string,mixed> $a */ /** @var list<array<string,mixed>> $signatures */ /** @var list<array<string,mixed>> $files */ /** @var bool $canSign */
$u = '/my/agreements/' . $a['public_id']; $signed = array_column($signatures, 'party'); $lab = ['sent' => 'Waiting for your signature', 'partially_signed' => 'Waiting for signatures', 'executed' => 'Signed by both parties', 'cancelled' => 'Cancelled', 'expired' => 'Expired']; ?>
<div class="page-head"><div><p class="eyebrow"><a href="/my/agreements">My agreements</a></p><h1><?= e($a['reference']) ?> · <?= e($a['title']) ?></h1><p class="muted"><?= e($lab[$a['status']] ?? $a['status']) ?></p></div>
    <div class="actions"><?php if ($a['mode'] === 'online') : ?><a class="btn btn-ghost" href="<?= e($u) ?>/document">Download PDF</a><?php endif; ?><?php foreach ($files as $f) : ?><a class="btn btn-ghost" href="<?= e($u) ?>/files/<?= (int) $f['version_no'] ?>">Download signed copy</a><?php endforeach; ?></div></div>
<?php if ($a['mode'] === 'online') : ?><div class="panel"><div class="prewrap agreement-text"><?= e($a['body']) ?></div></div><?php endif; ?>
<?php if ($signatures !== []) : ?><div class="panel"><h2>Signatures</h2><ul class="plain"><?php foreach ($signatures as $s) : ?><li><strong><?= e(ucfirst((string) $s['party'])) ?></strong>: <?= e($s['signer_name']) ?> &middot; <?= e(local_time($s['signed_at'])) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($canSign && $a['mode'] === 'online' && in_array($a['status'], ['sent', 'partially_signed'], true) && !in_array('client', $signed, true)) : ?><div class="panel"><h2>Sign this agreement</h2>
    <p class="muted">Signing is legally binding. Read the agreement above first. Your name, the time, your device address and the exact text you signed are recorded.</p>
    <form method="post" action="<?= e($u) ?>/sign" class="form-grid" data-confirm="Sign this agreement now?"><?= csrf_field() ?>
        <div class="field"><label for="tn">Type your full name</label><input id="tn" name="typed_name" required maxlength="160" autocomplete="name"></div>
        <div class="field"><label class="check"><input type="checkbox" name="declare" value="1" required> I have read this agreement, I understand it and I agree to be bound by it.</label></div>
        <div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Sign agreement</button></div></form></div><?php endif; ?>
