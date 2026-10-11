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
];
