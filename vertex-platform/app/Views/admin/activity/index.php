<?php
/** @var array<string,string> $filters */ /** @var array<string,mixed> $overview */ /** @var array<string,array{0:string,1:list<string>}> $categories */
/** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
$isFeed = $filters['view'] === 'feed';
$qs = array_filter($filters, static fn ($v) => $v !== '');
?>
<div class="page-head"><div><p class="eyebrow">Owner</p><h1>Account activity</h1></div><a class="btn btn-ghost" href="/admin/audit">Open the full audit log</a></div>
<p class="muted">Everything done on the platform, by every account: sign-ins, payments, project work, setting changes. Read-only and taken from the tamper-proof audit log. Times are in your time zone.</p>

<div class="stat-grid">
    <div class="stat"><strong><?= e((string) $overview['total_accounts']) ?></strong><span>accounts</span></div>
    <div class="stat"><strong><?= e((string) $overview['signed_in_24h']) ?></strong><span>signed in (24 h)</span></div>
    <div class="stat"><strong><?= e((string) $overview['failed_24h']) ?></strong><span>failed sign-in attempts (24 h)</span></div>
    <div class="stat"><strong><?= e((string) $overview['actions_24h']) ?></strong><span>actions recorded (24 h)</span></div>
</div>

<nav class="tabs" aria-label="Views"><a href="/admin/activity"<?= !$isFeed ? ' aria-current="page"' : '' ?>>Accounts</a><a href="/admin/activity?view=feed"<?= $isFeed ? ' aria-current="page"' : '' ?>>Live feed (all accounts)</a></nav>

<form method="get" action="/admin/activity" class="toolbar" role="search">
    <input type="hidden" name="view" value="<?= e($filters['view']) ?>">
    <div class="field"><label for="q">Name, email<?= $isFeed ? ', action or IP' : '' ?></label><input id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>"></div>
    <?php if ($isFeed) : ?>
        <div class="field"><label for="category">Kind of activity</label><select id="category" name="category"><option value="">Everything</option><?php foreach ($categories as $k => [$label]) : ?><option value="<?= e($k) ?>"<?= $filters['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($filters['from']) ?>"></div>
        <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($filters['to']) ?>"></div>
    <?php else : ?>
        <div class="field"><label for="group">Who</label><select id="group" name="group"><option value="">Everyone</option><?php foreach (['team' => 'KIS team', 'clients' => 'Clients', 'partners' => 'Referral partners'] as $k => $l) : ?><option value="<?= e($k) ?>"<?= $filters['group'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="status">Account status</label><select id="status" name="status"><option value="">Any</option><?php foreach (['active', 'pending', 'deactivated', 'suspended', 'archived'] as $s) : ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field"><button class="btn btn-ghost" type="submit">Filter</button></div>
</form>

<?php if ($isFeed) : ?>
    <?= \Vertex\Core\View::partial('activity-rows', ['rows' => $rows, 'showActor' => true]) ?>
<?php else : ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Sign-ins (30 d)</th><th>Actions (30 d)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) : ?>
        <tr>
            <td><strong><?= e($r['name']) ?></strong><br><span class="muted"><?= e($r['email']) ?></span></td>
            <td><?= e((string) $r['roles']) ?></td>
            <td><?= e(ucfirst((string) $r['status'])) ?><?= $r['totp_enabled_at'] ? '<br><span class="muted small">2-step login on</span>' : '' ?></td>
            <td><?= $r['last_login_at'] ? e(local_time($r['last_login_at'], 'd M Y, H:i')) . '<br><span class="muted small">' . e((string) $r['last_login_ip']) . '</span>' : '<span class="muted">never</span>' ?></td>
            <td><?= e((string) $r['signins_30d']) ?></td>
            <td><?= e((string) $r['actions_30d']) ?></td>
            <td><a class="btn btn-ghost btn-sm" href="/admin/activity/<?= e($r['public_id']) ?>">See activity</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []) : ?><tr><td colspan="7" class="muted">No accounts match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
