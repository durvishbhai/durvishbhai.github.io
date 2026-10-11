<?php /** @var list<array<string,mixed>> $projects */ ?>
<p class="eyebrow">Projects</p>
<h1>Your projects</h1>
<p class="muted">Only projects you are assigned to, or that belong to you, are listed. Project creation and management arrive in a later phase.</p>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Project</th><th>Client</th><th>Status</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($projects as $p) : ?>
        <tr>
            <td><a href="/projects/<?= e($p['public_id']) ?>"><?= e($p['title']) ?></a></td>
            <td><?= e($p['company_name'] ?: $p['client_name']) ?></td>
            <td><span class="badge"><?= e(status_label((string) $p['status'])) ?></span></td>
            <td><?= e(local_time($p['created_at'], 'd M Y')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($projects === []) : ?><tr><td colspan="4" class="muted">No projects.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
