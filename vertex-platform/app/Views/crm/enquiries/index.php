<?php
/** @var list<array<string,mixed>> $rows */
/** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,mixed> $filters */ /** @var array<string,string> $qs */
/** @var list<array<string,mixed>> $stages */ /** @var list<array<string,mixed>> $owners */
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head">
    <div><p class="eyebrow">CRM</p><h1>Enquiries</h1></div>
    <div class="action-row">
        <a class="btn btn-ghost" href="/crm">Dashboard</a>
        <?php if (can('enquiries.create')) : ?><a class="btn btn-primary" href="/crm/enquiries/create">New enquiry</a><?php endif; ?>
    </div>
</div>
<form method="get" action="/crm/enquiries" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Reference, name, email, company, phone" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="stage">Stage</label><select id="stage" name="stage"><option value="">All</option><?php foreach ($stages as $s) : ?><option value="<?= e($s['code']) ?>"<?= $sel($filters['stage'], $s['code']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="source">Source</label><select id="source" name="source"><option value="">All</option><?php foreach (config('workflow.sources') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['source'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><option value="">All</option><?php foreach (config('workflow.priorities') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['priority'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="service">Service</label><select id="service" name="service"><option value="">All</option><?php foreach (config('workflow.services') as $c => $l) : ?><option value="<?= e($c) ?>"<?= $sel($filters['service'], $c) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="owner">Owner</label><select id="owner" name="owner"><option value="">Anyone</option><option value="me"<?= $sel($filters['owner'], 'me') ?>>Me</option><option value="none"<?= $sel($filters['owner'], 'none') ?>>Unassigned</option></select></div>
    <div class="field"><label for="followup">Follow-up</label><select id="followup" name="followup"><option value="">Any</option><option value="overdue"<?= $sel($filters['followup'], 'overdue') ?>>Overdue</option><option value="due"<?= $sel($filters['followup'], 'due') ?>>Due or overdue</option></select></div>
    <?php if (can('enquiries.view_all')) : ?><div class="field"><label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label></div><?php endif; ?>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Reference</th><th>Contact</th><th>Service</th><th>Stage</th><th>Priority</th><th>Owner</th><th>Follow-up</th><th>Received</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : $overdue = $r['next_followup_date'] !== null && $r['stage_kind'] === 'open' && $r['next_followup_date'] < \Vertex\Services\AppTime::today(); ?>
        <tr>
            <td><a href="/crm/enquiries/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><?= $r['duplicate_status'] === 'suspected' ? ' <span class="badge badge-pending" title="Possible duplicate">dup?</span>' : '' ?></td>
            <td><?= e($r['contact_name']) ?><br><span class="muted"><?= e($r['company_name'] ?: $r['email']) ?></span></td>
            <td><?= e(config("workflow.services.{$r['service']}", $r['service'])) ?></td>
            <td><span class="badge badge-stage-<?= e($r['stage_kind']) ?>"><?= e($r['stage_name']) ?></span></td>
            <td><span class="badge badge-prio-<?= e($r['priority']) ?>"><?= e(status_label((string) $r['priority'])) ?></span></td>
            <td><?= e($r['owner_name'] ?? '—') ?></td>
            <td><?= $r['next_followup_date'] ? '<span' . ($overdue ? ' class="overdue"' : '') . '>' . e($r['next_followup_date']) . ($overdue ? ' (overdue)' : '') . '</span>' : '—' ?></td>
            <td><?= e(local_time($r['created_at'], 'd M Y')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="8" class="muted">No enquiries match<?= can('enquiries.view_all') ? '' : ' (you only see enquiries assigned to you)' ?>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
