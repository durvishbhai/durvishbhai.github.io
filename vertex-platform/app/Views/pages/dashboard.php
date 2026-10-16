<?php
/** @var array<string,mixed> $user */
/** @var list<string> $roles */
/** @var list<string> $permissions */
/** @var array{stats:list<array<string,string>>,todo:list<array<string,string>>,actions:list<array<string,string>>,notifications:list<array<string,mixed>>} $home */
/** @var list<array{title:string,svg:string,link:string}> $charts */
$hour = (int) \Vertex\Services\AppTime::now()->format('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string) $user['name']))[0] ?: (string) $user['name'];
?>
<div class="page-head dash-head">
    <div><p class="eyebrow"><?= e(local_date(\Vertex\Services\AppTime::today(), 'l, d M Y')) ?></p>
    <h1><?= e($greet) ?>, <span class="sr-only">Welcome, <?= e($user['name']) ?></span><span aria-hidden="true"><?= e($first) ?></span></h1></div>
    <?php if ($home['actions'] !== []) : ?><div class="quick-actions" aria-label="Quick actions"><?php foreach ($home['actions'] as $a) : ?><a class="btn btn-ghost btn-sm" href="<?= e($a['href']) ?>"><?= e($a['label']) ?></a><?php endforeach; ?></div><?php endif; ?>
</div>

<?php if ($home['stats'] !== []) : ?>
<div class="kpi-grid" role="list">
    <?php foreach ($home['stats'] as $s) : ?>
        <a class="kpi<?= $s['tone'] !== '' ? ' kpi-' . e($s['tone']) : '' ?>" role="listitem" href="<?= e($s['href']) ?>"><span class="kpi-n"<?= ctype_digit($s['n']) ? ' data-count="' . e($s['n']) . '"' : '' ?>><?= e($s['n']) ?></span><span class="kpi-l"><?= e($s['label']) ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="dash-grid">
    <section class="panel" aria-labelledby="todo-h">
        <h2 id="todo-h">What needs you</h2>
        <?php if ($home['todo'] === []) : ?>
            <p class="empty-state">You are all caught up. Nothing is overdue or due soon.</p>
        <?php else : ?>
            <ul class="todo-list">
                <?php foreach ($home['todo'] as $t) : ?>
                    <li class="todo<?= $t['tone'] !== '' ? ' todo-' . e($t['tone']) : '' ?>"><a href="<?= e($t['href']) ?>"><strong><?= e($t['title']) ?></strong><?php if ($t['sub'] !== '') : ?><span class="muted"> · <?= e($t['sub']) ?></span><?php endif; ?></a><span class="todo-when"><?= e($t['when']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="panel" aria-labelledby="notif-h">
        <h2 id="notif-h">Latest notifications</h2>
        <?php if ($home['notifications'] === []) : ?>
            <p class="empty-state">No unread notifications.</p>
        <?php else : ?>
            <ul class="todo-list">
                <?php foreach ($home['notifications'] as $n) : ?>
                    <li class="todo"><a href="<?= e($n['link'] ?: '/notifications') ?>"><strong><?= e($n['title']) ?></strong></a><span class="todo-when"><?= e(local_time($n['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p><a href="/notifications">All notifications</a></p>
        <?php endif; ?>
    </section>
</div>

<?php if ($charts !== []) : ?><div class="chart-grid"><?php foreach ($charts as $c) : ?><div class="panel chart-panel"><h2><a href="<?= e($c['link']) ?>"><?= e($c['title']) ?></a></h2><?= $c['svg'] ?></div><?php endforeach; ?></div><?php endif; ?>

<details class="panel access">
    <summary>Your access <span class="muted">(<?= e(count($roles)) ?> role<?= count($roles) === 1 ? '' : 's' ?>, <?= e(count($permissions)) ?> permissions)</span></summary>
    <p><?php foreach ($roles as $r) : ?><span class="chip"><?= e($r) ?></span><?php endforeach; ?><?php if ($roles === []) : ?><span class="muted">No roles assigned.</span><?php endif; ?></p>
    <p class="perm-list"><?php foreach ($permissions as $p) : ?><code><?= e($p) ?></code><?php endforeach; ?></p>
</details>
