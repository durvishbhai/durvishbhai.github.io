<?php /** @var array<string,mixed> $ticket */ /** @var string $role */ /** @var list<array<string,mixed>> $conversation */ /** @var list<array<string,mixed>> $activity */ /** @var list<array<string,mixed>> $initialFiles */ /** @var list<array<string,mixed>> $changes */
/** @var array<string,string> $moves */ /** @var bool $canDownload */ /** @var list<array<string,mixed>> $notes */ /** @var list<array<string,mixed>> $people */ /** @var array<string,mixed>|null $warranty */ /** @var array<string,mixed> $snapshot */
/** @var bool $canReply */ /** @var bool $canNote */ /** @var bool $canAssign */ /** @var bool $canEscalate */ /** @var bool $canSeverity */ /** @var bool $canPriority */ /** @var bool $canReview */ /** @var bool $canOverride */ /** @var bool $canPropose */ /** @var string $token */ /** @var string $limit */
use Vertex\Core\View;
use Vertex\Services\FieldRules;
$t = $ticket;
$base = '/tickets/' . $t['public_id'];
$bug = $t['kind'] === 'bug';
$v = static fn (string $k): string => old($k);
$considered = $t['considerations_json'] ? (json_decode((string) $t['considerations_json'], true) ?: []) : [];
?>
<div class="page-head"><div><p class="eyebrow"><?= $bug ? 'Bug report' : e(config("workflow.ticket_categories.{$t['category']}", $t['category'])) ?></p><h1><?= e($t['reference']) ?></h1></div><a class="btn btn-ghost" href="/tickets">&larr; Queue</a></div>
<div class="panel">
    <h2><?= e($t['subject']) ?><?= $t['escalated_at'] ? ' <span class="badge badge-prio-urgent">Escalated</span>' : '' ?></h2>
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><?= View::partial('ticket-status', ['s' => $t['status'], 'kind' => $t['kind']]) ?></div>
        <?php if ($bug) : ?><div><span class="muted">Severity (reported by client)</span><br><?= e(config("workflow.bug_severity_short.{$t['severity']}", $t['severity'])) ?></div><?php endif; ?>
        <div><span class="muted">Priority (internal)</span><br><?= e(config("workflow.priorities.{$t['priority']}", $t['priority'])) ?></div>
        <?php $sla = \Vertex\Services\SlaService::timers($t); if ($sla['first']['state'] !== 'none') : $fmt = static function (array $x): string { return match ($x['state']) { 'met' => 'met', 'late' => 'MISSED (due ' . local_time($x['due']) . ')', default => 'due ' . local_time($x['due']) . ' (' . ($x['minutes'] >= 60 ? intdiv($x['minutes'], 60) . ' h ' . ($x['minutes'] % 60) . ' min' : $x['minutes'] . ' min') . ' left)' }; }; ?>
        <div><span class="muted">SLA: first reply</span><br><?= e($fmt($sla['first'])) ?></div><div><span class="muted">SLA: resolution</span><br><?= e($fmt($sla['resolution'])) ?></div><?php endif; ?>
        <div><span class="muted">Assignee</span><br><?= e($t['assignee_name'] ?? 'Unassigned') ?></div>
        <div><span class="muted">Client</span><br><?= in_array($role, ['staff'], true) ? e($t['company_name'] ?: $t['client_name']) : '—' ?></div>
        <div><span class="muted">Project</span><br><?= $t['project_code'] ? '<a href="/projects/' . e($t['project_public_id']) . '">' . e($t['project_code']) . '</a>' : '—' ?></div>
        <div><span class="muted">Submitted</span><br><?= e(local_time($t['submitted_at'] ?? $t['created_at'])) ?></div>
        <div><span class="muted">Reopened</span><br><?= e($t['reopen_count']) ?> time(s)</div>
    </div>
    <h3>Description</h3><p class="prewrap"><?= e($t['description']) ?></p>
    <?php if ($bug) : ?><dl class="detail-list">
        <?php if ($t['affected_url']) : ?><dt>Affected page</dt><dd class="prewrap"><?= e($t['affected_url']) ?></dd><?php endif; ?>
        <dt>Steps to reproduce</dt><dd class="prewrap"><?= e($t['steps_to_reproduce']) ?></dd><dt>Expected</dt><dd class="prewrap"><?= e($t['expected_behaviour']) ?></dd><dt>Actual</dt><dd class="prewrap"><?= e($t['actual_behaviour']) ?></dd>
        <dt>Browser / device</dt><dd><?= e(trim(($t['browser_details'] ?? '') . ' · ' . ($t['device_details'] ?? ''), ' ·') ?: '—') ?></dd>
        <?php if ($t['captured_user_agent']) : ?><dt>Captured user agent</dt><dd class="muted"><?= e($t['captured_user_agent']) ?></dd><?php endif; ?>
    </dl><?php endif; ?>
    <?= View::partial('ticket-files', ['files' => $initialFiles, 'base' => $base, 'canDownload' => $canDownload]) ?>
    <?php if ($t['resolution']) : ?><h3>Resolution shown to the client</h3><p class="prewrap"><?= e($t['resolution']) ?></p><?php endif; ?>
