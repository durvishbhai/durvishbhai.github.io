<?php

declare(strict_types=1);

/*
 * Single source of truth for roles and permissions.
 * `php bin/console.php permissions:sync` writes this into the roles / permissions / role_permissions tables.
 *
 * Rules:
 *  - There is NO wildcard and NO implicit super-user bypass. Admin holds every permission explicitly
 *    (computed below), so removing a permission from Admin is a visible config change.
 *  - Manager is an explicit, limited list. It is never derived from Admin.
 *  - Permissions suffixed `.assigned` / `.own` are SCOPED: the permission only says the role may use the
 *    feature. Row-level access (assigned project, own invoice, ...) is enforced by policy classes in the
 *    module that owns the data, from Phase 2 onward.
 *  - Permissions marked admin-only below are intentionally absent from every other role.
 */

$permissions = [
    // module => [ key => description ]
    'users' => [
        'users.view' => 'View user accounts',
        'users.manage' => 'Create, edit, suspend user accounts',
        'users.assign_roles' => 'Change which roles a user holds',
        'roles.manage' => 'Edit the role/permission matrix',
    ],
    'cms' => [
        'cms.view' => 'View website CMS content',
        'cms.edit' => 'Edit draft website content',
        'cms.publish' => 'Publish website content',
        'legal.manage' => 'Edit legal pages and policies',
    ],
    'projects' => [
        'projects.view_all' => 'View every project',
        'projects.view_assigned' => 'View projects the user is assigned to',
        'projects.view_own' => 'View projects the client owns',
        'projects.create' => 'Create a project',
        'projects.assign' => 'Assign users to projects (Admin only)',
        'projects.update_status' => 'Change project status',
    ],
    'milestones' => [
        'milestones.view' => 'View milestones on accessible projects',
        'milestones.create' => 'Create milestones directly',
        'milestones.propose' => 'Propose milestones for approval',
        'milestones.approve' => 'Approve or reject proposed milestones',
        'tasks.view' => 'View tasks on accessible projects',
        'tasks.manage' => 'Create and assign tasks',
        'tasks.update_assigned' => 'Update tasks assigned to the user',
    ],
    'files' => [
        'files.upload' => 'Upload files/deliverables to accessible projects',
        'files.download' => 'Download files on accessible projects',
        'files.manage' => 'Delete or replace files',
    ],
    'pricing' => [
        'pricing.view' => 'View pricing rules and priority charges',
        'pricing.manage' => 'Edit pricing rules and priority charges',
        'estimates.create' => 'Create feature-based price estimates',
        'quotes.view' => 'View quotations',
        'quotes.create' => 'Draft quotations',
        'quotes.send' => 'Send quotations to clients',
    ],
    'billing' => [
        'invoices.view_all' => 'View all invoices',
        'invoices.view_own' => 'View own invoices (client)',
        'invoices.create' => 'Draft invoices',
        'invoices.issue' => 'Issue invoices as payable',
        'invoices.void' => 'Void invoices',
        'receipts.view_own' => 'View own receipts (client)',
        'payments.view_all' => 'View all client payments',
        'payments.pay' => 'Pay own invoices through the gateway (client)',
        'payments.reconcile' => 'Reconcile or manually record client payments',
    ],
    'payouts' => [
        'payouts.request' => 'Submit a payout request (staff/freelancer)',
        'payouts.view_own' => 'View own payout requests and history',
        'payouts.view_all' => 'View all payout requests',
        'payouts.approve' => 'Approve or reject payout requests (Admin only)',
        'payouts.record' => 'Record a manual payout as paid (Admin only)',
        'commissions.view_own' => 'View own commission ledger',
        'commissions.view_all' => 'View all commission ledgers',
        'commissions.rules.manage' => 'Edit commission rules (Admin only)',
    ],
    'referrals' => [
        'referrals.view_own' => 'View own referral dashboard',
        'referrals.review' => 'View referral applications',
        'referrals.decide' => 'Approve or reject referral applications (Admin only)',
    ],
    'support' => [
        'chat.use' => 'Use project chat on accessible projects',
        'revisions.request' => 'Request a revision (client)',
        'revisions.manage' => 'Triage and resolve revisions',
        'bugs.report' => 'Report a bug (client)',
        'bugs.manage' => 'Triage and resolve bugs',
        'warranty.view_own' => 'View own warranty status (client)',
        'warranty.manage' => 'Manage warranty records',
    ],
    'intake' => [
        'requirements.submit' => 'Submit a requirements form (client)',
        'requirements.view' => 'View submitted requirements',
        'appointments.book' => 'Book an appointment (client)',
        'appointments.manage' => 'Manage appointments and availability',
    ],
    'renewals' => [
        'renewals.view' => 'View domain/hosting renewals',
        'renewals.manage' => 'Create and edit renewal records',
        'renewals.approve' => 'Record Admin approval for a renewal (Admin only)',
        'renewals.confirm_provider' => 'Record provider confirmation and mark Renewed (Admin only)',
    ],
    'platform' => [
        'reports.operational' => 'View operational reports',
        'reports.financial' => 'View financial reports (Admin only)',
        'audit.view' => 'View audit logs (Admin only)',
        'backups.manage' => 'Create/download backups (Admin only)',
        'settings.manage' => 'Edit platform settings (Admin only)',
    ],
];

