<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="page-head"><div><p class="eyebrow">Billing</p><h1>My receipts</h1></div></div>
<?php if ($rows === []) : ?><p class="empty-state">You have no receipts yet. One appears here as soon as a payment of yours is confirmed.</p><?php else : ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Receipt</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><a href="/my/receipt/<?= e($r['public_id']) ?>"><?= e($r['number']) ?></a></td><td><?= e($r['paidDate']) ?></td><td class="num"><?= e(money($r['paidMinor'])) ?></td><td><?= e($r['methodLabel']) ?></td>
    <td><span class="badge badge-<?= $r['effective'] === 'valid' ? 'active' : 'archived' ?>"><?= e($r['effective']) ?></span></td><td><a href="/my/receipt/<?= e($r['public_id']) ?>/pdf">PDF</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