</div>

<?php if ($bug) : ?>
<div class="panel"><h2>Warranty review <span class="badge badge-sensitive">internal</span></h2>
    <p>Warranty today: <strong><?= e($warranty['label'] ?? 'No project') ?></strong><?= !empty($warranty['row']['end_date']) ? ' · ' . e(local_date((string) $warranty['row']['start_date'])) . ' to ' . e(local_date((string) $warranty['row']['end_date'])) : '' ?>
        <?php if ($snapshot !== []) : ?><br><span class="muted">When reported: <?= e($snapshot['label'] ?? '') ?><?= !empty($snapshot['end_date']) ? ', period ' . e(local_date((string) ($snapshot['start_date'] ?? ''))) . ' to ' . e(local_date((string) $snapshot['end_date'])) : '' ?>, terms <?= isset($snapshot['terms_version']) ? 'v' . e($snapshot['terms_version']) : 'not recorded' ?></span><?php endif; ?></p>
    <p>Eligibility: <strong><?= e(config("workflow.bug_eligibility.{$t['eligibility']}", $t['eligibility'] ?? '—')) ?></strong><?= $t['eligibility_overridden'] ? ' <span class="badge badge-sensitive">override</span>' : '' ?>
        <?php if ($t['eligibility_reviewed_at']) : ?><span class="muted"> · decided <?= e(local_time($t['eligibility_reviewed_at'])) ?></span><?php endif; ?></p>
    <?php if ($t['eligibility_reason']) : ?><p class="prewrap"><span class="muted">Explanation given to the client:</span> <?= e($t['eligibility_reason']) ?></p><?php endif; ?>
    <?php if ($t['eligibility_terms']) : ?><p class="muted">Terms relied on: <?= e($t['eligibility_terms']) ?></p><?php endif; ?>
    <?php if ($considered !== []) : ?><p class="muted">Considered: <?= e(implode(', ', array_map(static fn ($k) => (string) config("workflow.bug_considerations.{$k}", $k), $considered))) ?></p><?php endif; ?>
    <p class="muted">An active warranty does not mean an issue is covered, and an expired one does not mean it is refused: decide from the agreement and say why.</p>
    <?php if ($canReview) : ?>
    <form method="post" action="<?= e($base) ?>/eligibility" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ed">Decision</label><select id="ed" name="decision"><?php foreach (['covered', 'not_covered', 'potentially_chargeable'] as $c) : ?><option value="<?= e($c) ?>"<?= $t['eligibility'] === $c ? ' selected' : '' ?>><?= e(config("workflow.bug_eligibility.{$c}")) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="er">Explanation for the client (required)</label><textarea id="er" name="reason" rows="3" required minlength="10" maxlength="3000"><?= e($v('reason')) ?></textarea></div>
        <div class="field"><label for="et">Warranty terms relied on</label><input id="et" name="terms" maxlength="255" value="<?= e($v('terms') ?: ($snapshot !== [] && isset($snapshot['terms_version']) ? 'Warranty terms v' . $snapshot['terms_version'] : '')) ?>"></div>
        <fieldset class="field"><legend>Considerations (recorded only; none excludes an issue automatically)</legend>
            <?php foreach (config('workflow.bug_considerations') as $c => $l) : ?><label class="check"><input type="checkbox" name="considerations[]" value="<?= e($c) ?>"<?= in_array($c, $considered, true) ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></fieldset>
        <div class="field"><label class="check"><input type="checkbox" name="quotation_required" value="1"<?= $t['quotation_required'] ? ' checked' : '' ?>> A quotation is required before work</label></div>
        <div class="field"><label for="ei">Internal note (optional)</label><textarea id="ei" name="internal_note" rows="2" maxlength="3000"><?= e($v('internal_note')) ?></textarea></div>
        <?php if ($canOverride) : ?><div class="field"><label for="epw">Your password (administrators: required to override)</label><input id="epw" name="confirm_password" type="password" autocomplete="current-password"></div><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Record decision</button></div></form>
    <?php endif; ?>
</div>
<?php if ($t['eligibility'] === 'potentially_chargeable') : ?>
<div class="panel"><h2>Additional cost proposal <span class="badge badge-sensitive">client approval required</span></h2>
    <?php if ($changes !== []) : ?><ul class="plain-list"><?php foreach ($changes as $c) : ?><li><a href="/changes/<?= e($c['public_id']) ?>"><?= e($c['reference']) ?></a> · ₹<?= e(FieldRules::formatMinor((int) $c['proposed_amount_minor'])) ?> <span class="badge"><?= e(status_label((string) $c['status'])) ?></span></li><?php endforeach; ?></ul>
    <?php else : ?><p class="muted">No proposal yet. Work on a chargeable issue cannot start until the client approves a proposal. Nothing is invoiced from here: the quotation and invoice modules are not part of this release, so the approved proposal is the record that those modules will pick up.</p><?php endif; ?>
    <?php if ($canPropose) : ?>
    <form method="post" action="<?= e($base) ?>/propose-change" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ps">Proposed additional scope</label><textarea id="ps" name="scope_description" rows="3" maxlength="3000"><?= e($v('scope_description')) ?></textarea></div>
        <div class="field"><label for="pr">Why this is chargeable (the client will read this)</label><textarea id="pr" name="client_reason" rows="3" maxlength="3000"><?= e($v('client_reason')) ?></textarea></div>
        <div class="field"><label for="pa">Proposed cost (₹)</label><input id="pa" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>"></div>
        <div class="field"><label for="pd">Extra days (optional)</label><input id="pd" name="days" inputmode="numeric" value="<?= e($v('days')) ?>"></div>
        <div class="field"><label for="pdu">Proposed delivery date (optional)</label><input id="pdu" name="due_date" type="date" value="<?= e($v('due_date')) ?>"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create draft proposal</button></div></form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($moves !== []) : ?>
