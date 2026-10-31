<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Module 30: one place for everything that is waiting for a decision. Each line is shown only to people who hold the permission to make that decision,
 * counts come straight from the owning module's tables, and every line links to the module's own screen (the decision itself is still made there,
 * with that module's rules, step-up password and audit entry). Nothing here approves anything.
 */
final class ApprovalCenter
{
    /** @return list<array{group:string,label:string,count:int,url:string,oldest:?string}> only lines the user may act on and that have waiting items */
    public static function pending(int $userId): array
    {
        $defs = [
            ['Money', 'Client payments waiting to be checked against the bank', 'payments.reconcile', "SELECT COUNT(*) c, MIN(created_at) o FROM client_payments WHERE status = 'pending_verification'", '/client-payments?status=pending_verification'],
            ['Money', 'Draft invoices ready to issue', 'invoices.issue', "SELECT COUNT(*) c, MIN(created_at) o FROM invoices WHERE status = 'draft'", '/invoices?status=draft'],
            ['Money', 'Payout requests to approve', 'payouts.approve', "SELECT COUNT(*) c, MIN(requested_at) o FROM payout_requests WHERE status IN ('requested','under_review')", '/payouts'],
            ['Money', 'Approved payouts waiting to be paid', 'payouts.record', "SELECT COUNT(*) c, MIN(approved_at) o FROM payout_requests WHERE status IN ('approved','processing')", '/payouts'],
            ['Money', 'Commission entries to review', 'commissions.approve', "SELECT COUNT(*) c, MIN(calculated_at) o FROM commission_entries WHERE status = 'eligible_for_review'", '/commissions'],
            ['Partners', 'Referral partner applications to decide', 'referrals.decide', "SELECT COUNT(*) c, MIN(created_at) o FROM partner_applications WHERE status IN ('received','under_review')", '/referrals'],
            ['Services', 'Renewals awaiting Admin review', 'renewals.approve', "SELECT COUNT(*) c, MIN(updated_at) o FROM renewal_services WHERE status = 'awaiting_admin_review'", '/renewals?status=awaiting_admin_review'],
            ['Services', 'Renewals waiting for provider confirmation', 'renewals.confirm_provider', "SELECT COUNT(*) c, MIN(updated_at) o FROM renewal_services WHERE status IN ('payment_received','renewal_in_progress')", '/renewals'],
            ['Delivery', 'Deliverables waiting for internal review', 'deliverables.review', "SELECT COUNT(*) c, MIN(updated_at) o FROM deliverables WHERE status = 'pending_review' AND archived_at IS NULL", '/qa'],
            ['Delivery', 'Approved deliverables ready to publish to the client', 'deliverables.publish', "SELECT COUNT(*) c, MIN(updated_at) o FROM deliverables WHERE status = 'approved_internal' AND archived_at IS NULL", '/qa'],
            ['Delivery', 'Client revision requests to classify', 'revisions.classify', "SELECT COUNT(*) c, MIN(created_at) o FROM revision_requests WHERE status IN ('submitted','under_review')", '/revisions'],
            ['Sales', 'Quotations sent and waiting for the client', 'quotes.record_decision', "SELECT COUNT(*) c, MIN(sent_at) o FROM quotations WHERE status = 'sent'", '/quotations?status=sent'],
            ['Sales', 'Price changes waiting for the client to accept', 'pricing.manage', "SELECT COUNT(*) c, MIN(created_at) o FROM price_change_requests WHERE status = 'pending_client'", '/admin/pricing'],
            ['Sales', 'Scope changes waiting for the client', 'projects.record_approval', "SELECT COUNT(*) c, MIN(created_at) o FROM change_requests WHERE status = 'pending_client'", '/changes'],
        ];
        $out = [];
        foreach ($defs as [$group, $label, $perm, $sql, $url]) {
            if (!Gate::can($perm, $userId)) {
                continue;
            }
            try {
                $r = Database::one($sql);
            } catch (\Throwable) {
                continue; // a module whose table is not installed yet
            }
            if ((int) ($r['c'] ?? 0) > 0) {
                $out[] = ['group' => $group, 'label' => $label, 'count' => (int) $r['c'], 'url' => $url, 'oldest' => $r['o'] !== null ? (string) $r['o'] : null];
            }
        }

        return $out;
    }

    /** Recent decisions and sensitive changes with who, when and (for the owner/admin who may see the log) the reason. @return list<array<string,mixed>> */
    public static function history(int $userId): array
    {
        if (!Gate::can('audit.view', $userId)) {
            return [];
        }
        $actions = ['payment.verified', 'payment.rejected', 'client_payment.verified', 'client_payment.rejected', 'invoice.issued', 'invoice.voided', 'credit_note.issued', 'credit_note.voided', 'payout.approved', 'payout.rejected', 'payout.paid',
            'commission.approved', 'referral.approved', 'referral.rejected', 'renewal.approved', 'renewal.rejected', 'renewal.renewed', 'deliverable.published', 'deliverable.approved_internal', 'role.permissions_changed', 'role.created', 'role.deleted', 'recycle.restored', 'auth.2fa.reset_by_admin', 'data.exported', 'data.imported'];
        $in = implode(',', array_fill(0, count($actions), '?'));

        return Database::all(
            "SELECT a.created_at, a.action, a.entity_type, a.entity_id, a.meta_json, u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE a.action IN ({$in}) ORDER BY a.id DESC LIMIT 40",
            $actions,
        );
    }
}
