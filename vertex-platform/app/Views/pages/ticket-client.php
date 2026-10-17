<?php /** @var array<string,mixed> $ticket */ /** @var list<array<string,mixed>> $conversation */ /** @var list<array<string,mixed>> $activity */ /** @var list<array<string,mixed>> $initialFiles */ /** @var list<array<string,mixed>> $changes */
/** @var array<string,string> $moves */ /** @var bool $canDownload */ /** @var bool $canReply */ /** @var array<string,mixed>|null $review */ /** @var array<string,mixed>|null $warranty */ /** @var string $token */ /** @var string $limit */
use Vertex\Core\View;
use Vertex\Services\FieldRules;
$t = $ticket;
$base = '/tickets/' . $t['public_id'];
$bug = $t['kind'] === 'bug';
$v = static fn (string $k): string => old($k);
?>
<div class="page-head"><div><p class="eyebrow"><?= $bug ? 'Bug report' : e(config("workflow.ticket_categories.{$t['category']}", $t['category'])) ?></p><h1><?= e($t['reference']) ?></h1></div><a class="btn btn-ghost" href="/tickets">&larr; My tickets</a></div>
<div class="panel">
    <h2><?= e($t['subject']) ?></h2>
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><?= View::partial('ticket-status', ['s' => $t['status'], 'kind' => $t['kind']]) ?></div>
        <div><span class="muted">Submitted</span><br><?= e(local_time($t['submitted_at'] ?? $t['created_at'])) ?></div>
        <?php if ($t['project_code']) : ?><div><span class="muted">Project</span><br><a href="/projects/<?= e($t['project_public_id']) ?>"><?= e($t['project_code']) ?> · <?= e($t['project_title']) ?></a></div><?php endif; ?>
        <?php if ($bug) : ?><div><span class="muted">Severity (your assessment, may be adjusted)</span><br><?= e(config("workflow.bug_severity_short.{$t['severity']}", $t['severity'])) ?></div><?php endif; ?>
    </div>
    <h3>What you told us</h3><p class="prewrap"><?= e($t['description']) ?></p>
    <?php if ($bug) : ?><dl class="detail-list">
        <?php if ($t['affected_url']) : ?><dt>Affected page</dt><dd class="prewrap"><?= e($t['affected_url']) ?></dd><?php endif; ?>
        <dt>Steps to reproduce</dt><dd class="prewrap"><?= e($t['steps_to_reproduce']) ?></dd>
        <dt>Expected</dt><dd class="prewrap"><?= e($t['expected_behaviour']) ?></dd><dt>Actual</dt><dd class="prewrap"><?= e($t['actual_behaviour']) ?></dd>
        <?php if ($t['browser_details']) : ?><dt>Browser</dt><dd><?= e($t['browser_details']) ?></dd><?php endif; ?><?php if ($t['device_details']) : ?><dt>Device</dt><dd><?= e($t['device_details']) ?></dd><?php endif; ?>
    </dl><?php endif; ?>
    <?= View::partial('ticket-files', ['files' => $initialFiles, 'base' => $base, 'canDownload' => $canDownload]) ?>
    <?php if ($t['resolution'] && in_array($t['status'], ['resolved', 'closed', 'fixed', 'client_verification', 'rejected', 'unable_to_reproduce'], true)) : ?><h3>Resolution</h3><p class="prewrap"><?= e($t['resolution']) ?></p><?php endif; ?>
</div>

<?php if ($bug && $review !== null) : ?>
<div class="panel"><h2>Warranty review</h2>
    <?php if ($warranty !== null) : ?><p>Your website warranty when this was reported: <strong><?= e($warranty['label'] ?? '') ?></strong><?= !empty($warranty['end_date']) ? ' · covered period ends ' . e(local_date((string) $warranty['end_date'])) : '' ?>.</p><?php endif; ?>
    <?php if ($review['decided']) : ?>
        <p>Outcome: <strong><?= e($review['label']) ?></strong> (<?= e(local_time($review['reviewed_at'], 'd M Y')) ?>)</p>
        <p class="prewrap"><?= e($review['reason']) ?></p>
        <?php if ($review['terms'] !== '') : ?><p class="muted">Terms relied on: <?= e($review['terms']) ?></p><?php endif; ?>
        <?php if ($review['quotation_required']) : ?><p class="muted">Because this may be outside your warranty, any additional work will be quoted first and will only go ahead if you approve it.</p><?php endif; ?>
    <?php else : ?>
        <p><?= e($review['label'] ?: 'Awaiting review') ?>. Being reported does not automatically mean it is covered or not covered: our team reviews every report against your agreement and will explain the decision here.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($changes !== []) : ?><div class="panel"><h2>Additional work proposals</h2><ul class="plain-list"><?php foreach ($changes as $c) : ?>
    <li><a href="/changes/<?= e($c['public_id']) ?>"><?= e($c['reference']) ?></a> · ₹<?= e(FieldRules::formatMinor((int) $c['proposed_amount_minor'])) ?> <span class="badge"><?= e(status_label((string) $c['status'])) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($moves !== []) : ?>
<div class="panel<?= $t['status'] === 'client_verification' ? ' panel-attention' : '' ?>"><h2><?= $t['status'] === 'client_verification' ? 'Please check the fix' : 'Update this ' . ($bug ? 'report' : 'ticket') ?></h2>
    <?php if ($t['status'] === 'client_verification') : ?><p>Our team marked this as fixed. Nothing is closed until you confirm. If it still does not work, tell us what you see and reopen it.</p><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/status" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="ms">What would you like to do?</label><select id="ms" name="status"><?php foreach ($moves as $c => $l) : ?><option value="<?= e($c) ?>"><?= e(match (true) {
            $c === 'closed' => 'Confirm it is fixed and close',
            in_array($c, ['open', 'reopened'], true) => 'Reopen this ' . ($bug ? 'report' : 'ticket'),
            default => $l }) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="mn">Explanation (required when reopening: tell us what you still see)</label><textarea id="mn" name="note" rows="3" maxlength="3000"><?= e($v('note')) ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Send</button></div></form></div>
<?php endif; ?>

<div class="panel"><h2>Conversation</h2>
    <?= View::partial('ticket-thread', ['conversation' => $conversation, 'base' => $base, 'canDownload' => $canDownload, 'role' => 'client']) ?>
    <?php if ($canReply) : ?>
    <form method="post" action="<?= e($base) ?>/reply" enctype="multipart/form-data" class="form-grid" novalidate data-busy="Sending…"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="rb">Your message</label><textarea id="rb" name="body" rows="4" required maxlength="5000"><?= e($v('body')) ?></textarea></div>
        <div class="field"><label for="rf">Attachments (optional, up to 3)</label><input id="rf" type="file" name="files[]" multiple><p class="hint">Up to <?= e($limit) ?> each.</p></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Send message</button></div></form>
    <?php else : ?><p class="muted">This <?= $bug ? 'report' : 'ticket' ?> is closed<?= $moves !== [] ? '. You can reopen it above.' : '.' ?></p><?php endif; ?>
</div>
<div class="panel"><h2>History</h2><?= View::partial('ticket-activity', ['activity' => $activity, 'ticket' => $t, 'staff' => false]) ?></div>
