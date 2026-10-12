<?php

declare(strict_types=1);

/*
 * Catalogue of permissions + DEFAULT role grants.
 *
 * The database is the live source of truth (admins edit role grants in the UI). `permissions:sync`:
 *   - adds new catalogue permissions, removes permissions no longer in the catalogue,
 *   - refreshes the `is_sensitive` flag,
 *   - creates missing system roles and gives each role the defaults below ONLY for permissions that are new,
 *     so UI edits are never overwritten by a deploy,
 *   - always re-grants EVERY permission to the locked Super Admin role,
 *   - `--reset-roles` restores the defaults below for all non-locked system roles (audited).
 *
 * Rules (enforced by tests):
 *  - No wildcard and no implicit bypass: Super Admin holds permissions explicitly.
 *  - `level` orders privilege. A user may only manage users/roles with a strictly lower rank than their own highest.
 *  - Sensitive permissions can be granted/revoked in the UI only by someone holding `roles.manage_sensitive`
 *    (Super Admin by default).
 *  - Permissions named *.view_assigned / *.view_own / *_own are SCOPED: they open a feature; per-record access is
 *    enforced by policy classes (see App\Services\ProjectPolicy).
 */

$p = [
    'users' => [
        'users.view' => 'View user accounts',
        'users.create' => 'Create/invite user accounts',
        'users.edit' => 'Edit user profile details',
        'users.deactivate' => 'Deactivate and reactivate accounts (revokes access)',
        'users.archive' => 'Archive accounts',
        'users.assign_roles' => 'Change which roles a user holds',
        'users.reset_password' => 'Send a password-reset email to a user',
    ],
    'roles' => [
        'roles.view' => 'View roles and permission grants',
        'roles.manage' => 'Edit non-sensitive permission grants of lower-ranked roles',
        'roles.manage_sensitive' => 'Grant/revoke sensitive permissions',
    ],
    'platform' => [
        'audit.view' => 'View audit logs',
        'settings.view' => 'View system settings',
        'settings.manage' => 'Change system settings',
        'reports.operational' => 'View operational reports',
        'reports.financial' => 'View financial reports',
        'backups.manage' => 'Create/download backups',
    ],
    'projects' => [
        'projects.view_all' => 'View every project',
        'projects.view_assigned' => 'View projects the user is assigned to',
        'projects.view_own' => 'View projects owned by the client',
        'projects.create' => 'Create projects',
        'projects.edit' => 'Edit project details',
        'projects.update_status' => 'Change project status',
        'projects.archive' => 'Archive projects',
        'projects.assign' => 'Assign and unassign team members on projects',
        'projects.complete' => 'Confirm project completion (and reopen)',
        'projects.set_priority' => 'Change project priority',
        'projects.notes_internal' => 'View and add internal project notes',
        'projects.notes_client' => 'Post client-visible project notes',
        'projects.give_feedback' => 'Give feedback on own project (client)',
        'projects.record_approval' => 'Record the approved quotation baseline of a project',
    ],
    'delivery' => [
        'milestones.view' => 'View milestones on accessible projects',
        'milestones.create' => 'Create milestones directly',
        'milestones.edit' => 'Edit milestones and change their status',
        'milestones.propose' => 'Propose milestones for approval',
        'milestones.approve' => 'Approve or reject proposed milestones',
        'tasks.view' => 'View tasks on accessible projects',
        'tasks.create' => 'Create tasks on accessible projects',
        'tasks.edit' => 'Edit tasks and move them between any statuses',
        'tasks.assign' => 'Assign tasks to project team members',
        'tasks.review' => 'Review tasks (approve or request changes)',
        'tasks.archive' => 'Archive tasks',
        'tasks.comment' => 'Comment on accessible tasks',
        'tasks.log_time' => 'Log time on tasks assigned to the user',
        'tasks.update_assigned' => 'Update status of tasks assigned to the user',
        'files.upload' => 'Upload files to accessible projects',
        'files.download' => 'Download files on accessible projects',
        'files.manage' => 'Delete or replace files',
        'deliverables.view' => 'View deliverables',
        'deliverables.upload' => 'Upload deliverables',
        'deliverables.publish' => 'Publish deliverables to the client and choose the client-facing version',
        'deliverables.view_drafts' => 'View draft, in-review and approved-but-unpublished deliverables on accessible projects',
        'deliverables.review' => 'Approve internally or send back deliverables in QA review',
        'deliverables.archive' => 'Archive and restore deliverables',
        'deliverables.respond' => 'Approve, reject or request revisions on own published deliverables (client)',
    ],
    'website' => [
        'cms.view' => 'View website content',
        'cms.edit' => 'Edit draft website content',
        'cms.publish' => 'Publish website content',
        'legal.manage' => 'Edit legal pages and policies',
    ],
    'pricing' => [
        'pricing.view' => 'View pricing rules and priority charges',
        'pricing.manage' => 'Edit pricing rules and priority charges',
        'estimates.create' => 'Create feature-based estimates',
        'quotes.view' => 'View quotations',
        'quotes.create' => 'Draft quotations',
        'quotes.send' => 'Send quotations to clients',
        'quotes.respond' => 'Accept or decline own quotations (client)',
        'quotes.record_decision' => 'Record a quotation decision received outside the portal',
        'pricing.request_change' => 'Propose a price change (e.g. from a priority change) for client approval',
        'pricing.respond_change' => 'Approve or reject a proposed price change on own project (client)',
    ],
    'finance' => [
        'finance.view' => 'View financial data',
        'invoices.view_all' => 'View all invoices',
        'invoices.view_own' => 'View own invoices (client)',
        'invoices.create' => 'Draft invoices',
        'invoices.edit' => 'Edit draft invoices',
        'invoices.issue' => 'Issue invoices as payable',
        'invoices.void' => 'Void invoices',
        'receipts.view_own' => 'View own receipts (client)',
        'payments.view_all' => 'View all client payments',
        'payments.pay' => 'Pay own invoices (client)',
        'payments.record' => 'Record payments',
        'payments.reconcile' => 'Reconcile payments with the gateway',
        'billing.configure' => 'Edit business details and the Google Pay settings',
        'transactions.approve' => 'Approve financial transactions',
    ],
    'payouts' => [
        'payouts.request' => 'Submit a payout request',
        'payouts.view_own' => 'View own payout requests',
        'payouts.view_all' => 'View all payout requests',
        'payouts.approve' => 'Approve or reject payout requests',
        'payouts.record' => 'Record a manual payout as paid',
        'commissions.view_own' => 'View own commission ledger',
        'commissions.view_all' => 'View all commission ledgers',
        'commissions.rules.manage' => 'Edit commission rules',
        'commissions.approve' => 'Approve, hold or release commission entries',
        'commissions.adjust' => 'Adjust or reverse commission entries',
    ],
    'referrals' => [
        'referrals.view_own' => 'View own referral records',
        'referrals.review' => 'View referral applications',
        'referrals.decide' => 'Approve or reject partner applications; decide referral attribution; record partner terms',
        'referrals.manage' => 'View all referrals; update referral status, notes and CRM links; send previewed status emails',
        'referrals.submit' => 'Submit referrals (approved referral partner)',
    ],
    'support' => [
        'chat.use' => 'Use project chat on accessible projects',
        'revisions.request' => 'Request a revision (client)',
        'revisions.manage' => 'Triage and resolve revisions',
        'revisions.classify' => 'Classify revisions as included or potentially chargeable and propose extra cost',
        'revisions.override' => 'Override a revision classification (audited)',
        'revisions.policy' => 'Manage revision policies and terms',
        'revisions.respond_cost' => 'Approve or decline a proposed additional cost on own project (client)',
        'bugs.report' => 'Report a bug (client)',
        'bugs.manage' => 'Triage bug reports: triage, confirm, request information, reject, change severity/priority',
        'bugs.fix' => 'Mark assigned bug fixes in progress and fixed',
        'bugs.review_eligibility' => 'Decide the warranty eligibility of a bug report',
        'tickets.create' => 'Create support tickets (client)',
        'tickets.view_own' => 'View own support tickets and bug reports (client)',
        'tickets.reply_own' => 'Reply to, confirm or reopen own tickets (client)',
        'tickets.view_all' => 'View every ticket and bug report',
        'tickets.view_assigned' => 'View tickets on assigned projects and tickets assigned to the user',
        'tickets.view_technical' => 'View technical tickets and bug reports on assigned projects (no billing/account tickets)',
        'tickets.reply' => 'Reply to clients on tickets',
        'tickets.note' => 'Add internal notes and technical findings',
        'tickets.assign' => 'Assign and reassign tickets',
        'tickets.update_status' => 'Move tickets through their status workflow',
        'tickets.close' => 'Close and reopen tickets',
        'tickets.escalate' => 'Escalate a ticket',
        'tickets.propose_change' => 'Propose a chargeable change for a ticket (client approval still required)',
        'support.dashboard' => 'View the support dashboards and warranty reports',
        'warranty.view_own' => 'View own warranty status (client)',
        'warranty.view' => 'View recorded warranty terms and dates (staff)',
        'warranty.manage' => 'Configure contractual warranty settings (duration, start date, terms, suspension)',
        'warranty.override' => 'Override a recorded warranty eligibility decision (audited)',
        'requirements.submit' => 'Submit a requirements form (client)',
        'requirements.view' => 'View submitted requirements',
        'appointments.book' => 'Book an appointment (client)',
        'appointments.manage' => 'Manage appointments',
    ],
    'crm' => [
        'enquiries.view_all' => 'View every enquiry',
        'enquiries.view_assigned' => 'View enquiries the user owns',
        'enquiries.view_own' => 'View enquiries the user submitted (referral partner)',
        'enquiries.create' => 'Create enquiries manually',
        'enquiries.edit' => 'Edit enquiry details and attachments',
        'enquiries.assign' => 'Assign an internal owner to an enquiry',
        'enquiries.move_stage' => 'Move enquiries between open pipeline stages and to Lost',
        'enquiries.mark_won' => 'Mark an enquiry Won (explicit, confirmed action)',
        'enquiries.reopen' => 'Reopen a Won/Lost enquiry that was not converted',
        'enquiries.convert' => 'Convert a Won enquiry into a client and draft project',
        'enquiries.notes' => 'Add notes, contact history and follow-ups',
        'enquiries.archive' => 'Archive enquiries',
        'enquiries.submit_referral' => 'Submit referral enquiries (referral partner)',
        'crm.configure' => 'Configure CRM pipeline stages',
    ],
    'clients' => [
        'clients.view_all' => 'View every client record',
        'clients.view_assigned' => 'View clients of projects the user is assigned to',
        'clients.view_own' => 'View own client record (client portal)',
        'clients.create' => 'Create client records',
        'clients.edit' => 'Edit client records',
        'clients.archive' => 'Archive clients',
        'clients.merge' => 'Merge duplicate client records',
        'clients.invite' => 'Invite a client to the portal',
        'clients.notes_view' => 'View restricted client notes',
        'clients.notes_add' => 'Add restricted client notes',
    ],
    'renewals' => [
        'renewals.view' => 'View domain/hosting renewals',
        'renewals.manage' => 'Create and edit renewal records',
        'renewals.approve' => 'Record Admin approval for a renewal',
        'renewals.confirm_provider' => 'Record provider confirmation and mark Renewed',
        'renewals.prices' => 'Edit the renewal price list',
        'renewals.view_own' => 'View own domain/hosting services (client)',
    ],
];