$all = [];
foreach ($permissions as $group) {
    foreach (array_keys($group) as $key) {
        $all[] = $key;
    }
}

$roles = [
    'admin' => [
        'name' => 'Administrator',
        'description' => 'Full control. Only role that assigns projects, approves payouts, referrals and renewals.',
        'permissions' => $all, // explicit grant of every catalogue permission
    ],
    'manager' => [
        'name' => 'Manager',
        'description' => 'Operational oversight of assigned work. Explicitly limited; never inherits Admin rights.',
        'permissions' => [
            'users.view',
            'cms.view', 'cms.edit',
            'projects.view_assigned', 'projects.update_status',
            'milestones.view', 'milestones.create', 'milestones.propose', 'milestones.approve',
            'tasks.view', 'tasks.manage', 'tasks.update_assigned',
            'files.upload', 'files.download',
            'pricing.view', 'estimates.create', 'quotes.view', 'quotes.create',
            'payouts.view_own', 'payouts.request', 'commissions.view_own',
            'chat.use', 'revisions.manage', 'bugs.manage', 'warranty.manage',
            'requirements.view', 'appointments.manage',
            'renewals.view', 'renewals.manage',
            'reports.operational',
        ],
    ],
    'staff' => [
        'name' => 'Staff (salaried)',
        'description' => 'Salaried team member working on assigned projects; submits payout requests.',
        'permissions' => [
            'projects.view_assigned',
            'milestones.view', 'milestones.propose',
            'tasks.view', 'tasks.update_assigned',
            'files.upload', 'files.download',
            'payouts.request', 'payouts.view_own',
            'chat.use',
        ],
    ],
    'freelancer' => [
        'name' => 'Freelancer',
        'description' => 'External contributor on assigned projects; commission follows client amounts actually received.',
        'permissions' => [
            'projects.view_assigned',
            'milestones.view', 'milestones.propose',
            'tasks.view', 'tasks.update_assigned',
            'files.upload', 'files.download',
            'payouts.request', 'payouts.view_own', 'commissions.view_own',
            'chat.use',
        ],
    ],
    'video_editor' => [
        'name' => 'Video Editor',
        'description' => 'Specialist freelancer role for video work.',
        'permissions' => [
            'projects.view_assigned',
            'milestones.view', 'milestones.propose',
            'tasks.view', 'tasks.update_assigned',
            'files.upload', 'files.download',
            'payouts.request', 'payouts.view_own', 'commissions.view_own',
            'chat.use',
        ],
    ],
    'website_developer' => [
        'name' => 'Website Developer',
        'description' => 'Specialist freelancer role for web development.',
        'permissions' => [
            'projects.view_assigned',
            'milestones.view', 'milestones.propose',
            'tasks.view', 'tasks.update_assigned',
            'files.upload', 'files.download',
            'payouts.request', 'payouts.view_own', 'commissions.view_own',
            'chat.use',
        ],
    ],
    'referral_partner' => [
        'name' => 'Referral Partner',
        'description' => 'Earns 10% on eligible advance and 5% on full payment of referred clients. No project access.',
        'permissions' => [
            'referrals.view_own', 'commissions.view_own', 'payouts.request', 'payouts.view_own',
        ],
    ],
    'client' => [
        'name' => 'Client',
        'description' => 'Customer portal: own projects, invoices, payments, chat, revisions, bugs, warranty.',
        'permissions' => [
            'projects.view_own',
            'milestones.view', 'tasks.view',
            'files.download', 'files.upload',
            'invoices.view_own', 'receipts.view_own', 'payments.pay', 'quotes.view',
            'chat.use', 'revisions.request', 'bugs.report', 'warranty.view_own',
            'requirements.submit', 'appointments.book',
        ],
    ],
];

return [
    'catalogue' => $permissions,
    'roles' => $roles,
];
