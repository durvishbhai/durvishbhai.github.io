<?php
use Vertex\Services\Auth;

/** @var string $content */
/** @var string|null $title */
$user = Auth::user();
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . config('app.name') : (string) config('app.name');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$staffProjects = can('projects.view_all') || can('projects.view_assigned');
// Links that only make sense for a customer or a partner need that record: the Owner holds every permission but has no client file, so these would only lead to a 404.
$uid = (int) Auth::id();
$isClient = $uid > 0 && \Vertex\Core\Database::one('SELECT 1 AS x FROM clients WHERE user_id = ?', [$uid]) !== null;
$isPartner = $uid > 0 && \Vertex\Core\Database::one('SELECT 1 AS x FROM referral_partners WHERE user_id = ?', [$uid]) !== null;
$unread = $user !== null ? \Vertex\Services\Notifier::unreadCount((int) Auth::id()) : 0;
$nav = [
    ['/dashboard', 'Dashboard', true],
    ['/crm', 'CRM', can('enquiries.view_all') || can('enquiries.view_assigned')],
    ['/clients', 'Clients', can('clients.view_all') || can('clients.view_assigned')],
    ['/my/company', 'My company', can('clients.view_own') && $isClient],
    ['/my/project-requests', 'Request a project', can('requirements.submit') && $isClient],
    ['/partner', 'Partner portal', can('referrals.view_own') && $isPartner],
    ['/partner/referrals', 'Earlier referrals', can('enquiries.view_own') && !can('referrals.view_own') && $isPartner],
    ['/referrals/dashboard', 'Referral partners', can('referrals.review') || can('commissions.view_all') || can('payouts.view_all') || can('payments.view_all')],
    ['/referrals', 'Referrals', can('referrals.review')],
    ['/commissions', 'Commissions', can('commissions.view_all')],
    ['/payouts', 'Payouts', can('payouts.view_all') || can('payouts.approve') || can('payouts.record')],
    ['/invoices', 'Invoices', can('invoices.view_all')],
    ['/payment-requests', 'Payment requests', can('payments.request')],
    ['/receipts', 'Receipts', can('receipts.view_all')],
    ['/quotations', 'Quotations', can('quotes.create') || can('quotes.send') || can('quotes.record_decision')],
    ['/my/invoices', 'My invoices', can('invoices.view_own') && $isClient],
    ['/my/receipts', 'My receipts', can('receipts.view_own') && $isClient],
    ['/my/quotes', 'My quotations', can('quotes.view') && can('quotes.respond') && $isClient],
    ['/approvals', 'Approvals', can('payments.reconcile') || can('invoices.issue') || can('payouts.approve') || can('payouts.record') || can('commissions.approve') || can('referrals.decide') || can('renewals.approve') || can('renewals.confirm_provider') || can('deliverables.review') || can('deliverables.publish') || can('revisions.classify')],
    ['/coupons', 'Coupons', can('coupons.view')],
    ['/scope-requests', 'Scope requests', can('scope.assess')],
    ['/my/scope-requests', 'My scope requests', can('scope.request') && $isClient],
    ['/project-templates', 'Project templates', can('templates.manage') || can('templates.apply')],
    ['/agreements', 'Agreements', can('agreements.view')],
    ['/my/agreements', 'My agreements', can('agreements.view_own') && $isClient],
    ['/forms', 'Forms', can('forms.manage') || can('forms.view_submissions')],
    ['/reports', 'Reports', can('reports.financial') || can('reports.operational')],
    ['/client-payments', 'Client payments', can('payments.view_all') || can('payments.record') || can('payments.reconcile')],
    ['/renewals', 'Domains & hosting', can('renewals.view') || can('renewals.manage') || can('renewals.approve') || can('renewals.confirm_provider')],
    ['/my/services', 'My domains & hosting', can('renewals.view_own') && $isClient],
    ['/projects', 'Projects', can('projects.view_all') || can('projects.view_assigned') || can('projects.view_own')],
    ['/team', 'Team', can('projects.view_all')],
    ['/tasks', 'Tasks', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/board', 'Board', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/deadlines', 'Deadlines', can('tasks.view') && (can('projects.view_all') || can('projects.view_assigned'))],
    ['/deliverables', 'Deliverables', can('deliverables.view') && $staffProjects],
    ['/qa', 'QA', (can('deliverables.review') || can('deliverables.publish')) && $staffProjects],
    ['/my/deliverables', 'My deliverables', can('deliverables.view') && can('projects.view_own') && !$staffProjects],
    ['/revisions', can('revisions.manage') && $staffProjects ? 'Revisions' : 'My revisions', (can('revisions.manage') && $staffProjects) || can('revisions.request')],
    ['/support', 'Support', can('tickets.view_own') && $isClient],
    ['/tickets', 'Tickets', can('tickets.view_all') || can('tickets.view_assigned') || can('tickets.view_technical')],
    ['/support/dashboard', 'Support dashboard', can('support.dashboard')],
    ['/warranties', 'Warranties', can('warranty.view')],
    ['/admin/pricing', 'Pricing', can('pricing.view')],
    ['/admin/revision-policies', 'Revision policy', can('revisions.policy') || can('revisions.classify')],
    ['/admin/users', 'Users', can('users.view')],
    ['/admin/roles', 'Roles', can('roles.view')],
    ['/admin/automation', 'Workflow rules', can('automation.manage')],
    ['/admin/sla', 'Support SLA', can('sla.manage')],
    ['/admin/data', 'Import & export', can('data.export') || can('data.import')],
    ['/admin/backup', 'Backup (.sql)', can('backups.manage')],
    ['/admin/recycle-bin', 'Recycle bin', can('recycle.view')],
    ['/admin/activity', 'Account activity', can('activity.view_all')],
    ['/admin/audit', 'Audit log', can('audit.view')],
    ['/admin/business', 'Business details', can('billing.configure')],
    ['/admin/manual-upi', 'Manual UPI payments', can('billing.configure')],
    ['/admin/site', 'Website', can('cms.view')],
    ['/admin/cms', 'Pages & media', can('cms.view')],
    ['/admin/careers', 'Job applications', can('careers.view') || can('careers.manage')],
    ['/ai', 'AI assistant', can('ai.use') && \Vertex\Services\Settings::feature('ai')],
    ['/admin/settings', 'Integrations & settings', can('settings.view')],
    ['/admin/system', 'System status', can('settings.view')],
    ['/notifications', 'Notifications' . ($unread > 0 ? ' (' . $unread . ')' : ''), true],
    ['/account', 'My account', true],
    ['/account#change-password', 'Change password', true],
];

