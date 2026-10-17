<?php
/** @var array<string,mixed> $project */
/** @var string $tab */
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;

$uid = (int) \Vertex\Services\Auth::id();
$level = ProjectPolicy::level($uid, $project);
$staff = in_array($level, ['all', 'assigned'], true);
$base = '/projects/' . $project['public_id'];
$tabs = ['overview' => [$base, 'Overview'], 'milestones' => [$base . '/milestones', 'Milestones']];
if ($staff && Gate::can('tasks.view', $uid)) {
    $tabs['tasks'] = [$base . '/tasks', 'Tasks'];
}
if (\Vertex\Services\Gate::can('deliverables.view', $uid) && ($staff || $level === 'own')) {
    $tabs['deliverables'] = [$base . '/deliverables', 'Deliverables'];
}
$tabs['checklist'] = [$base . '/checklist', 'Checklist'];
$tabs['timeline'] = [$base . '/timeline', 'Timeline'];
if (\Vertex\Services\PriceChangeService::canView($uid, $project)) {
    $tabs['pricing'] = [$base . '/pricing', 'Pricing'];
}
if (($staff && Gate::can('warranty.view', $uid)) || ($level === 'own' && Gate::can('warranty.view_own', $uid))) {
    $tabs['warranty'] = [$base . '/warranty', 'Warranty'];
}
?>
<nav class="tabs" aria-label="Project sections">
    <?php foreach ($tabs as $key => [$href, $label]) : ?>
        <a href="<?= e($href) ?>"<?= $key === ($tab ?? 'overview') ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
