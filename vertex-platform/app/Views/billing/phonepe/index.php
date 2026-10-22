<?php /** @var list<array<string,mixed>> $rows */ /** @var array{ready:bool,reason:string} $ready */
$badge = ['pending' => 'pending', 'completed' => 'active', 'failed' => 'archived', 'expired' => 'archived', 'cancelled' => 'archived', 'created' => 'pending']; ?>
<div class="page-head"><div><p class="eyebrow">Money</p><h1>PhonePe payment requests</h1></div><a class="btn btn-ghost" href="/invoices">Choose an invoice</a></div>
<p class="muted">To collect a payment, open an invoice and press <strong>Request PhonePe payment</strong>, then type the amount.</p>
<?php if (!$ready['ready']) : ?><div class="alert alert-warn" role="alert"><p><?= e($ready['reason']) ?></p></div><?php endif; ?>
<?php if ($rows === []) : ?><p class="empty-state">No requests yet.</p><?php else : ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Created</th><th>Invoice</th><th>Client</th><th>Amount</th><th>Status</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) : $live = \Vertex\Services\PhonePeGateway::isLive($r); $s = $live ? 'open' : ($r['status'] === 'pending' ? 'expired' : $r['status']); ?>
    <tr><td><?= e(local_time((string) $r['created_at'])) ?></td><td><a href="/invoices/<?= e($r['invoice_pub']) ?>"><?= e($r['invoice_ref']) ?></a></td><td><?= e($r['client_name']) ?></td><td><?= e(money((int) $r['amount_minor'])) ?></td>
        <td><span class="badge badge-<?= e($badge[$r['status']] ?? 'pending') ?>"><?= e($s) ?></span></td><td><?= e((string) $r['by_name']) ?></td><td><a href="/phonepe-requests/<?= e($r['public_id']) ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
