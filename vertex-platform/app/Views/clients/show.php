<?php
/** @var array<string,mixed> $c */ /** @var string $level */
/** @var list<array<string,mixed>> $projects */ /** @var list<array<string,mixed>> $enquiries */ /** @var list<array<string,mixed>> $notes */
/** @var bool $canNotes */ /** @var list<array<string,mixed>> $history */ /** @var list<array<string,mixed>> $mergeTargets */ /** @var bool $mailEnabled */
/** @var array<string,mixed>|null $mergedInto */
$u = '/clients/' . $c['public_id'];
$staff = in_array($level, ['all', 'assigned'], true);
$values = [];
foreach (['client_type', 'contact_name', 'email', 'phone', 'company_name', 'website', 'tax_id', 'address'] as $f) { $values[$f] = old($f, (string) ($c[$f] ?? '')); }
?>
<div class="page-head"><div><p class="eyebrow"><?= $staff ? 'Client' : 'My company' ?></p><h1><?= e($c['company_name'] ?: $c['contact_name']) ?></h1></div>
    <?php if ($staff && can('clients.view_assigned') || can('clients.view_all')) : ?><a class="btn btn-ghost" href="/clients">&larr; All clients</a><?php endif; ?></div>

<?php if ($mergedInto) : ?><div class="alert alert-info"><p>This record was merged into <a href="/clients/<?= e($mergedInto['public_id']) ?>"><?= e($mergedInto['contact_name']) ?></a> and is archived.</p></div><?php endif; ?>
<?php if ($c['status'] === 'archived' && !$mergedInto) : ?><div class="alert alert-info"><p>This client is archived.</p></div><?php endif; ?>

<div class="grid-2">
<div class="panel"><h2>Details</h2>
    <dl class="kv">
        <dt>Type</dt><dd><?= e(status_label((string) $c['client_type'])) ?></dd>
        <dt>Contact</dt><dd><?= e($c['contact_name']) ?></dd>
        <dt>Email</dt><dd><?= e($c['email']) ?></dd>
        <dt>Phone</dt><dd><?= e($c['phone'] ?? '—') ?></dd>
        <dt>Company</dt><dd><?= e($c['company_name'] ?? '—') ?></dd>
        <dt>Website</dt><dd><?= e($c['website'] ?? '—') ?></dd>
        <dt>Tax / GST no.</dt><dd><?= e($c['tax_id'] ?? '—') ?></dd>
        <dt>Address</dt><dd><?= e($c['address'] ?? '—') ?></dd>
        <dt>Status</dt><dd><span class="badge badge-<?= e($c['status'] === 'active' ? 'active' : 'pending') ?>"><?= e(status_label((string) $c['status'])) ?></span></dd>
        <dt>Portal login</dt><dd><?= $c['user_id'] ? 'Yes' : 'No' ?></dd>
        <?php if ($staff) : ?><dt>Created</dt><dd><?= e(local_time($c['created_at'])) ?></dd><dt>Last modified</dt><dd><?= e(local_time($c['updated_at'])) ?></dd><?php endif; ?>
    </dl>
    <?php if (!$staff) : ?><p class="muted">To change your details, please contact us.</p><?php endif; ?>
</div>

