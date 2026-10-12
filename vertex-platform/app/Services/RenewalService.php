<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Domain / hosting / SSL renewal records and their workflow.
 *
 * Lifecycle (one renewal_cycle per attempt):
 *   Active/Renewed -> Renewal Approaching -> Awaiting Admin Review -> (Admin approves a price) Approved for Renewal -> (invoice recorded) Invoice Issued
 *   -> (marked sent) Awaiting Payment -> (payments cover the invoice) Payment Received -> (staff start the provider work) Renewal in Progress
 *   -> (provider confirmation + new expiry recorded) Renewed.   Side states: Expired, On Hold, Cancelled.
 *
 * Rules that the tests pin: payment never makes a service Renewed; Renewed is reachable only through recordProviderConfirmation; the approved price is a
 * snapshot, an issued invoice must equal it and cannot be silently changed (revise = void the invoice and go back to Admin review); reasons are required
 * for hold, rejection, cancellation, price overrides, waivers and expiry changes; no provider credential may be typed into any text field.
 * There is no quotation/invoice module in this system: quotation and invoice numbers are references recorded by staff, not generated here.
 */
final class RenewalService
{
    private const PER_PAGE_MAX = 100;

    // ------------------------------------------------------------------------------------------------ access

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT s.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, c.public_id AS client_public_id, c.user_id AS client_user_id, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title
               FROM renewal_services s JOIN clients c ON c.id = s.client_id LEFT JOIN projects p ON p.id = s.project_id WHERE s.public_id = ?",
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $s = Gate::canAny(['renewals.view', 'renewals.manage', 'renewals.approve', 'renewals.confirm_provider'], $userId) ? self::find($publicId) : null;

