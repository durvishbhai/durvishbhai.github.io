<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Quotations: an offer with line items, sent to a client who accepts or declines it in the portal (or staff record a decision received elsewhere).
 * Accepting a quotation records agreement only. It never moves money, never changes a project price, and never makes any referral commission eligible:
 * commission depends on VERIFIED PAYMENTS, nothing else.
 */
final class QuotationService
{
    private const STAFF = ['quotes.create', 'quotes.send', 'quotes.record_decision'];

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT q.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, c.public_id AS client_public_id, c.user_id AS client_user_id, p.public_id AS project_public_id, p.code AS project_code,
                    (SELECT i.public_id FROM invoices i WHERE i.quotation_id = q.id AND i.status <> 'void' ORDER BY i.id LIMIT 1) AS invoice_public_id
               FROM quotations q JOIN clients c ON c.id = q.client_id LEFT JOIN projects p ON p.id = q.project_id WHERE q.public_id = ?",
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $q = Gate::canAny(self::STAFF, $userId) ? self::find($publicId) : null;

        return $q ?? throw new HttpException(404);
    }

    /** The signed-in client's own, already-sent quotation (a client never sees a draft). @return array<string,mixed> */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $q = Gate::can('quotes.view', $userId) ? self::find($publicId) : null;
        if ($q === null || $q['client_user_id'] === null || (int) $q['client_user_id'] !== $userId || $q['status'] === 'draft') {
            throw new HttpException(404);
        }
        self::expireOne($q);

        return self::find($publicId) ?? throw new HttpException(404);
    }

    /** @return list<array<string,mixed>> */
    public static function items(array $q): array
    {
        return Database::all('SELECT position, description, quantity_x100, unit_minor, total_minor FROM quotation_items WHERE quotation_id = ? ORDER BY position', [$q['id']]);
    }

    /** @param array<string,mixed> $in @return array{title:string,project:?array<string,mixed>,valid_until:?string,client_notes:?string,internal_notes:?string,items:array<string,mixed>} */
    private static function validated(array $in, ?array $client): array
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
        $title = (string) $get(static fn () => FieldRules::line($in['title'] ?? '', 'Title', 3, 200));
        $valid = $get(static fn () => FieldRules::date($in['valid_until'] ?? '', 'Valid until', false, AppTime::today()));
        $cn = $get(static fn () => FieldRules::text($in['client_notes'] ?? '', 'Notes for the client', 2, 3000, false));
        $in_ = $get(static fn () => FieldRules::text($in['internal_notes'] ?? '', 'Internal notes', 2, 3000, false));
        $items = $get(static fn () => BillingItems::parse($in['items'] ?? []));
        $project = null;
        $pid = trim((string) ($in['project'] ?? ''));
        if ($pid !== '') {
            $project = ProjectPolicy::find($pid);
            if ($project === null || ($client !== null && (int) $project['client_id'] !== (int) $client['id'])) {
                $errors[] = 'Choose one of this client\'s projects.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'project' => $project, 'valid_until' => is_string($valid) ? $valid : null, 'client_notes' => is_string($cn) ? $cn : null, 'internal_notes' => is_string($in_) ? $in_ : null, 'items' => (array) $items];
    }

    /** @param array<string,mixed> $in @return array{public_id:string,reference:string} */
    public static function create(int $actorId, string $clientPublicId, array $in): array
    {
        Gate::authorize('quotes.create', $actorId);
        $client = ClientPolicy::find($clientPublicId);
        if ($client === null || $client['archived_at'] !== null) {
            throw new ValidationException(['Choose an existing client.']);
        }
        $v = self::validated($in, $client);
        $pub = uuid_hex();
        $ref = CrmReference::next('quotation');

        return Database::transaction(function () use ($actorId, $client, $v, $pub, $ref): array {
            Database::run("INSERT INTO quotations (public_id, reference, client_id, project_id, title, status, valid_until, total_minor, client_notes, internal_notes, created_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $client['id'], $v['project']['id'] ?? null, $v['title'], $v['valid_until'], $v['items']['subtotal_minor'], $v['client_notes'], $v['internal_notes'], $actorId]);
            $id = Database::insertId();
            BillingItems::replace('quotation_items', 'quotation_id', $id, $v['items']['items']);
            Audit::record('quotation.created', 'quotation', $id, [], ['reference' => $ref, 'total_minor' => $v['items']['subtotal_minor'], 'client_id' => (int) $client['id']], [], $actorId);

            return ['public_id' => $pub, 'reference' => $ref];
        });
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $q, array $in): void
    {
        Gate::authorize('quotes.create', $actorId);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$q['client_id']]);
        $v = self::validated($in, $client);
        Database::transaction(function () use ($actorId, $q, $v): void {
            $cur = Database::one('SELECT status, total_minor FROM quotations WHERE id = ? FOR UPDATE', [$q['id']]);
            if ($cur === null || $cur['status'] !== 'draft') {
                throw new ValidationException(['Only a draft can be edited. Withdraw a sent quotation and make a new one.']);
            }
            Database::run('UPDATE quotations SET title = ?, project_id = ?, valid_until = ?, total_minor = ?, client_notes = ?, internal_notes = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$v['title'], $v['project']['id'] ?? null, $v['valid_until'], $v['items']['subtotal_minor'], $v['client_notes'], $v['internal_notes'], $q['id']]);
            BillingItems::replace('quotation_items', 'quotation_id', (int) $q['id'], $v['items']['items']);
            Audit::record('quotation.updated', 'quotation', (int) $q['id'], ['total_minor' => (int) $cur['total_minor']], ['total_minor' => $v['items']['subtotal_minor']], [], $actorId);
        });
    }

    public static function send(int $actorId, array $q): void
    {
        Gate::authorize('quotes.send', $actorId);
        Database::transaction(function () use ($actorId, $q): void {
            $cur = Database::one('SELECT * FROM quotations WHERE id = ? FOR UPDATE', [$q['id']]);
            if ($cur === null || $cur['status'] !== 'draft') {
                throw new ValidationException(['Only a draft can be sent.']);
            }
            if ((int) $cur['total_minor'] < 1) {
                throw new ValidationException(['Add at least one line before sending.']);
            }
            if ($cur['valid_until'] !== null && $cur['valid_until'] < AppTime::today()) {
                throw new ValidationException(['The "valid until" date has passed. Choose a later date.']);
            }
            Database::run("UPDATE quotations SET status = 'sent', sent_at = UTC_TIMESTAMP(), sent_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $q['id']]);
            Audit::record('quotation.sent', 'quotation', (int) $q['id'], ['status' => 'draft'], ['status' => 'sent'], ['total_minor' => (int) $cur['total_minor']], $actorId);
            if ($q['client_user_id']) {
                Notifier::send([(int) $q['client_user_id']], 'quote.sent', 'New quotation: ' . $cur['title'], 'Total ₹' . FieldRules::formatMinor((int) $cur['total_minor']) . '. Open it to accept or decline.', '/my/quotes/' . $q['public_id'], null, 'quotation', (int) $q['id'], 'quo:' . $q['id'] . ':sent');
            }
        });
    }

    public static function withdraw(int $actorId, array $q, string $reason): void
    {
        Gate::authorize('quotes.send', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        Database::transaction(function () use ($actorId, $q, $reason): void {
            $cur = Database::one('SELECT status FROM quotations WHERE id = ? FOR UPDATE', [$q['id']]);
            if ($cur === null || $cur['status'] !== 'sent') {
                throw new ValidationException(['Only a sent quotation that has not been decided can be withdrawn.']);
            }
            Database::run("UPDATE quotations SET status = 'withdrawn', decision_note = ?, decided_at = UTC_TIMESTAMP(), decided_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $actorId, $q['id']]);
            Audit::record('quotation.withdrawn', 'quotation', (int) $q['id'], ['status' => 'sent'], ['status' => 'withdrawn'], ['reason_recorded' => true], $actorId);
        });
    }

    /** The client's own decision in the portal. */
    public static function clientDecision(int $userId, array $q, string $decision, string $note = ''): void
    {
        Gate::authorize('quotes.respond', $userId);
        if ($q['client_user_id'] === null || (int) $q['client_user_id'] !== $userId) {
            throw new HttpException(404);
        }
        self::decide($userId, $q, $decision, $note, 'portal');
    }

    /** A decision the client communicated outside the portal (email, call): staff record it with a note. */
    public static function recordOfflineDecision(int $actorId, array $q, string $decision, string $note): void
    {
        Gate::authorize('quotes.record_decision', $actorId);
        $note = (string) FieldRules::line($note, 'How and when the client told you', 5, 500);
        self::decide($actorId, $q, $decision, $note, 'offline');
    }

    private static function decide(int $actorId, array $q, string $decision, string $note, string $channel): void
    {
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new ValidationException(['Choose accept or decline.']);
        }
        $note = (string) FieldRules::line($note, 'Note', 2, 500, false);
        self::expireOne($q); // outside the transaction: the expiry must persist even though the decision is refused
        Database::transaction(function () use ($actorId, $q, $decision, $note, $channel): void {
            $cur = Database::one('SELECT * FROM quotations WHERE id = ? FOR UPDATE', [$q['id']]);
            if ($cur === null || $cur['status'] !== 'sent') {
                throw new ValidationException(['This quotation can no longer be accepted or declined' . ($cur !== null && $cur['status'] === 'expired' ? ' (it has expired)' : '') . '.']);
            }
            $to = $decision === 'accept' ? 'accepted' : 'rejected';
            Database::run('UPDATE quotations SET status = ?, decided_at = UTC_TIMESTAMP(), decided_by = ?, decision_channel = ?, decision_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $actorId, $channel, $note === '' ? null : $note, $q['id']]);
            Audit::record('quotation.' . $to, 'quotation', (int) $q['id'], ['status' => 'sent'], ['status' => $to], ['channel' => $channel, 'total_minor' => (int) $cur['total_minor']], $actorId);
            $staff = array_unique(array_merge(Notifier::withPermission('quotes.send'), $cur['created_by'] ? [(int) $cur['created_by']] : []));
            Notifier::send($staff, 'quote.decided', 'Quotation ' . $q['reference'] . ' was ' . $to, $q['client_name'] . ' · ₹' . FieldRules::formatMinor((int) $cur['total_minor']), '/quotations/' . $q['public_id'], null, 'quotation', (int) $q['id'], 'quo:' . $q['id'] . ':' . $to, $actorId);
        });
    }

    /** Marks sent quotations past their valid-until date as expired. Idempotent; also run by the scheduled task. */
    public static function expireDue(): int
    {
        return Database::run("UPDATE quotations SET status = 'expired', updated_at = UTC_TIMESTAMP() WHERE status = 'sent' AND valid_until IS NOT NULL AND valid_until < ?", [AppTime::today()])->rowCount();
    }

    private static function expireOne(array $q): void
    {
        if ($q['status'] === 'sent' && $q['valid_until'] !== null && $q['valid_until'] < AppTime::today()) {
            Database::run("UPDATE quotations SET status = 'expired', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'sent'", [$q['id']]);
        }
    }

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::canAny(self::STAFF, $userId)) {
            throw new HttpException(403);
        }
        self::expireDue();
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(q.reference LIKE ? OR q.title LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'withdrawn'], true)) {
            $w[] = 'q.status = ?';
            $p[] = $f['status'];
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['client'] ?? ''))) {
            $w[] = 'c.public_id = ?';
            $p[] = $f['client'];
        }
        $cond = implode(' AND ', $w);
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM quotations q JOIN clients c ON c.id = q.client_id WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT q.public_id, q.reference, q.title, q.status, q.total_minor, q.valid_until, q.created_at, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name
                                 FROM quotations q JOIN clients c ON c.id = q.client_id WHERE {$cond} ORDER BY q.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> the client's sent quotations, newest first */
    public static function forClient(int $userId): array
    {
        if (!Gate::can('quotes.view', $userId)) {
            return [];
        }
        self::expireDue();

        return Database::all("SELECT q.public_id, q.reference, q.title, q.status, q.total_minor, q.valid_until, q.sent_at FROM quotations q JOIN clients c ON c.id = q.client_id WHERE c.user_id = ? AND q.status <> 'draft' ORDER BY q.id DESC", [$userId]);
    }
}
