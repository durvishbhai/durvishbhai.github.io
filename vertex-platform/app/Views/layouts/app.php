<?php
use Vertex\Services\Auth;

/** @var string $content */
/** @var string|null $title */
$user = Auth::user();
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . config('app.name') : (string) config('app.name');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$staffProjects = can('projects.view_all') || can('projects.view_assigned');
$unread = $user !== null ? \Vertex\Services\Notifier::unreadCount((int) Auth::id()) : 0;
$nav = [
    ['/dashboard', 'Dashboard', true],
    ['/crm', 'CRM', can('enquiries.view_all') || can('enquiries.view_assigned')],
    ['/clients', 'Clients', can('clients.view_all') || can('clients.view_assigned')],
    ['/my/company', 'My company', can('clients.view_own')],
    ['/partner', 'Partner portal', can('referrals.view_own')],
    ['/partner/referrals', 'Earlier referrals', can('enquiries.view_own') && !can('referrals.view_own')],
    ['/referrals/dashboard', 'Referral partners', can('referrals.review') || can('commissions.view_all') || can('payouts.view_all') || can('payments.view_all')],
    ['/referrals', 'Referrals', can('referrals.review')],
    ['/commissions', 'Commissions', can('commissions.view_all')],
    ['/payouts', 'Payouts', can('payouts.view_all') || can('payouts.approve') || can('payouts.record')],
    ['/client-payments', 'Client payments', can('payments.view_all') || can('payments.record') || can('payments.reconcile')],
    ['/projects', 'Projects', can('projects.view_all') || can('projects.view_assigned') || can('projects.view_own')],
    ['/team', 'Team', can('projects.view_all')],
    ['/tasks', 'Tasks', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/board', 'Board', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/deadlines', 'Deadlines', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/deliverables', 'Deliverables', can('deliverables.view') && $staffProjects],
    ['/qa', 'QA', (can('deliverables.review') || can('deliverables.publish')) && $staffProjects],
    ['/my/deliverables', 'My deliverables', can('deliverables.view') && can('projects.view_own') && !$staffProjects],
    ['/revisions', can('revisions.manage') && $staffProjects ? 'Revisions' : 'My revisions', (can('revisions.manage') && $staffProjects) || can('revisions.request')],
    ['/support', 'Support', can('tickets.view_own')],
    ['/tickets', 'Tickets', can('tickets.view_all') || can('tickets.view_assigned') || can('tickets.view_technical')],
    ['/support/dashboard', 'Support dashboard', can('support.dashboard')],
    ['/warranties', 'Warranties', can('warranty.view')],
    ['/admin/pricing', 'Pricing', can('pricing.view')],
    ['/admin/revision-policies', 'Revision policy', can('revisions.policy') || can('revisions.classify')],
    ['/admin/users', 'Users', can('users.view')],
    ['/admin/roles', 'Roles', can('roles.view')],
    ['/admin/audit', 'Audit log', can('audit.view')],
    ['/notifications', 'Notifications' . ($unread > 0 ? ' (' . $unread . ')' : ''), true],
    ['/account', 'My account', true],
];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#05070d">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="portal">
<a class="skip-link" href="#main">Skip to content</a>
<header class="portal-bar">
    <a class="brand" href="/dashboard" aria-label="Vertex dashboard">
        <img src="<?= e(asset('img/vertex-mark.png')) ?>" alt="" width="40" height="35">
        <span class="brand-word">Vertex</span>
    </a>
    <nav class="portal-nav" aria-label="Main">
        <?php foreach ($nav as [$href, $label, $show]) : ?>
            <?php if ($show) : ?>
                <a href="<?= e($href) ?>"<?= str_starts_with($path, $href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="portal-user">
        <span class="portal-name"><?= e($user['name'] ?? '') ?></span>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main id="main" class="portal-main">
    <div class="container-wide">
        <?= \Vertex\Core\View::partial('flash', ['errors' => $errors ?? [], 'notice' => $notice ?? null]) ?>
        <?= $content ?>
    </div>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
