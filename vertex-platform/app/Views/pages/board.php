<?php
/** @var array<string,list<array<string,mixed>>> $columns */ /** @var bool $truncated */ /** @var array<string,list<string>> $allowed */
/** @var array<string,mixed> $filters */ /** @var list<array<string,mixed>> $projects */ /** @var list<array<string,mixed>> $people */
$labels = (array) config('workflow.task_statuses');
$qsx = http_build_query(array_filter($filters, static fn ($x): bool => $x !== '' && $x !== false));
?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>Task board</h1></div>
    <div class="action-row"><a class="btn btn-ghost" href="/tasks<?= $qsx !== '' ? '?' . e($qsx) : '' ?>">List view</a><a class="btn btn-ghost" href="/deadlines">Deadlines</a></div></div>
<?= \Vertex\Core\View::partial('task-filters', ['filters' => $filters, 'action' => '/board', 'projects' => $projects, 'people' => $people, 'withStatus' => false, 'withProject' => true]) ?>
<?php if ($truncated) : ?><div class="alert alert-info" role="status">Showing the first <?= e(\Vertex\Services\TaskQuery::BOARD_LIMIT) ?> matching tasks. Use the filters to narrow the board.</div><?php endif; ?>
<p class="muted">Drag a card to another column to change its status, or use the Move control on the card. Only moves you are allowed to make are accepted; the server checks every one.</p>
<div id="board-status" class="sr-only" role="status" aria-live="polite"></div>
<div class="board" data-board data-csrf="<?= e(csrf_token()) ?>" data-reason-for="<?= e(implode(',', \Vertex\Services\TaskWorkflow::NEEDS_REASON)) ?>">
    <?php foreach ($columns as $status => $cards) : ?>
        <section class="board-col" data-status="<?= e($status) ?>" aria-label="<?= e($labels[$status]) ?>">
            <h2 class="board-col-head"><?= e($labels[$status]) ?> <span class="muted" data-count><?= e(count($cards)) ?></span></h2>
            <div class="board-cards" data-dropzone>
                <?php foreach ($cards as $c) : $ok = $allowed[$c['public_id']] ?? []; ?>
                    <article class="board-card<?= $c['is_overdue'] ? ' is-overdue' : '' ?>" data-task="<?= e($c['public_id']) ?>" data-status="<?= e($status) ?>" data-allowed="<?= e(implode(',', $ok)) ?>"<?= $ok !== [] ? ' draggable="true"' : '' ?>>
                        <a class="board-title" href="/tasks/<?= e($c['public_id']) ?>"><?= e($c['title']) ?></a>
                        <div class="board-meta"><a href="/projects/<?= e($c['project_public_id']) ?>/tasks"><?= e($c['project_code']) ?></a>
                            <span class="badge badge-prio-<?= e($c['priority']) ?>"><?= e(status_label((string) $c['priority'])) ?></span></div>
                        <div class="board-meta"><span><?= $c['assignee_name'] ? e($c['assignee_name']) : 'Unassigned' ?></span>
                            <?php if ($c['due_date']) : ?><span class="<?= $c['is_overdue'] ? 'overdue' : 'muted' ?>"><?= e($c['due_date']) ?></span><?php endif; ?></div>
                        <?php if ((int) $c['open_deps'] > 0) : ?><div class="muted">Waiting on <?= e($c['open_deps']) ?> task<?= (int) $c['open_deps'] === 1 ? '' : 's' ?></div><?php endif; ?>
                        <?php if ($ok !== []) : ?>
                            <form method="post" action="/tasks/<?= e($c['public_id']) ?>/status" class="board-move"><?= csrf_field() ?>
                                <input type="hidden" name="return" value="board">
                                <label class="sr-only" for="mv-<?= e($c['public_id']) ?>">Move to</label>
                                <select id="mv-<?= e($c['public_id']) ?>" name="status"><?php foreach ($ok as $to) : ?><option value="<?= e($to) ?>"><?= e($labels[$to]) ?></option><?php endforeach; ?></select>
                                <input type="hidden" name="reason" value="">
                                <button class="btn btn-ghost btn-sm" type="submit">Move</button></form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($cards === []) : ?><p class="muted board-empty">Nothing here.</p><?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<script src="<?= e(asset('js/board.js')) ?>" defer></script>
