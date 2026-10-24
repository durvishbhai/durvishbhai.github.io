<?php /** @var array<string,string> $categories */ /** @var array<string,string> $timelines */ /** @var array<string,string> $serviceChoices */ /** @var string $preselect */ /** @var string $limit */ /** @var string $extensions */
$f = static fn (string $k): string => old($k); $services = (array) $services; $budgets = (array) $budgets;
$choices = $serviceChoices !== [] ? $serviceChoices : $services;
$h = \Vertex\Services\SiteSections::get('requirements', 'header'); ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Project requirements</p><h1><?= st($h['title']) ?></h1><p class="lead"><?= st($h['intro']) ?></p></div></section>
<section class="pub-section"><div class="container narrow"><div class="panel wizard-panel">
    <?= \Vertex\Core\View::partial('requirements-wizard', ['action' => '/request-quote', 'identity' => true, 'public' => true, 'preselect' => $preselect, 'categories' => $categories, 'timelines' => $timelines, 'budgets' => $budgets, 'serviceChoices' => $choices, 'limit' => $limit, 'extensions' => $extensions]) ?>
</div></div></section>
