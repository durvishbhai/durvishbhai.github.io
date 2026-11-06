<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ /** @var bool $unread */ /** @var array<string,string> $qs */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Your account</p><h1>Notifications</h1></div>
    <div class="action-row"><a class="btn btn-ghost" href="/notifications<?= $unread ? '' : '?unread=1' ?>"><?= $unread ? 'Show all' : 'Unread only' ?></a>
        <form method="post" action="/notifications/read" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Mark all as read</button></form></div></div>
<p class="muted">These appear on this site only. They are not emailed.</p>
<?php if ($rows === []) : ?><div class="panel"><p class="muted"><?= $unread ? 'No unread notifications.' : 'No notifications yet.' ?></p></div><?php endif; ?>
<ul class="plain-list notifications">
    <?php foreach ($rows as $n) : ?>
        <li class="note<?= $n['read_at'] === null ? ' is-unread' : '' ?>">
            <strong><?php if ($n['link']) : ?><a href="<?= e($n['link']) ?>"><?= e($n['title']) ?></a><?php else : ?><?= e($n['title']) ?><?php endif; ?></strong>
            <?php if ($n['body']) : ?><br><span class="muted"><?= e($n['body']) ?></span><?php endif; ?>
            <br><span class="muted"><?= e(local_time($n['created_at'])) ?></span>
            <?php if ($n['read_at'] === null) : ?><form method="post" action="/notifications/read" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($n['id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Mark read</button></form><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?= View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
