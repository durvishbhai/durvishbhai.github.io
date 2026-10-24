<?php /** @var array<string,mixed> $client */ /** @var array<string,string> $categories */ /** @var array<string,string> $timelines */ /** @var array<string,string> $budgets */ /** @var array<string,string> $serviceChoices */ /** @var string $limit */ /** @var string $extensions */
$f = static fn (string $k): string => old($k); ?>
<div class="page-head"><div><p class="eyebrow">Projects</p><h1>Request a new project</h1></div><a class="btn btn-ghost" href="/my/project-requests">&larr; My requests</a></div>
<p class="muted">We already have your details (<?= e((string) ($client['company_name'] ?: $client['contact_name'])) ?>, <?= e((string) $client['email']) ?>). Tell us what you need; the project is confirmed once the advance payment is received.</p>
<div class="panel wizard-panel">
    <?= \Vertex\Core\View::partial('requirements-wizard', ['action' => '/my/project-requests', 'identity' => false, 'public' => false, 'deadline' => true, 'preselect' => '', 'categories' => $categories, 'timelines' => $timelines, 'budgets' => $budgets, 'serviceChoices' => $serviceChoices, 'limit' => $limit, 'extensions' => $extensions]) ?>
</div>