/** Granting/revoking these through the UI needs `roles.manage_sensitive`. */
$sensitive = [
    'users.assign_roles', 'roles.manage', 'roles.manage_sensitive', 'audit.view',
    'settings.manage', 'backups.manage', 'reports.financial', 'finance.view',
    'projects.assign', 'transactions.approve', 'payments.record', 'payments.reconcile',
    'invoices.issue', 'invoices.void', 'payouts.approve', 'payouts.record',
    'commissions.rules.manage', 'commissions.approve', 'commissions.adjust', 'referrals.decide', 'renewals.approve', 'renewals.confirm_provider', 'renewals.prices', 'billing.configure', 'quotes.record_decision',
    'pricing.manage', 'quotes.send',
    'crm.configure', 'clients.merge', 'clients.invite', 'projects.record_approval',
    'revisions.override', 'revisions.policy', 'warranty.manage', 'warranty.override',
];

$all = [];
foreach ($p as $group) {
    foreach (array_keys($group) as $key) {
        $all[] = $key;
    }
}

$worker = [
    'projects.view_assigned',
    'milestones.view', 'milestones.propose',
    'tasks.view', 'tasks.update_assigned', 'tasks.comment', 'tasks.log_time',
    'files.upload', 'files.download',
    'deliverables.view', 'deliverables.upload', 'deliverables.view_drafts',
    'tickets.view_technical', 'tickets.note', 'bugs.fix',
    'payouts.request', 'payouts.view_own', 'commissions.view_own',
    'chat.use',
];

