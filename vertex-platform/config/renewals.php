<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Domain / hosting / SSL renewals (Phase 7).
 *
 * Nothing here talks to a registrar or hosting provider. A renewal is "Renewed" only when a person records the provider's confirmation; payment never
 * implies renewal. See docs/15-PHASE7-RENEWALS-OPERATIONS.md.
 */
return [
    'types' => ['domain' => 'Domain', 'hosting' => 'Hosting', 'ssl' => 'SSL certificate', 'other' => 'Other'],

    'statuses' => [
        'active' => 'Active', 'renewal_approaching' => 'Renewal Approaching', 'awaiting_admin_review' => 'Awaiting Admin Review', 'approved_for_renewal' => 'Approved for Renewal',
        'invoice_issued' => 'Invoice Issued', 'awaiting_payment' => 'Awaiting Payment', 'payment_received' => 'Payment Received', 'renewal_in_progress' => 'Renewal in Progress',
        'renewed' => 'Renewed', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'on_hold' => 'On Hold',
    ],

    /**
     * Staff moves. The automatic moves (approaching, review, expired) are made by the scheduler and are listed too so they can also be made by hand.
     * `renewed` has no entry here: it is reachable ONLY through RenewalService::recordProviderConfirmation. Approval, invoice, payment and the start of the
     * provider work each have their own action with their own permission, so none of them is a bare status change.
     */
    'manual_transitions' => [
        'active' => ['renewal_approaching', 'awaiting_admin_review'],
        'renewed' => ['renewal_approaching', 'awaiting_admin_review'],
        'renewal_approaching' => ['awaiting_admin_review'],
        'expired' => ['awaiting_admin_review'],
    ],
    // statuses the scheduler may turn into Expired when the expiry date has passed without provider confirmation (paid / in-progress renewals are flagged overdue instead)
    'expirable' => ['active', 'renewal_approaching', 'awaiting_admin_review', 'approved_for_renewal', 'invoice_issued', 'awaiting_payment'],
    'cancellable' => ['active', 'renewed', 'renewal_approaching', 'awaiting_admin_review', 'approved_for_renewal', 'invoice_issued', 'awaiting_payment', 'payment_received', 'renewal_in_progress', 'expired', 'on_hold'],
    'holdable' => ['active', 'renewed', 'renewal_approaching', 'awaiting_admin_review', 'approved_for_renewal', 'invoice_issued', 'awaiting_payment', 'expired'],

    'lead' => [
        'approach_days' => (int) Env::get('RENEWAL_APPROACH_DAYS', 30),   // Active/Renewed -> Renewal Approaching
        'review_days' => (int) Env::get('RENEWAL_REVIEW_DAYS', 10),       // -> Awaiting Admin Review (the approval is due about 10 days before expiry)
        'invoice_due_days' => 5,                                          // approved but still no invoice this many days before expiry: remind staff
        'confirmation_due_days' => 3,                                     // paid but the provider has not confirmed this many days before expiry: remind staff
    ],
    // Reminder offsets in days before expiry (0 = on the expiry day). At most the SMALLEST offset that has been reached and not yet sent fires, so a missed cron
    // run never produces a burst of old reminders.
    'reminder_days' => [30, 10, 3, 1, 0],
    // Clients are reminded in the portal. Email to a client is attempted ONLY when this is true AND a mail driver is configured; every attempt is recorded.
    'client_email' => filter_var(Env::get('RENEWAL_CLIENT_EMAIL', false), FILTER_VALIDATE_BOOL),
    'client_reminder_statuses' => ['active', 'renewal_approaching', 'awaiting_admin_review', 'approved_for_renewal', 'invoice_issued', 'awaiting_payment', 'expired'],
    'email_max_attempts' => 3,

    'periods' => [1, 3, 6, 12, 24, 36, 60, 120],
    'payment_methods' => ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'card' => 'Card', 'cheque' => 'Cheque', 'cash' => 'Cash', 'gateway' => 'Payment gateway', 'other' => 'Other'],
];
