<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */
use Vertex\Core\View;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : ''; ?>
<div class="page-head"><div><p class="eyebrow">Support Centre</p><h1>My tickets</h1></div><div class="action-row"><a class="btn btn-primary" href="/support/bugs/new">Report a bug</a><a class="btn btn-ghost" href="/support/tickets/new">Create a ticket</a></div></div>
<nav class="tabs" aria-label="Ticket groups"><a href="/tickets"<?= $filters['group'] === '' ? ' aria-current="page"' : '' ?>>All</a><a href="/tickets?group=open"<?= $filters['group'] === 'open' ? ' aria-current="page"' : '' ?>>Open</a><a href="/tickets?group=resolved"<?= $filters['group'] === 'resolved' ? ' aria-current="page"' : '' ?>>Resolved</a><a href="/tickets?group=draft"<?= $filters['group'] === 'draft' ? ' aria-current="page"' : '' ?>>Drafts</a></nav>
<form method="get" action="/tickets" class="toolbar" role="search"><input type="hidden" name="group" value="<?= e($filters['group']) ?>">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Reference or subject" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="k">Type</label><select id="k" name="kind"><option value="">All</option><option value="bug"<?= $sel($filters['kind'], 'bug') ?>>Bug reports</option><option value="support"<?= $sel($filters['kind'], 'support') ?>>Support tickets</option></select></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Ticket</th><th>Type</th><th>Project</th><th>Status</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/tickets/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><?= e($r['subject']) ?><?= $r['unread'] > 0 ? ' <span class="badge badge-pending">New reply</span>' : '' ?></td>
        <td><?= e(config("workflow.ticket_categories.{$r['category']}", $r['category'])) ?></td><td><?= e($r['project_code'] ?? '—') ?></td>
        <td><?= View::partial('ticket-status', ['s' => $r['status'], 'kind' => $r['kind']]) ?></td><td><?= e(local_time($r['updated_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="5" class="muted">No tickets match.</td></tr><?php endif; ?>
</tbody></table></div>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
