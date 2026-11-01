<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Acceptance of materially updated policies by clients.
 *
 * Publishing a MATERIAL version of a document that requires acceptance (a) sets a deadline (the version's days, else the default setting), (b) emails every active
 * client and (c) from then on shows each client an acceptance screen. A client is RESTRICTED (computed, never stored as a user status, so unrelated suspensions are
 * untouched and nothing is deleted) when they have refused, or when the deadline has passed without an acceptance, if the Owner has restriction switched on. A restricted
 * client can still read legal documents, open their invoices, receipts, quotations and agreements (read only), change their password and contact us. Recording an
 * acceptance lifts the restriction at once. The new text is never applied retroactively: completed orders stay under the terms they were made under.
 */
final class PolicyAcceptance
{
    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /** Only portal clients are asked: users who hold a client profile. */
    public static function isClient(int $userId): bool
    {
        return Database::one('SELECT 1 AS x FROM clients WHERE user_id = ?', [$userId]) !== null;
    }

    /**
     * @return array{pending:list<array<string,mixed>>,overdue:bool,declined:bool,restricted:bool,deadline:?string}
     */
    public static function state(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $empty = ['pending' => [], 'overdue' => false, 'declined' => false, 'restricted' => false, 'deadline' => null];
        try {
            if (!self::isClient($userId)) {
                return self::$cache[$userId] = $empty;
            }
            LegalDocuments::sync();
            $rows = Database::all(
                "SELECT v.id AS version_id, d.id AS document_id, d.doc_key, d.title, v.version_no, v.change_summary, v.effective_date, v.accept_by, v.published_at,
                        (SELECT a.decision FROM legal_acceptances a WHERE a.user_id = ? AND a.version_id = v.id) AS my_decision
                   FROM legal_documents d JOIN legal_document_versions v ON v.document_id = d.id AND v.status = 'published'
                  WHERE d.requires_acceptance = 1 AND v.is_material = 1
                    AND NOT EXISTS (SELECT 1 FROM legal_acceptances a2 WHERE a2.user_id = ? AND a2.version_id = v.id AND a2.decision = 'accepted')
                  ORDER BY d.sort_order",
                [$userId, $userId],
            );
        } catch (\Throwable) {
            return self::$cache[$userId] = $empty;
        }
        $today = AppTime::today();
        $overdue = $declined = false;
        $deadline = null;
        foreach ($rows as $r) {
            $declined = $declined || $r['my_decision'] === 'declined';
            $overdue = $overdue || ($r['accept_by'] !== null && (string) $r['accept_by'] < $today);
            if ($r['accept_by'] !== null && ($deadline === null || (string) $r['accept_by'] < $deadline)) {
                $deadline = (string) $r['accept_by'];
            }
        }
        $on = ComplianceSettings::bool('restriction_enabled');

        return self::$cache[$userId] = ['pending' => $rows, 'overdue' => $overdue, 'declined' => $declined, 'restricted' => $rows !== [] && $on && ($overdue || $declined), 'deadline' => $deadline];
    }

    /** Paths a restricted client can still use. GET-only unless listed in $write. */
    public static function allowed(string $method, string $path): bool
    {
        $write = ['/policy-acceptance', '/logout', '/account/password', '/account/change-password'];
        foreach ($write as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        foreach (['/account', '/my/invoices', '/my/receipts', '/my/quotes', '/my/agreements', '/invoices/', '/receipts/', '/quotations/', '/agreements/', '/notifications', '/assets/', '/offline', '/sw.js'] as $p) {
            if ($path === rtrim($p, '/') || str_starts_with($path, $p)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------ announcing

    /** Called after a version goes live: sets the deadline and tells the clients. */
    public static function announce(int $versionId): void
    {
        $v = Database::one("SELECT v.*, d.doc_key, d.title AS doc_title, d.requires_acceptance FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE v.id = ? AND v.status = 'published'", [$versionId]);
        if ($v === null || (int) $v['requires_acceptance'] !== 1 || (int) $v['is_material'] !== 1) {
            return;
        }
        $days = $v['acceptance_days'] !== null ? (int) $v['acceptance_days'] : ComplianceSettings::int('acceptance_days');
        $by = (new \DateTimeImmutable(AppTime::today()))->modify('+' . max(1, $days) . ' day')->format('Y-m-d');
        Database::run('UPDATE legal_document_versions SET accept_by = ? WHERE id = ?', [$by, $versionId]);
        $v['accept_by'] = $by;
        foreach (Database::all("SELECT u.id, u.name, u.email FROM users u JOIN clients c ON c.user_id = u.id WHERE u.status = 'active'") as $u) {
            $n = Database::run('INSERT IGNORE INTO legal_notices (version_id, user_id, notified_at) VALUES (?, ?, UTC_TIMESTAMP())', [$versionId, $u['id']])->rowCount();
            if ($n === 1) {
                PolicyMail::updated((string) $u['email'], (string) $u['name'], $v);
            }
        }
        self::reset();
    }

    // ------------------------------------------------------------------------------------------------ deciding

    /** @param list<int> $versionIds the versions the client agrees to (all the pending ones) */
    public static function accept(int $userId, array $versionIds, ?string $ip, ?string $ua, string $method = 'portal', ?int $recordedBy = null, ?string $note = null): void
    {
        $was = self::state($userId);
        if ($versionIds === []) {
            throw new ValidationException(['Tick the box to confirm you accept the updated terms.']);
        }
        Database::transaction(function () use ($userId, $versionIds, $ip, $ua, $method, $recordedBy, $note): void {
            foreach ($versionIds as $vid) {
                $v = Database::one("SELECT v.id, v.document_id, v.version_no, d.doc_key FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE v.id = ? AND v.status = 'published'", [$vid]) ?? throw new ValidationException(['That version is no longer current. Please reload the page.']);
                Database::run(
                    "INSERT INTO legal_acceptances (user_id, document_id, version_id, decision, method, decided_at, ip, user_agent, recorded_by, note) VALUES (?, ?, ?, 'accepted', ?, UTC_TIMESTAMP(), ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE decision = 'accepted', method = VALUES(method), decided_at = UTC_TIMESTAMP(), ip = VALUES(ip), user_agent = VALUES(user_agent), recorded_by = VALUES(recorded_by), note = VALUES(note)",
                    [$userId, $v['document_id'], $v['id'], $method, $ip === null ? null : substr($ip, 0, 45), $ua === null ? null : substr($ua, 0, 255), $recordedBy, $note],
                );
                Audit::record('legal.accepted', 'legal_document', (int) $v['document_id'], [], ['key' => $v['doc_key'], 'version' => $v['version_no'], 'user' => $userId, 'method' => $method], [], $recordedBy ?? $userId);
            }
        });
        self::reset();
        if ($was['restricted'] && !self::state($userId)['restricted']) {
            $u = Database::one('SELECT name, email FROM users WHERE id = ?', [$userId]);
            if ($u !== null) {
                PolicyMail::reactivated((string) $u['email'], (string) $u['name']);
            }
        }
    }

    /** The client refuses the updated terms. Their account is restricted at once; nothing is deleted. */
    public static function decline(int $userId, array $versionIds, ?string $ip, ?string $ua): void
    {
        if ($versionIds === []) {
            throw new ValidationException(['Nothing to decline.']);
        }
        Database::transaction(function () use ($userId, $versionIds, $ip, $ua): void {
            foreach ($versionIds as $vid) {
                $v = Database::one("SELECT v.id, v.document_id, v.version_no, d.doc_key FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE v.id = ? AND v.status = 'published'", [$vid]) ?? throw new ValidationException(['That version is no longer current.']);
                Database::run(
                    "INSERT INTO legal_acceptances (user_id, document_id, version_id, decision, method, decided_at, ip, user_agent) VALUES (?, ?, ?, 'declined', 'portal', UTC_TIMESTAMP(), ?, ?)
                     ON DUPLICATE KEY UPDATE decision = IF(decision = 'accepted', 'accepted', 'declined'), decided_at = UTC_TIMESTAMP()",
                    [$userId, $v['document_id'], $v['id'], $ip === null ? null : substr($ip, 0, 45), $ua === null ? null : substr($ua, 0, 255)],
                );
                Audit::record('legal.declined', 'legal_document', (int) $v['document_id'], [], ['key' => $v['doc_key'], 'version' => $v['version_no'], 'user' => $userId], [], $userId);
            }
        });
        self::reset();
        $u = Database::one('SELECT name, email FROM users WHERE id = ?', [$userId]);
        if ($u !== null && self::state($userId)['restricted']) {
            PolicyMail::restricted((string) $u['email'], (string) $u['name'], true);
        }
    }

    /** Sends the "your account is restricted" email once per version, the first time a restricted client is seen. */
    public static function noteRestricted(int $userId): void
    {
        $st = self::state($userId);
        if (!$st['restricted']) {
            return;
        }
        $u = Database::one('SELECT name, email FROM users WHERE id = ?', [$userId]);
        foreach ($st['pending'] as $r) {
            $n = Database::run('UPDATE legal_notices SET restricted_notified_at = UTC_TIMESTAMP() WHERE version_id = ? AND user_id = ? AND restricted_notified_at IS NULL', [$r['version_id'], $userId])->rowCount();
            if ($n === 1 && $u !== null) {
                PolicyMail::restricted((string) $u['email'], (string) $u['name'], (bool) $st['declined']);
                break;
            }
        }
    }

    /** At signup the client accepts the live versions of the documents that need acceptance. */
    public static function recordSignup(int $userId, ?string $ip, ?string $ua): void
    {
        try {
            foreach (Database::all("SELECT v.id FROM legal_documents d JOIN legal_document_versions v ON v.document_id = d.id AND v.status = 'published' WHERE d.requires_acceptance = 1") as $v) {
                Database::run("INSERT IGNORE INTO legal_acceptances (user_id, document_id, version_id, decision, method, decided_at, ip, user_agent) SELECT ?, v.document_id, v.id, 'accepted', 'signup', UTC_TIMESTAMP(), ?, ? FROM legal_document_versions v WHERE v.id = ?",
                    [$userId, $ip === null ? null : substr($ip, 0, 45), $ua === null ? null : substr($ua, 0, 255), $v['id']]);
            }
        } catch (\Throwable) {
        }
        self::reset();
    }

    // ------------------------------------------------------------------------------------------------ the Owner's view

    /** @return list<array<string,mixed>> material live versions with their acceptance numbers */
    public static function overview(): array
    {
        $out = [];
        $clients = (int) Database::one("SELECT COUNT(*) AS c FROM users u JOIN clients c ON c.user_id = u.id WHERE u.status = 'active'")['c'];
        foreach (Database::all("SELECT v.*, d.doc_key, d.title AS doc_title FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE d.requires_acceptance = 1 AND v.is_material = 1 AND v.status IN ('published','superseded') ORDER BY v.published_at DESC LIMIT 30") as $v) {
            $acc = (int) Database::one("SELECT COUNT(*) AS c FROM legal_acceptances a JOIN users u ON u.id = a.user_id JOIN clients c ON c.user_id = u.id WHERE a.version_id = ? AND a.decision = 'accepted' AND u.status = 'active'", [$v['id']])['c'];
            $dec = (int) Database::one("SELECT COUNT(*) AS c FROM legal_acceptances a WHERE a.version_id = ? AND a.decision = 'declined'", [$v['id']])['c'];
            $v['accepted'] = $acc;
            $v['declined'] = $dec;
            $v['pending'] = max(0, $clients - $acc - $dec);
            $v['overdue'] = $v['accept_by'] !== null && (string) $v['accept_by'] < AppTime::today();
            $out[] = $v;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> one row per client for a version: accepted / declined / pending (+restricted) */
    public static function clientsFor(int $versionId): array
    {
        $v = Database::one('SELECT accept_by FROM legal_document_versions WHERE id = ?', [$versionId]) ?? throw new HttpException(404);
        $rows = Database::all(
            "SELECT u.id, u.name, u.email, a.decision, a.decided_at, a.method, n.notified_at, n.reminded_at, n.restricted_notified_at FROM users u JOIN clients c ON c.user_id = u.id
               LEFT JOIN legal_acceptances a ON a.user_id = u.id AND a.version_id = ? LEFT JOIN legal_notices n ON n.user_id = u.id AND n.version_id = ? WHERE u.status = 'active' ORDER BY u.name",
            [$versionId, $versionId],
        );
        foreach ($rows as &$r) {
            $r['state'] = $r['decision'] === 'accepted' ? 'accepted' : ($r['decision'] === 'declined' ? 'declined' : ($v['accept_by'] !== null && (string) $v['accept_by'] < AppTime::today() ? 'overdue' : 'pending'));
        }

        return $rows;
    }

    /** Sends a reminder to every client who has not accepted. @return int emails sent */
    public static function remind(int $actorId, int $versionId): int
    {
        Gate::authorize('compliance.manage', $actorId);
        $v = Database::one("SELECT v.*, d.title AS doc_title, d.doc_key FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE v.id = ? AND v.status = 'published' AND v.is_material = 1", [$versionId]) ?? throw new ValidationException(['Only the live version can be reminded about.']);
        $n = 0;
        foreach (self::clientsFor($versionId) as $c) {
            if (in_array($c['state'], ['pending', 'overdue'], true)) {
                PolicyMail::reminder((string) $c['email'], (string) $c['name'], $v);
                Database::run('UPDATE legal_notices SET reminded_at = UTC_TIMESTAMP() WHERE version_id = ? AND user_id = ?', [$versionId, $c['id']]);
                $n++;
            }
        }
        Audit::record('legal.reminders_sent', 'legal_document', (int) $v['document_id'], [], ['version' => $v['version_no'], 'count' => $n], [], $actorId);

        return $n;
    }

    public static function extendDeadline(int $actorId, int $versionId, string $newDate): void
    {
        Gate::authorize('compliance.manage', $actorId);
        if (!DeliveryService::validDate($newDate) || $newDate < AppTime::today()) {
            throw new ValidationException(['Choose a deadline that is today or later.']);
        }
        $v = Database::one("SELECT document_id, version_no FROM legal_document_versions WHERE id = ? AND status = 'published'", [$versionId]) ?? throw new HttpException(404);
        Database::run('UPDATE legal_document_versions SET accept_by = ? WHERE id = ?', [$newDate, $versionId]);
        Audit::record('legal.deadline_extended', 'legal_document', (int) $v['document_id'], [], ['version' => $v['version_no'], 'accept_by' => $newDate], [], $actorId);
        self::reset();
    }

    /** The Owner records an acceptance given another way (for example on paper). */
    public static function recordFor(int $actorId, int $userId, int $versionId, string $note): void
    {
        Gate::authorize('compliance.manage', $actorId);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 250) {
            throw new ValidationException(['Say how the acceptance was given (up to 250 characters).']);
        }
        self::accept($userId, [$versionId], null, null, 'admin_record', $actorId, $note);
    }
}
