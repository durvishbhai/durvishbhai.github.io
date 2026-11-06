<?php /** @var list<array<string,mixed>> $docs */ /** @var array<string,mixed> $st */ /** @var string $email */ /** @var string $whatsapp */ ?>
<div class="page-head"><div><p class="eyebrow">Account</p><h1>Updated terms</h1></div></div>
<?php if ($st['restricted']) : ?><div class="alert alert-error" role="alert"><p><strong>Your account is restricted</strong> until you accept the updated terms. Nothing has been deleted. You can still read your <a href="/my/invoices">invoices</a>, <a href="/my/receipts">receipts</a> and <a href="/my/agreements">agreements</a>, <a href="/account">change your password</a> and contact us. Accepting restores access straight away.</p></div><?php endif; ?>
<p class="muted">We updated the documents below. The updated terms apply to future services: work and orders already completed stay under the terms they were made under, and your invoices, receipts and records are not changed.<?= $st['deadline'] ? ' Please accept by <strong>' . e(local_date($st['deadline'], 'j F Y')) . '</strong>.' : '' ?></p>
<form method="post" action="/policy-acceptance" class="form-grid" novalidate><?= csrf_field() ?>
    <?php foreach ($docs as $d) : ?>
    <div class="panel"><h2><?= e($d['title']) ?> <span class="muted">· version <?= e($d['version_no']) ?></span></h2>
        <p><?= $d['change_summary'] ? '<strong>What changed:</strong> ' . e($d['change_summary']) : 'Please read the full text.' ?> <?= $d['effective_date'] ? 'Effective ' . e(local_date((string) $d['effective_date'])) . '.' : '' ?></p>
        <details><summary>Read the full text</summary><div class="prose policy legal-body"><?= $d['html'] ?></div></details>
        <p><a href="/<?= e($d['doc_key']) ?>" target="_blank" rel="noopener">Open in a new tab</a></p>
        <input type="hidden" name="versions[]" value="<?= e($d['version_id']) ?>"></div>
    <?php endforeach; ?>
    <div class="panel">
        <label class="check"><input type="checkbox" name="confirm" value="1"> <span>I have read the updated document(s) above and I accept them.</span></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit" name="decision" value="accept">I accept</button>
            <button class="btn btn-ghost" type="submit" name="decision" value="decline" data-confirm="If you do not accept, your account access is restricted until you do. Nothing is deleted. Continue?">I do not accept</button></div>
        <p class="muted small">Questions first? <?= $email !== '' ? 'Email <a href="mailto:' . e($email) . '">' . e($email) . '</a>' : '' ?> <?= $whatsapp !== '' ? ' or WhatsApp ' . e($whatsapp) : '' ?> · <a href="/contact-and-grievance">Contact information</a>.</p>
    </div>
</form>
