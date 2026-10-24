<?php /** @var list<array<string,mixed>> $forms */ /** @var bool $canManage */ $st = ['draft' => 'Draft', 'published' => 'Live', 'closed' => 'Closed']; ?>
<div class="page-head"><div><p class="eyebrow">Website</p><h1>Forms</h1></div><?php if ($canManage) : ?><div class="actions"><a class="btn btn-primary" href="/forms/new">New form</a></div><?php endif; ?></div>
<p class="muted">Build forms with your own questions, validation and conditional questions. Published forms are public at <code>/f/&lt;address&gt;</code> (or by secret link, or for signed-in people only).</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Form</th><th>Address</th><th>Access</th><th>Status</th><th>Answers</th></tr></thead><tbody>
<?php foreach ($forms as $f) : ?><tr><td><a href="/forms/<?= e($f['public_id']) ?>"><strong><?= e($f['title']) ?></strong></a></td><td><code>/f/<?= e($f['slug']) ?></code></td><td><?= e(config("forms.access.{$f['access']}", $f['access'])) ?></td><td><?= e($st[$f['status']] ?? $f['status']) ?></td>
    <td><a href="/forms/<?= e($f['public_id']) ?>/submissions"><?= (int) $f['submissions'] ?></a><?= (int) $f['new_submissions'] > 0 ? ' <span class="badge">' . (int) $f['new_submissions'] . ' new</span>' : '' ?></td></tr><?php endforeach; ?>
<?php if ($forms === []) : ?><tr><td colspan="5" class="muted">No forms yet.</td></tr><?php endif; ?></tbody></table></div>
