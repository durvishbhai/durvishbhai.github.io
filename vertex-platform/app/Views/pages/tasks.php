<?php
/** @var list<array<string,mixed>> $tasks */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */
/** @var array<string,mixed> $filters */ /** @var array<string,mixed> $qs */ /** @var list<array<string,mixed>> $projects */ /** @var list<array<string,mixed>> $people */
?>
<div class="page-head"><div><p class="eyebrow">Delivery</p><h1>Tasks</h1></div>
    <div class="action-row"><a class="btn btn-ghost" href="/board">Kanban board</a><a class="btn btn-ghost" href="/deadlines">Deadlines</a></div></div>
<p class="muted">Tasks on projects you are assigned to. Create tasks from a project's Tasks tab.</p>
<?= \Vertex\Core\View::partial('task-filters', ['filters' => $filters, 'action' => '/tasks', 'projects' => $projects, 'people' => $people, 'withStatus' => true, 'withProject' => true]) ?>
<?= \Vertex\Core\View::partial('task-table', ['tasks' => $tasks, 'showProject' => true]) ?>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $qs]) ?>
