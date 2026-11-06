<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Referrals submitted by approved partners, their tracking workflow and ATTRIBUTION (who is credited).
 *
 * Duplicate and attribution rules (also in docs/14-PHASE6-REFERRAL-COMMISSIONS.md):
 *  1. A partner cannot submit the same prospect twice (same e-mail or phone while the earlier referral is still open): they are told which of THEIR OWN referrals it is.
 *  2. If ANOTHER partner already referred the same e-mail/phone, or the prospect matches a CRM client/enquiry, the new referral is stored and flagged for admin review.
 *     Nothing is merged, nothing is credited and the partner is told nothing about the other record.
 *  3. Credit is an explicit admin decision with a reason (credited / denied / duplicate). Only ONE partner can be credited for the same prospect, and only one
 *     credited referral can point at a project (UNIQUE credit_project_id).
 *  4. A commission can only exist for a credited referral that was marked Converted with a linked project. Approval and "Quote Sent" never convert a referral.
 *  5. Once a commission entry has become eligible the attribution is LOCKED: credit, project link and partner can no longer be changed through the application.
 *  6. Every attribution decision, link and status change is in referral_history (and the audit log).
 */
final class ReferralService
{
    // ------------------------------------------------------------------------------------------------ submission

    /**
     * @param array<string,mixed> $in prospect_name, email, phone, business_name, service, description, estimated_budget, notes, token
     * @return array{public_id:string,reference:string,created:bool}
     */
    public static function submit(int $userId, array $in): array
    {
        $partner = PartnerService::require($userId, 'referrals.submit');
        if (!Gate::can('referrals.submit', $userId)) {
            throw new HttpException(403);
        }
        $token = preg_match('/^[a-f0-9]{32}$/', (string) ($in['token'] ?? '')) ? (string) $in['token'] : null;
        if ($token !== null) {
            $dup = Database::one('SELECT public_id, reference FROM partner_referrals WHERE partner_id = ? AND submit_token = ?', [$partner['id'], $token]);
            if ($dup !== null) {
                return ['public_id' => (string) $dup['public_id'], 'reference' => (string) $dup['reference'], 'created' => false];
            }
        }
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $name = $try(static fn () => (string) FieldRules::line($in['prospect_name'] ?? '', 'Prospect name', 2, 120));
        $email = $try(static fn () => FieldRules::email($in['email'] ?? '', false));
        $phone = $try(static fn () => FieldRules::phone($in['phone'] ?? '', false));
        $business = $try(static fn () => FieldRules::line($in['business_name'] ?? '', 'Business name', 2, 160, false));
        $service = $try(static fn () => (string) FieldRules::choice($in['service'] ?? '', (array) Config::get('workflow.services'), 'service'));
        $desc = $try(static fn () => (string) FieldRules::text($in['description'] ?? '', 'Project description', 10, 3000));
        $budget = $try(static fn () => FieldRules::moneyMinor($in['estimated_budget'] ?? '', 'Estimated budget', false));
        $notes = $try(static fn () => FieldRules::text($in['notes'] ?? '', 'Additional notes', 2, 2000, false));
        if ($email === null && $phone === null && $errors === []) {
            $errors[] = 'Give at least an email address or a phone number for the prospect.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!RateLimiter::hit('partner_referral_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $emailNorm = $email === null ? null : mb_strtolower($email);
        $digits = FieldRules::phoneDigits($phone);
        $digits = $digits !== null && strlen($digits) >= 7 ? $digits : null;
        $company = FieldRules::companyNorm($business);

        return Database::transaction(function () use ($partner, $userId, $name, $email, $emailNorm, $phone, $digits, $business, $company, $service, $desc, $budget, $notes, $token): array {
            // serialise one partner's submissions so the "already referred" check cannot be raced
            Database::one('SELECT id FROM referral_partners WHERE id = ? FOR UPDATE', [$partner['id']]);
            $mine = self::matches((int) $partner['id'], $emailNorm, $digits, true);
            if ($mine !== []) {
                throw new ValidationException(["You already referred this contact: {$mine[0]['reference']} (still open). Open that referral instead of submitting a new one."]);
            }
            $others = self::matches((int) $partner['id'], $emailNorm, $digits, false);
            $crm = [];
            foreach (EnquiryService::findDuplicates($emailNorm, $digits, null)['strong'] as $e) {
                $crm[] = 'CRM enquiry ' . $e['reference'];
            }
            foreach (ClientService::findDuplicates($emailNorm, $digits, null)['strong'] as $c) {
                $crm[] = 'CRM client ' . $c['contact_name'];
            }
            $flag = $others !== [] || $crm !== [];
            $pub = uuid_hex();
            $ref = CrmReference::next('partner_ref');
            Database::run(
                "INSERT INTO partner_referrals (public_id, reference, partner_id, prospect_name, email, email_norm, phone, phone_digits, business_name, company_norm, service, description,
                        estimated_budget_minor, notes, status, attribution, duplicate_status, duplicate_of_id, submit_token, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $partner['id'], $name, $email, $emailNorm, $phone, $digits, $business, $company, $service, $desc, $budget, $notes,
                    $others !== [] ? 'conflict' : 'pending', $flag ? 'suspected' : 'none', $others[0]['id'] ?? null, $token],
            );
            $id = Database::insertId();
            self::history($id, 'created', null, 'received', null, 'partner', $userId);
            if ($flag) {
                $detail = [];
                foreach ($others as $o) {
                    $detail[] = "same contact as {$o['reference']} (another partner)";
                    if ($o['attribution'] === 'pending') {
                        Database::run("UPDATE partner_referrals SET attribution = 'conflict', duplicate_status = IF(duplicate_status = 'none', 'suspected', duplicate_status), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$o['id']]);
                        self::history((int) $o['id'], 'duplicate', null, 'conflict', "Another partner submitted the same contact ({$ref}). Admin review required.", 'internal', null);
                    }
                }
                self::history($id, 'duplicate', null, 'suspected', 'Possible duplicate: ' . implode('; ', [...$detail, ...$crm]) . '. Not merged, not credited.', 'internal', null);
            }
            Audit::record('referral.created', 'partner_referral', $id, [], ['reference' => $ref, 'service' => $service, 'possible_duplicate' => $flag], ['partner_id' => (int) $partner['id']], $userId);
            Notifier::send(Notifier::withPermission('referrals.manage'), $flag ? 'referral.received_duplicate' : 'referral.received', $flag ? 'New referral (possible duplicate)' : 'New referral received', "{$ref}: {$name}",
                '/referrals/' . $pub, null, 'partner_referral', $id, "referral:{$id}:received");

            return ['public_id' => $pub, 'reference' => $ref, 'created' => true];
        });
    }

    /**
     * Referrals (not rejected) with the same e-mail or phone: the partner's own open ones, or everyone else's.
     *
     * @return list<array<string,mixed>>
     */
    private static function matches(int $partnerId, ?string $emailNorm, ?string $digits, bool $own): array
    {
        $conds = [];
        $p = [$partnerId];
        if ($emailNorm !== null) {
            $conds[] = 'email_norm = ?';
            $p[] = $emailNorm;
        }
        if ($digits !== null) {
            $conds[] = 'phone_digits = ?';
            $p[] = $digits;
        }
        if ($conds === []) {
            return [];
        }
        $status = $own ? "status NOT IN ('rejected','not_converted') AND attribution NOT IN ('denied','duplicate')" : "status <> 'rejected' AND attribution <> 'denied'";

        return Database::all('SELECT id, reference, attribution, partner_id FROM partner_referrals WHERE partner_id ' . ($own ? '=' : '<>') . " ? AND {$status} AND (" . implode(' OR ', $conds) . ') ORDER BY id LIMIT 5', $p);
    }

    // ------------------------------------------------------------------------------------------------ staff workflow

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(self::SELECT . ' WHERE r.public_id = ?', [$publicId]) : null;
    }

