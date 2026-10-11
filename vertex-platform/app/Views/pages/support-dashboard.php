<?php /** @var array<string,int|null> $c */ /** @var list<array<string,mixed>> $recent */ /** @var bool $slaOn */ /** @var array<string,int>|null $warranty */ /** @var int $expiringDays */
use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Support</p><h1>Support dashboard</h1></div><div class="action-row"><a class="btn btn-ghost" href="/tickets">All tickets</a><?php if ($warranty !== null) : ?><a class="btn btn-ghost" href="/warranties">Warranties</a><?php endif; ?></div></div>
<div class="stat-grid">
    <a class="stat" href="/tickets?group=open"><span class="stat-n"><?= e($c['open']) ?></span><span class="stat-l">Open</span></a>
    <a class="stat" href="/tickets?group=unassigned"><span class="stat-n"><?= e($c['unassigned']) ?></span><span class="stat-l">Unassigned</span></a>
    <a class="stat" href="/tickets?group=high_critical"><span class="stat-n"><?= e($c['high_critical']) ?></span><span class="stat-l">High or critical</span></a>
    <a class="stat" href="/tickets?group=awaiting_client"><span class="stat-n"><?= e($c['awaiting_client']) ?></span><span class="stat-l">Awaiting client</span></a>
    <a class="stat" href="/tickets?group=awaiting_internal"><span class="stat-n"><?= e($c['awaiting_internal']) ?></span><span class="stat-l">Awaiting internal team</span></a>
    <a class="stat" href="/tickets?group=fixed_verification"><span class="stat-n"><?= e($c['fixed_verification']) ?></span><span class="stat-l">Fixed, awaiting verification</span></a>
    <a class="stat" href="/tickets?group=open&amp;escalated=1"><span class="stat-n"><?= e($c['escalated']) ?></span><span class="stat-l">Escalated</span></a>
    <?php if ($slaOn && $c['overdue'] !== null) : ?><a class="stat" href="/tickets?group=overdue"><span class="stat-n"><?= e($c['overdue']) ?></span><span class="stat-l">Past first-response target</span></a><?php endif; ?>
</div>
<?php if (!$slaOn) : ?><p class="muted">No response-time target is configured, so no ticket is shown as overdue and none is promised to clients.</p><?php endif; ?>
<?php if ($warranty !== null) : ?>
<div class="panel"><h2>Website warranties</h2>
    <div class="stat-grid">
        <a class="stat" href="/warranties?status=active"><span class="stat-n"><?= e($warranty['active']) ?></span><span class="stat-l">Active</span></a>
        <a class="stat" href="/warranties?expiring=1"><span class="stat-n"><?= e($warranty['expiring']) ?></span><span class="stat-l">Ending within <?= e($expiringDays) ?> days</span></a>
        <a class="stat" href="/warranties?status=expired"><span class="stat-n"><?= e($warranty['expired']) ?></span><span class="stat-l">Expired</span></a>
        <a class="stat" href="/warranties?status=pending_confirmation"><span class="stat-n"><?= e($warranty['pending']) ?></span><span class="stat-l">Start date not confirmed</span></a>
    </div></div>
<?php endif; ?>
<div class="panel"><h2>Most recently active open tickets</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Ticket</th><th>Status</th><th>Severity</th><th>Assignee</th><th>Updated</th></tr></thead><tbody>
        <?php foreach ($recent as $r) : ?><tr><td><a href="/tickets/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><?= e($r['subject']) ?></td><td><?= View::partial('ticket-status', ['s' => $r['status'], 'kind' => $r['kind']]) ?></td>
            <td><?= $r['severity'] ? e(config("workflow.bug_severity_short.{$r['severity']}", $r['severity'])) : '—' ?></td><td><?= e($r['assignee_name'] ?? 'Unassigned') ?></td><td><?= e(local_time($r['updated_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
        <?php if ($recent === []) : ?><tr><td colspan="5" class="muted">Nothing open.</td></tr><?php endif; ?></tbody></table></div></div>