$roles = [
    'super_admin' => [
        'name' => 'Super Admin / Owner',
        'description' => 'Owner. Holds every permission; the only role that can change sensitive grants and system settings. Created via console only.',
        'level' => 100, 'locked' => true, 'assignable_in_ui' => false, 'phone_required' => false,
        'permissions' => $all,
    ],
    'administrator' => [
        'name' => 'Administrator',
        'description' => 'Runs day-to-day administration, assigns projects, approves payouts. Cannot change sensitive permission grants or system settings.',
        'level' => 90, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => false,
        'permissions' => array_values(array_diff($all, [
            'roles.manage_sensitive', 'settings.manage', 'backups.manage',
            // client/partner-only actions: an Administrator must not act as the client or as a referral partner
            'pricing.respond_change', 'projects.give_feedback', 'enquiries.submit_referral', 'enquiries.view_own', 'clients.view_own',
            'referrals.submit', 'referrals.view_own', 'renewals.view_own', 'quotes.respond',
            'deliverables.respond', 'revisions.respond_cost', 'revisions.request',
            'bugs.report', 'tickets.create', 'tickets.view_own', 'tickets.reply_own', 'warranty.view_own',
        ])),
    ],
    'manager' => [
        'name' => 'Manager / Salaried Staff',
        'description' => 'Operational work on assigned projects. Never inherits Administrator rights.',
        'level' => 60, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => false,
        'permissions' => [
            'cms.view', 'cms.edit',
            'projects.view_assigned', 'projects.update_status', 'projects.edit', 'projects.complete', 'projects.set_priority',
            'projects.notes_internal', 'projects.notes_client',
            'milestones.view', 'milestones.create', 'milestones.edit', 'milestones.propose', 'milestones.approve',
            'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.assign', 'tasks.review', 'tasks.archive', 'tasks.comment', 'tasks.log_time', 'tasks.update_assigned',
            'enquiries.view_assigned', 'enquiries.create', 'enquiries.edit', 'enquiries.move_stage', 'enquiries.mark_won', 'enquiries.notes',
            'clients.view_assigned', 'clients.notes_view', 'clients.notes_add',
            'pricing.request_change',
            'files.upload', 'files.download',
            'deliverables.view', 'deliverables.upload', 'deliverables.publish', 'deliverables.view_drafts', 'deliverables.review', 'deliverables.archive',
            'revisions.classify',
            'pricing.view', 'estimates.create', 'quotes.view', 'quotes.create',
            'payouts.request', 'payouts.view_own', 'commissions.view_own',
            'chat.use', 'revisions.manage', 'bugs.manage', 'bugs.fix', 'bugs.review_eligibility',
            'tickets.view_assigned', 'tickets.reply', 'tickets.note', 'tickets.assign', 'tickets.update_status', 'tickets.close', 'tickets.escalate', 'tickets.propose_change',
            'support.dashboard', 'warranty.view',
            'requirements.view', 'appointments.manage',
            'renewals.view', 'renewals.manage',
            'reports.operational',
        ],
    ],
    'finance_staff' => [
        'name' => 'Finance Staff',
        'description' => 'Explicitly limited financial operations. No project access, no approvals.',
        'level' => 50, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => false,
        'permissions' => [
            'finance.view',
            'invoices.view_all', 'invoices.create', 'invoices.edit',
            'payments.view_all', 'payments.record',
            'payouts.view_all', 'payouts.record', 'commissions.view_all',
        ],
    ],
    'support_staff' => [
        'name' => 'Support Staff',
        'description' => 'Client support on assigned projects: chat, revisions, bugs, warranty, appointments.',
        'level' => 50, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => false,
        'permissions' => [
            'projects.view_assigned', 'milestones.view', 'tasks.view', 'tasks.comment',
            'clients.view_assigned',
            'files.download', 'deliverables.view',
            'chat.use', 'revisions.manage', 'bugs.manage', 'warranty.view',
            'tickets.view_assigned', 'tickets.reply', 'tickets.note', 'tickets.update_status', 'tickets.close', 'tickets.escalate', 'support.dashboard',
            'requirements.view', 'appointments.manage',
        ],
    ],
    'freelancer' => [
        'name' => 'Freelancer',
        'description' => 'External contributor on assigned projects.',
        'level' => 30, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => true,
        'permissions' => $worker,
    ],
    'video_editor' => [
        'name' => 'Video Editor',
        'description' => 'Specialist freelancer role for video work.',
        'level' => 30, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => true,
        'permissions' => $worker,
    ],
    'website_developer' => [
        'name' => 'Website Developer',
        'description' => 'Specialist freelancer role for web development.',
        'level' => 30, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => true,
        'permissions' => $worker,
    ],
    'referral_partner' => [
        'name' => 'Referral Partner',
        'description' => 'Sees only own referral records and commissions. No project access.',
        'level' => 20, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => true,
        'permissions' => ['referrals.view_own', 'referrals.submit', 'enquiries.submit_referral', 'enquiries.view_own', 'commissions.view_own', 'payouts.request', 'payouts.view_own'],
    ],
    'client' => [
        'name' => 'Client',
        'description' => 'Customer portal: own projects, invoices, payments, support.',
        'level' => 10, 'locked' => false, 'assignable_in_ui' => true, 'phone_required' => true,
        'permissions' => [
            'projects.view_own', 'projects.give_feedback', 'clients.view_own', 'pricing.respond_change',
            'milestones.view',
            'files.download', 'files.upload', 'deliverables.view', 'deliverables.respond', 'revisions.respond_cost',
            'invoices.view_own', 'receipts.view_own', 'payments.pay', 'quotes.view',
            'chat.use', 'revisions.request', 'bugs.report', 'warranty.view_own', 'tickets.create', 'tickets.view_own', 'tickets.reply_own',
            'requirements.submit', 'appointments.book', 'renewals.view_own', 'quotes.respond',
        ],
    ],
];

return [
    'catalogue' => $p,
    'sensitive' => $sensitive,
    'roles' => $roles,
];
