<?php /** @var array<string,mixed> $e */ /** @var string $status */ ?>
<div class="page-head"><div><p class="eyebrow">Referral <?= e($e['reference']) ?></p><h1><?= e($e['contact_name']) ?></h1></div><a class="btn btn-ghost" href="/partner/referrals">&larr; My referrals</a></div>
<div class="panel"><dl class="kv">
    <dt>Status</dt><dd><span class="badge"><?= e($status) ?></span></dd>
    <dt>Submitted</dt><dd><?= e(local_time($e['created_at'])) ?></dd>
    <dt>Business</dt><dd><?= e($e['company_name'] ?? '—') ?></dd>
    <dt>Service</dt><dd><?= e(config("workflow.services.{$e['service']}", $e['service'])) ?></dd>
    <dt>Email</dt><dd><?= e($e['email']) ?></dd><dt>Phone</dt><dd><?= e($e['phone'] ?? '—') ?></dd>
</dl><h3>What you told us</h3><p class="prewrap"><?= e($e['description']) ?></p>
<p class="muted">Internal notes and pipeline details are not shown to referral partners.</p></div>