    private const SELECT = 'SELECT r.*, p.public_id AS partner_public_id, p.reference AS partner_reference, pu.name AS partner_name, pu.id AS partner_user_id, pu.email AS partner_email,
                                   e.public_id AS enquiry_public_id, e.reference AS enquiry_reference, c.public_id AS client_public_id, c.contact_name AS client_name,
                                   pj.public_id AS project_public_id, pj.code AS project_code, pj.title AS project_title, pj.status AS project_status,
                                   d.reference AS duplicate_of_reference, ab.name AS attribution_by_name
                              FROM partner_referrals r JOIN referral_partners p ON p.id = r.partner_id JOIN users pu ON pu.id = p.user_id
                              LEFT JOIN enquiries e ON e.id = r.enquiry_id LEFT JOIN clients c ON c.id = r.client_id LEFT JOIN projects pj ON pj.id = r.project_id
                              LEFT JOIN partner_referrals d ON d.id = r.duplicate_of_id LEFT JOIN users ab ON ab.id = r.attribution_by';

    /** @return array<string,mixed> staff detail, 404 for anyone without `referrals.review` */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $r = Gate::can('referrals.review', $userId) ? self::find($publicId) : null;
        if ($r === null) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** @return array<string,mixed> the partner's OWN referral, 404 otherwise */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $p = PartnerService::require($userId);
        $r = self::find($publicId);
        if ($r === null || (int) $r['partner_id'] !== (int) $p['id']) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** What a PARTNER may see of a referral: their own submission, the status and the reason written for them. No internal flags, no CRM or project ids. @param array<string,mixed> $r @return array<string,mixed> */
    public static function partnerView(array $r): array
    {
        $visible = in_array($r['status'], ['rejected', 'not_converted'], true) ? $r['status_reason'] : null;
        $attr = match ($r['attribution']) {
            'credited' => 'credited',
            'denied', 'duplicate' => 'not_credited',
            default => 'in_review',
        };

        return [
            'public_id' => $r['public_id'], 'reference' => $r['reference'], 'prospect_name' => $r['prospect_name'], 'email' => $r['email'], 'phone' => $r['phone'], 'business_name' => $r['business_name'],
            'service' => $r['service'], 'description' => $r['description'], 'estimated_budget_minor' => $r['estimated_budget_minor'], 'notes' => $r['notes'], 'status' => $r['status'],
            'reason' => $visible, 'credit' => $attr, 'converted' => $r['status'] === 'converted', 'created_at' => $r['created_at'], 'converted_at' => $r['converted_at'],
        ];
    }

