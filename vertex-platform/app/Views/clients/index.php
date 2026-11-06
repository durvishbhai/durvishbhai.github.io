<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var array<string,mixed> $filters */ /** @var array<string,string> $qs */
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : ''; ?>
<div class="page-head"><div><p class="eyebrow">CRM</p><h1>Clients</h1></div>
    <?php if (can('clients.create')) : ?><a class="btn btn-primary" href="/clients/create">New client</a><?php endif; ?></div>
<form method="get" action="/clients" class="toolbar" role="search">
    <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" maxlength="100" placeholder="Name, email, company, phone" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="type">Type</label><select id="type" name="type"><option value="">All</option><option value="individual"<?= $sel($filters['type'], 'individual') ?>>Individual</option><option value="business"<?= $sel($filters['type'], 'business') ?>>Business</option></select></div>
    <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">Any</option><option value="active"<?= $sel($filters['status'], 'active') ?>>Active</option><option value="inactive"<?= $sel($filters['status'], 'inactive') ?>>Inactive</option></select></div>
    <?php if (can('clients.view_all')) : ?><div class="field"><label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label></div><?php endif; ?>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Client</th><th>Type</th><th>Contact</th><th>Status</th><th>Portal login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr><td><strong><?= e($r['company_name'] ?: $r['contact_name']) ?></strong><?= $r['company_name'] ? '<br><span class="muted">' . e($r['contact_name']) . '</span>' : '' ?></td>
            <td><?= e(status_label((string) $r['client_type'])) ?></td>
            <td><?= e($r['email']) ?><br><span class="muted"><?= e($r['phone'] ?? '') ?></span></td>
            <td><span class="badge badge-<?= e($r['status'] === 'active' ? 'active' : 'pending') ?>"><?= e(status_label((string) $r['status'])) ?></span></td>
            <td><?= $r['user_id'] ? 'Yes' : 'No' ?></td>
            <td><a class="btn btn-ghost btn-sm" href="/clients/<?= e($r['public_id']) ?>">Open</a></td></tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="6" class="muted">No clients match<?= can('clients.view_all') ? '' : ' (you only see clients on projects you are assigned to)' ?>.</td></tr><?php endif; ?>
    </tbody></table></div>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
