<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * CRM enquiries. Rules enforced here (server side, never by the UI):
 *  - Anyone may SUBMIT through the public forms (validated, rate-limited, consent recorded); nothing else is public.
 *  - A staff member needs the specific permission AND access to the particular enquiry (owner / all) for every action.
 *  - Stage rules: Won only by an explicit, confirmed action by someone holding enquiries.mark_won; Lost needs a reason;
 *    re-opening Won/Lost needs enquiries.reopen; a converted enquiry is locked. Nothing (quotes included) moves an
 *    enquiry to Won automatically; external events only add a timeline note (recordExternalEvent).
 *  - Possible duplicates are flagged and shown, never merged automatically.
 *  - Every change writes the enquiry timeline and the security audit log in the same transaction. The audit log stores
 *    field NAMES and ids, not personal data.
 */
final class EnquiryService
{
    // ------------------------------------------------------------------ creation

    /**
     * Validates input for a given source. Public sources never accept priority/owner/stage.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function validate(array $in, string $source): array
    {
        $services = (array) Config::get('workflow.services');
        $budgets = (array) Config::get('workflow.budget_ranges');
        $priorities = (array) Config::get('workflow.priorities');
        $public = in_array($source, ['website_requirements', 'contact_form', 'appointment_request', 'client_portal'], true);
        $today = AppTime::today();

        // Collect every field's error so the person sees all problems at once instead of fixing them one by one.
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };

        $phone = $try(fn () => FieldRules::phone($in['phone'] ?? '', $source === 'appointment_request'));
        $company = $try(fn () => FieldRules::line($in['company_name'] ?? '', 'Company name', 2, 160, false));
        $description = $try(fn () => match ($source) {
            'website_requirements', 'client_portal' => FieldRules::text($in['description'] ?? '', 'Project description', 20, 5000),
            'contact_form' => FieldRules::text($in['description'] ?? '', 'Message', 10, 3000),
            'appointment_request' => FieldRules::text($in['description'] ?? '', 'What would you like to discuss', 5, 1000),
            default => FieldRules::text($in['description'] ?? '', 'Project description', 5, 5000),
        });
        $out = [
            'contact_name' => $try(fn () => FieldRules::line($in['contact_name'] ?? '', 'Name', 2, 120)),
            'email' => $try(fn () => FieldRules::email($in['email'] ?? '')),
            'phone' => $phone,
            'phone_digits' => FieldRules::phoneDigits($phone),
            'company_name' => $company,
            'company_norm' => FieldRules::companyNorm($company),
            'service' => $try(fn () => FieldRules::choice($in['service'] ?? '', $services, 'service', in_array($source, ['website_requirements', 'manual', 'referral_partner', 'client_portal'], true), 'other')),
            'description' => $description,
            'budget_range' => $try(fn () => FieldRules::choice($in['budget_range'] ?? '', $budgets, 'budget range', false)),
            'expected_deadline' => $try(fn () => FieldRules::date($in['expected_deadline'] ?? '', 'Expected deadline', false, $public || $source === 'referral_partner' ? $today : null)),
            'preferred_slot' => $source === 'appointment_request' ? $try(fn () => FieldRules::line($in['preferred_slot'] ?? '', 'Preferred date and time', 3, 120)) : null,
            'priority' => $public || $source === 'referral_partner' ? 'normal' : $try(fn () => FieldRules::choice($in['priority'] ?? 'normal', $priorities, 'priority', false, 'normal')),
        ];
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    /**
     * Creates an enquiry. $actorId is null for public form submissions.
     *
     * @param array<string,mixed> $in
     * @param array{consent?:bool,ip?:string,referral_partner_user_id?:int} $meta
     * @return array{id:int,public_id:string,reference:string,duplicate_of:?int}
     */
    public static function create(?int $actorId, string $source, array $in, array $meta = []): array
    {
        if (!array_key_exists($source, (array) Config::get('workflow.sources'))) {
            throw new \InvalidArgumentException("Unknown enquiry source '{$source}'.");
        }
        if ($source === 'manual') {
            Gate::authorize('enquiries.create', (int) $actorId);
        } elseif ($source === 'referral_partner' && isset($meta['referral_partner_user_id'])) {
            // staff creating the CRM enquiry for a partner's approved referral (ReferralService::createEnquiry); the partner is the credited submitter
            Gate::authorize('enquiries.create', (int) $actorId);
        } elseif ($source === 'referral_partner') {
            Gate::authorize('enquiries.submit_referral', (int) $actorId);
        } elseif ($actorId !== null) {
            throw new \InvalidArgumentException('Public sources are anonymous.');
        }
        $needsConsent = in_array($source, ['website_requirements', 'contact_form', 'appointment_request'], true) && empty($meta['consent']);
        try {
            $d = self::validate($in, $source);
        } catch (ValidationException $e) {
            throw new ValidationException($needsConsent ? [...$e->errors, 'Please confirm that we may contact you about this request.'] : $e->errors);
        }
        if ($needsConsent) {
            throw new ValidationException(['Please confirm that we may contact you about this request.']);
        }

        $made = Database::transaction(function () use ($actorId, $source, $d, $meta): array {
            $stage = CrmStageService::initial();
            // a client sending another request is not a duplicate of their own earlier ones
            $dups = $source === 'client_portal' ? ['strong' => [], 'weak' => []] : self::findDuplicates($d['email'], $d['phone_digits'], $d['company_norm']);
            $dupOf = $dups['strong'][0]['id'] ?? null;
            $pub = uuid_hex();
            $ref = CrmReference::next('enquiry');
            $ipHash = isset($meta['ip']) ? hash_hmac('sha256', (string) $meta['ip'], (string) Config::get('app.key', 'unset')) : null;
            Database::run(
                "INSERT INTO enquiries (public_id, reference, source, contact_name, email, phone, phone_digits, company_name, company_norm, service, description,
                        budget_range, expected_deadline, preferred_slot, priority, stage_id, stage_changed_at, referral_partner_id, possible_duplicate_of, duplicate_status,
                        consent_at, submitted_ip_hash, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [
                    $pub, $ref, $source, $d['contact_name'], $d['email'], $d['phone'], $d['phone_digits'], $d['company_name'], $d['company_norm'], $d['service'], $d['description'],
                    $d['budget_range'], $d['expected_deadline'], $d['preferred_slot'], $d['priority'], $stage['id'],
                    $source === 'referral_partner' ? ($meta['referral_partner_user_id'] ?? $actorId) : null,
                    $dupOf, $dupOf !== null ? 'suspected' : 'none',
                    !empty($meta['consent']) ? gmdate('Y-m-d H:i:s') : null, $ipHash, $source === 'manual' ? $actorId : null,
                ],
            );
            $id = Database::insertId();
            self::activity($id, $actorId, 'created', 'Enquiry received via ' . Config::get("workflow.sources.{$source}"));
            if ($dupOf !== null) {
                self::activity($id, null, 'duplicate', 'Possible duplicate of ' . $dups['strong'][0]['reference'] . ' (same email or phone). Review before proceeding.');
            }
            Audit::record('enquiry.created', 'enquiry', $id, [], ['reference' => $ref, 'source' => $source, 'service' => $d['service']], ['possible_duplicate' => $dupOf !== null], $actorId);

            if (in_array($source, ['website_requirements', 'contact_form', 'appointment_request', 'client_portal'], true)) { // tell the people who work the enquiry queue
                Notifier::send(Notifier::withPermission('enquiries.view_all'), 'enquiry.received', 'New ' . strtolower((string) Config::get("workflow.sources.{$source}")), $ref . ': ' . $d['contact_name'], '/crm/enquiries/' . $pub, null, 'enquiry', $id, "enquiry:{$id}:received");
            }

            return ['id' => $id, 'public_id' => $pub, 'reference' => $ref, 'duplicate_of' => $dupOf === null ? null : (int) $dupOf];
        });
        if (in_array($source, ['website_requirements', 'contact_form', 'appointment_request', 'client_portal'], true) && (string) ($d['email'] ?? '') !== '') {
            IntakeMail::enquiry((string) $d['email'], (string) $d['contact_name'], $made['reference'], $source, (string) ($d['service'] ?? ''));
        }

        return $made;
    }

