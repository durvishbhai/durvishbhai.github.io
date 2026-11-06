<?php /** @var array<string,mixed> $c */ /** @var string $role */ /** @var array<string,mixed> $base */ /** @var bool $canDecide */ /** @var bool $canStaff */
use Vertex\Services\FieldRules;
$money = static fn (int $m): string => '₹' . FieldRules::formatMinor($m);
$staff = $role === 'staff';
$url = '/changes/' . $c['public_id'];
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<div class="page-head"><div><p class="eyebrow">Additional work proposal</p><h1><?= e($c['reference']) ?></h1></div>
    <?php if ($c['revision_public_id']) : ?><a class="btn btn-ghost" href="/revisions/<?= e($c['revision_public_id']) ?>">&larr; <?= e($c['revision_reference']) ?></a><?php endif; ?>
    <?php if ($c['ticket_public_id']) : ?><a class="btn btn-ghost" href="/tickets/<?= e($c['ticket_public_id']) ?>">&larr; <?= e($c['ticket_reference']) ?></a><?php endif; ?></div>
<div class="panel<?= $c['status'] === 'pending_client' ? ' panel-attention' : '' ?>">
    <p>Status: <span class="badge badge-pr-<?= e($c['status']) ?>"><?= e(status_label((string) $c['status'])) ?></span></p>
    <h2><?= e($c['title']) ?></h2>
    <dl class="detail-list">
        <dt>Proposed additional scope</dt><dd class="prewrap"><?= e($c['scope_description']) ?></dd>
        <dt>Why it is additional</dt><dd class="prewrap"><?= e($c['reason']) ?></dd>
        <dt>Proposed extra cost</dt><dd><strong><?= e($money((int) $c['proposed_amount_minor'])) ?></strong> <span class="muted"><?= e($c['currency']) ?></span></dd>
        <dt>Timeline</dt><dd><?= $c['proposed_days'] !== null ? e($c['proposed_days']) . ' extra day(s)' : '' ?><?= $c['proposed_due_date'] ? ($c['proposed_days'] !== null ? ' · ' : '') . 'delivery by ' . e($c['proposed_due_date']) : '' ?><?= $c['proposed_days'] === null && !$c['proposed_due_date'] ? 'Not specified' : '' ?></dd>
        <dt>Your original agreement</dt><dd><?= !empty($base['quotation_ref']) ? e($base['quotation_ref']) : 'Not recorded' ?><?= isset($base['approved_total_minor']) ? ' · approved total ' . e($money((int) $base['approved_total_minor'])) : '' ?><br><span class="muted">The original scope and approved price are not changed by this proposal.</span></dd>
        <?php if ($c['sent_at']) : ?><dt>Sent</dt><dd><?= e(local_time($c['sent_at'])) ?></dd><?php endif; ?>
        <?php if ($c['decided_at']) : ?><dt>Decided</dt><dd><?= e(local_time($c['decided_at'])) ?><?= $c['decision_note'] ? ' · ' . e($c['decision_note']) : '' ?></dd><?php endif; ?>
        <?php if ($staff && !empty($base['approved_additional_work_minor'])) : ?><dt>Approved additional work so far</dt><dd><?= e($money((int) $base['approved_additional_work_minor'])) ?></dd><?php endif; ?>
    </dl>
    <p class="muted">Nothing is charged or invoiced by approving this page. Approval records your consent to this scope, price and timeline; invoicing will follow the normal quotation and invoice process.</p>

    <?php if ($canDecide) : ?>
        <form method="post" action="<?= e($url) ?>/decision" class="form-grid" data-confirm="Record your decision?"><?= csrf_field() ?>
            <div class="field"><label for="dc">Comment (optional)</label><input id="dc" name="comment" maxlength="255"></div>
            <div class="form-actions"><button class="btn btn-primary" name="decision" value="approve" type="submit">Approve this additional work</button>
                <button class="btn btn-ghost" name="decision" value="reject" type="submit">Decline</button></div></form>
    <?php endif; ?>
    <?php if ($canStaff && in_array($c['status'], ['draft', 'pending_client'], true)) : ?>
        <div class="action-row">
            <?php if ($c['status'] === 'draft') : ?><form method="post" action="<?= e($url) ?>/send" class="inline-form" data-confirm="Send this proposal to the client? They will be asked to approve it."><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Send to client</button></form><?php endif; ?>
            <form method="post" action="<?= e($url) ?>/withdraw" class="inline-form" data-confirm="Withdraw this proposal?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Withdraw</button></form></div>
    <?php endif; ?>
</div>
<?php if ($canStaff && $c['status'] === 'draft') : ?>
<div class="panel"><h2>Edit the draft</h2>
    <form method="post" action="<?= e($url) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="es">Proposed additional scope</label><textarea id="es" name="scope_description" rows="3" maxlength="3000"><?= e($v('scope_description', (string) $c['scope_description'])) ?></textarea></div>
        <div class="field"><label for="er">Why it is additional (client-facing)</label><textarea id="er" name="client_reason" rows="3" maxlength="3000"><?= e($v('client_reason', (string) $c['reason'])) ?></textarea></div>
        <div class="field"><label for="ea">Proposed extra cost (₹)</label><input id="ea" name="amount" inputmode="decimal" value="<?= e($v('amount', number_format((int) $c['proposed_amount_minor'] / 100, 2, '.', ''))) ?>"></div>
        <div class="field"><label for="ed">Extra days</label><input id="ed" name="days" inputmode="numeric" value="<?= e($v('days', (string) ($c['proposed_days'] ?? ''))) ?>"></div>
        <div class="field"><label for="edu">Proposed delivery date</label><input id="edu" name="due_date" type="date" value="<?= e($v('due_date', (string) ($c['proposed_due_date'] ?? ''))) ?>"></div>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Save draft</button></div></form></div>
<?php endif; ?>
