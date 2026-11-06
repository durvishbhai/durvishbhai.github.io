<?php /** @var array<string,mixed> $a */ /** @var list<array<string,mixed>> $notes */ /** @var bool $canDecide */ use Vertex\Core\View; $url = '/referrals/applications/' . $a['public_id']; $v = static fn (string $k): string => old($k); $open = in_array($a['status'], ['received', 'under_review'], true); ?>
<div class="page-head"><div><p class="eyebrow">Partner application</p><h1><?= e($a['reference']) ?></h1></div><a class="btn btn-ghost" href="/referrals/applications">&larr; Applications</a></div>
<div class="panel"><div class="detail-grid"><div><span class="muted">Status</span><br><?= View::partial('pbadge', ['kind' => 'application', 's' => $a['status']]) ?></div><div><span class="muted">Received</span><br><?= e(local_time($a['created_at'])) ?></div>
    <div><span class="muted">Terms agreed</span><br>v<?= e($a['terms_version']) ?> · <?= e(local_time($a['terms_accepted_at'])) ?></div><div><span class="muted">Linked account</span><br><?= $a['user_id'] ? 'Yes (signed in when applying)' : 'No' ?></div></div>
    <dl class="detail-list"><dt>Name</dt><dd><?= e($a['full_name']) ?></dd><dt>Email</dt><dd><?= e($a['email']) ?></dd><dt>Phone</dt><dd><?= e($a['phone']) ?></dd><dt>Organisation</dt><dd><?= e($a['organization'] ?? '—') ?></dd>
        <dt>Background</dt><dd class="prewrap"><?= e($a['professional_info']) ?></dd><dt>Introduced by</dt><dd class="prewrap"><?= e($a['referral_source']) ?></dd>
        <?php if ($a['file_public_id']) : ?><dt>Supporting file</dt><dd><a href="<?= e($url) ?>/files/<?= e($a['file_public_id']) ?>"><?= e($a['file_name']) ?></a></dd><?php endif; ?>
        <?php if ($a['decided_at']) : ?><dt>Decision</dt><dd><?= e(local_time($a['decided_at'])) ?> · <?= e($a['decided_by_name'] ?? '') ?><?= $a['decision_reason'] ? '<br><span class="prewrap">' . e($a['decision_reason']) . '</span>' : '' ?></dd><?php endif; ?>
        <?php if ($a['partner_public_id']) : ?><dt>Partner</dt><dd><a href="/referrals/partners/<?= e($a['partner_public_id']) ?>">Open the partner record</a></dd><?php endif; ?></dl></div>
<?php if ($open && $canDecide) : ?>
<div class="panel"><h2>Decision</h2>
    <?php if ($a['status'] === 'received') : ?><form method="post" action="<?= e($url) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="review"><button class="btn btn-ghost btn-sm" type="submit">Move to Under Review</button></form><?php endif; ?>
    <form method="post" action="<?= e($url) ?>" class="form-grid" data-confirm="Approve this application? This creates the partner account."><?= csrf_field() ?><input type="hidden" name="action" value="approve">
        <p class="muted">Approving creates the partner record. The applicant activates their own account with a secure invitation link (or, if they applied while signed in, the role is added to that account). Nothing is activated automatically.</p>
        <div class="field"><label for="an">Note (optional)</label><input id="an" name="note" maxlength="500" value="<?= e($v('note')) ?>"></div>
        <div class="field"><label for="apw">Your password</label><input id="apw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Approve</button></div></form>
    <form method="post" action="<?= e($url) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="reject">
        <div class="field"><label for="rr">Rejection reason (required)</label><textarea id="rr" name="reason" rows="3" required minlength="10" maxlength="500"><?= e($v('reason')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-danger btn-sm" type="submit">Reject</button></div></form></div>
<?php endif; ?>
<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">staff only</span></h2>
    <?php foreach ($notes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= e($n['author'] ?? '') ?> · <?= e(local_time($n['created_at'])) ?></p></div><?php endforeach; ?><?php if ($notes === []) : ?><p class="muted">No notes.</p><?php endif; ?>
    <form method="post" action="<?= e($url) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="note"><div class="field"><label for="nb">Add a note</label><textarea id="nb" name="body" rows="2" required maxlength="3000"></textarea></div><div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form></div>
