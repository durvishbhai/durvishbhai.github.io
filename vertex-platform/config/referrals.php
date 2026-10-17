<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Referral Partner portal and commission settings.
 *
 * `rules` are the SHIPPED DEFAULTS of the commission rules. The live rules are versioned in the database (commission_rules, edited by
 * `commissions.rules.manage` with the account password) and every ledger entry stores the version and a snapshot of the rules it was
 * calculated under, so changing a rule never rewrites an earlier calculation. See docs/14-PHASE6-REFERRAL-COMMISSIONS.md for the exact
 * formula, the worked examples and the open business decision on the formula mode.
 */
return [
    'rules' => [
        'advance_rate_bps' => 1000,              // 10.00 %: first milestone, after the qualifying advance payment is received AND verified
        'full_rate_bps' => 500,                  //  5.00 %: second milestone, after the full payment condition is met
        'formula_mode' => 'cumulative',          // 'cumulative' (total earned = 15 % of eligible received) or 'per_milestone' (10 % of the advance-stage payments + 5 % of the later payments)
        'advance_min_bps' => 0,                  // an advance qualifies when advance-role payments reach this share of the contract value (0 = any verified advance)
        'exclude_tax' => true,                   // tax collected from the client is never part of the base
        'net_of_refunds' => true,                // refunds and chargebacks reduce the base
        'include_change_requests' => true,       // client-approved additional work counts toward the contract value that must be paid in full
        'full_payment_tolerance_minor' => 0,     // paise short of the contract value that still counts as paid in full
        'advance_without_completion' => 'keep',  // 'keep' = the first milestone stays earned if the project is cancelled; 'reverse' = it is reversed (a negative adjustment if already paid)
        'payable_hold_days' => 0,                // days between approval and the entry becoming Payable
        'min_payout_minor' => 0,                 // smallest payout request, in paise
        'separation_of_duties' => (bool) Env::get('COMMISSION_SEPARATION_OF_DUTIES', true),
    ],

    'application_statuses' => ['received' => 'Received', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected'],

    'referral_statuses' => [
        'received' => 'Received', 'under_review' => 'Under Review', 'approved' => 'Approved', 'contacted' => 'Contacted', 'qualified' => 'Qualified',
        'quote_sent' => 'Quote Sent', 'converted' => 'Converted', 'not_converted' => 'Not Converted', 'rejected' => 'Rejected',
    ],
    /**
     * Allowed moves made by staff (`referrals.manage`). `converted` is NOT reachable here: it needs a linked project and its own action, so neither
     * an approval nor a quotation can ever turn a referral into a conversion. `rejected` and `not_converted` need a reason the partner can read.
     */
    'referral_transitions' => [
        'received' => ['under_review', 'rejected'],
        'under_review' => ['approved', 'rejected'],
        'approved' => ['contacted', 'not_converted', 'rejected'],
        'contacted' => ['qualified', 'not_converted'],
        'qualified' => ['quote_sent', 'not_converted'],
        'quote_sent' => ['qualified', 'not_converted'],
        'not_converted' => ['contacted'],
        'rejected' => ['under_review'],
        'converted' => [],
    ],
    'attribution_labels' => ['pending' => 'Awaiting review', 'credited' => 'Credited to the partner', 'conflict' => 'Needs admin review', 'duplicate' => 'Duplicate', 'denied' => 'Not credited'],

    'commission_statuses' => [
        'pending_eligibility' => 'Pending Eligibility', 'eligible_for_review' => 'Eligible for Review', 'approved' => 'Approved', 'payable' => 'Payable',
        'payout_requested' => 'Payout Requested', 'paid' => 'Paid', 'on_hold' => 'On Hold', 'adjusted' => 'Adjusted', 'reversed' => 'Reversed',
    ],
    'milestone_labels' => ['advance' => 'Advance milestone', 'full' => 'Full-payment milestone', 'adjustment' => 'Adjustment'],
    'payout_statuses' => ['requested' => 'Requested', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'processing' => 'Processing', 'paid' => 'Paid', 'cancelled' => 'Cancelled'],
    'payment_methods' => ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'card' => 'Card', 'cheque' => 'Cheque', 'cash' => 'Cash', 'gateway' => 'Payment gateway', 'other' => 'Other'],
];
