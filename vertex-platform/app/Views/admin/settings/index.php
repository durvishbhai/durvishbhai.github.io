<?php /** @var array<string,string> $groups */ /** @var array<string,list<array<string,mixed>>> $screen */ /** @var array{ready:bool,reason:string} $razorpay */ /** @var array{ready:bool,reason:string,used:int,cap:int} $ai */ /** @var string $webhookUrl */
$canManage = can('settings.manage'); ?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Integrations &amp; settings</h1></div></div>
<p class="muted">Company facts, email, storage, AI, payments and feature switches in one place. A value saved here replaces the one in the server's <code>.env</code> file; "Use the default again" removes it. Secrets (passwords, keys) are stored encrypted and are never shown again — leave a secret box empty to keep the saved one.</p>
<?php if (!$canManage) : ?><div class="alert alert-error" role="status"><p>You can look at these settings but only the owner can change them.</p></div><?php endif; ?>
<nav class="tabs" aria-label="Settings sections"><?php foreach ($groups as $g => $label) : ?><a href="#<?= e($g) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<?php foreach ($groups as $g => $label) : ?>
<div class="panel" id="<?= e($g) ?>"><h2><?= e($label) ?>
    <?php if ($g === 'razorpay') : ?><span class="badge badge-sys-<?= $razorpay['ready'] ? 'ok' : 'warn' ?>"><?= $razorpay['ready'] ? 'Ready' : 'Not ready' ?></span><?php endif; ?>
    <?php if ($g === 'ai') : ?><span class="badge badge-sys-<?= $ai['ready'] ? 'ok' : 'warn' ?>"><?= $ai['ready'] ? 'Ready' : 'Off' ?></span><?php endif; ?></h2>
    <?php if ($g === 'razorpay') : ?>
        <?php if ($razorpay['reason'] !== '') : ?><p class="muted"><?= e($razorpay['reason']) ?></p><?php endif; ?>
        <p class="muted">In the Razorpay Dashboard → Account &amp; Settings → Webhooks, add the address <code class="prewrap"><?= e($webhookUrl) ?></code>, tick <em>order.paid</em>, <em>payment.captured</em>, <em>qr_code.credited</em>, <em>payment_link.paid</em> (and <em>qr_code.closed</em>), and type the same secret you save below. Payments are recorded only after the server has asked Razorpay and found a captured payment of exactly the requested amount; the webhook only tells us when to ask. Try it with test keys (<code>rzp_test_…</code>) first.</p>
    <?php elseif ($g === 'ai') : ?>
        <?php if ($ai['reason'] !== '') : ?><p class="muted"><?= e($ai['reason']) ?></p><?php endif; ?>
        <p class="muted">Requests this month: <?= (int) $ai['used'] ?> of <?= (int) $ai['cap'] ?>. The assistant only drafts text; it never saves, sends or approves anything, and it only sees records the person asking may open.</p>
    <?php endif; ?>
    <form method="post" action="/admin/settings/<?= e($g) ?>" class="form-grid" autocomplete="off"><?= csrf_field() ?>
    <?php foreach ($screen[$g] ?? [] as $f) : $name = str_replace('.', '__', $f['key']); $val = old($name, is_bool($f['value']) ? ($f['value'] ? '1' : '0') : (string) ($f['value'] ?? '')); ?>
        <div class="field">
        <?php if ($f['type'] === 'bool') : ?>
            <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?><?= $canManage ? '' : ' disabled' ?>> <?= e($f['label']) ?></label>
        <?php else : ?>
            <label for="s-<?= e($name) ?>"><?= e($f['label']) ?><?= $f['type'] === 'secret' && $f['secret_set'] ? ' <span class="badge badge-active">saved</span>' : '' ?></label>
            <?php if ($f['type'] === 'select') : ?>
                <select id="s-<?= e($name) ?>" name="<?= e($name) ?>"<?= $canManage ? '' : ' disabled' ?>><?php foreach ($f['options'] as $ov => $ol) : ?><option value="<?= e($ov) ?>"<?= $val === (string) $ov ? ' selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
            <?php elseif ($f['type'] === 'textarea') : ?>
                <textarea id="s-<?= e($name) ?>" name="<?= e($name) ?>" rows="3" maxlength="2000"<?= $canManage ? '' : ' disabled' ?>><?= e($val) ?></textarea>
            <?php elseif ($f['type'] === 'secret') : ?>
                <input id="s-<?= e($name) ?>" name="<?= e($name) ?>" type="password" maxlength="500" autocomplete="new-password" placeholder="<?= $f['secret_set'] ? 'leave empty to keep the saved value' : '' ?>"<?= $canManage ? '' : ' disabled' ?>>
            <?php else : ?>
                <input id="s-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $f['type'] === 'int' ? 'number' : 'text' ?>" maxlength="300" value="<?= e($val) ?>"<?= $canManage ? '' : ' disabled' ?>>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($f['help'] !== '') : ?><p class="hint"><?= e($f['help']) ?></p><?php endif; ?>
        <?php if ($canManage) : ?><p class="hint">Source: <?= e($f['source']) ?><?php if ($f['source'] === 'saved here') : ?> · <label class="check"><input type="checkbox" name="clear[]" value="<?= e($f['key']) ?>"> Use the default again</label><?php endif; ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($canManage) : ?><div class="field"><label for="pw-<?= e($g) ?>">Your password</label><input id="pw-<?= e($g) ?>" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save <?= e(strtolower($label)) ?></button></div><?php endif; ?>
    </form></div>
<?php endforeach; ?>
