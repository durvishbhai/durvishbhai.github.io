<?php

declare(strict_types=1);

/*
 * Business vocabulary and EXPLICIT state machines for CRM, projects, tasks and milestones.
 * Nothing is inferred: a transition that is not listed here is refused by the service layer.
 * (CRM stage NAMES/ORDER are configurable in the database via the admin UI; the won/lost semantics are by `kind`.)
 */

return [
    'services' => [
        'website_development' => 'Website development',
        'video_editing' => 'Video editing',
        'domain_hosting' => 'Domain & hosting',
        'other' => 'Other / not sure yet',
    ],

    // Form options only: ranges are labels, not prices. Edit freely; confirm with the business (decision D-31).
    'budget_ranges' => [
        'under_25k' => 'Under ₹25,000',
        '25k_100k' => '₹25,000 – ₹1,00,000',
        '100k_500k' => '₹1,00,000 – ₹5,00,000',
        'over_500k' => 'Above ₹5,00,000',
        'not_sure' => 'Not sure yet',
    ],

    'sources' => [
        'website_requirements' => 'Website requirements form',
        'contact_form' => 'Contact form',
        'appointment_request' => 'Appointment request',
        'manual' => 'Created by staff',
        'referral_partner' => 'Referral partner',
    ],

    'contact_channels' => ['call' => 'Phone call', 'email' => 'Email', 'meeting' => 'Meeting', 'message' => 'Message / chat', 'other' => 'Other'],

    'priorities' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'],

    'project_statuses' => [
        'draft' => 'Draft',
        'awaiting_client_approval' => 'Awaiting Client Approval',
        'awaiting_payment' => 'Awaiting Payment',
        'ready_to_start' => 'Ready to Start',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'in_review' => 'In Review',
        'awaiting_client_feedback' => 'Awaiting Client Feedback',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    /*
     * Project status machine: from => [to => required permission].
     * The permission is checked in ADDITION to access to the project. Completion is never automatic (not even when all
     * tasks are done): it needs `projects.complete` and an explicit confirmation. No transition here marks anything as
     * paid: financial status will come only from verified payment records (Phase 5).
     */
    'project_transitions' => [
        'draft' => ['awaiting_client_approval' => 'projects.update_status', 'ready_to_start' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'awaiting_client_approval' => ['draft' => 'projects.update_status', 'awaiting_payment' => 'projects.update_status', 'ready_to_start' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'awaiting_payment' => ['ready_to_start' => 'projects.update_status', 'on_hold' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'ready_to_start' => ['in_progress' => 'projects.update_status', 'on_hold' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'in_progress' => ['on_hold' => 'projects.update_status', 'in_review' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'on_hold' => ['ready_to_start' => 'projects.update_status', 'in_progress' => 'projects.update_status', 'cancelled' => 'projects.update_status'],
        'in_review' => ['in_progress' => 'projects.update_status', 'on_hold' => 'projects.update_status', 'awaiting_client_feedback' => 'projects.update_status', 'completed' => 'projects.complete'],
        'awaiting_client_feedback' => ['in_progress' => 'projects.update_status', 'in_review' => 'projects.update_status', 'completed' => 'projects.complete', 'cancelled' => 'projects.update_status'],
        'completed' => ['in_review' => 'projects.complete'],
        'cancelled' => ['draft' => 'projects.edit'],
    ],
    /** Transitions that must carry a written reason/note. */
    'project_transition_requires_note' => ['cancelled', 'on_hold'],
    /** Moving from awaiting_payment to ready_to_start is a MANUAL override until payment records exist (Phase 5). */
    'project_manual_payment_override' => ['awaiting_payment>ready_to_start'],

    'task_statuses' => [
        'backlog' => 'Backlog',
        'to_do' => 'To Do',
        'in_progress' => 'In Progress',
        'blocked' => 'Blocked',
        'in_review' => 'In Review',
        'changes_requested' => 'Changes Requested',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    /*
     * Task status machine: from => [to => who may make the move].
     *   A = the assignee (needs tasks.update_assigned and to be the assignee)
     *   E = an editor (tasks.edit)
     *   R = a reviewer (tasks.review)
     * Extra rules in TaskWorkflow: moving to in_progress / in_review / completed needs every dependency resolved;
     * in_progress -> completed directly is only for tasks with review_required = 0 (reviewers may always complete).
     */
    'task_transitions' => [
        'backlog' => ['to_do' => 'E', 'cancelled' => 'E'],
        'to_do' => ['backlog' => 'E', 'in_progress' => 'AE', 'blocked' => 'AE', 'cancelled' => 'E'],
        'in_progress' => ['to_do' => 'AE', 'blocked' => 'AE', 'in_review' => 'AE', 'completed' => 'AER', 'cancelled' => 'E'],
        'blocked' => ['to_do' => 'AE', 'in_progress' => 'AE', 'cancelled' => 'E'],
        'in_review' => ['in_progress' => 'AER', 'completed' => 'R', 'changes_requested' => 'R', 'cancelled' => 'E'],
        'changes_requested' => ['in_progress' => 'AE', 'to_do' => 'E', 'cancelled' => 'E'],
        'completed' => ['in_progress' => 'RE'],
        'cancelled' => ['backlog' => 'E'],
    ],

    'milestone_statuses' => [
        'proposed' => 'Proposed', 'planned' => 'Planned', 'in_progress' => 'In Progress',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected',
    ],
    // Milestone machine: from => [to => permission]. Proposals are decided with milestones.approve.
    'milestone_transitions' => [
        'proposed' => ['planned' => 'milestones.approve', 'rejected' => 'milestones.approve'],
        'planned' => ['in_progress' => 'milestones.edit', 'completed' => 'milestones.edit', 'cancelled' => 'milestones.edit'],
        'in_progress' => ['planned' => 'milestones.edit', 'completed' => 'milestones.edit', 'cancelled' => 'milestones.edit'],
        'completed' => ['in_progress' => 'milestones.edit'],
        'cancelled' => ['planned' => 'milestones.edit'],
        'rejected' => [],
    ],

    'due_soon_days' => 7,

    // ---- Phase 5: support tickets, bug reports, warranty ------------------------------------------------------------------------

    'ticket_categories' => [
        'bug_report' => 'Bug Report', 'technical_support' => 'Technical Support', 'project_query' => 'Project Query', 'billing' => 'Billing',
        'payment' => 'Payment', 'account_access' => 'Account Access', 'domain_hosting' => 'Domain and Hosting', 'general_enquiry' => 'General Enquiry', 'other' => 'Other',
    ],
    /** Categories a worker (tickets.view_technical) may ever see: never billing, payment or account-access tickets. */
    'ticket_technical_categories' => ['bug_report', 'technical_support', 'project_query', 'domain_hosting'],

    // SEVERITY (how bad the problem is) and PRIORITY (how urgently staff schedule it) are separate fields.
    'bug_severities' => [
        'low' => 'Low: minor visual or non-critical issue', 'normal' => 'Normal: standard functional issue',
        'high' => 'High: significant feature disruption', 'critical' => 'Critical: major outage or severe business impact',
    ],
    'bug_severity_short' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'],

    'bug_statuses' => [
        'draft' => 'Draft', 'open' => 'Open', 'triaged' => 'Triaged', 'confirmed' => 'Confirmed', 'in_progress' => 'In Progress', 'fixed' => 'Fixed',
        'client_verification' => 'Client Verification', 'closed' => 'Closed', 'need_more_information' => 'Need More Information',
        'unable_to_reproduce' => 'Unable to Reproduce', 'rejected' => 'Rejected with Explanation',
    ],
    'support_statuses' => [
        'open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In Progress', 'awaiting_client_response' => 'Awaiting Client Response',
        'awaiting_internal_team' => 'Awaiting Internal Team', 'resolved' => 'Resolved', 'closed' => 'Closed', 'reopened' => 'Reopened',
    ],
    'ticket_statuses_closed' => ['closed'],
    'ticket_statuses_resolved' => ['resolved', 'closed', 'rejected', 'unable_to_reproduce'],

    /*
     * Who may make a move. Letters: S staff handling the ticket (bugs.manage for bugs, tickets.update_status for support tickets),
     * F fixer (bugs.fix), K closer (tickets.close), C the owning client (tickets.reply_own), X = only the dedicated workflow
     * (submit a draft, the chargeable-cost approval). The client can never make a technical move (triage, confirm, in progress, fixed).
     */
    'bug_transitions' => [
        'draft' => ['open' => 'X'],
        'open' => ['triaged' => 'S', 'need_more_information' => 'S', 'unable_to_reproduce' => 'S', 'rejected' => 'S', 'closed' => 'K'],
        'triaged' => ['confirmed' => 'S', 'need_more_information' => 'S', 'unable_to_reproduce' => 'S', 'rejected' => 'S', 'closed' => 'K'],
        'confirmed' => ['in_progress' => 'SF', 'need_more_information' => 'S', 'rejected' => 'S', 'closed' => 'K'],
        'in_progress' => ['fixed' => 'SF', 'confirmed' => 'S', 'need_more_information' => 'S', 'closed' => 'K'],
        'fixed' => ['client_verification' => 'SF', 'in_progress' => 'SF', 'closed' => 'K'],
        'client_verification' => ['closed' => 'CK', 'open' => 'C', 'in_progress' => 'S'],
        'need_more_information' => ['open' => 'C', 'rejected' => 'S', 'closed' => 'K'],
        'unable_to_reproduce' => ['open' => 'C', 'closed' => 'CK'],
        'rejected' => ['open' => 'C', 'closed' => 'K'],
        'closed' => ['open' => 'CK'],
    ],
    'support_transitions' => [
        'open' => ['assigned' => 'S', 'in_progress' => 'S', 'awaiting_client_response' => 'S', 'awaiting_internal_team' => 'S', 'resolved' => 'S', 'closed' => 'K'],
        'assigned' => ['open' => 'S', 'in_progress' => 'S', 'awaiting_client_response' => 'S', 'awaiting_internal_team' => 'S', 'resolved' => 'S', 'closed' => 'K'],
        'in_progress' => ['assigned' => 'S', 'awaiting_client_response' => 'S', 'awaiting_internal_team' => 'S', 'resolved' => 'S', 'closed' => 'K'],
        'awaiting_client_response' => ['in_progress' => 'SC', 'awaiting_internal_team' => 'S', 'resolved' => 'S', 'closed' => 'K'],
        'awaiting_internal_team' => ['in_progress' => 'S', 'awaiting_client_response' => 'S', 'resolved' => 'S', 'closed' => 'K'],
        'resolved' => ['closed' => 'CK', 'reopened' => 'CS'],
        'closed' => ['reopened' => 'CK'],
        'reopened' => ['assigned' => 'S', 'in_progress' => 'S', 'awaiting_client_response' => 'S', 'awaiting_internal_team' => 'S', 'resolved' => 'S', 'closed' => 'K'],
    ],
    /** Moves that need a written explanation (shown to the client). */
    'ticket_note_required' => ['rejected', 'unable_to_reproduce', 'need_more_information', 'reopened_by_client', 'open_by_client'],

    // Warranty review of a bug report.
    'bug_eligibility' => [
        'awaiting_technical_review' => 'Awaiting technical review',
        'submitted_for_assessment' => 'Submitted for assessment',
        'covered' => 'Covered by the warranty',
        'not_covered' => 'Not covered by the warranty',
        'potentially_chargeable' => 'Potentially chargeable',
    ],
    /** Points a reviewer may weigh. They are CONSIDERATIONS only, never automatic exclusions: the contract decides. */
    'bug_considerations' => [
        'new_feature' => 'Request for a new feature', 'scope_change' => 'Change to the originally approved scope', 'client_modification' => 'Client modification of code or configuration',
        'third_party' => 'Third-party service failure', 'hosting_domain_api' => 'Hosting, domain or external API problem', 'environment_change' => 'Unsupported software or environment change',
    ],

    'warranty_statuses' => [
        'not_applicable' => 'Not Applicable', 'pending_confirmation' => 'Pending Confirmation', 'scheduled' => 'Scheduled',
        'active' => 'Active', 'expired' => 'Expired', 'suspended' => 'Suspended',
    ],
    'warranty_start_bases' => ['final_delivery' => 'Final delivery', 'client_acceptance' => 'Client acceptance', 'contract_date' => 'Manually confirmed contractual start date'],


    // ---- Phase 3: deliverables, versions, revisions ------------------------------------------------------------------------

    'deliverable_categories' => [
        'video' => 'Video', 'thumbnail' => 'Thumbnail', 'graphic_design' => 'Graphic Design', 'website_files' => 'Website Files',
        'document' => 'Document', 'source_files' => 'Source Files', 'other' => 'Other',
    ],

    // Lifecycle/visibility of the deliverable as a whole. Only `published` (and `archived` after having been published) reaches clients.
    'deliverable_statuses' => [
        'draft' => 'Draft', 'pending_review' => 'Pending Review', 'approved_internal' => 'Approved Internally',
        'published' => 'Published to Client', 'archived' => 'Archived',
    ],

    // Each VERSION has its own status; approval/publication never carries over from one version to another.
    'version_statuses' => [
        'draft' => 'Draft', 'pending_review' => 'Pending Review', 'changes_requested' => 'Changes Requested (internal)',
        'approved_internal' => 'Approved Internally', 'published' => 'Published (current)', 'superseded' => 'Superseded', 'withdrawn' => 'Withdrawn',
    ],

    'revision_statuses' => [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'included_revision' => 'Included Revision',
        'potentially_chargeable' => 'Potentially Chargeable Change',
        'awaiting_cost_approval' => 'Awaiting Client Approval of Additional Cost',
        'approved_for_work' => 'Approved for Work',
        'in_progress' => 'In Progress',
        'ready_for_client_review' => 'Ready for Client Review',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected with Explanation',
        'cancelled' => 'Cancelled',
    ],

    // What the CLIENT is shown. "Potentially chargeable" is an internal assessment: until a proposal is sent the client just sees "Under review".
    'revision_client_labels' => [
        'submitted' => 'Received',
        'under_review' => 'Under review',
        'included_revision' => 'Accepted: included in your project',
        'potentially_chargeable' => 'Under review',
        'awaiting_cost_approval' => 'Additional cost awaiting your approval',
        'approved_for_work' => 'Approved for work',
        'in_progress' => 'In progress',
        'ready_for_client_review' => 'Ready for your review',
        'resolved' => 'Resolved',
        'rejected' => 'Not accepted',
        'cancelled' => 'Cancelled',
    ],

    /*
     * Revision status machine: from => [to => who]. Letters:
     *   S staff reviewer (revisions.manage + staff access to the project)   K classifier (revisions.classify)
     *   O override (revisions.override: administrators)                     C the owning client (revisions.request)
     *   X not a direct move: only the dedicated workflow (send proposal / client cost decision) may perform it.
     */
    'revision_transitions' => [
        'submitted' => ['under_review' => 'S', 'rejected' => 'S', 'cancelled' => 'SC'],
        'under_review' => ['included_revision' => 'K', 'potentially_chargeable' => 'K', 'rejected' => 'S', 'cancelled' => 'SC'],
        'included_revision' => ['in_progress' => 'S', 'under_review' => 'K', 'rejected' => 'S', 'cancelled' => 'SC'],
        'potentially_chargeable' => ['awaiting_cost_approval' => 'X', 'under_review' => 'K', 'included_revision' => 'O', 'rejected' => 'S', 'cancelled' => 'SC'],
        'awaiting_cost_approval' => ['approved_for_work' => 'X', 'potentially_chargeable' => 'X', 'cancelled' => 'SC'],
        'approved_for_work' => ['in_progress' => 'S', 'cancelled' => 'SC'],
        'in_progress' => ['ready_for_client_review' => 'S', 'cancelled' => 'S'],
        'ready_for_client_review' => ['resolved' => 'SC', 'in_progress' => 'SC'],
        'resolved' => [],
        'rejected' => [],
        'cancelled' => [],
    ],
    /** Moves that need a written explanation that the client can read. */
    'revision_transition_requires_note' => ['rejected', 'in_progress_from_review'],

];
