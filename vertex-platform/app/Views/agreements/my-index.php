<?php /** @var list<array<string,mixed>> $rows */ $lab = ['sent' => 'Waiting for your signature', 'partially_signed' => 'Waiting for the other party', 'executed' => 'Signed by both', 'cancelled' => 'Cancelled', 'expired' => 'Expired']; ?>
<p class="eyebrow">Documents</p><h1>My agreements</h1>
<div class="table-wrap"><table class="table"><thead><tr><th>Agreement</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><a href="/my/agreements/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><span class="muted"><?= e($r['title']) ?></span></td><td><?= e($lab[$r['status']] ?? $r['status']) ?></td><td><?= e(local_date(substr((string) ($r['executed_at'] ?? $r['sent_at'] ?? ''), 0, 10))) ?></td></tr><?php endforeach; ?>
<?php if ($rows === []) : ?><tr><td colspan="3" class="muted">You have no agreements yet.</td></tr><?php endif; ?></tbody></table></div>
