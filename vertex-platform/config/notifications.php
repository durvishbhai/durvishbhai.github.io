<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Email copies of in-app notifications (EmailChannel). Every notification the portal creates for a person is also emailed to them in the standard KIS design,
 * unless its event is in `skip` (it is already emailed by its own flow, or it needs a human to confirm the wording first).
 *
 * Category and tone are chosen by the longest matching event prefix. Nothing is invented here: wording comes from the notification itself, written for its recipient.
 */
$on = static fn (string $key, bool $default): bool => filter_var(Env::get($key, $default), FILTER_VALIDATE_BOOL);

return [
    'email_clients' => $on('NOTIFY_EMAIL_CLIENTS', true),   // clients and referral partners
    'email_staff' => $on('NOTIFY_EMAIL_STAFF', true),       // KIS team members
    'push' => $on('NOTIFY_PUSH', true),                      // phone and browser notifications for devices that allowed them
    'login_alert' => $on('NOTIFY_LOGIN_ALERT', true),       // "a new sign-in to your account" email
    'max_attempts' => 3,

    // Not emailed from here. renewal.client_reminder: the renewal scheduler sends its own dated email. user.registration_approved: AccountMail::approved.
    // referral.status_changed: a person previews and confirms that wording before it is emailed.
    'skip' => ['renewal.client_reminder', 'user.registration_approved', 'referral.status_changed'],

    'categories' => [
        'payment' => ['Payment', 'success'], 'receipt' => ['Receipt', 'success'], 'invoice' => ['Invoice', 'info'], 'credit_note' => ['Invoice', 'info'],
        'client_payment' => ['Payment', 'info'], 'project' => ['Project', 'success'], 'deliverable' => ['Project update', 'info'], 'revision' => ['Project update', 'info'],
        'scope' => ['Project change', 'info'], 'change_request' => ['Project change', 'warning'], 'quote' => ['Quotation', 'info'], 'agreement' => ['Agreement', 'info'],
        'ticket' => ['Support', 'info'], 'renewal' => ['Renewal', 'info'], 'referral' => ['Referral partner', 'info'], 'partner' => ['Referral partner', 'info'],
        'commission' => ['Referral partner', 'info'], 'payout' => ['Referral partner', 'info'], 'user' => ['Account', 'info'], 'career' => ['Careers', 'info'],
        'enquiry' => ['Enquiry', 'info'], 'form' => ['Form', 'info'], 'automation' => ['Notification', 'info'],
    ],
    // More specific wording or colour for individual events. 'button' is the label of the one action button.
    'events' => [
        'payment.confirmed' => ['category' => 'Payment successful', 'tone' => 'success', 'button' => 'View invoice'],
        'payment.not_confirmed' => ['category' => 'Payment not confirmed', 'tone' => 'danger', 'button' => 'View invoice'],
        'payment.failed' => ['category' => 'Payment failed', 'tone' => 'danger', 'button' => 'Try again'],
        'payment.requested' => ['category' => 'Payment request', 'tone' => 'info', 'button' => 'Pay now'],
        'receipt.issued' => ['category' => 'Payment receipt', 'tone' => 'success', 'button' => 'View receipt'],
        'invoice.issued' => ['category' => 'Invoice', 'tone' => 'info', 'button' => 'View invoice'],
        'invoice.paid' => ['category' => 'Invoice paid', 'tone' => 'success', 'button' => 'View invoice'],
        'credit_note.issued' => ['category' => 'Credit note', 'tone' => 'info', 'button' => 'View credit note'],
        'project.confirmed' => ['category' => 'Project confirmed', 'tone' => 'success', 'button' => 'View project'],
        'quote.sent' => ['category' => 'Quotation', 'tone' => 'info', 'button' => 'Review quotation'],
        'agreement.sent' => ['category' => 'Agreement', 'tone' => 'warning', 'button' => 'Review and sign'],
        'change_request.awaiting_approval' => ['category' => 'Action required', 'tone' => 'warning', 'button' => 'Review and decide'],
        'renewal.price_raised' => ['category' => 'Renewal price update', 'tone' => 'warning', 'button' => 'View renewal'],
        'renewal.payment_received' => ['category' => 'Renewal payment', 'tone' => 'success', 'button' => 'View renewal'],
        'renewal.completed' => ['category' => 'Renewal complete', 'tone' => 'success', 'button' => 'View renewal'],
        'partner.application_approved' => ['category' => 'Referral partner', 'tone' => 'success', 'button' => 'Open Partner portal'],
        'partner.application_rejected' => ['category' => 'Referral partner', 'tone' => 'warning'],
        'scope.declined' => ['category' => 'Project change', 'tone' => 'warning'],
        'ticket.sla_breached' => ['category' => 'Support', 'tone' => 'warning'],
        'ticket.escalated' => ['category' => 'Support', 'tone' => 'warning'],
        'client_payment.needs_verification' => ['category' => 'Approval needed', 'tone' => 'warning', 'button' => 'Review payment'],
        'user.registration_pending' => ['category' => 'Approval needed', 'tone' => 'warning', 'button' => 'Review account'],
        'payout.requested' => ['category' => 'Approval needed', 'tone' => 'warning', 'button' => 'Review payout'],
        'commission.review_needed' => ['category' => 'Approval needed', 'tone' => 'warning', 'button' => 'Review commission'],
        'partner.application_received' => ['category' => 'Approval needed', 'tone' => 'warning', 'button' => 'Review application'],
    ],
];
