<?php /** @var array<string,mixed> $r */ /** @var list<array<string,mixed>> $history */ /** @var bool $hasCommission */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Referral</p><h1><?= e($r['reference']) ?></h1></div><a class="btn btn-ghost" href="/partner">&larr; Partner portal</a></div>
<div class="panel"><div class="detail-grid">
    <div><span class="muted">Status</span><br><?= View::partial('pbadge', ['kind' => 'referral', 's' => $r['status']]) ?></div>
    <div><span class="muted">Credit</span><br><?= e(match ($r['credit']) { 'credited' => 'You are credited for this referral', 'not_credited' => 'Not credited', default => 'Our team is reviewing the credit' }) ?></div>
    <div><span class="muted">Submitted</span><br><?= e(local_time($r['created_at'])) ?></div><?php if ($r['converted_at']) : ?><div><span class="muted">Converted</span><br><?= e(local_time($r['converted_at'])) ?></div><?php endif; ?></div>
    <?php if ($r['reason']) : ?><h3>Message from our team</h3><p class="prewrap"><?= e($r['reason']) ?></p><?php endif; ?>
    <h3>What you submitted</h3>
    <dl class="detail-list"><dt>Prospect</dt><dd><?= e($r['prospect_name']) ?></dd><?php if ($r['business_name']) : ?><dt>Business</dt><dd><?= e($r['business_name']) ?></dd><?php endif; ?>
        <dt>Contact</dt><dd><?= e(trim(($r['email'] ?? '') . ' ' . ($r['phone'] ?? ''))) ?></dd><dt>Service</dt><dd><?= e(config("workflow.services.{$r['service']}", $r['service'])) ?></dd>
        <dt>Description</dt><dd class="prewrap"><?= e($r['description']) ?></dd><?php if ($r['estimated_budget_minor'] !== null) : ?><dt>Estimated budget</dt><dd><?= e(money($r['estimated_budget_minor'])) ?></dd><?php endif; ?>
        <?php if ($r['notes']) : ?><dt>Notes</dt><dd class="prewrap"><?= e($r['notes']) ?></dd><?php endif; ?></dl>
    <?php if ($hasCommission) : ?><p><a href="/partner/commissions">See the commission for this referral</a></p><?php endif; ?></div>
<div class="panel"><h2>History</h2><ol class="timeline"><?php foreach ($history as $h) : ?><li><strong><?= e(config("referrals.referral_statuses.{$h['to_value']}", $h['to_value'])) ?></strong> <span class="muted"><?= e(local_time($h['created_at'])) ?></span><?php if ($h['note']) : ?><p class="prewrap"><?= e($h['note']) ?></p><?php endif; ?></li><?php endforeach; ?></ol></div>
