<?php /** @var array<string,mixed> $project */ /** @var list<array<string,mixed>> $items */ /** @var int $total */ /** @var int $page */ $tab = 'timeline'; ?>
<div class="page-head"><div><p class="eyebrow">Project <?= e($project['code']) ?></p><h1><?= e($project['title']) ?></h1></div><a class="btn btn-ghost" href="/projects">&larr; Projects</a></div>
<?= \Vertex\Core\View::partial('project-tabs', ['project' => $project, 'tab' => $tab]) ?>
<div class="panel"><h2>Activity timeline</h2>
    <p class="muted">Every entry shows who did what and when. Clients see only the entries marked for them.</p>
    <?= \Vertex\Core\View::partial('timeline', ['items' => $items]) ?>
    <?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => 30, 'total' => $total, 'filters' => []]) ?></div>
