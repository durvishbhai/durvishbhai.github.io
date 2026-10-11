<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var int $days */
use Vertex\Core\View;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : ''; ?>
<div class="page-head"><div><p class="eyebrow">Support</p><h1>Warranties</h1></div><a class="btn btn-ghost" href="/support/dashboard">Dashboard</a></div>
<p class="muted">Status is worked out from the recorded start date and today's date in <?= e(config('app.timezone')) ?>. A project with no recorded start date is shown as Pending Confirmation; the system never guesses it.</p>
<form method="get" action="/warranties" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Project title or code" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="s">Status</label><select id="s" name="status"><option value="">All</option><?php foreach (config('workflow.warranty_statuses') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['status'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check"><input type="checkbox" name="expiring" value="1"<?= $filters['expiring'] ? ' checked' : '' ?>> Ending within <?= e($days) ?> days</label></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Project</th><th>Client</th><th>Status</th><th>Basis</th><th>Start</th><th>End</th><th>Quotation / agreement</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/projects/<?= e($r['public_id']) ?>/warranty"><strong><?= e($r['code']) ?></strong></a> · <?= e($r['title']) ?></td><td><?= e($r['company_name'] ?: $r['contact_name']) ?></td>
        <td><span class="badge badge-w-<?= e($r['wstatus']) ?>"><?= e(config("workflow.warranty_statuses.{$r['wstatus']}", $r['wstatus'])) ?></span></td>
        <td><?= $r['start_basis'] ? e(config("workflow.warranty_start_bases.{$r['start_basis']}", $r['start_basis'])) : '—' ?></td><td><?= e(local_date($r['start_date'])) ?></td><td><?= e(local_date($r['end_date'])) ?></td><td><?= e($r['quotation_ref'] ?? '—') ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="7" class="muted">No projects match.</td></tr><?php endif; ?>
</tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
<?php if (can('warranty.manage')) : ?>
<div class="panel"><h2>Record a new version of the warranty terms</h2>
    <p class="muted">Paste the wording of your signed warranty terms. Nothing is pre-filled: the platform does not assume what your agreement says. Existing projects keep pointing at the version they were recorded with.</p>
    <form method="post" action="/warranties/terms" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="wt">Title</label><input id="wt" name="title" required maxlength="160" value="<?= e(old('title')) ?>"></div>
        <div class="field"><label for="wx">Terms</label><textarea id="wx" name="terms" rows="6" required maxlength="10000"><?= e(old('terms')) ?></textarea></div>
        <div class="field"><label for="wm">Default duration in months (optional)</label><input id="wm" name="default_months" inputmode="numeric" value="<?= e(old('default_months')) ?>"></div>
        <div class="field"><label for="wr">Source (agreement or quotation reference, optional)</label><input id="wr" name="source_reference" maxlength="120" value="<?= e(old('source_reference')) ?>"></div>
        <div class="field"><label for="wpw">Your password</label><input id="wpw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Record terms</button></div></form></div>
<?php endif; ?>
