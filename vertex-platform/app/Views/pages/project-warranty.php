<?php /** @var array<string,mixed> $project */ /** @var string $tab */ /** @var array<string,mixed> $w */ /** @var array<string,?string> $hints */ /** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $terms */ /** @var bool $canManage */ /** @var int $expiringDays */
use Vertex\Core\View;
$row = $w['row'];
$v = static fn (string $k, string $d = ''): string => old($k, $d);
$base = '/projects/' . $project['public_id'] . '/warranty';
$hintLabels = ['project_completed' => 'Project marked completed', 'last_client_approval' => 'Last client approval', 'last_deliverable_published' => 'Last deliverable published'];
?>
<div class="page-head"><div><p class="eyebrow"><?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects/<?= e($project['public_id']) ?>">&larr; Project</a></div>
<?= View::partial('project-tabs', ['project' => $project, 'tab' => 'warranty']) ?>
<div class="panel"><h2>Warranty</h2>
    <div class="detail-grid">
        <div><span class="muted">Status</span><br><span class="badge badge-w-<?= e($w['status']) ?>"><?= e($w['label']) ?></span></div>
        <div><span class="muted">Duration</span><br><?= $row ? e($row['duration_months']) . ' month(s)' : 'Not recorded (default ' . e($w['default_months']) . ')' ?></div>
        <div><span class="muted">Start</span><br><?= e(local_date($row['start_date'] ?? null)) ?><?= !empty($row['start_basis']) ? '<br><span class="muted">' . e(config("workflow.warranty_start_bases.{$row['start_basis']}")) . '</span>' : '' ?></div>
        <div><span class="muted">Last covered day</span><br><?= e(local_date($row['end_date'] ?? null)) ?><?= $w['days_left'] !== null ? '<br><span class="muted">' . e($w['days_left']) . ' day(s) left</span>' : '' ?></div>
        <div><span class="muted">Quotation / agreement</span><br><?= e($row['quotation_ref'] ?? '—') ?></div>
        <div><span class="muted">Terms</span><br><?= !empty($row['terms_version']) ? 'v' . e($row['terms_version']) . ' · ' . e($row['terms_title']) : 'Not recorded' ?></div>
    </div>
    <?php if (!empty($row['suspended'])) : ?><p class="panel-warn">Suspended: <?= e($row['suspended_reason']) ?></p><?php endif; ?>
    <?php if ($w['status'] === 'pending_confirmation') : ?><p class="muted">The start date has not been confirmed, so no dates are shown to the client and no end date is calculated.</p><?php endif; ?>
    <p class="muted">Inside the period does not mean an issue is covered. Each bug report is reviewed against the agreement.</p>
    <?php if (!empty($row['project_terms'])) : ?><h3>Project-specific terms</h3><p class="prewrap"><?= e($row['project_terms']) ?></p><?php endif; ?>
    <?php if (!empty($row['admin_notes'])) : ?><h3>Admin notes <span class="badge badge-sensitive">internal</span></h3><p class="prewrap"><?= e($row['admin_notes']) ?></p><?php endif; ?>
