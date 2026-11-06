<?php /** @var array<string,mixed> $r */ /** @var array<string,mixed> $p */ $url = '/referrals/' . $r['public_id']; ?>
<div class="page-head"><div><p class="eyebrow">Preview</p><h1>Email to the partner</h1></div><a class="btn btn-ghost" href="<?= e($url) ?>">&larr; <?= e($r['reference']) ?></a></div>
<div class="panel"><p class="muted">Nothing has been sent. Read the exact message below, then confirm.</p>
    <dl class="detail-list"><dt>To</dt><dd><?= e($p['to']) ?></dd><dt>Subject</dt><dd><?= e($p['subject']) ?></dd><dt>Message</dt><dd class="prewrap"><?= e($p['body']) ?></dd></dl>
    <?php if ($p['already_sent']) : ?><p><strong>This status email was already sent.</strong></p>
    <?php else : ?>
        <?php if (!$p['mail_enabled']) : ?><p class="alert alert-error" role="status">Email is not configured on this system. Confirming will record that nothing was sent.</p><?php endif; ?>
        <form method="post" action="<?= e($url) ?>/email/<?= (int) $p['history_id'] ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="hash" value="<?= e($p['hash']) ?>">
            <div class="field"><label class="check"><input type="checkbox" name="confirm" value="1" required> I have read this message and want it sent to the partner</label></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send email</button></div></form><?php endif; ?></div>
