<?php /** @var list<array<string,mixed>> $applications */ /** @var array<string,mixed>|null $partner */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Referral Partner</p><h1>Your application</h1></div><?php if ($partner) : ?><a class="btn btn-primary" href="/partner">Partner portal</a><?php endif; ?></div>
<?php foreach ($applications as $a) : ?>
<div class="panel"><h2><?= e($a['reference']) ?> <?= View::partial('pbadge', ['kind' => 'application', 's' => $a['status']]) ?></h2>
    <p class="muted">Submitted <?= e(local_time($a['created_at'])) ?><?= $a['decided_at'] ? ' · decided ' . e(local_time($a['decided_at'])) : '' ?></p>
    <?php if ($a['status'] === 'rejected' && $a['decision_reason']) : ?><p class="prewrap"><strong>Reason:</strong> <?= e($a['decision_reason']) ?></p><?php endif; ?>
    <?php if ($a['status'] === 'approved') : ?><p>Approved. Use the Partner portal to submit referrals.</p><?php endif; ?>
    <?php if (in_array($a['status'], ['received', 'under_review'], true)) : ?><p>We are reviewing your application. Your account is not active as a partner until it is approved.</p><?php endif; ?></div>
<?php endforeach; ?>
<?php if ($applications === []) : ?><div class="panel"><p class="muted">You have no application linked to this account. <a href="/partners/apply">Apply to become a Referral Partner</a>.</p></div><?php endif; ?>