</div>
<?php if ($canManage) : ?>
<div class="panel"><h2>Record or change the warranty</h2>
    <p class="muted">Facts that may help you choose the start date (suggestions only, never applied automatically):
        <?php foreach ($hintLabels as $k => $l) : ?><br><?= e($l) ?>: <?= e(local_date($hints[$k] ?? null)) ?><?php endforeach; ?></p>
    <form method="post" action="<?= e($base) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="we">Eligibility</label><select id="we" name="eligibility"><option value="eligible"<?= $v('eligibility', (string) ($row['eligibility'] ?? 'eligible')) === 'eligible' ? ' selected' : '' ?>>Eligible for a warranty</option><option value="not_applicable"<?= $v('eligibility', (string) ($row['eligibility'] ?? '')) === 'not_applicable' ? ' selected' : '' ?>>Not applicable</option></select></div>
        <div class="field"><label for="wm">Duration (months)</label><input id="wm" name="months" inputmode="numeric" value="<?= e($v('months', (string) ($row['duration_months'] ?? $w['default_months']))) ?>"></div>
        <div class="field"><label for="wb">Start date basis</label><select id="wb" name="start_basis"><option value="">Not confirmed yet</option><?php foreach (config('workflow.warranty_start_bases') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('start_basis', (string) ($row['start_basis'] ?? '')) === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ws">Start date</label><input id="ws" name="start_date" type="date" value="<?= e($v('start_date', (string) ($row['start_date'] ?? ''))) ?>"><p class="hint">Leave empty while it is not confirmed. The end date is calculated from the start date and duration.</p></div>
        <div class="field"><label for="wq">Related quotation or agreement</label><input id="wq" name="quotation_ref" maxlength="120" value="<?= e($v('quotation_ref', (string) ($row['quotation_ref'] ?? ''))) ?>"></div>
        <div class="field"><label for="wv">Terms version</label><select id="wv" name="terms_version"><option value="">None recorded</option><?php foreach ($terms as $tv) : ?><option value="<?= e($tv['version']) ?>"<?= $v('terms_version', (string) ($row['terms_version'] ?? '')) === (string) $tv['version'] ? ' selected' : '' ?>>v<?= e($tv['version']) ?> · <?= e($tv['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="wp">Project-specific terms (optional)</label><textarea id="wp" name="project_terms" rows="3" maxlength="5000"><?= e($v('project_terms', (string) ($row['project_terms'] ?? ''))) ?></textarea></div>
        <div class="field"><label for="wn">Admin notes (internal)</label><textarea id="wn" name="admin_notes" rows="3" maxlength="3000"><?= e($v('admin_notes', (string) ($row['admin_notes'] ?? ''))) ?></textarea></div>
        <div class="field"><label for="wr">Reason for this change<?= $row ? ' (required)' : '' ?></label><input id="wr" name="reason" maxlength="255" value="<?= e($v('reason')) ?>"></div>
        <div class="field"><label for="wpw">Your password</label><input id="wpw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save warranty</button></div></form>
    <?php if ($row) : ?>
    <form method="post" action="<?= e($base) ?>/suspend" class="form-grid" data-confirm="<?= empty($row['suspended']) ? 'Suspend this warranty?' : 'Resume this warranty?' ?>"><?= csrf_field() ?>
        <div class="field"><label for="sr"><?= empty($row['suspended']) ? 'Suspend (only where the agreement allows)' : 'Resume' ?>: reason</label><input id="sr" name="reason" maxlength="255" required></div>
        <div class="field"><label for="spw">Your password</label><input id="spw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit" name="action" value="<?= empty($row['suspended']) ? 'suspend' : 'resume' ?>"><?= empty($row['suspended']) ? 'Suspend warranty' : 'Resume warranty' ?></button></div></form>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="panel"><h2>History</h2>
    <?php foreach ($history as $h) : $b = json_decode((string) $h['before_json'], true) ?: []; $a = json_decode((string) $h['after_json'], true) ?: []; ?>
        <div class="note"><p><strong><?= e(ucfirst((string) $h['event'])) ?></strong> <span class="muted"><?= e(local_time($h['created_at'])) ?> · <?= e($h['actor_name'] ?? '') ?></span></p>
            <?php foreach ($a as $k => $val) : if (($b[$k] ?? null) !== $val) : ?><p class="muted"><?= e(str_replace('_', ' ', $k)) ?>: <?= e(is_scalar($b[$k] ?? null) ? (string) $b[$k] : '—') ?> → <?= e(is_scalar($val) ? (string) $val : '—') ?></p><?php endif; endforeach; ?>
            <?php if ($h['reason']) : ?><p class="prewrap"><?= e($h['reason']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($history === []) : ?><p class="muted">No warranty changes recorded.</p><?php endif; ?></div>
