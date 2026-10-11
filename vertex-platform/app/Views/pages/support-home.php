<?php /** @var array{rows:list<array<string,mixed>>,total:int} $open */ /** @var int $resolvedTotal */ /** @var list<array<string,mixed>> $drafts */ /** @var list<array<string,mixed>> $warranty */ /** @var int $unread */ /** @var bool $canBug */ /** @var bool $canTicket */
use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Support</p><h1>Support Centre</h1></div>
    <div class="action-row"><?php if ($canBug) : ?><a class="btn btn-primary" href="/support/bugs/new">Report a bug</a><?php endif; ?><?php if ($canTicket) : ?><a class="btn btn-ghost" href="/support/tickets/new">Create a ticket</a><?php endif; ?></div></div>
<div class="stat-grid">
    <a class="stat" href="/tickets?group=open"><span class="stat-n"><?= e($open['total']) ?></span><span class="stat-l">Open tickets</span></a>
    <a class="stat" href="/tickets?group=resolved"><span class="stat-n"><?= e($resolvedTotal) ?></span><span class="stat-l">Resolved tickets</span></a>
    <div class="stat"><span class="stat-n"><?= e($unread) ?></span><span class="stat-l">Unread replies</span></div>
</div>
<?php if ($drafts !== []) : ?><div class="panel"><h2>Your drafts</h2><ul class="plain-list"><?php foreach ($drafts as $d) : ?><li><a href="/tickets/<?= e($d['public_id']) ?>"><?= e($d['reference']) ?></a> · <?= e($d['subject']) ?> <span class="badge">Draft</span></li><?php endforeach; ?></ul>
    <p class="muted">Drafts are visible only to you until you submit them.</p></div><?php endif; ?>
<div class="panel"><h2>Your open tickets</h2>
    <?php if ($open['rows'] === []) : ?><p class="muted">You have no open tickets.</p><?php else : ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Ticket</th><th>Type</th><th>Status</th><th>Updated</th></tr></thead><tbody>
        <?php foreach ($open['rows'] as $r) : ?><tr><td><a href="/tickets/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><?= e($r['subject']) ?><?= $r['unread'] > 0 ? ' <span class="badge badge-pending">New reply</span>' : '' ?></td>
            <td><?= e(config("workflow.ticket_categories.{$r['category']}", $r['category'])) ?></td><td><?= View::partial('ticket-status', ['s' => $r['status'], 'kind' => $r['kind']]) ?></td><td><?= e(local_time($r['updated_at'], 'd M Y')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <p><a href="/tickets">All my tickets</a></p></div>
<div class="panel"><h2>Website warranty</h2>
    <?php if ($warranty === []) : ?><p class="muted">You have no projects yet.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($warranty as $w) : ?><li><strong><?= e($w['project']['title']) ?></strong>: <?= e($w['label']) ?><?= $w['end_date'] ? ' · covered period ends ' . e(local_date((string) $w['end_date'])) : '' ?></li><?php endforeach; ?></ul>
    <p><a href="/support/warranty">Warranty details</a></p></div>