// Sidebar groups: every link keeps its own permission test above; a group with no visible link disappears.
$groupOf = [
    'Overview' => ['/dashboard', '/notifications', '/approvals', '/ai', '/reports'],
    'Sales & clients' => ['/crm', '/clients', '/my/company', '/my/project-requests', '/quotations', '/my/quotes', '/admin/pricing', '/coupons'],
    'Projects & delivery' => ['/projects', '/tasks', '/board', '/deadlines', '/team', '/deliverables', '/qa', '/my/deliverables', '/revisions', '/scope-requests', '/my/scope-requests', '/project-templates', '/admin/revision-policies', '/warranties'],
    'Money' => ['/invoices', '/my/invoices', '/receipts', '/my/receipts', '/client-payments', '/commissions', '/payouts', '/renewals', '/my/services'],
    'Partners' => ['/partner', '/partner/referrals', '/referrals/dashboard', '/referrals'],
    'Support' => ['/support', '/tickets', '/support/dashboard', '/admin/sla'],
    'Contracts & forms' => ['/agreements', '/my/agreements', '/forms'],
    'Website' => ['/admin/site', '/admin/cms', '/admin/careers'],
    'Administration' => ['/admin/users', '/admin/roles', '/admin/automation', '/admin/data', '/admin/recycle-bin', '/admin/activity', '/admin/audit', '/admin/payments', '/admin/business', '/admin/manual-upi', '/admin/settings', '/admin/system'],
    'Account' => ['/account', '/account#change-password'],
];
$byHref = [];
foreach ($nav as [$href, $label, $show]) {
    $byHref[$href] = [$label, $show];
}
$groups = [];
foreach ($groupOf as $gname => $hrefs) {
    foreach ($hrefs as $href) {
        if (isset($byHref[$href]) && $byHref[$href][1]) {
            $groups[$gname][] = [$href, $byHref[$href][0]];
        }
    }
}
$isCurrent = static fn (string $href): bool => $path === $href || str_starts_with($path, $href . '/'); // the longest match wins below
$currentGroup = null;
$best = 0;
foreach ($groups as $gname => $items) {
    foreach ($items as [$href]) {
        if ($isCurrent($href) && strlen($href) > $best) {
            $best = strlen($href);
            $currentGroup = $gname;
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#050818">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <?= \Vertex\Core\View::partial('pwa-head') ?>
    <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="portal">
<a class="skip-link" href="#main">Skip to content</a>
<header class="portal-bar">
    <button class="side-toggle" type="button" aria-expanded="false" aria-controls="portal-side" data-side-toggle><span class="sr-only">Menu</span><span class="bars" aria-hidden="true"></span></button>
    <a class="brand" href="/dashboard" aria-label="Krazy dashboard">
        <img src="<?= e(asset('img/krazy-mark.png')) ?>" alt="" width="82" height="34">
        <span class="brand-word">Krazy</span>
    </a>
    <form class="portal-search" method="get" action="/search" role="search"><label class="sr-only" for="gs">Search</label><input id="gs" name="q" type="search" placeholder="Search everything" maxlength="100" autocomplete="off"></form>
    <div class="portal-user">
        <?= \Vertex\Core\View::partial('theme-toggle') ?>
        <a class="bell" href="/notifications" aria-label="Notifications<?= $unread > 0 ? ' (' . $unread . ' unread)' : '' ?>">&#128276;<?php if ($unread > 0) : ?><span class="bell-n"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?></a>
        <a class="portal-name" href="/account"><?= e($user['name'] ?? '') ?></a>
        <form method="post" action="/logout">
            <?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
    </div>
</header>
<div class="portal-shell">
    <aside class="portal-side" id="portal-side" aria-label="Main">
        <form class="side-search" method="get" action="/search" role="search"><label class="sr-only" for="gs2">Search</label><input id="gs2" name="q" type="search" placeholder="Search everything" maxlength="100" autocomplete="off"></form>
        <nav>
        <?php foreach ($groups as $gname => $items) : $open = $gname === $currentGroup || $gname === 'Overview'; ?>
            <details class="nav-group"<?= $open ? ' open' : '' ?>>
                <summary><?= e($gname) ?></summary>
                <?php foreach ($items as [$href, $label]) : ?>
                    <a href="<?= e($href) ?>"<?= $gname === $currentGroup && $isCurrent($href) && strlen($href) === $best ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
        </nav>
    </aside>
    <main id="main" class="portal-main">
        <div class="container-wide">
            <div class="install-banner" data-install-banner hidden><span><strong>Get the app.</strong> Install <?= e((string) config('app.name')) ?> on your phone for quick access.</span><span class="install-actions"><button type="button" class="btn btn-primary btn-sm" data-install>Install</button><button type="button" class="btn btn-ghost btn-sm" data-banner-dismiss aria-label="Dismiss">&times;</button></span><p class="muted small" data-install-ios hidden>On iPhone: tap the Share button, then <strong>Add to Home Screen</strong>.</p></div>
            <?php if (\Vertex\Services\PushChannel::enabled()) : ?><div class="install-banner" data-push-banner hidden><span><strong>Stay up to date.</strong> Get payment, project and request updates as notifications on this device.</span><span class="install-actions"><button type="button" class="btn btn-primary btn-sm" data-push-enable>Turn on notifications</button><button type="button" class="btn btn-ghost btn-sm" data-banner-dismiss aria-label="Dismiss">&times;</button></span></div><?php endif; ?>
            <?= \Vertex\Core\View::partial('flash', ['errors' => $errors ?? [], 'notice' => $notice ?? null]) ?>
            <?= $content ?>
        </div>
    </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