    /**
     * Possible duplicates by identity (strong: same email OR same phone) and by company name (weak).
     *
     * @return array{strong:list<array<string,mixed>>,weak:list<array<string,mixed>>}
     */
    public static function findDuplicates(?string $email, ?string $phoneDigits, ?string $companyNorm, ?int $excludeId = null): array
    {
        $excl = $excludeId !== null ? ' AND e.id <> ' . (int) $excludeId : '';
        $conds = [];
        $params = [];
        if ($email !== null && $email !== '') {
            $conds[] = 'e.email = ?';
            $params[] = $email;
        }
        if ($phoneDigits !== null && strlen($phoneDigits) >= 7) {
            $conds[] = 'e.phone_digits = ?';
            $params[] = $phoneDigits;
        }
        $strong = $conds === [] ? [] : Database::all(
            'SELECT e.id, e.public_id, e.reference, e.contact_name, e.owner_id, e.referral_partner_id, e.archived_at FROM enquiries e
              WHERE (' . implode(' OR ', $conds) . ") AND e.archived_at IS NULL{$excl} ORDER BY e.id DESC LIMIT 5",
            $params,
        );
        $weak = [];
        if ($companyNorm !== null) {
            $ids = array_column($strong, 'id');
            $weak = array_values(array_filter(
                Database::all("SELECT e.id, e.public_id, e.reference, e.contact_name, e.owner_id, e.referral_partner_id, e.archived_at FROM enquiries e WHERE e.company_norm = ? AND e.archived_at IS NULL{$excl} ORDER BY e.id DESC LIMIT 5", [$companyNorm]),
                static fn (array $r): bool => !in_array($r['id'], $ids, true),
            ));
        }

        return ['strong' => $strong, 'weak' => $weak];
    }

    /**
     * Duplicates the VIEWER is allowed to see, plus a count of matches hidden from them (no cross-scope leakage).
     *
     * @return array{strong:list<array<string,mixed>>,weak:list<array<string,mixed>>,hidden:int}
     */
    public static function duplicatesFor(int $userId, array $enq): array
    {
        $all = self::findDuplicates($enq['email'], $enq['phone_digits'], $enq['company_norm'], (int) $enq['id']);
        $out = ['strong' => [], 'weak' => [], 'hidden' => 0];
        foreach (['strong', 'weak'] as $kind) {
            foreach ($all[$kind] as $d) {
                $full = EnquiryPolicy::find($d['public_id']);
                if ($full !== null && in_array(EnquiryPolicy::level($userId, $full), ['all', 'assigned'], true)) {
                    $out[$kind][] = $d;
                } else {
                    $out['hidden']++;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ editing

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $enq, array $in): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.edit');
        self::assertEditable($enq);
        $d = self::validate($in, 'manual');
        $changed = [];
        $diff = [];
        foreach (['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline', 'priority'] as $f) {
            if ((string) $d[$f] !== (string) $enq[$f]) {
                $changed[] = $f;
                $diff[$f] = ['from' => $enq[$f], 'to' => $d[$f]];
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $enq, $d, $changed, $diff): void {
            Database::run(
                'UPDATE enquiries SET contact_name = ?, email = ?, phone = ?, phone_digits = ?, company_name = ?, company_norm = ?, service = ?, description = ?,
                        budget_range = ?, expected_deadline = ?, priority = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$d['contact_name'], $d['email'], $d['phone'], $d['phone_digits'], $d['company_name'], $d['company_norm'], $d['service'], $d['description'],
                    $d['budget_range'], $d['expected_deadline'], $d['priority'], $enq['id']],
            );
            // Timeline shows what changed (visible only to people who can open the enquiry); audit keeps field names only.
            unset($diff['description']);
            self::activity((int) $enq['id'], $actorId, 'edited', 'Updated: ' . implode(', ', array_map(static fn (string $f): string => str_replace('_', ' ', $f), $changed)), null, $diff);
            Audit::record('enquiry.updated', 'enquiry', (int) $enq['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    // ------------------------------------------------------------------ pipeline

    /**
     * Moves an enquiry to another stage. $reason is required for Lost; $confirmed must be true for Won.
     */
    public static function moveStage(int $actorId, array $enq, string $toCode, ?string $reason = null, bool $confirmed = false): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.move_stage');
        self::assertEditable($enq);
        $to = CrmStageService::byCode($toCode) ?? throw new ValidationException(['Choose a valid stage.']);
        if (!$to['is_active']) {
            throw new ValidationException(['That stage is not active.']);
        }
        if ((int) $to['id'] === (int) $enq['stage_id']) {
            throw new ValidationException(['The enquiry is already in that stage.']);
        }
        $fromKind = (string) $enq['stage_kind'];

        if ($to['kind'] === 'won') {
            Gate::authorize('enquiries.mark_won', $actorId);
            if ($fromKind !== 'open') {
                throw new ValidationException(['Reopen the enquiry first before marking it Won.']);
            }
            if (!$confirmed) {
                throw new ValidationException(['Marking an enquiry Won is a deliberate decision: tick the confirmation box to continue.']);
            }
        } elseif ($to['kind'] === 'lost') {
            if ($fromKind !== 'open') {
                throw new ValidationException(['Reopen the enquiry first before marking it Lost.']);
            }
            $reason = FieldRules::line($reason ?? '', 'Reason for losing the enquiry', 3, 255);
        } elseif ($fromKind !== 'open') {
            Gate::authorize('enquiries.reopen', $actorId);
        }

        Database::transaction(function () use ($actorId, $enq, $to, $fromKind, $reason): void {
            $won = $to['kind'] === 'won';
            $lost = $to['kind'] === 'lost';
            Database::run(
                'UPDATE enquiries SET stage_id = ?, stage_changed_at = UTC_TIMESTAMP(), won_at = ?, lost_at = ?, lost_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to['id'], $won ? gmdate('Y-m-d H:i:s') : null, $lost ? gmdate('Y-m-d H:i:s') : null, $lost ? $reason : null, $enq['id']],
            );
            self::activity((int) $enq['id'], $actorId, 'stage_changed', "Stage: {$enq['stage_name']} → {$to['name']}" . ($lost ? " (reason: {$reason})" : ''), null, [
                'from' => $enq['stage_code'], 'to' => $to['code'], 'reason' => $lost ? $reason : null,
            ]);
            Audit::record('enquiry.stage_changed', 'enquiry', (int) $enq['id'], ['stage' => $enq['stage_code']], ['stage' => $to['code']], ['from_kind' => $fromKind, 'to_kind' => $to['kind']], $actorId);
        });
    }

    /**
     * An external module (e.g. a future quotation module) tells us something happened. This ONLY adds a timeline note:
     * it can never change the stage, so a quotation being created or sent can never mark an enquiry Won.
     */
    public static function recordExternalEvent(int $enquiryId, string $summary, ?int $actorId = null): void
    {
        $summary = (string) FieldRules::line($summary, 'Summary', 3, 255);
        Database::transaction(function () use ($enquiryId, $summary, $actorId): void {
            self::activity($enquiryId, $actorId, 'external', $summary);
        });
    }

    public static function assign(int $actorId, array $enq, ?int $ownerId): void
    {
        Gate::authorize('enquiries.assign', $actorId);
        if (EnquiryPolicy::level($actorId, $enq) !== 'all') {
            throw new HttpException(403);
        }
        self::assertEditable($enq);
        $ownerName = null;
        if ($ownerId !== null) {
            $owner = Database::one("SELECT id, name FROM users WHERE id = ? AND status = 'active'", [$ownerId]);
            if ($owner === null || !Gate::canAny(['enquiries.view_assigned', 'enquiries.view_all'], $ownerId)) {
                throw new ValidationException(['That person cannot own enquiries (they must be active staff with CRM access).']);
            }
            $ownerName = $owner['name'];
        }
        if (($enq['owner_id'] === null ? null : (int) $enq['owner_id']) === $ownerId) {
            return;
        }
        Database::transaction(function () use ($actorId, $enq, $ownerId, $ownerName): void {
            Database::run('UPDATE enquiries SET owner_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$ownerId, $enq['id']]);
            self::activity((int) $enq['id'], $actorId, 'assigned', $ownerId === null ? 'Owner removed' : "Assigned to {$ownerName}", null, ['from' => $enq['owner_id'], 'to' => $ownerId]);
            Audit::record('enquiry.assigned', 'enquiry', (int) $enq['id'], ['owner_id' => $enq['owner_id']], ['owner_id' => $ownerId], [], $actorId);
        });
    }

    /** @return list<array<string,mixed>> active staff who may own enquiries */
    public static function ownerCandidates(): array
    {
        return Database::all(
            "SELECT DISTINCT u.id, u.name FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.perm_key IN ('enquiries.view_assigned', 'enquiries.view_all')
              WHERE u.status = 'active' ORDER BY u.name",
        );
    }

    public static function setArchived(int $actorId, array $enq, bool $archive): void
    {
        Gate::authorize('enquiries.archive', $actorId);
        if (EnquiryPolicy::level($actorId, $enq) !== 'all') {
            throw new HttpException(403);
        }
        if ($archive === ($enq['archived_at'] !== null)) {
            return;
        }
        Database::transaction(function () use ($actorId, $enq, $archive): void {
            Database::run('UPDATE enquiries SET archived_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$archive ? gmdate('Y-m-d H:i:s') : null, $enq['id']]);
            self::activity((int) $enq['id'], $actorId, 'archived', $archive ? 'Archived' : 'Restored from archive');
            Audit::record($archive ? 'enquiry.archived' : 'enquiry.restored', 'enquiry', (int) $enq['id'], [], [], [], $actorId);
        });
    }

    // ------------------------------------------------------------------ duplicates

    /** Staff confirms this enquiry duplicates another one: it is closed as Lost (reason recorded); nothing is merged or deleted. */
    public static function markDuplicate(int $actorId, array $enq, string $otherReference): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.move_stage');
        self::assertEditable($enq);
        $other = Database::one('SELECT id, public_id, reference FROM enquiries WHERE reference = ?', [strtoupper(trim($otherReference))]);
        $otherFull = $other === null ? null : EnquiryPolicy::find((string) $other['public_id']);
        if ($otherFull === null || !EnquiryPolicy::canView($actorId, $otherFull) || (int) $other['id'] === (int) $enq['id']) {
            throw new ValidationException(['Enter the reference of another enquiry that you can access.']);
        }
        if ($enq['stage_kind'] !== 'open') {
            throw new ValidationException(['Only open enquiries can be marked as duplicates.']);
        }
        $lost = Database::one("SELECT code FROM crm_stages WHERE kind = 'lost' AND is_active = 1 ORDER BY position LIMIT 1");
        Database::transaction(function () use ($actorId, $enq, $other, $lost): void {
            Database::run("UPDATE enquiries SET possible_duplicate_of = ?, duplicate_status = 'confirmed', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$other['id'], $enq['id']]);
            self::activity((int) $other['id'], $actorId, 'duplicate', "{$enq['reference']} was marked as a duplicate of this enquiry");
            self::activity((int) $enq['id'], $actorId, 'duplicate', "Confirmed duplicate of {$other['reference']}");
            Audit::record('enquiry.duplicate_confirmed', 'enquiry', (int) $enq['id'], [], [], ['duplicate_of' => (int) $other['id']], $actorId);
            // Same transaction: if closing as Lost fails, the duplicate flag is rolled back too.
            self::moveStage($actorId, EnquiryPolicy::find((string) $enq['public_id']) ?? $enq, (string) $lost['code'], "Duplicate of {$other['reference']}");
        });
    }

    public static function dismissDuplicate(int $actorId, array $enq): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.edit');
        if ($enq['duplicate_status'] !== 'suspected') {
            return;
        }
        Database::transaction(function () use ($actorId, $enq): void {
            Database::run("UPDATE enquiries SET duplicate_status = 'dismissed', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$enq['id']]);
            self::activity((int) $enq['id'], $actorId, 'duplicate', 'Possible duplicate dismissed (reviewed: not the same enquiry)');
            Audit::record('enquiry.duplicate_dismissed', 'enquiry', (int) $enq['id'], [], [], [], $actorId);
        });
    }

    // ------------------------------------------------------------------ notes, contact history, follow-ups

    public static function addNote(int $actorId, array $enq, string $body): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.notes');
        $body = (string) FieldRules::text($body, 'Note', 2, 4000);
        Database::transaction(function () use ($actorId, $enq, $body): void {
            self::activity((int) $enq['id'], $actorId, 'note', 'Internal note', $body);
            Audit::record('enquiry.note_added', 'enquiry', (int) $enq['id'], [], [], ['length' => mb_strlen($body)], $actorId);
        });
    }

    public static function addContact(int $actorId, array $enq, string $channel, string $body): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.notes');
        $channels = (array) Config::get('workflow.contact_channels');
        $channel = (string) FieldRules::choice($channel, $channels, 'contact channel');
        $body = (string) FieldRules::text($body, 'What was discussed', 2, 4000);
        Database::transaction(function () use ($actorId, $enq, $channel, $channels, $body): void {
            self::activity((int) $enq['id'], $actorId, 'contact', 'Contact: ' . $channels[$channel], $body, ['channel' => $channel]);
            Audit::record('enquiry.contact_logged', 'enquiry', (int) $enq['id'], [], [], ['channel' => $channel], $actorId);
        });
    }

    public static function addFollowup(int $actorId, array $enq, string $dueDate, string $note): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.notes');
        self::assertEditable($enq);
        $due = (string) FieldRules::date($dueDate, 'Follow-up date', true, AppTime::today());
        $note = (string) FieldRules::line($note, 'Follow-up note', 2, 255);
        Database::transaction(function () use ($actorId, $enq, $due, $note): void {
            Database::run('INSERT INTO enquiry_followups (enquiry_id, due_date, note, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$enq['id'], $due, $note, $actorId]);
            self::refreshNextFollowup((int) $enq['id']);
            self::activity((int) $enq['id'], $actorId, 'followup', "Follow-up scheduled for {$due}", $note);
            Audit::record('enquiry.followup_added', 'enquiry', (int) $enq['id'], [], [], ['due_date' => $due], $actorId);
        });
    }

    public static function completeFollowup(int $actorId, array $enq, int $followupId): void
    {
        EnquiryPolicy::authorizeStaff($actorId, $enq, 'enquiries.notes');
        $f = Database::one('SELECT * FROM enquiry_followups WHERE id = ? AND enquiry_id = ?', [$followupId, $enq['id']]) ?? throw new HttpException(404);
        if ($f['completed_at'] !== null) {
            return;
        }
        Database::transaction(function () use ($actorId, $enq, $f): void {
            Database::run('UPDATE enquiry_followups SET completed_at = UTC_TIMESTAMP(), completed_by = ? WHERE id = ? AND completed_at IS NULL', [$actorId, $f['id']]);
            self::refreshNextFollowup((int) $enq['id']);
            self::activity((int) $enq['id'], $actorId, 'followup', "Follow-up done ({$f['due_date']})", $f['note']);
            Audit::record('enquiry.followup_completed', 'enquiry', (int) $enq['id'], [], [], ['followup_id' => (int) $f['id']], $actorId);
        });
    }

    /** @return list<array<string,mixed>> */
    public static function followups(int $enquiryId): array
    {
        return Database::all(
            'SELECT f.*, u.name AS created_by_name FROM enquiry_followups f LEFT JOIN users u ON u.id = f.created_by WHERE f.enquiry_id = ? ORDER BY (f.completed_at IS NOT NULL), f.due_date, f.id',
            [$enquiryId],
        );
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public static function timeline(int $enquiryId, int $page = 1, int $perPage = 40): array
    {
        $total = (int) Database::one('SELECT COUNT(*) AS c FROM enquiry_activity WHERE enquiry_id = ?', [$enquiryId])['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT a.id, a.type, a.summary, a.body, a.details_json, a.created_at, a.actor_id, u.name AS actor_name
               FROM enquiry_activity a LEFT JOIN users u ON u.id = a.actor_id WHERE a.enquiry_id = ? ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$enquiryId],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    // ------------------------------------------------------------------ conversion

    /**
     * Converts a Won enquiry into a client record (new, or an explicitly chosen existing one) and a DRAFT project.
     * The enquiry and its whole history stay as they are; it is merely linked to the client and the project and locked.
     *
     * @param array<string,mixed> $in client ('new' or a client public id), confirm_new, project_name, target_date
     * @return array{client_id:int,client_public_id:string,project_id:int,project_public_id:string,project_code:string}
     */
    public static function convert(int $actorId, array $enq, array $in): array
    {
        Gate::authorize('enquiries.convert', $actorId);
        Gate::authorize('projects.create', $actorId);
        if (EnquiryPolicy::level($actorId, $enq) !== 'all') {
            throw new HttpException(403);
        }
        if ($enq['archived_at'] !== null) {
            throw new ValidationException(['Restore the enquiry from the archive first.']);
        }
        if ($enq['stage_kind'] !== 'won') {
            throw new ValidationException(['Only an enquiry that was explicitly marked Won can be converted.']);
        }
        if ($enq['converted_project_id'] !== null) {
            throw new ValidationException(['This enquiry has already been converted.']);
        }

        return Database::transaction(function () use ($actorId, $enq, $in): array {
            // Re-read under lock so two admins cannot convert the same enquiry twice.
            $locked = Database::one('SELECT converted_project_id FROM enquiries WHERE id = ? FOR UPDATE', [$enq['id']]);
            if ($locked['converted_project_id'] !== null) {
                throw new ValidationException(['This enquiry has already been converted.']);
            }
            $choice = (string) ($in['client'] ?? '');
            if (($choice === '' || $choice === 'new') && $enq['source'] === 'client_portal' && $enq['client_id'] !== null) { // sent by an existing client from their dashboard: it is theirs
                $choice = (string) (Database::one('SELECT public_id FROM clients WHERE id = ?', [$enq['client_id']])['public_id'] ?? '');
            }
            if ($choice === '' || $choice === 'new') {
                $client = ClientService::create($actorId, [
                    'client_type' => $enq['company_name'] !== null ? 'business' : 'individual',
                    'contact_name' => $enq['contact_name'], 'email' => $enq['email'], 'phone' => $enq['phone'] ?? '',
                    'company_name' => $enq['company_name'] ?? '', 'confirm_new' => !empty($in['confirm_new']),
                ], (int) $enq['id']);
                $clientId = $client['id'];
                $clientPublic = $client['public_id'];
            } else {
                $existing = ClientPolicy::find($choice);
                if ($existing === null || $existing['status'] !== 'active' || $existing['archived_at'] !== null || ClientPolicy::level($actorId, $existing) !== 'all') {
                    throw new ValidationException(['Choose a valid active client to link.']);
                }
                $clientId = (int) $existing['id'];
                $clientPublic = (string) $existing['public_id'];
            }
            $serviceName = (string) Config::get("workflow.services.{$enq['service']}", $enq['service']);
            $title = FieldRules::line($in['project_name'] ?? '', 'Project name', 3, 200, false)
                ?? mb_substr(($enq['company_name'] ?? $enq['contact_name']) . ' — ' . $serviceName, 0, 200);
            $project = ProjectService::create($actorId, $clientId, [
                'title' => $title,
                'service_category' => array_key_exists($enq['service'], (array) Config::get('workflow.services')) ? $enq['service'] : null,
                'description' => $enq['description'], 'priority' => $enq['priority'], 'target_date' => $enq['expected_deadline'],
            ], (int) $enq['id']);

            Database::run('UPDATE enquiries SET client_id = ?, converted_project_id = ?, converted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$clientId, $project['id'], $enq['id']]);
            self::activity((int) $enq['id'], $actorId, 'converted', "Converted to project {$project['code']}" . ($choice === '' || $choice === 'new' ? ' with a new client record' : ' linked to an existing client'));
            Audit::record('enquiry.converted', 'enquiry', (int) $enq['id'], [], [], ['client_id' => $clientId, 'project_id' => $project['id'], 'linked_existing_client' => !($choice === '' || $choice === 'new')], $actorId);
            ReferralService::onEnquiryConverted((int) $enq['id'], (int) $clientId, (int) $project['id'], $actorId);

            return ['client_id' => $clientId, 'client_public_id' => $clientPublic, 'project_id' => $project['id'], 'project_public_id' => $project['public_id'], 'project_code' => $project['code']];
        });
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string,mixed> $enq */
    private static function assertEditable(array $enq): void
    {
        if ($enq['converted_project_id'] !== null) {
            throw new ValidationException(['This enquiry has been converted to a project and is locked. Its history is preserved.']);
        }
        if ($enq['archived_at'] !== null) {
            throw new ValidationException(['Restore the enquiry from the archive first.']);
        }
    }

    /** @param array<string,mixed> $details */
    private static function activity(int $enquiryId, ?int $actorId, string $type, string $summary, ?string $body = null, array $details = []): void
    {
        Database::run(
            'INSERT INTO enquiry_activity (enquiry_id, actor_id, type, summary, body, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$enquiryId, $actorId, $type, mb_substr($summary, 0, 255), $body, $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)],
        );
    }

    private static function refreshNextFollowup(int $enquiryId): void
    {
        Database::run(
            'UPDATE enquiries SET next_followup_date = (SELECT MIN(due_date) FROM enquiry_followups WHERE enquiry_id = ? AND completed_at IS NULL), updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$enquiryId, $enquiryId],
        );
    }
}
