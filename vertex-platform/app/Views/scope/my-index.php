<?php /** @var list<array<string,mixed>> $rows */ $lab = ['submitted' => 'Received', 'assessing' => 'Being looked at', 'quoted' => 'We sent you a proposal', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn']; ?>
<p class="eyebrow">Projects</p><h1>My scope requests</h1>
<div class="table-wrap"><table class="table"><thead><tr><th>Request</th><th>Project</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><a href="/my/scope-requests/<?= e($r['public_id']) ?>"><strong><?= e($r['reference']) ?></strong></a><br><span class="muted"><?= e(mb_substr((string) $r['description'], 0, 90)) ?></span></td><td><?= e($r['project_code']) ?></td><td><?= e($lab[$r['status']] ?? $r['status']) ?></td></tr><?php endforeach; ?>
<?php if ($rows === []) : ?><tr><td colspan="3" class="muted">You have not asked for any changes. Open a project and choose &ldquo;Request a change&rdquo;.</td></tr><?php endif; ?></tbody></table></div>
