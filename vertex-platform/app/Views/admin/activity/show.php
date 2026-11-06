<?php
/** @var array<string,mixed> $account */ /** @var array<string,string> $filters */ /** @var array<string,array{0:string,1:list<string>}> $categories */
/** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
$qs = array_filter($filters, static fn ($v) => $v !== '');
?>
<div class="page-head"><div><p class="eyebrow"><a href="/admin/activity">Account activity</a></p><h1><?= e($account['name']) ?></h1></div>
    <?php if (\Vertex\Services\Gate::can('users.view', (int) \Vertex\Services\Auth::id())) : ?><a class="btn btn-ghost" href="/admin/users/<?= e($account['public_id']) ?>">Open the account</a><?php endif; ?></div>
<div class="panel"><dl class="kv">
    <dt>Email</dt><dd><?= e($account['email']) ?></dd>
    <dt>Phone</dt><dd><?= e((string) ($account['phone'] ?? '—')) ?></dd>
    <dt>Role</dt><dd><?= e((string) $account['roles']) ?></dd>
    <dt>Status</dt><dd><?= e(ucfirst((string) $account['status'])) ?></dd>
    <dt>Created</dt><dd><?= e(local_time($account['created_at'], 'd M Y, H:i')) ?><?= (int) $account['self_registered'] === 1 ? ' (signed up on the website)' : '' ?></dd>
    <dt>Last sign-in</dt><dd><?= $account['last_login_at'] ? e(local_time($account['last_login_at'], 'd M Y, H:i')) . ' from ' . e((string) $account['last_login_ip']) : 'never' ?></dd>
    <dt>Security</dt><dd><?= $account['totp_enabled_at'] ? 'Two-step login is on' : 'Two-step login is off' ?><?= (int) $account['has_google'] === 1 ? ' · Google linked' : '' ?></dd>
</dl></div>

<form method="get" action="/admin/activity/<?= e($account['public_id']) ?>" class="toolbar">
    <div class="field"><label for="direction">Show</label><select id="direction" name="direction"><option value="">Everything about this account</option><option value="by"<?= $filters['direction'] === 'by' ? ' selected' : '' ?>>What this account did</option><option value="to"<?= $filters['direction'] === 'to' ? ' selected' : '' ?>>What others did to it (including failed sign-ins)</option></select></div>
    <div class="field"><label for="category">Kind of activity</label><select id="category" name="category"><option value="">Everything</option><?php foreach ($categories as $k => [$label]) : ?><option value="<?= e($k) ?>"<?= $filters['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($filters['to']) ?>"></div>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>
<?= \Vertex\Core\View::partial('activity-rows', ['rows' => $rows, 'showActor' => true]) ?>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
