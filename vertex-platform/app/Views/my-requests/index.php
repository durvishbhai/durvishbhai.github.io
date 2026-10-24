<?php /** @var list<array<string,mixed>> $rows */
$tone = ['review' => 'pending', 'project' => 'pending', 'confirmed' => 'active', 'closed' => 'archived']; ?>
<div class="page-head"><div><p class="eyebrow">Projects</p><h1>My project requests</h1></div><a class="btn btn-primary" href="/my/project-requests/new">Request a new project</a></div>
<p class="muted">Tell us about a new project with the requirements form. Our team reviews it, sends a quotation, and the project is <strong>confirmed automatically once the advance payment is received</strong>.</p>
<?php if ($rows === []) : ?><p class="empty-state">You have not sent a request yet. Press <strong>Request a new project</strong> to start.</p><?php else : ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Request</th><th>Sent</th><th>Where it stands</th></tr></thead><tbody>
<?php foreach ($rows as $r) : ?><tr><td><strong><?= e($r['name']) ?></strong><br><span class="muted"><?= e($r['reference']) ?></span></td><td><?= e(local_date(substr((string) $r['created_at'], 0, 10))) ?></td>
    <td><span class="badge badge-<?= e($tone[$r['state']] ?? 'pending') ?>"><?= e(['review' => 'In review', 'project' => 'Project created', 'confirmed' => 'Confirmed', 'closed' => 'Closed'][$r['state']] ?? 'In review') ?></span>
        <p class="muted small"><?= e($r['state_text']) ?><?php if ($r['project_public_id'] !== null && $r['state'] !== 'closed') : ?> <a href="/projects/<?= e($r['project_public_id']) ?>">Open the project</a><?php endif; ?></p></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