<div class="panel"><h2>Move this <?= $bug ? 'report' : 'ticket' ?></h2>
    <form method="post" action="<?= e($base) ?>/status" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ms">New status</label><select id="ms" name="status"><?php foreach ($moves as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="mn">Explanation for the client (required for Need More Information, Unable to Reproduce, Rejected<?= $bug ? '' : ', Reopened' ?>)</label><textarea id="mn" name="note" rows="3" maxlength="3000"><?= e($v('note')) ?></textarea></div>
        <div class="field"><label for="mi">Internal note (optional)</label><textarea id="mi" name="internal_note" rows="2" maxlength="3000"><?= e($v('internal_note')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Update status</button></div></form></div>
<?php endif; ?>

<?php if ($canAssign || $canSeverity || $canPriority || $canEscalate) : ?>
<div class="panel"><h2>Assignment, severity and priority</h2>
    <?php if ($canAssign) : ?><form method="post" action="<?= e($base) ?>/assign" class="inline-form"><?= csrf_field() ?><label for="as" class="sr-only">Assignee</label>
        <select id="as" name="assignee"><option value="">Unassigned</option><?php foreach ($people as $u) : ?><option value="<?= e($u['public_id']) ?>"<?= $t['assignee_public_id'] === $u['public_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit">Assign</button></form><?php endif; ?>
    <?php if ($canSeverity) : ?><form method="post" action="<?= e($base) ?>/severity" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="sv">Severity (how serious the problem is)</label><select id="sv" name="severity"><?php foreach (config('workflow.bug_severity_short') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $t['severity'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="svr">Reason for the change (required)</label><input id="svr" name="reason" maxlength="1000"></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Change severity</button></div></form><?php endif; ?>
    <?php if ($canPriority) : ?><form method="post" action="<?= e($base) ?>/priority" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="pp">Priority (how urgently we schedule it; no response time is promised)</label><select id="pp" name="priority"><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $t['priority'] === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ppr">Reason (required)</label><input id="ppr" name="reason" maxlength="1000"></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Change priority</button></div></form><?php endif; ?>
    <?php if ($canEscalate) : ?><form method="post" action="<?= e($base) ?>/escalate" class="inline-form" data-confirm="Escalate this ticket?"><?= csrf_field() ?><label for="esr" class="sr-only">Reason</label><input id="esr" name="reason" maxlength="255" placeholder="Reason for escalation" required>
        <button class="btn btn-ghost btn-sm" type="submit">Escalate</button></form><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel"><h2>Conversation with the client</h2>
    <?= View::partial('ticket-thread', ['conversation' => $conversation, 'base' => $base, 'canDownload' => $canDownload, 'role' => $role]) ?>
    <?php if ($canReply) : ?>
    <form method="post" action="<?= e($base) ?>/reply" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="rb">Reply (the client will see this)</label><textarea id="rb" name="body" rows="4" required maxlength="5000"><?= e($v('body')) ?></textarea></div>
        <div class="field"><label for="rf">Attachments (optional, up to 3)</label><input id="rf" type="file" name="files[]" multiple><p class="hint">Up to <?= e($limit) ?> each.</p></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Send reply</button></div></form>
    <?php endif; ?>
</div>

<?php if ($canNote) : ?>
<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">staff only</span></h2>
    <?php foreach ($notes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= e($n['author_name'] ?? '') ?> · <?= e(local_time($n['created_at'])) ?> · <?= e(str_replace('_', ' ', $n['kind'])) ?></p></div><?php endforeach; ?>
    <?php if ($notes === []) : ?><p class="muted">No internal notes.</p><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/notes" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="nk">Type</label><select id="nk" name="kind"><option value="note">Note</option><option value="technical_finding">Technical finding</option><option value="decision">Decision</option></select></div>
        <div class="field"><label for="nb">Add an internal note</label><textarea id="nb" name="body" rows="3" required maxlength="5000"><?= e($v('note')) ?></textarea></div>
        <div class="field"><label for="nf">Internal file (optional, staff only)</label><input id="nf" type="file" name="file"></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form></div>
<?php endif; ?>
<div class="panel"><h2>History</h2><?= View::partial('ticket-activity', ['activity' => $activity, 'ticket' => $t, 'staff' => true]) ?></div>