<div>
<?php if ($staff && can('clients.edit') && $c['status'] !== 'archived') : ?>
<div class="panel"><h2>Status</h2>
    <form method="post" action="<?= e($u) ?>/status" class="inline-form"><?= csrf_field() ?>
        <select name="status" aria-label="Status"><option value="active"<?= $c['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $c['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option></select>
        <button class="btn btn-ghost btn-sm" type="submit">Save</button></form>
    <?php if (can('clients.archive') && $level === 'all') : ?>
    <form method="post" action="<?= e($u) ?>/archive" class="inline-form" data-confirm="Archive this client? It disappears from lists but nothing is deleted.">
        <?= csrf_field() ?><input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-danger btn-sm" type="submit">Archive</button></form>
    <?php endif; ?>
    <?php if (can('clients.invite') && $level === 'all' && $c['user_id'] === null && $c['status'] === 'active') : ?>
    <form method="post" action="<?= e($u) ?>/invite" class="inline-form" data-confirm="Create a portal login for this client and email an invitation?"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Invite to portal</button></form>
    <?php if (!$mailEnabled) : ?><p class="muted">Email is not configured: the invitation cannot be sent automatically.</p><?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel"><h2>Quotations &amp; invoices</h2>
    <p class="muted">Not connected yet. Quotations and invoices will be listed here when the billing modules are integrated (the existing invoice module has not been located: see open decision D-01). Nothing on this page implies that anything has been invoiced or paid.</p></div>
</div>
</div>

<div class="panel"><h2>Projects</h2>
    <?php if ($projects === []) : ?><p class="muted">No projects<?= $staff && !can('clients.view_all') ? ' that you can access' : '' ?>.</p><?php else : ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Project</th><th>Status</th><th>Target date</th></tr></thead><tbody>
        <?php foreach ($projects as $p) : ?><tr><td><?= e($p['code']) ?></td><td><a href="/projects/<?= e($p['public_id']) ?>"><?= e($p['title']) ?></a></td><td><span class="badge"><?= e(config("workflow.project_statuses.{$p['status']}", $p['status'])) ?></span></td><td><?= e($p['target_date'] ?? '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></div>

<?php if ($staff) : ?>
<div class="panel"><h2>Related enquiries</h2>
    <?php if ($enquiries === []) : ?><p class="muted">No enquiries that you can access are linked to this client.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($enquiries as $e) : ?><li><a href="/crm/enquiries/<?= e($e['public_id']) ?>"><strong><?= e($e['reference']) ?></strong></a> — <?= e(config("workflow.services.{$e['service']}", $e['service'])) ?> <span class="badge"><?= e($e['stage_name']) ?></span></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($canNotes || $notes !== []) : ?>
<div class="panel"><h2>Internal notes <span class="badge badge-sensitive">restricted</span></h2>
    <p class="muted">Visible only to staff with permission. Never shown to the client.</p>
    <?php foreach ($notes as $n) : ?><div class="note"><p class="prewrap"><?= e($n['body']) ?></p><p class="muted"><?= e($n['author_name'] ?? 'System') ?> · <?= e(local_time($n['created_at'])) ?></p></div><?php endforeach; ?>
    <?php if ($canNotes) : ?><form method="post" action="<?= e($u) ?>/notes" class="form-grid"><?= csrf_field() ?><div class="field"><textarea name="body" rows="3" maxlength="4000" required aria-label="New note"></textarea></div><div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($staff && can('clients.edit') && $c['status'] !== 'archived') : ?>
<div class="panel"><h2>Edit details</h2>
    <form method="post" action="<?= e($u) ?>" class="form-grid" novalidate><?= csrf_field() ?><?= \Vertex\Core\View::partial('client-fields', ['values' => $values]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div></form></div>
<?php endif; ?>

<?php if ($mergeTargets !== []) : ?>
<div class="panel panel-warn"><h2>Merge a duplicate</h2>
    <p class="muted">Use only when this record and another describe the same client. Enquiries, projects and notes move to the chosen record; this record is archived (nothing is deleted). Only records without a portal login can be merged away.</p>
    <form method="post" action="<?= e($u) ?>/merge" class="inline-form" data-confirm="Merge this record into the selected client? This archives this record.">
        <?= csrf_field() ?>
        <select name="target" required aria-label="Merge into"><option value="">Merge into…</option><?php foreach ($mergeTargets as $t) : ?><option value="<?= e($t['public_id']) ?>"><?= e($t['contact_name']) ?> — <?= e($t['company_name'] ?: $t['email']) ?></option><?php endforeach; ?></select>
        <input name="confirm_password" type="password" placeholder="Your password" required autocomplete="current-password" aria-label="Your password"><button class="btn btn-danger btn-sm" type="submit">Merge</button></form></div>
<?php endif; ?>

<?php if ($history !== []) : ?>
<div class="panel"><h2>Record history</h2>
    <ul class="plain-list"><?php foreach ($history as $h) : $m = json_decode((string) $h['meta_json'], true) ?: []; ?>
        <li><code><?= e($h['action']) ?></code> <?= isset($m['changed_fields']) ? '<span class="muted">(' . e(implode(', ', $m['changed_fields'])) . ')</span>' : '' ?> — <?= e($h['actor_name'] ?? 'system') ?> <span class="muted">· <?= e(local_time($h['created_at'])) ?></span></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>