        return $s ?? throw new HttpException(404);
    }

    /** The signed-in client's own service. @return array<string,mixed> */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $s = Gate::can('renewals.view_own', $userId) ? self::find($publicId) : null;
        if ($s === null || (int) $s['client_user_id'] !== $userId) {
            throw new HttpException(404);
        }

        return $s;
    }

    private static function staffAccess(int $userId): void
    {
        if (!Gate::canAny(['renewals.view', 'renewals.manage', 'renewals.approve', 'renewals.confirm_provider'], $userId)) {
            throw new HttpException(403);
        }
    }

    // ------------------------------------------------------------------------------------------------ validation helpers

    /** Provider passwords and keys must never be typed into a notes field: refuse text that looks like a credential. */
    private static function noCredentials(?string $text, string $label): void
    {
        if ($text !== null && preg_match('/\b(pass(word|wd|code|phrase)?|pwd|secret|api[ _-]?key|auth(or[is]sation)?[ _-]?(code|key)|epp([ _-]?(code|key))?|transfer[ _-]?(code|key)|token|otp|pin)\b\s*[:=\-]/i', $text)) {
            throw new ValidationException(["{$label} looks like it contains a password, key or code. Never store provider credentials here: keep them in the provider's own account or a password manager."]);
        }
    }

    /** @return array{type:string,identifier:string,provider:string,activated_on:string,expires_on:string,period:int,internal:?string,client_notes:?string,reminders:int,project:?array<string,mixed>} */
    private static function validated(array $in, bool $creating): array
    {
        $errors = [];
        $get = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $type = $get(static fn () => FieldRules::choice($in['service_type'] ?? '', (array) Config::get('renewals.types'), 'service type'));
        $identifier = (string) $get(static fn () => FieldRules::line($in['identifier'] ?? '', 'Domain or service name', 2, 190));
        if ($type === 'domain' && $identifier !== '') {
            $identifier = strtolower(trim($identifier));
            if (!preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $identifier)) {
                $errors[] = 'Enter the domain as a host name such as example.com.';
            }
        }
        $provider = (string) $get(static fn () => FieldRules::line($in['provider'] ?? '', 'Provider or registrar', 2, 120));
        $activated = $get(static fn () => FieldRules::date($in['activated_on'] ?? '', 'Activation date', true));
        $expires = $get(static fn () => FieldRules::date($in['expires_on'] ?? '', 'Expiry date', true));
        if (is_string($activated) && is_string($expires) && $expires <= $activated) {
            $errors[] = 'The expiry date must be after the activation date.';
        }
        $period = (int) ($in['period_months'] ?? 0);
        if (!in_array($period, (array) Config::get('renewals.periods'), true)) {
            $errors[] = 'Choose one of the standard renewal periods.';
        }
        $internal = $get(static fn () => FieldRules::text($in['internal_notes'] ?? '', 'Internal notes', 2, 3000, false));
        $clientNotes = $get(static fn () => FieldRules::text($in['client_notes'] ?? '', 'Notes for the client', 2, 1000, false));
        foreach ([[$internal, 'Internal notes'], [$clientNotes, 'Notes for the client'], [$identifier, 'The service name']] as [$t, $l]) {
            try {
                self::noCredentials(is_string($t) ? $t : null, $l);
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        $project = null;
        $pid = trim((string) ($in['project'] ?? ''));
        if ($pid !== '') {
            $project = ProjectPolicy::find($pid);
            if ($project === null) {
                $errors[] = 'That project was not found.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['type' => (string) $type, 'identifier' => $identifier, 'provider' => $provider, 'activated_on' => (string) $activated, 'expires_on' => (string) $expires, 'period' => $period,
            'internal' => is_string($internal) ? $internal : null, 'client_notes' => is_string($clientNotes) ? $clientNotes : null, 'reminders' => !empty($in['client_reminders']) && $in['client_reminders'] !== '0' ? 1 : 0, 'project' => $project];
    }

    // ------------------------------------------------------------------------------------------------ create / update

    /** @param array<string,mixed> $in @return array{public_id:string,reference:string} */
    public static function create(int $actorId, string $clientPublicId, array $in): array
    {
        Gate::authorize('renewals.manage', $actorId);
        $client = ClientPolicy::find($clientPublicId);
        if ($client === null || $client['archived_at'] !== null) {
            throw new ValidationException(['Choose an existing client.']);
        }
        $v = self::validated($in, true);
        if ($v['project'] !== null && (int) $v['project']['client_id'] !== (int) $client['id']) {
            throw new ValidationException(['That project belongs to a different client.']);
        }
        $pub = uuid_hex();
        $ref = CrmReference::next('renewal');
        try {
            Database::transaction(function () use ($actorId, $client, $v, $pub, $ref): void {
                Database::run("INSERT INTO renewal_services (public_id, reference, client_id, project_id, service_type, identifier, provider, activated_on, expires_on, period_months, internal_notes, client_notes, client_reminders, created_by, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [$pub, $ref, $client['id'], $v['project']['id'] ?? null, $v['type'], $v['identifier'], $v['provider'], $v['activated_on'], $v['expires_on'], $v['period'], $v['internal'], $v['client_notes'], $v['reminders'], $actorId]);
                $id = Database::insertId();
                self::history($id, 1, 'created', null, 'active', null, $actorId);
                Audit::record('renewal.created', 'renewal_service', $id, [], ['reference' => $ref, 'type' => $v['type'], 'expires_on' => $v['expires_on'], 'client_id' => (int) $client['id']], [], $actorId);
                self::advance($id, AppTime::today(), null);
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['This client already has a service with that type and name.']);
            }
            throw $e;
        }

        return ['public_id' => $pub, 'reference' => $ref];
    }

    /** Edits the descriptive fields. The expiry date, status and price have their own audited actions. @param array<string,mixed> $in */
    public static function update(int $actorId, array $service, array $in): void
    {
        Gate::authorize('renewals.manage', $actorId);
        $in += ['service_type' => $service['service_type'], 'expires_on' => $service['expires_on'], 'identifier' => $service['identifier']];
        $v = self::validated($in + ['activated_on' => $service['activated_on']], false);
        if ($v['type'] !== $service['service_type'] || $v['identifier'] !== $service['identifier']) {
            throw new ValidationException(['The type and name of a service cannot be changed; create a new record instead.']);
        }
        if ($v['project'] !== null && (int) $v['project']['client_id'] !== (int) $service['client_id']) {
            throw new ValidationException(['That project belongs to a different client.']);
        }
        Database::transaction(function () use ($actorId, $service, $v): void {
            $before = ['provider' => $service['provider'], 'activated_on' => $service['activated_on'], 'period_months' => (int) $service['period_months'], 'client_reminders' => (int) $service['client_reminders'], 'project_id' => $service['project_id']];
            $cur = Database::one('SELECT status FROM renewal_services WHERE id = ? FOR UPDATE', [$service['id']]);
            if ($cur === null || in_array($cur['status'], ['invoice_issued', 'awaiting_payment', 'payment_received', 'renewal_in_progress'], true) && $v['period'] !== (int) $service['period_months']) {
                throw new ValidationException(['The renewal period cannot change after an invoice has been issued. Void the invoice first.']);
            }
            Database::run('UPDATE renewal_services SET provider = ?, activated_on = ?, period_months = ?, internal_notes = ?, client_notes = ?, client_reminders = ?, project_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$v['provider'], $v['activated_on'], $v['period'], $v['internal'], $v['client_notes'], $v['reminders'], $v['project']['id'] ?? null, $service['id']]);
            self::history((int) $service['id'], (int) $service['cycle_no'], 'edited', null, null, null, $actorId);
            Audit::record('renewal.updated', 'renewal_service', (int) $service['id'], $before, ['provider' => $v['provider'], 'activated_on' => $v['activated_on'], 'period_months' => $v['period'], 'client_reminders' => $v['reminders'], 'project_id' => $v['project']['id'] ?? null], ['notes_changed' => true], $actorId);
        });
    }

    /** Changes the expiry date by hand (a correction). Needs a reason; not allowed while money or provider work is in flight. */
    public static function changeExpiry(int $actorId, array $service, string $newExpiry, string $reason): void
    {
        Gate::authorize('renewals.manage', $actorId);
        $new = (string) FieldRules::date($newExpiry, 'New expiry date', true);
        $reason = (string) FieldRules::line($reason, 'Reason for the change', 10, 255);
        if ($new <= $service['activated_on']) {
            throw new ValidationException(['The expiry date must be after the activation date.']);
        }
        Database::transaction(function () use ($actorId, $service, $new, $reason): void {
            $cur = self::lock((int) $service['id']);
            if (in_array($cur['status'], ['invoice_issued', 'awaiting_payment', 'payment_received', 'renewal_in_progress', 'cancelled'], true)) {
                throw new ValidationException(['The expiry date cannot be corrected while an invoice, payment or provider renewal is in progress.']);
            }
            Database::run('UPDATE renewal_services SET expires_on = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$new, $service['id']]);
            Database::run('UPDATE renewal_cycles SET from_expiry = ?, updated_at = UTC_TIMESTAMP() WHERE service_id = ? AND closed_at IS NULL', [$new, $service['id']]);
            self::history((int) $service['id'], (int) $cur['cycle_no'], 'expiry_corrected', $cur['expires_on'], $new, $reason, $actorId);
            Audit::record('renewal.expiry_corrected', 'renewal_service', (int) $service['id'], ['expires_on' => $cur['expires_on']], ['expires_on' => $new], ['reason_recorded' => true], $actorId);
            self::advance((int) $service['id'], AppTime::today(), $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ automatic and manual stage moves

    /**
     * Moves one service forward on the calendar: Active/Renewed -> Approaching -> Awaiting Admin Review, and -> Expired when the expiry date has passed without
     * provider confirmation. Called by the scheduler, after create and after an expiry correction. Idempotent.
     *
     * @return list<string> the statuses entered
     */
    public static function advance(int $serviceId, string $today, ?int $actorId): array
    {
        $entered = [];
        Database::transaction(function () use ($serviceId, $today, $actorId, &$entered): void {
            $s = self::lock($serviceId);
            $days = (int) ((strtotime($s['expires_on']) - strtotime($today)) / 86400);
            $lead = (array) Config::get('renewals.lead');
            if ($days < 0 && in_array($s['status'], (array) Config::get('renewals.expirable'), true)) {
                self::cycleFor($s);
                self::moveTo($s, 'expired', 'expired', 'The expiry date passed without a provider confirmation.', $actorId);
                $entered[] = 'expired';

                return;
            }
            if (in_array($s['status'], ['active', 'renewed'], true) && $days >= 0 && $days <= (int) $lead['approach_days']) {
                self::cycleFor($s);
                $s = self::moveTo($s, 'renewal_approaching', 'status', 'Renewal window opened.', $actorId);
                $entered[] = 'renewal_approaching';
            }
            if ($s['status'] === 'renewal_approaching' && $days >= 0 && $days <= (int) $lead['review_days']) {
                self::cycleFor($s);
                self::moveTo($s, 'awaiting_admin_review', 'status', 'Admin review is due.', $actorId);
                $entered[] = 'awaiting_admin_review';
                self::notifyReviewNeeded($s);
            }
        });

        return $entered;
    }

    /** Staff move between the early stages (the allowed list is in config/renewals.php). */
    public static function setStatus(int $actorId, array $service, string $to): void
    {
        Gate::authorize('renewals.manage', $actorId);
        Database::transaction(function () use ($actorId, $service, $to): void {
            $s = self::lock((int) $service['id']);
            if (!in_array($to, (array) Config::get("renewals.manual_transitions.{$s['status']}", []), true)) {
                throw new ValidationException(['A ' . self::label($s['status']) . ' service cannot move to ' . self::label($to) . ' from here.']);
            }
            self::cycleFor($s);
            $s = self::moveTo($s, $to, 'status', null, $actorId);
            if ($to === 'awaiting_admin_review') {
                self::notifyReviewNeeded($s);
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ approval and price

    /**
     * Admin approval of a renewal and its price. price_mode: `list` (the price list in force today), `override` (a typed net amount and tax %, reason required) or
     * `waived` (no charge, reason required: skips straight to Payment Received because nothing is owed).
     *
     * @param array<string,mixed> $in
     */
    public static function approve(int $actorId, array $service, array $in): void
    {
        Gate::authorize('renewals.approve', $actorId);
        $mode = (string) ($in['price_mode'] ?? 'list');
        if (!in_array($mode, ['list', 'override', 'waived'], true)) {
            throw new ValidationException(['Choose how the renewal is priced.']);
        }
        $reason = null;
        if ($mode !== 'list') {
            $reason = (string) FieldRules::line($in['reason'] ?? '', $mode === 'waived' ? 'Reason for waiving the charge' : 'Reason for the price override', 10, 255);
        }
        $period = (int) ($in['period_months'] ?? $service['period_months']);
        if (!in_array($period, (array) Config::get('renewals.periods'), true)) {
            throw new ValidationException(['Choose one of the standard renewal periods.']);
        }
        $net = $tax = 0;
        $snapshot = ['mode' => $mode, 'period_months' => $period];
        if ($mode === 'list') {
            $p = RenewalPricing::lookup((string) $service['service_type'], $period);
            if ($p === null) {
                throw new ValidationException(['No renewal price is configured for this type and period. Add it to the price list, or approve with an override and a reason.']);
            }
            $net = $p['net_minor'];
            $tax = $p['tax_minor'];
            $snapshot += $p;
        } elseif ($mode === 'override') {
            $net = (int) FieldRules::moneyMinor($in['net'] ?? '', 'Approved price (before tax)', true);
            $taxPct = trim((string) ($in['tax_percent'] ?? '0'));
            if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $taxPct) || (float) $taxPct > 50) {
                throw new ValidationException(['Tax must be a percentage between 0 and 50 with at most two decimals.']);
            }
            $tax = Money::bps($net, (int) round((float) $taxPct * 100));
            $snapshot += ['net_minor' => $net, 'tax_minor' => $tax, 'tax_bps' => (int) round((float) $taxPct * 100)];
        }
        Database::transaction(function () use ($actorId, $service, $mode, $reason, $period, $net, $tax, $snapshot): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'awaiting_admin_review') {
                throw new ValidationException(['This renewal is not waiting for approval (it may have been changed by someone else).']);
            }
            $cycle = self::cycleFor($s);
            $total = $net + $tax;
            Database::run("UPDATE renewal_cycles SET period_months = ?, price_source = ?, net_minor = ?, tax_minor = ?, total_minor = ?, pricing_json = ?, approved_by = ?, approved_at = UTC_TIMESTAMP(), waiver_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$period, $mode === 'list' ? 'price_list' : $mode, $net, $tax, $total, json_encode($snapshot, JSON_THROW_ON_ERROR), $actorId, $mode === 'waived' ? $reason : null, $cycle['id']]);
            if ($mode === 'override') {
                Database::run('UPDATE renewal_services SET price_override_minor = ?, price_override_reason = ? WHERE id = ?', [$net, $reason, $s['id']]);
            }
            Audit::record('renewal.approved', 'renewal_service', (int) $s['id'], [], ['cycle_no' => (int) $s['cycle_no'], 'price_mode' => $mode, 'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total, 'period_months' => $period], ['reason_recorded' => $reason !== null], $actorId);
            if ($mode === 'waived') {
                Database::run('UPDATE renewal_cycles SET payment_complete_at = UTC_TIMESTAMP() WHERE id = ?', [$cycle['id']]);
                $s = self::moveTo($s, 'payment_received', 'approved', 'Approved with no charge: ' . $reason, $actorId);
                self::notifyStaff('renewal.ready_for_provider', $s, 'Ready for the provider: ' . $s['identifier'], 'Approved with no charge. Start the renewal with the provider.', 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':ready', ['renewals.manage', 'renewals.confirm_provider']);

                return;
            }
            $s = self::moveTo($s, 'approved_for_renewal', 'approved', null, $actorId);
            self::notifyStaff('renewal.approved', $s, 'Renewal approved: ' . $s['identifier'], 'Record the quotation or invoice for ' . FieldRules::formatMinor($total) . '.', 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':approved', ['renewals.manage']);
        });
    }

    /** Admin declines to proceed for now. The service goes On Hold and can be released back to review. */
    public static function reject(int $actorId, array $service, string $reason): void
    {
        Gate::authorize('renewals.approve', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $service, $reason): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'awaiting_admin_review') {
                throw new ValidationException(['This renewal is not waiting for approval.']);
            }
            self::cycleFor($s);
            Database::run('UPDATE renewal_services SET held_from = ?, status_reason = ? WHERE id = ?', [$s['status'], $reason, $s['id']]);
            $s = self::moveTo(self::lock((int) $s['id']), 'on_hold', 'rejected', $reason, $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ invoice and payment

    /**
     * Records the quotation/invoice that was issued OUTSIDE this system for the approved amount. The amount must equal the approved total: an invoice is never
     * silently different from the approved price.
     *
     * @param array<string,mixed> $in
     */
    public static function recordInvoice(int $actorId, array $service, array $in): void
    {
        Gate::authorize('renewals.manage', $actorId);
        $inv = (string) FieldRules::line($in['invoice_ref'] ?? '', 'Invoice number', 2, 80);
        $quote = FieldRules::line($in['quotation_ref'] ?? '', 'Quotation number', 2, 80, false);
        $amount = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Invoice amount (including tax)', true);
        Database::transaction(function () use ($actorId, $service, $inv, $quote, $amount): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'approved_for_renewal') {
                throw new ValidationException(['An invoice can be recorded only for an approved renewal.']);
            }
            $cycle = self::cycleFor($s);
            if ($amount !== (int) $cycle['total_minor']) {
                throw new ValidationException(['The invoice amount must equal the approved total of ₹' . FieldRules::formatMinor((int) $cycle['total_minor']) . '. To change the price, send the renewal back to Admin review.']);
            }
            Database::run('UPDATE renewal_cycles SET invoice_ref = ?, quotation_ref = ?, invoice_amount_minor = ?, invoice_recorded_by = ?, invoice_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$inv, $quote, $amount, $actorId, $cycle['id']]);
            Audit::record('renewal.invoice_recorded', 'renewal_service', (int) $s['id'], [], ['cycle_no' => (int) $s['cycle_no'], 'invoice_ref' => $inv, 'quotation_ref' => $quote, 'amount_minor' => $amount], [], $actorId);
            self::moveTo($s, 'invoice_issued', 'invoice', 'Invoice ' . $inv, $actorId);
        });
    }

    public static function markInvoiceSent(int $actorId, array $service): void
    {
        Gate::authorize('renewals.manage', $actorId);
        Database::transaction(function () use ($actorId, $service): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'invoice_issued') {
                throw new ValidationException(['There is no issued invoice waiting to be sent.']);
            }
            $cycle = self::cycleFor($s);
            Database::run('UPDATE renewal_cycles SET invoice_sent_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$cycle['id']]);
            $s = self::moveTo($s, 'awaiting_payment', 'invoice_sent', null, $actorId);
            $client = $service['client_user_id'] ? [(int) $service['client_user_id']] : [];
            Notifier::send($client, 'renewal.invoice', 'Invoice for your ' . strtolower((string) Config::get("renewals.types.{$s['service_type']}")) . ' renewal', $s['identifier'] . ': invoice ' . $cycle['invoice_ref'] . ' for ₹' . FieldRules::formatMinor((int) $cycle['invoice_amount_minor']) . ' is ready.', '/my/services/' . $s['public_id'], $s['project_id'] === null ? null : (int) $s['project_id'], 'renewal_service', (int) $s['id'], 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':invoice-sent');
        });
    }

    /** Voids the issued invoice and sends the renewal back to Admin review (the way to change a price). Refused once money was received. */
    public static function reviseOrVoid(int $actorId, array $service, string $reason): void
    {
        Gate::authorize('renewals.approve', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $service, $reason): void {
            $s = self::lock((int) $service['id']);
            if (!in_array($s['status'], ['approved_for_renewal', 'invoice_issued', 'awaiting_payment'], true)) {
                throw new ValidationException(['Only an approved renewal that has not been paid can be sent back for a new price.']);
            }
            $cycle = self::cycleFor($s);
            if ((int) $cycle['paid_minor'] > 0) {
                throw new ValidationException(['Money has already been recorded against this invoice. Resolve the payment first; the price cannot be changed.']);
            }
            Audit::record('renewal.price_revision_started', 'renewal_service', (int) $s['id'], ['invoice_ref' => $cycle['invoice_ref'], 'total_minor' => $cycle['total_minor']], [], ['reason_recorded' => true], $actorId);
            Database::run("UPDATE renewal_cycles SET price_source = NULL, net_minor = NULL, tax_minor = NULL, total_minor = NULL, pricing_json = NULL, approved_by = NULL, approved_at = NULL, quotation_ref = NULL, invoice_ref = NULL,
                           invoice_amount_minor = NULL, invoice_recorded_by = NULL, invoice_at = NULL, invoice_sent_at = NULL, waiver_reason = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$cycle['id']]);
            Database::run('UPDATE renewal_services SET price_override_minor = NULL, price_override_reason = NULL WHERE id = ?', [$s['id']]);
            $s = self::moveTo($s, 'awaiting_admin_review', 'price_revision', $reason . ' (previous invoice: ' . ($cycle['invoice_ref'] ?? 'none') . ')', $actorId);
            self::notifyReviewNeeded($s, 'rev' . time());
        });
    }

    /**
     * Records money received for the cycle's invoice by staff (the system does not take payments). Several partial payments are allowed; the same transaction
     * reference cannot be recorded twice; the total can never exceed the invoice. When the invoice is covered the status becomes Payment Received, which does
     * NOT mean the provider has renewed anything.
     *
     * @param array<string,mixed> $in
     */
    public static function recordPayment(int $actorId, array $service, array $in): void
    {
        Gate::authorize('renewals.manage', $actorId);
        $amount = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Amount received', true);
        $method = (string) FieldRules::choice($in['method'] ?? '', (array) Config::get('renewals.payment_methods'), 'payment method');
        $ref = (string) FieldRules::line($in['reference'] ?? '', 'Transaction reference', 3, 120);
        $on = (string) FieldRules::date($in['received_on'] ?? '', 'Date received', true);
        if ($amount <= 0) {
            throw new ValidationException(['The amount must be more than zero.']);
        }
        if ($on > AppTime::today()) {
            throw new ValidationException(['The date received cannot be in the future.']);
        }
        Database::transaction(function () use ($actorId, $service, $amount, $method, $ref, $on): void {
            $s = self::lock((int) $service['id']);
            if (!in_array($s['status'], ['invoice_issued', 'awaiting_payment'], true)) {
                throw new ValidationException(['A payment can be recorded only against an issued invoice.']);
            }
            $cycle = self::cycleFor($s);
            $remaining = (int) $cycle['invoice_amount_minor'] - (int) $cycle['paid_minor'];
            if ($amount > $remaining) {
                throw new ValidationException(['That is more than the ₹' . FieldRules::formatMinor($remaining) . ' still due on the invoice.']);
            }
            try {
                Database::run('INSERT INTO renewal_payments (cycle_id, amount_minor, method, reference, payment_key, received_on, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$cycle['id'], $amount, $method, $ref, hash('sha256', $method . ':' . strtolower($ref)), $on, $actorId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ValidationException(['That transaction has already been recorded. It cannot be counted twice.']);
                }
                throw $e;
            }
            $paid = (int) $cycle['paid_minor'] + $amount;
            Database::run('UPDATE renewal_cycles SET paid_minor = ?, payment_complete_at = IF(? >= invoice_amount_minor, UTC_TIMESTAMP(), NULL), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$paid, $paid, $cycle['id']]);
            Audit::record('renewal.payment_recorded', 'renewal_service', (int) $s['id'], ['paid_minor' => (int) $cycle['paid_minor']], ['paid_minor' => $paid, 'amount_minor' => $amount, 'method' => $method], [], $actorId);
            if ($paid >= (int) $cycle['invoice_amount_minor']) {
                $s = self::moveTo($s, 'payment_received', 'payment', 'Payment complete (the provider has NOT been asked to renew yet).', $actorId);
                self::notifyStaff('renewal.payment_received', $s, 'Payment received: ' . $s['identifier'], 'Start the renewal with the provider and record their confirmation.', 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':paid', ['renewals.manage', 'renewals.confirm_provider']);
                if ($s['client_user_id']) {
                    Notifier::send([(int) $s['client_user_id']], 'renewal.payment_received', 'Payment received for ' . $s['identifier'], 'Thank you. We will now renew it with the provider and confirm here once it is done.', '/my/services/' . $s['public_id'], null, 'renewal_service', (int) $s['id'], 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':client-paid');
                }
            } elseif ($s['status'] === 'invoice_issued') {
                self::moveTo($s, 'awaiting_payment', 'payment', 'Part payment recorded.', $actorId);
            } else {
                self::history((int) $s['id'], (int) $s['cycle_no'], 'payment', null, null, 'Part payment of ₹' . FieldRules::formatMinor($amount) . ' recorded.', $actorId);
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ provider work

    public static function startProviderRenewal(int $actorId, array $service): void
    {
        Gate::authorize('renewals.manage', $actorId);
        Database::transaction(function () use ($actorId, $service): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'payment_received') {
                throw new ValidationException(['The provider renewal can start only after payment is received (or the charge was waived).']);
            }
            $cycle = self::cycleFor($s);
            Database::run('UPDATE renewal_cycles SET in_progress_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$cycle['id']]);
            self::moveTo($s, 'renewal_in_progress', 'provider_started', null, $actorId);
        });
    }

    /**
     * The ONLY way a service becomes Renewed: a person records the provider's confirmation (their reference, the date) and the new expiry date the PROVIDER
     * shows. The next cycle is scheduled from that verified expiry.
     *
     * @param array<string,mixed> $in
     */
    public static function recordProviderConfirmation(int $actorId, array $service, array $in): void
    {
        Gate::authorize('renewals.confirm_provider', $actorId);
        $ref = (string) FieldRules::line($in['confirmation_ref'] ?? '', 'Provider confirmation reference', 3, 190);
        self::noCredentials($ref, 'The confirmation reference');
        $on = (string) FieldRules::date($in['confirmed_on'] ?? '', 'Confirmation date', true);
        $new = (string) FieldRules::date($in['new_expiry'] ?? '', 'New expiry date (as shown by the provider)', true);
        if ($on > AppTime::today()) {
            throw new ValidationException(['The confirmation date cannot be in the future.']);
        }
        if ($new > AppTime::todayPlus(3660)) {
            throw new ValidationException(['That expiry date is more than ten years away. Check it against the provider.']);
        }
        Database::transaction(function () use ($actorId, $service, $ref, $on, $new): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'renewal_in_progress') {
                throw new ValidationException(['Provider confirmation can be recorded only while the renewal is In Progress (after payment, and after the provider work was started).']);
            }
            if ($new <= $s['expires_on']) {
                throw new ValidationException(['The new expiry date must be later than the current one (' . $s['expires_on'] . ').']);
            }
            $cycle = self::cycleFor($s);
            Database::run('UPDATE renewal_cycles SET provider_confirmation_ref = ?, provider_confirmed_on = ?, provider_confirmed_by = ?, provider_confirmed_at = UTC_TIMESTAMP(), new_expiry = ?, closed_at = UTC_TIMESTAMP(), closed_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$ref, $on, $actorId, $new, 'renewed', $cycle['id']]);
            $months = (int) ($cycle['period_months'] ?? $s['period_months']);
            Audit::record('renewal.provider_confirmed', 'renewal_service', (int) $s['id'], ['expires_on' => $s['expires_on']], ['expires_on' => $new, 'cycle_no' => (int) $s['cycle_no']], ['confirmation_recorded' => true], $actorId);
            $s = self::moveTo($s, 'renewed', 'renewed', 'Provider confirmed. New expiry ' . $new . '.', $actorId);
            Database::run('UPDATE renewal_services SET expires_on = ?, period_months = ?, last_renewed_at = UTC_TIMESTAMP(), cycle_no = cycle_no + 1, price_override_minor = NULL, price_override_reason = NULL WHERE id = ?', [$new, $months, $s['id']]);
            if ($s['client_user_id']) {
                Notifier::send([(int) $s['client_user_id']], 'renewal.completed', $s['identifier'] . ' has been renewed', 'New expiry date: ' . $new . '.', '/my/services/' . $s['public_id'], null, 'renewal_service', (int) $s['id'], 'rnw:' . $s['id'] . ':' . $cycle['cycle_no'] . ':renewed');
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ hold / release / cancel

    public static function hold(int $actorId, array $service, string $reason): void
    {
        Gate::authorize('renewals.manage', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $service, $reason): void {
            $s = self::lock((int) $service['id']);
            if (!in_array($s['status'], (array) Config::get('renewals.holdable'), true)) {
                throw new ValidationException(['A ' . self::label($s['status']) . ' service cannot be put on hold (money or provider work may be in flight).']);
            }
            $open = Database::one('SELECT paid_minor FROM renewal_cycles WHERE service_id = ? AND closed_at IS NULL FOR UPDATE', [$s['id']]);
            if ($open !== null && (int) $open['paid_minor'] > 0) {
                throw new ValidationException(['A service with a payment recorded cannot be put on hold: complete the renewal or resolve the payment first.']);
            }
            Database::run('UPDATE renewal_services SET held_from = ?, status_reason = ? WHERE id = ?', [$s['status'], $reason, $s['id']]);
            self::moveTo(self::lock((int) $s['id']), 'on_hold', 'hold', $reason, $actorId);
        });
    }

    public static function release(int $actorId, array $service): void
    {
        Gate::authorize('renewals.manage', $actorId);
        Database::transaction(function () use ($actorId, $service): void {
            $s = self::lock((int) $service['id']);
            if ($s['status'] !== 'on_hold') {
                throw new ValidationException(['This service is not on hold.']);
            }
            $back = in_array($s['held_from'], array_keys((array) Config::get('renewals.statuses')), true) && $s['held_from'] !== 'on_hold' ? (string) $s['held_from'] : 'active';
            Database::run('UPDATE renewal_services SET held_from = NULL, status_reason = NULL WHERE id = ?', [$s['id']]);
            self::moveTo(self::lock((int) $s['id']), $back, 'release', null, $actorId);
            self::advance((int) $s['id'], AppTime::today(), $actorId);
        });
    }

    /** Cancels the service permanently (the client no longer wants it, or it was moved elsewhere). Needs the approve permission and a reason. */
    public static function cancel(int $actorId, array $service, string $reason): void
    {
        Gate::authorize('renewals.approve', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason for cancelling', 10, 255);
        Database::transaction(function () use ($actorId, $service, $reason): void {
            $s = self::lock((int) $service['id']);
            if (!in_array($s['status'], (array) Config::get('renewals.cancellable'), true)) {
                throw new ValidationException(['This service cannot be cancelled in its current state.']);
            }
            $cycle = Database::one('SELECT * FROM renewal_cycles WHERE service_id = ? AND closed_at IS NULL FOR UPDATE', [$s['id']]);
            if ($cycle !== null) {
                if ((int) $cycle['paid_minor'] > 0) {
                    throw new ValidationException(['Money has been recorded for this renewal. Resolve the payment (refund or credit) outside this screen and note it before cancelling.']);
                }
                Database::run('UPDATE renewal_cycles SET closed_at = UTC_TIMESTAMP(), closed_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', ['cancelled: ' . $reason, $cycle['id']]);
            }
            Database::run('UPDATE renewal_services SET status_reason = ?, held_from = NULL WHERE id = ?', [$reason, $s['id']]);
            self::moveTo(self::lock((int) $s['id']), 'cancelled', 'cancelled', $reason, $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @return array<string,mixed> the locked service row */
    private static function lock(int $id): array
    {
        return Database::one('SELECT * FROM renewal_services WHERE id = ? FOR UPDATE', [$id]) ?? throw new HttpException(404);
    }

    /** The open cycle for the service's current expiry, created on first need. @return array<string,mixed> */
    private static function cycleFor(array $s): array
    {
        $c = Database::one('SELECT * FROM renewal_cycles WHERE service_id = ? AND closed_at IS NULL ORDER BY cycle_no DESC LIMIT 1 FOR UPDATE', [$s['id']]);
        if ($c !== null) {
            return $c;
        }
        Database::run('INSERT INTO renewal_cycles (service_id, cycle_no, from_expiry, period_months, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$s['id'], $s['cycle_no'], $s['expires_on'], $s['period_months']]);

        return Database::one('SELECT * FROM renewal_cycles WHERE id = ?', [Database::insertId()]) ?? throw new \RuntimeException('cycle');
    }

    /** @param array<string,mixed> $s the locked row @return array<string,mixed> the refreshed row */
    private static function moveTo(array $s, string $to, string $event, ?string $note, ?int $actorId): array
    {
        Database::run('UPDATE renewal_services SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $s['id']]);
        self::history((int) $s['id'], (int) $s['cycle_no'], $event, $s['status'], $to, $note, $actorId);
        Audit::record('renewal.status_changed', 'renewal_service', (int) $s['id'], ['status' => $s['status']], ['status' => $to], ['event' => $event], $actorId);

        return Database::one('SELECT s.*, c.user_id AS client_user_id FROM renewal_services s JOIN clients c ON c.id = s.client_id WHERE s.id = ?', [$s['id']]) ?? $s;
    }

    private static function history(int $serviceId, ?int $cycleNo, string $event, ?string $from, ?string $to, ?string $note, ?int $actorId): void
    {
        Database::run('INSERT INTO renewal_history (service_id, cycle_no, event, from_status, to_status, note, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$serviceId, $cycleNo, $event, $from, $to, $note === null ? null : mb_substr($note, 0, 500), $actorId]);
    }

    public static function label(string $status): string
    {
        return (string) Config::get("renewals.statuses.{$status}", $status);
    }

    /** @param list<string> $permissions */
    private static function notifyStaff(string $event, array $s, string $title, string $body, string $dedupe, array $permissions): void
    {
        $ids = [];
        foreach ($permissions as $p) {
            array_push($ids, ...Notifier::withPermission($p));
        }
        Notifier::send($ids, $event, $title, $body, '/renewals/' . $s['public_id'], null, 'renewal_service', (int) $s['id'], $dedupe);
    }

    private static function notifyReviewNeeded(array $s, string $variant = ''): void
    {
        self::notifyStaff('renewal.review_needed', $s, 'Renewal needs your approval: ' . $s['identifier'], 'Expires ' . $s['expires_on'] . '. Approve a price or put it on hold.', 'rnw:' . $s['id'] . ':' . $s['cycle_no'] . ':review' . $variant, ['renewals.approve']);
    }

    // ------------------------------------------------------------------------------------------------ reads

    /** @return array<string,mixed>|null the open cycle */
    public static function openCycle(array $service): ?array
    {
        return Database::one('SELECT cy.*, au.name AS approved_by_name, iu.name AS invoice_by_name, cu.name AS confirmed_by_name FROM renewal_cycles cy LEFT JOIN users au ON au.id = cy.approved_by LEFT JOIN users iu ON iu.id = cy.invoice_recorded_by LEFT JOIN users cu ON cu.id = cy.provider_confirmed_by
                              WHERE cy.service_id = ? AND cy.closed_at IS NULL ORDER BY cy.cycle_no DESC LIMIT 1', [$service['id']]);
    }

    /** @return list<array<string,mixed>> closed cycles, newest first */
    public static function pastCycles(array $service): array
    {
        return Database::all('SELECT cy.*, cu.name AS confirmed_by_name FROM renewal_cycles cy LEFT JOIN users cu ON cu.id = cy.provider_confirmed_by WHERE cy.service_id = ? AND cy.closed_at IS NOT NULL ORDER BY cy.cycle_no DESC', [$service['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function payments(array $cycle): array
    {
        return Database::all('SELECT p.amount_minor, p.method, p.reference, p.received_on, p.created_at, u.name AS recorded_by FROM renewal_payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.cycle_id = ? ORDER BY p.id', [$cycle['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function historyOf(array $service): array
    {
        return Database::all('SELECT h.cycle_no, h.event, h.from_status, h.to_status, h.note, h.created_at, u.name AS actor FROM renewal_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.service_id = ? ORDER BY h.id DESC', [$service['id']]);
    }

    /** @return list<array<string,mixed>> */
    public static function reminders(array $service): array
    {
        return Database::all('SELECT expires_on, kind, offset_days, audience, channel, result, attempts, recipients, created_at FROM renewal_reminders WHERE service_id = ? ORDER BY id DESC LIMIT 50', [$service['id']]);
    }

    /** True when the expiry passed but money or provider work is in flight (so it is not marked Expired). */
    public static function isOverdue(array $s): bool
    {
        return in_array($s['status'], ['payment_received', 'renewal_in_progress'], true) && $s['expires_on'] < AppTime::today();
    }

    /** What a client is told. No internal notes, hold reasons, provider confirmation reference or unapproved price. @return array<string,mixed> */
    public static function clientView(array $s): array
    {
        $cycle = self::openCycle($s);
        $approved = $cycle !== null && $cycle['approved_at'] !== null;
        $stage = match ($s['status']) {
            'active', 'renewed' => 'Active',
            'renewal_approaching', 'awaiting_admin_review' => 'Renewal coming up: our team is preparing it',
            'approved_for_renewal' => 'Renewal approved: your invoice is being prepared',
            'invoice_issued' => 'Invoice issued',
            'awaiting_payment' => 'Waiting for your payment',
            'payment_received' => 'Payment received: we are about to renew it with the provider',
            'renewal_in_progress' => 'Renewal in progress with the provider',
            'expired' => 'Expired: please contact us to renew',
            'on_hold' => 'On hold: please contact us',
            'cancelled' => 'Cancelled',
            default => (string) $s['status'],
        };

        return ['public_id' => $s['public_id'], 'reference' => $s['reference'], 'type' => $s['service_type'], 'identifier' => $s['identifier'], 'provider' => $s['provider'], 'activated_on' => $s['activated_on'], 'expires_on' => $s['expires_on'],
            'period_months' => (int) $s['period_months'], 'status' => $s['status'], 'stage' => $stage, 'client_notes' => $s['client_notes'], 'last_renewed_at' => $s['last_renewed_at'],
            'price' => $approved ? ['net_minor' => (int) $cycle['net_minor'], 'tax_minor' => (int) $cycle['tax_minor'], 'total_minor' => (int) $cycle['total_minor'], 'period_months' => (int) $cycle['period_months'], 'waived' => $cycle['price_source'] === 'waived'] : null,
            'quotation_ref' => $cycle['quotation_ref'] ?? null, 'invoice_ref' => $cycle['invoice_ref'] ?? null, 'invoice_amount_minor' => $cycle !== null && $cycle['invoice_amount_minor'] !== null ? (int) $cycle['invoice_amount_minor'] : null,
            'paid_minor' => (int) ($cycle['paid_minor'] ?? 0), 'steps' => self::steps($s['status'])];
    }

    /** @return list<array{label:string,state:string}> a plain progress bar for the client */
    private static function steps(string $status): array
    {
        $order = ['approved_for_renewal' => 1, 'invoice_issued' => 2, 'awaiting_payment' => 2, 'payment_received' => 3, 'renewal_in_progress' => 4, 'renewed' => 5];
        $at = $order[$status] ?? 0;
        $labels = [1 => 'Approved', 2 => 'Invoice and payment', 3 => 'Payment received', 4 => 'Renewing with the provider', 5 => 'Renewed'];
        $out = [];
        foreach ($labels as $n => $l) {
            $out[] = ['label' => $l, 'state' => $at === 0 ? 'todo' : ($n < $at || ($n === $at && $status === 'renewed') ? 'done' : ($n === $at ? 'current' : 'todo'))];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ lists, dashboard, export

    /** @return array<string,array{0:string,1:list<mixed>}> named bucket conditions (SQL on alias s, params) */
    private static function buckets(string $today): array
    {
        $lead = (int) Config::get('renewals.lead.approach_days');

        return [
            'upcoming' => ["s.status IN ('active','renewed','renewal_approaching') AND s.expires_on BETWEEN ? AND ?", [$today, AppTime::todayPlus($lead)]],
            'awaiting_review' => ["s.status = 'awaiting_admin_review'", []],
            'approved' => ["s.status = 'approved_for_renewal'", []],
            'outstanding_invoices' => ["s.status IN ('invoice_issued','awaiting_payment')", []],
            'paid_unconfirmed' => ["s.status IN ('payment_received','renewal_in_progress')", []],
            'overdue' => ["s.status IN ('payment_received','renewal_in_progress') AND s.expires_on < ?", [$today]],
            'expired' => ["s.status = 'expired'", []],
            'recent' => ["s.status = 'renewed' AND s.last_renewed_at >= ?", [gmdate('Y-m-d H:i:s', strtotime('-30 days'))]],
            'on_hold' => ["s.status = 'on_hold'", []],
        ];
    }

    /** @param array<string,mixed> $f @return array{0:string,1:list<mixed>} */
    private static function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = "(s.reference LIKE ? OR s.identifier LIKE ? OR s.provider LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)";
            array_push($p, $like, $like, $like, $like, $like);
        }
        if (array_key_exists((string) ($f['type'] ?? ''), (array) Config::get('renewals.types'))) {
            $w[] = 's.service_type = ?';
            $p[] = $f['type'];
        }
        if (array_key_exists((string) ($f['status'] ?? ''), (array) Config::get('renewals.statuses'))) {
            $w[] = 's.status = ?';
            $p[] = $f['status'];
        }
        $b = self::buckets(AppTime::today());
        if (isset($b[(string) ($f['bucket'] ?? '')])) {
            $w[] = '(' . $b[$f['bucket']][0] . ')';
            array_push($p, ...$b[$f['bucket']][1]);
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['client'] ?? ''))) {
            $w[] = 'c.public_id = ?';
            $p[] = $f['client'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($f[$k] ?? ''));
            if ($d !== false && $d->format('Y-m-d') === $f[$k]) {
                $w[] = "s.expires_on {$op} ?";
                $p[] = $f[$k];
            }
        }

        return [implode(' AND ', $w), $p];
    }

    private const LIST_SQL = "FROM renewal_services s JOIN clients c ON c.id = s.client_id";

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        self::staffAccess($userId);
        [$cond, $p] = self::where($f);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        $total = (int) Database::one('SELECT COUNT(*) AS c ' . self::LIST_SQL . " WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT s.public_id, s.reference, s.service_type, s.identifier, s.provider, s.expires_on, s.status, s.period_months, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, DATEDIFF(s.expires_on, ?) AS days_left
                               " . self::LIST_SQL . " WHERE {$cond} ORDER BY s.expires_on ASC, s.id ASC LIMIT {$perPage} OFFSET {$offset}", array_merge([AppTime::today()], $p));

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,int> counts per dashboard bucket, plus the total */
    public static function dashboard(int $userId): array
    {
        self::staffAccess($userId);
        $out = ['total' => (int) Database::one("SELECT COUNT(*) AS c FROM renewal_services WHERE status <> 'cancelled'")['c']];
        foreach (self::buckets(AppTime::today()) as $name => [$cond, $params]) {
            $out[$name] = (int) Database::one("SELECT COUNT(*) AS c FROM renewal_services s WHERE {$cond}", $params)['c'];
        }

        return $out;
    }

    /** @param array<string,mixed> $f @return list<list<string>> CSV rows, header first. Cells that could be read as spreadsheet formulas are neutralised. */
    public static function export(int $userId, array $f): array
    {
        Gate::authorize('renewals.manage', $userId);
        [$cond, $p] = self::where($f);
        $rows = Database::all("SELECT s.reference, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client, s.service_type, s.identifier, s.provider, s.activated_on, s.expires_on, s.period_months, s.status,
                                      cy.total_minor, cy.invoice_ref, cy.paid_minor
                                 FROM renewal_services s JOIN clients c ON c.id = s.client_id LEFT JOIN renewal_cycles cy ON cy.service_id = s.id AND cy.closed_at IS NULL
                                WHERE {$cond} ORDER BY s.expires_on ASC, s.id ASC LIMIT 5000", $p);
        $safe = static fn (mixed $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        $out = [['Reference', 'Client', 'Type', 'Name', 'Provider', 'Activated', 'Expires', 'Period (months)', 'Status', 'Approved total (INR)', 'Invoice', 'Paid (INR)']];
        foreach ($rows as $r) {
            $out[] = array_map($safe, [$r['reference'], $r['client'], Config::get("renewals.types.{$r['service_type']}"), $r['identifier'], $r['provider'], $r['activated_on'], $r['expires_on'], $r['period_months'], self::label($r['status']),
                $r['total_minor'] === null ? '' : FieldRules::formatMinor((int) $r['total_minor']), $r['invoice_ref'] ?? '', $r['invoice_ref'] === null ? '' : FieldRules::formatMinor((int) $r['paid_minor'])]);
        }
        Audit::record('renewal.exported', 'renewal_service', 0, [], ['rows' => count($out) - 1], [], $userId);

        return $out;
    }

    /** The signed-in client's services. @return list<array<string,mixed>> */
    public static function forClient(int $userId): array
    {
        if (!Gate::can('renewals.view_own', $userId)) {
            return [];
        }

        return Database::all('SELECT s.* FROM renewal_services s JOIN clients c ON c.id = s.client_id WHERE c.user_id = ? ORDER BY s.expires_on ASC, s.id ASC', [$userId]);
    }
}