    /** @param array<string,mixed> $r */
    public static function setStatus(int $actorId, array $r, string $to, string $reason = '', string $internalNote = ''): void
    {
        Gate::authorize('referrals.manage', $actorId);
        $allowed = (array) Config::get("referrals.referral_transitions.{$r['status']}", []);
        if ($to === 'converted') {
            throw new ValidationException(['Use "Mark converted" and link the project: approval or a quotation never converts a referral by itself.']);
        }
        if (!in_array($to, $allowed, true)) {
            throw new ValidationException(['A ' . strtolower((string) Config::get("referrals.referral_statuses.{$r['status']}")) . ' referral cannot move to ' . strtolower((string) Config::get("referrals.referral_statuses.{$to}", $to)) . '.']);
        }
        $needReason = in_array($to, ['rejected', 'not_converted'], true);
        $reason = (string) FieldRules::text($reason, 'Reason (the partner will read this)', 10, 500, $needReason);
        $internalNote = (string) FieldRules::text($internalNote, 'Internal note', 2, 3000, false);
        Database::transaction(function () use ($actorId, $r, $to, $reason, $internalNote): void {
            $cur = Database::one('SELECT status, attribution_locked FROM partner_referrals WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($cur === null || $cur['status'] !== $r['status']) {
                throw new ValidationException(['This referral was changed by someone else. Reload and try again.']);
            }
            if ((int) $cur['attribution_locked'] === 1 && in_array($to, ['rejected', 'not_converted'], true)) {
                throw new ValidationException(['Commission eligibility has already been established for this referral. Use a commission adjustment or reversal instead.']);
            }
            Database::run('UPDATE partner_referrals SET status = ?, status_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $reason === '' ? null : $reason, $r['id']]);
            $hist = self::history((int) $r['id'], 'status', (string) $r['status'], $to, $reason === '' ? null : $reason, 'partner', $actorId);
            if ($internalNote !== '') {
                Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('referral', ?, ?, ?, UTC_TIMESTAMP())", [$r['id'], $actorId, $internalNote]);
            }
            Audit::record('referral.status_changed', 'partner_referral', (int) $r['id'], ['status' => $r['status']], ['status' => $to], ['reason_recorded' => $reason !== ''], $actorId);
            Notifier::send([(int) $r['partner_user_id']], 'referral.status_changed', 'Referral update', "{$r['reference']}: " . Config::get("referrals.referral_statuses.{$to}"), '/partner/r/' . $r['public_id'], null,
                'partner_referral', (int) $r['id'], "referral:{$r['id']}:hist:{$hist}");
        });
    }

    /**
     * Marks the referral Converted: the prospect became a client with a project. Needs a project (never inferred), and links the CRM client.
     * If the referral is credited, the project becomes the commission project (one credited referral per project).
     *
     * @param array<string,mixed> $r
     */
    public static function markConverted(int $actorId, array $r, string $projectPublicId): void
    {
        Gate::authorize('referrals.manage', $actorId);
        if (!in_array($r['status'], ['approved', 'contacted', 'qualified', 'quote_sent'], true)) {
            throw new ValidationException(['Only an approved referral that is being worked can be converted.']);
        }
        $project = ProjectPolicy::find($projectPublicId);
        if ($project === null || $project['status'] === 'cancelled' || $project['archived_at'] !== null) {
            throw new ValidationException(['Choose an existing, active project for this client.']);
        }
        Database::transaction(function () use ($actorId, $r, $project): void {
            $cur = Database::one('SELECT status, attribution, attribution_locked FROM partner_referrals WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($cur === null || $cur['status'] !== $r['status']) {
                throw new ValidationException(['This referral was changed by someone else. Reload and try again.']);
            }
            $credit = $cur['attribution'] === 'credited' ? (int) $project['id'] : null;
            if ($credit !== null && Database::one('SELECT 1 AS x FROM partner_referrals WHERE credit_project_id = ? AND id <> ?', [$credit, $r['id']]) !== null) {
                throw new ValidationException(['Another referral is already credited for that project.']);
            }
            Database::run("UPDATE partner_referrals SET status = 'converted', status_reason = NULL, client_id = ?, project_id = ?, credit_project_id = ?, converted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$project['client_id'], $project['id'], $credit, $r['id']]);
            $hist = self::history((int) $r['id'], 'status', (string) $r['status'], 'converted', null, 'partner', $actorId);
            self::history((int) $r['id'], 'link', null, 'project', 'Linked to project ' . $project['code'], 'internal', $actorId);
            Audit::record('referral.converted', 'partner_referral', (int) $r['id'], ['status' => $r['status']], ['status' => 'converted'], ['project_id' => (int) $project['id'], 'client_id' => (int) $project['client_id'], 'credited' => $credit !== null], $actorId);
            Notifier::send([(int) $r['partner_user_id']], 'referral.converted', 'Your referral converted', $r['reference'] . ': the prospect became a client.', '/partner/r/' . $r['public_id'], null, 'partner_referral', (int) $r['id'], "referral:{$r['id']}:hist:{$hist}");
            if ($credit !== null) {
                CommissionService::evaluateProject((int) $project['id'], $actorId);
            }
        });
    }

    /**
     * Links CRM records (enquiry, project) WITHOUT changing the status. Refused for the project once attribution is locked.
     *
     * @param array<string,mixed> $r
     */
    public static function linkRecords(int $actorId, array $r, string $enquiryPublicId, string $projectPublicId): void
    {
        Gate::authorize('referrals.manage', $actorId);
        $enq = $enquiryPublicId === '' ? null : Database::one('SELECT id, reference, converted_project_id FROM enquiries WHERE public_id = ?', [$enquiryPublicId]);
        $project = $projectPublicId === '' ? null : ProjectPolicy::find($projectPublicId);
        if (($enquiryPublicId !== '' && $enq === null) || ($projectPublicId !== '' && $project === null)) {
            throw new ValidationException(['That CRM record was not found.']);
        }
        if ($enq === null && $project === null) {
            throw new ValidationException(['Enter an enquiry or a project to link.']);
        }
        Database::transaction(function () use ($actorId, $r, $enq, $project): void {
            $cur = Database::one('SELECT status, attribution, attribution_locked, project_id FROM partner_referrals WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($project !== null && (int) $cur['attribution_locked'] === 1 && (int) $cur['project_id'] !== (int) $project['id']) {
                throw new ValidationException(['Commission eligibility is established for this referral; its project can no longer be changed.']);
            }
            if ($project !== null && $cur['attribution'] === 'credited' && $cur['status'] === 'converted') {
                throw new ValidationException(['A converted, credited referral keeps its project. Use a commission adjustment if the link was wrong.']);
            }
            Database::run('UPDATE partner_referrals SET enquiry_id = COALESCE(?, enquiry_id), project_id = COALESCE(?, project_id), client_id = COALESCE(?, client_id), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$enq['id'] ?? null, $project['id'] ?? null, $project['client_id'] ?? null, $r['id']]);
            self::history((int) $r['id'], 'link', null, $project !== null ? 'project' : 'enquiry', ($enq !== null ? 'Enquiry ' . $enq['reference'] . '. ' : '') . ($project !== null ? 'Project ' . $project['code'] . '.' : ''), 'internal', $actorId);
            Audit::record('referral.linked', 'partner_referral', (int) $r['id'], [], ['enquiry_id' => $enq['id'] ?? null, 'project_id' => $project['id'] ?? null], [], $actorId);
        });
    }

    /** Creates a CRM enquiry from an APPROVED referral (so the sales team works it in the normal pipeline) and links it. Never merges with an existing enquiry or client. @param array<string,mixed> $r */
    public static function createEnquiry(int $actorId, array $r): void
    {
        Gate::authorize('referrals.manage', $actorId);
        Gate::authorize('enquiries.create', $actorId);
        if ($r['enquiry_id'] !== null || !in_array($r['status'], ['approved', 'contacted', 'qualified', 'quote_sent'], true)) {
            throw new ValidationException(['Only an approved referral without a CRM enquiry can create one.']);
        }
        Database::transaction(function () use ($actorId, $r): void {
            $e = EnquiryService::create($actorId, 'referral_partner', [
                'contact_name' => $r['prospect_name'], 'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '', 'company_name' => $r['business_name'] ?? '', 'service' => $r['service'],
                'description' => $r['description'], 'budget_range' => '', 'expected_deadline' => '',
            ], ['referral_partner_user_id' => (int) $r['partner_user_id']]);
            Database::run('UPDATE partner_referrals SET enquiry_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND enquiry_id IS NULL', [$e['id'], $r['id']]);
            self::history((int) $r['id'], 'link', null, 'enquiry', 'CRM enquiry ' . $e['reference'] . ' created from this referral.', 'internal', $actorId);
            Audit::record('referral.enquiry_created', 'partner_referral', (int) $r['id'], [], ['enquiry_id' => $e['id']], [], $actorId);
        });
    }

    /**
     * The attribution decision. `credited`: this partner earns commission on the converted project. `denied`: not credited (e.g. the prospect was already
     * a client). `duplicate`: counted as a duplicate of an earlier claim. A reason is mandatory. Blocked once a commission is eligible.
     *
     * @param array<string,mixed> $r
     */
    public static function decideAttribution(int $actorId, array $r, string $decision, string $reason): void
    {
        Gate::authorize('referrals.decide', $actorId);
        if (!in_array($decision, ['credited', 'denied', 'duplicate'], true)) {
            throw new ValidationException(['Choose credited, not credited or duplicate.']);
        }
        $reason = (string) FieldRules::text($reason, 'Reason for the decision', 10, 500);
        Database::transaction(function () use ($actorId, $r, $decision, $reason): void {
            $cur = Database::one('SELECT * FROM partner_referrals WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($cur === null) {
                throw new HttpException(404);
            }
            if ((int) $cur['attribution_locked'] === 1) {
                throw new ValidationException(['Commission eligibility is established, so the attribution is locked. Use a commission adjustment or reversal.']);
            }
            if ($decision === 'credited') {
                if ($cur['status'] === 'rejected') {
                    throw new ValidationException(['A rejected referral cannot be credited.']);
                }
                $conds = [];
                $p = [$cur['id']];
                if ($cur['email_norm'] !== null) {
                    $conds[] = 'email_norm = ?';
                    $p[] = $cur['email_norm'];
                }
                if ($cur['phone_digits'] !== null) {
                    $conds[] = 'phone_digits = ?';
                    $p[] = $cur['phone_digits'];
                }
                if ($conds !== [] && Database::one("SELECT reference FROM partner_referrals WHERE id <> ? AND attribution = 'credited' AND (" . implode(' OR ', $conds) . ') FOR UPDATE', $p) !== null) {
                    throw new ValidationException(['Another referral for the same contact is already credited. Only one partner can be credited for a client: change that decision first.']);
                }
                if ($cur['project_id'] !== null && $cur['status'] === 'converted'
                    && Database::one('SELECT 1 AS x FROM partner_referrals WHERE credit_project_id = ? AND id <> ?', [$cur['project_id'], $cur['id']]) !== null) {
                    throw new ValidationException(['Another referral is already credited for that project.']);
                }
            }
            $dupStatus = $decision === 'duplicate' ? 'confirmed' : ($cur['duplicate_status'] === 'suspected' ? 'dismissed' : $cur['duplicate_status']);
            $credit = $decision === 'credited' && $cur['status'] === 'converted' && $cur['project_id'] !== null ? $cur['project_id'] : null;
            Database::run('UPDATE partner_referrals SET attribution = ?, attribution_reason = ?, attribution_by = ?, attribution_at = UTC_TIMESTAMP(), duplicate_status = ?, credit_project_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$decision, $reason, $actorId, $dupStatus, $credit, $r['id']]);
            self::history((int) $r['id'], 'attribution', (string) $cur['attribution'], $decision, $reason, 'internal', $actorId);
            Audit::record('referral.attribution_changed', 'partner_referral', (int) $r['id'], ['attribution' => $cur['attribution'], 'partner_id' => (int) $cur['partner_id']], ['attribution' => $decision, 'partner_id' => (int) $cur['partner_id']],
                ['reason_recorded' => true, 'credit_project_id' => $credit], $actorId);
            if ($credit !== null) {
                CommissionService::evaluateProject((int) $credit, $actorId);
            }
        });
    }

    /**
     * Called inside EnquiryService::convert. A referral linked to the enquiry gets the new client and project LINKED (so the admin can see them); its status
     * and attribution are untouched: marking it Converted stays an explicit decision.
     */
    public static function onEnquiryConverted(int $enquiryId, int $clientId, int $projectId, ?int $actorId): void
    {
        foreach (Database::all('SELECT id, reference FROM partner_referrals WHERE enquiry_id = ? AND project_id IS NULL AND attribution_locked = 0 FOR UPDATE', [$enquiryId]) as $r) {
            Database::run('UPDATE partner_referrals SET client_id = ?, project_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$clientId, $projectId, $r['id']]);
            self::history((int) $r['id'], 'link', null, 'project', 'The linked CRM enquiry was converted into a project. Review the referral and mark it Converted when appropriate.', 'internal', $actorId);
            Notifier::send(Notifier::withPermission('referrals.manage'), 'referral.enquiry_converted', 'A referred enquiry became a project', $r['reference'], '/referrals/' . Database::one('SELECT public_id FROM partner_referrals WHERE id = ?', [$r['id']])['public_id'],
                null, 'partner_referral', (int) $r['id'], "referral:{$r['id']}:enquiry_converted");
        }
    }

    /** @param array<string,mixed> $r */
    public static function addNote(int $actorId, array $r, string $body): void
    {
        Gate::authorize('referrals.manage', $actorId);
        $body = (string) FieldRules::text($body, 'Note', 2, 3000);
        Database::transaction(function () use ($actorId, $r, $body): void {
            Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('referral', ?, ?, ?, UTC_TIMESTAMP())", [$r['id'], $actorId, $body]);
            Audit::record('referral.note_added', 'partner_referral', (int) $r['id'], [], ['length' => mb_strlen($body)], [], $actorId);
        });
    }

    /** @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function notes(int $userId, array $r): array
    {
        return Gate::can('referrals.review', $userId) ? Database::all("SELECT n.body, n.created_at, u.name AS author FROM partner_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.subject_type = 'referral' AND n.subject_id = ? ORDER BY n.id", [$r['id']]) : [];
    }

    /** History: partners get only the rows written for them. @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function historyFor(int $userId, array $r, bool $staff): array
    {
        if ($staff ? !Gate::can('referrals.review', $userId) : false) {
            return [];
        }

        return Database::all(
            'SELECT h.id, h.event, h.from_value, h.to_value, h.note, h.visibility, h.created_at, ' . ($staff ? 'u.name AS actor_name' : "NULL AS actor_name") . ' FROM referral_history h LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.referral_id = ?' . ($staff ? '' : " AND h.visibility = 'partner'") . ' ORDER BY h.id',
            [$r['id']],
        );
    }

    /** @return int history id */
    private static function history(int $referralId, string $event, ?string $from, ?string $to, ?string $note, string $visibility, ?int $actorId): int
    {
        Database::run('INSERT INTO referral_history (referral_id, event, from_value, to_value, note, visibility, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$referralId, $event, $from, $to, $note, $visibility, $actorId]);

        return Database::insertId();
    }

    // ------------------------------------------------------------------------------------------------ queries

    /**
     * @param array{q?:string,status?:string,attribution?:string,duplicates?:bool,partner?:string,from?:string,to?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::can('referrals.review', $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(r.reference LIKE ? OR r.prospect_name LIKE ? OR r.email LIKE ? OR r.business_name LIKE ? OR pu.name LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like);
        }
        if (array_key_exists((string) ($f['status'] ?? ''), (array) Config::get('referrals.referral_statuses'))) {
            $w[] = 'r.status = ?';
            $p[] = $f['status'];
        }
        if (array_key_exists((string) ($f['attribution'] ?? ''), (array) Config::get('referrals.attribution_labels'))) {
            $w[] = 'r.attribution = ?';
            $p[] = $f['attribution'];
        }
        if (!empty($f['duplicates'])) {
            $w[] = "(r.attribution = 'conflict' OR r.duplicate_status = 'suspected')";
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['partner'] ?? ''))) {
            $w[] = 'p.public_id = ?';
            $p[] = $f['partner'];
        }
        [$w, $p] = PartnerApplicationService::dateRange($w, $p, 'r.created_at', $f);
        $cond = implode(' AND ', $w);
        $base = 'FROM partner_referrals r JOIN referral_partners p ON p.id = r.partner_id JOIN users pu ON pu.id = p.user_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base} WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT r.public_id, r.reference, r.prospect_name, r.business_name, r.service, r.status, r.attribution, r.duplicate_status, r.attribution_locked, r.created_at,
                    pu.name AS partner_name, p.public_id AS partner_public_id {$base} WHERE {$cond} ORDER BY (r.attribution = 'conflict') DESC, r.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The signed-in partner's own referrals, partner-shaped.
     *
     * @param array{q?:string,status?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginateOwn(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        $partner = PartnerService::require($userId);
        $w = ['r.partner_id = ?'];
        $p = [$partner['id']];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(r.reference LIKE ? OR r.prospect_name LIKE ? OR r.business_name LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (array_key_exists((string) ($f['status'] ?? ''), (array) Config::get('referrals.referral_statuses'))) {
            $w[] = 'r.status = ?';
            $p[] = $f['status'];
        }
        $cond = implode(' AND ', $w);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM partner_referrals r WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT r.* FROM partner_referrals r WHERE {$cond} ORDER BY r.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => array_map([self::class, 'partnerView'], $rows), 'total' => $total];
    }

    /** Counts for the partner dashboard. @return array<string,int> */
    public static function countsOwn(int $userId): array
    {
        $partner = PartnerService::require($userId);
        $rows = Database::all('SELECT status, COUNT(*) AS c FROM partner_referrals WHERE partner_id = ? GROUP BY status', [$partner['id']]);
        $by = array_column($rows, 'c', 'status');
        $n = static fn (string ...$s): int => array_sum(array_map(static fn (string $k): int => (int) ($by[$k] ?? 0), $s));

        return ['total' => array_sum(array_map('intval', $by)), 'under_review' => $n('received', 'under_review'), 'approved' => $n('approved', 'contacted', 'qualified', 'quote_sent'),
            'converted' => $n('converted'), 'not_converted' => $n('not_converted', 'rejected')];
    }
}
