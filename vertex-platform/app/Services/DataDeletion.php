<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Data deletion requests.
 *
 *   1. Anyone can ask (signed in or not). The request is only passed on after the requester proves they control the email address (a one-time link), or is signed in
 *      to the matching account. The answer is the same whether or not we hold data about that address.
 *   2. The Owner reviews it: verify another way, ask for clarification, approve or reject with a reason the requester can read.
 *   3. Processing (Owner, with their password) DELETES what is not needed, ANONYMISES what must stay but need not identify the person, and RETAINS what the law or a
 *      contract requires (invoices, quotations, payment receipts, signed agreements and the project records that support them). What was done, what was kept and why is
 *      recorded on the request and sent to the requester. A request never deletes a financial or contractual record.
 *   Team (staff) accounts are not handled here: the Owner deals with them directly.
 */
final class DataDeletion
{
    public const STATUS = ['awaiting_verification' => 'Waiting for email confirmation', 'verified' => 'Received: under review', 'needs_clarification' => 'We need more information', 'approved' => 'Approved: being processed', 'rejected' => 'Not approved', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn'];
    private const OPEN = ['awaiting_verification', 'verified', 'needs_clarification', 'approved'];

    // ------------------------------------------------------------------------------------------------ requester side

    /** @param array<string,mixed> $in name, email, reason @return array{reference:string} the same shape whether or not data exists */
    public static function submit(array $in, ?array $signedIn, string $ip): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $reason = trim((string) ($in['reason'] ?? ''));
        $errors = [];
        if ($signedIn !== null) {
            $name = (string) $signedIn['name'];
            $email = mb_strtolower((string) $signedIn['email']);
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[<>]/', $name)) {
            $errors[] = 'Enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Enter the email address the data is held under.';
        }
        if (mb_strlen($reason) > 1000 || preg_match('/[<>]/', $reason)) {
            $errors[] = 'The reason is too long or contains < or >.';
        }
        if (empty($in['confirm']) || $in['confirm'] === '0') {
            $errors[] = 'Please confirm that you are the person the data is about, or are authorised to act for them.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!RateLimiter::hit('deletion_ip', $ip) || !RateLimiter::hit('deletion_email', $email)) {
            throw new \Vertex\Core\HttpException(429);
        }
        $existing = Database::one("SELECT * FROM deletion_requests WHERE requester_email = ? AND status IN ('awaiting_verification','verified','needs_clarification','approved') ORDER BY id DESC LIMIT 1", [$email]);
        if ($existing !== null) {
            if ($existing['status'] === 'awaiting_verification') {
                self::sendVerification($existing, $name);
            }

            return ['reference' => (string) $existing['reference']];
        }
        $user = Database::one('SELECT id, name FROM users WHERE email = ?', [$email]);
        $verifyToken = bin2hex(random_bytes(32));
        $same = $signedIn !== null && (int) $signedIn['id'] === (int) ($user['id'] ?? 0);
        $ref = self::newReference();
        $id = (int) Database::transaction(function () use ($ref, $name, $email, $user, $reason, $verifyToken, $same, $ip): int {
            Database::run(
                'INSERT INTO deletion_requests (reference, requester_name, requester_email, user_id, reason, status, verify_hash, ip_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$ref, $name, $email, $user['id'] ?? null, $reason === '' ? null : $reason, 'awaiting_verification', hash('sha256', $verifyToken), hash('sha256', (string) \Vertex\Core\Config::get('app.key', 'del') . '|' . $ip)],
            );
            $id = Database::insertId();
            self::event($id, 'submitted', 'Request received.', true, null);
            Audit::record('privacy.deletion_requested', 'deletion_request', $id, [], ['reference' => $ref], [], $same ? (int) $user['id'] : null);

            return $id;
        });
        if ($same) {
            self::markVerified($id, 'signed_in', null);
        } else {
            self::sendVerification(Database::one('SELECT * FROM deletion_requests WHERE id = ?', [$id]), $name, $verifyToken);
        }

        return ['reference' => $ref];
    }

    private static function newReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $ref = 'DEL-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            if (Database::one('SELECT 1 AS x FROM deletion_requests WHERE reference = ?', [$ref]) === null) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not create a reference.');
    }

    /** @param array<string,mixed> $req */
    private static function sendVerification(array $req, string $name, ?string $token = null): void
    {
        if ($token === null) { // a repeated submission: a fresh link replaces the old one
            $token = bin2hex(random_bytes(32));
            Database::run('UPDATE deletion_requests SET verify_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [hash('sha256', $token), $req['id']]);
        }
        PrivacyMail::verify((string) $req['requester_email'], $name, (string) $req['reference'], $token);
    }

    /** The requester opened the email link. @return array<string,mixed>|null the request when the link was valid */
    public static function verifyLink(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $r = Database::one("SELECT * FROM deletion_requests WHERE verify_hash = ? AND status = 'awaiting_verification' AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY", [hash('sha256', $token)]);
        if ($r === null) {
            return null;
        }
        self::markVerified((int) $r['id'], 'email_link', null);

        return Database::one('SELECT * FROM deletion_requests WHERE id = ?', [$r['id']]);
    }

    private static function markVerified(int $id, string $method, ?int $actor): void
    {
        $track = bin2hex(random_bytes(32));
        Database::transaction(function () use ($id, $method, $actor, $track): void {
            Database::run("UPDATE deletion_requests SET status = 'verified', verified_at = UTC_TIMESTAMP(), verified_method = ?, verify_hash = NULL, track_hash = COALESCE(track_hash, ?), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('awaiting_verification','needs_clarification','verified')", [$method, hash('sha256', $track), $id]);
            self::event($id, 'verified', $method === 'owner' ? 'Identity confirmed by our team.' : 'Email address confirmed.', true, $actor);
        });
        $r = Database::one('SELECT * FROM deletion_requests WHERE id = ?', [$id]);
        // the private status link is only known at this moment (only its hash is stored)
        if ($r !== null && $r['track_hash'] === hash('sha256', $track)) {
            PrivacyMail::received((string) $r['requester_email'], (string) $r['requester_name'], (string) $r['reference'], $track, ComplianceSettings::int('deletion_response_days'));
        }
    }

    /** @return array<string,mixed>|null */
    public static function byTrack(string $token): ?array
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) ? Database::one('SELECT * FROM deletion_requests WHERE track_hash = ?', [hash('sha256', $token)]) : null;
    }

    /** "Where is my request?" The reply never says whether a request exists: if reference and email match, a fresh private link is emailed. */
    public static function sendStatusLink(string $reference, string $email, string $ip): void
    {
        if (!RateLimiter::hit('deletion_ip', $ip)) {
            throw new \Vertex\Core\HttpException(429);
        }
        $r = Database::one('SELECT * FROM deletion_requests WHERE reference = ? AND requester_email = ?', [strtoupper(trim($reference)), mb_strtolower(trim($email))]);
        if ($r === null || $r['status'] === 'awaiting_verification') {
            return;
        }
        $track = bin2hex(random_bytes(32));
        Database::run('UPDATE deletion_requests SET track_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [hash('sha256', $track), $r['id']]);
        PrivacyMail::statusLink((string) $r['requester_email'], (string) $r['requester_name'], (string) $r['reference'], $track);
    }

    /** @return list<array<string,mixed>> */
    public static function events(int $requestId, bool $publicOnly): array
    {
        return Database::all('SELECT e.*, u.name AS actor_name FROM deletion_request_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ?' . ($publicOnly ? ' AND e.visible_to_requester = 1' : '') . ' ORDER BY e.id', [$requestId]);
    }

    public static function reply(array $req, string $note): void
    {
        $note = trim($note);
        if (!in_array($req['status'], ['needs_clarification', 'verified'], true) || $note === '' || mb_strlen($note) > 1000 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['Write your reply (up to 1000 characters, without < or >).']);
        }
        Database::transaction(function () use ($req, $note): void {
            Database::run("UPDATE deletion_requests SET status = 'verified', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$req['id']]);
            self::event((int) $req['id'], 'requester_reply', $note, true, null);
        });
    }

    public static function withdraw(array $req): void
    {
        if (!in_array($req['status'], self::OPEN, true)) {
            throw new ValidationException(['This request can no longer be withdrawn.']);
        }
        Database::transaction(function () use ($req): void {
            Database::run("UPDATE deletion_requests SET status = 'withdrawn', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$req['id']]);
            self::event((int) $req['id'], 'withdrawn', 'Withdrawn by the requester.', true, null);
        });
    }

    private static function event(int $id, string $event, ?string $note, bool $visible, ?int $actor): void
    {
        Database::run('INSERT INTO deletion_request_events (request_id, event, note, visible_to_requester, actor_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$id, $event, $note === null ? null : mb_substr($note, 0, 1000), $visible ? 1 : 0, $actor]);
    }

    // ------------------------------------------------------------------------------------------------ the Owner's side

    /** @param array{status?:string,q?:string} $f @return array{rows:list<array<string,mixed>>,counts:array<string,int>} */
    public static function listing(int $actorId, array $f): array
    {
        Gate::authorize('compliance.manage', $actorId);
        $w = ['1 = 1'];
        $p = [];
        if (isset(self::STATUS[$f['status'] ?? ''])) {
            $w[] = 'status = ?';
            $p[] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $w[] = '(reference LIKE ? OR requester_email LIKE ? OR requester_name LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        $counts = [];
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM deletion_requests GROUP BY status') as $r) {
            $counts[(string) $r['status']] = (int) $r['c'];
        }

        return ['rows' => Database::all('SELECT * FROM deletion_requests WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(status, \'verified\', \'needs_clarification\', \'approved\', \'awaiting_verification\', \'completed\', \'rejected\', \'withdrawn\'), id DESC LIMIT 200', $p), 'counts' => $counts];
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        return Database::one('SELECT d.*, h.name AS handler_name FROM deletion_requests d LEFT JOIN users h ON h.id = d.handled_by WHERE d.id = ?', [$id]) ?? throw new HttpException(404);
    }

    private static function count(string $sql, array $p = []): int
    {
        try {
            return (int) Database::one($sql, $p)['c'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * What processing would do for this request (nothing is changed). Used for the Owner's preview and by execute().
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public static function plan(array $req): array
    {
        $email = (string) $req['requester_email'];
        $user = Database::one('SELECT id, name, status FROM users WHERE email = ?', [$email]);
        $uid = $user === null ? 0 : (int) $user['id'];
        $client = Database::one('SELECT id, contact_name FROM clients WHERE user_id = ? OR (email = ? AND user_id IS NULL)', [$uid, $email]);
        $cid = $client === null ? 0 : (int) $client['id'];
        $roles = $uid === 0 ? [] : array_column(Database::all('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$uid]), 'slug');
        $blocked = array_values(array_diff($roles, ['client'])) !== [] ? 'This is a team account (roles: ' . implode(', ', array_diff($roles, ['client'])) . '). Team accounts are not processed here: handle them directly.' : '';
        $fin = [
            'Invoices' => self::count('SELECT COUNT(*) AS c FROM invoices WHERE client_id = ?', [$cid]),
            'Payment receipts' => self::count('SELECT COUNT(*) AS c FROM receipts WHERE client_id = ?', [$cid]),
            'Credit notes' => self::count('SELECT COUNT(*) AS c FROM credit_notes WHERE client_id = ?', [$cid]),
            'Quotations' => self::count('SELECT COUNT(*) AS c FROM quotations WHERE client_id = ?', [$cid]),
            'Recorded payments' => self::count('SELECT COUNT(*) AS c FROM client_payments WHERE client_id = ?', [$cid]),
            'Online payment orders' => self::count('SELECT COUNT(*) AS c FROM gateway_orders WHERE client_id = ?', [$cid]),
            'Agreements' => self::count('SELECT COUNT(*) AS c FROM agreements WHERE client_id = ?', [$cid]),
            'Domain and hosting service records' => self::count('SELECT COUNT(*) AS c FROM renewal_services WHERE client_id = ?', [$cid]),
            'Projects and their records' => self::count('SELECT COUNT(*) AS c FROM projects WHERE client_id = ?', [$cid]),
        ];
        $financial = array_sum(array_diff_key($fin, ['Projects and their records' => 1])) > 0;
        $delete = [
            'Notifications' => $uid === 0 ? 0 : self::count('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?', [$uid]),
            'Phone and browser notification registrations' => $uid === 0 ? 0 : self::count('SELECT COUNT(*) AS c FROM push_subscriptions WHERE user_id = ?', [$uid]),
            'Sign-in links and recovery codes' => $uid === 0 ? 0 : self::count('SELECT COUNT(*) AS c FROM auth_tokens WHERE user_id = ?', [$uid]) + self::count('SELECT COUNT(*) AS c FROM user_recovery_codes WHERE user_id = ?', [$uid]),
            'Sign-in attempt records' => self::count('SELECT COUNT(*) AS c FROM login_attempts WHERE email = ?', [$email]),
            'Enquiries that did not become a project' => self::count('SELECT COUNT(*) AS c FROM enquiries WHERE (email = ? OR client_id = ?) AND converted_project_id IS NULL', [$email, $cid]),
            'Staff notes about you' => $cid === 0 ? 0 : self::count('SELECT COUNT(*) AS c FROM client_notes WHERE client_id = ?', [$cid]),
            'Job applications' => self::count('SELECT COUNT(*) AS c FROM site_job_applications WHERE email = ?', [$email]),
        ];
        $anon = ['Your sign-in account (name, email, phone, password, Google link, two-factor secret): replaced by a placeholder' => $uid === 0 ? 0 : 1,
            'Enquiries that became a project: phone number and tracking data removed' => self::count('SELECT COUNT(*) AS c FROM enquiries WHERE (email = ? OR client_id = ?) AND converted_project_id IS NOT NULL', [$email, $cid]),
            'Partner applications' => self::count('SELECT COUNT(*) AS c FROM partner_applications WHERE email = ?', [$email]),
            'Client profile: ' . ($financial ? 'phone and website removed; the name, company, email, address and tax number that appear on invoices are kept' : 'all personal details replaced by a placeholder') => $cid === 0 ? 0 : 1];
        $years = ComplianceSettings::int('retention_years');
        $reason = 'Kept for accounting, tax and contract record-keeping' . ($years > 0 ? ' (we keep them for ' . $years . ' years, or longer if the law requires)' : '') . '; the exact period is to be confirmed by our accountant and lawyer.';
        $retain = [];
        foreach ($fin as $what => $n) {
            if ($n > 0) {
                $retain[] = ['what' => $what, 'count' => $n, 'reason' => $what === 'Projects and their records' ? 'Needed to support the invoices, agreements and warranty of the work done for you.' : $reason];
            }
        }
        $retain[] = ['what' => 'Security and audit log', 'count' => null, 'reason' => 'Kept to protect accounts and to show that actions were authorised (it holds technical details such as IP addresses).'];
        $retain[] = ['what' => 'This deletion request and its outcome', 'count' => 1, 'reason' => 'Kept as evidence that your request was handled.'];

        return ['user_id' => $uid, 'client_id' => $cid, 'blocked' => $blocked, 'financial' => $financial, 'delete' => $delete, 'anonymise' => $anon, 'retain' => $retain, 'nothing' => $uid === 0 && $cid === 0 && array_sum($delete) === 0];
    }

    private static function owner(int $actorId): void
    {
        Gate::authorize('compliance.manage', $actorId);
    }

    public static function verifyByOwner(int $actorId, int $id, string $note): void
    {
        self::owner($actorId);
        $r = self::find($id);
        if (!in_array($r['status'], ['awaiting_verification', 'needs_clarification'], true)) {
            throw new ValidationException(['This request does not need verification.']);
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new ValidationException(['Say how you confirmed the identity (up to 500 characters).']);
        }
        self::markVerified($id, 'owner', $actorId);
        Database::run('UPDATE deletion_requests SET handled_by = ?, internal_note = CONCAT(COALESCE(internal_note, \'\'), ?, CHAR(10)) WHERE id = ?', [$actorId, '[' . gmdate('Y-m-d H:i') . '] Verified: ' . $note, $id]);
        Audit::record('privacy.deletion_verified', 'deletion_request', $id, [], ['by' => 'owner'], [], $actorId);
    }

    public static function clarify(int $actorId, int $id, string $question): void
    {
        self::owner($actorId);
        $r = self::find($id);
        $q = trim($question);
        if ($r['status'] !== 'verified' || $q === '' || mb_strlen($q) > 1000 || preg_match('/[<>]/', $q)) {
            throw new ValidationException(['Write what you need to know (up to 1000 characters, without < or >). Only a verified request can be questioned.']);
        }
        Database::transaction(function () use ($actorId, $id, $q): void {
            Database::run("UPDATE deletion_requests SET status = 'needs_clarification', decision_note = ?, handled_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$q, $actorId, $id]);
            self::event($id, 'clarification_requested', $q, true, $actorId);
        });
        PrivacyMail::statusChanged((string) $r['requester_email'], (string) $r['requester_name'], (string) $r['reference'], 'We need more information', $q, (string) ($r['track_hash'] ?? ''));
    }

    public static function approve(int $actorId, int $id, string $note): void
    {
        self::owner($actorId);
        $r = self::find($id);
        if ($r['status'] !== 'verified') {
            throw new ValidationException(['Only a verified request that is under review can be approved.']);
        }
        $plan = self::plan($r);
        if ($plan['blocked'] !== '') {
            throw new ValidationException([$plan['blocked']]);
        }
        $note = trim($note);
        if (mb_strlen($note) > 1000 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['The note is too long or contains < or >.']);
        }
        Database::transaction(function () use ($actorId, $id, $note): void {
            Database::run("UPDATE deletion_requests SET status = 'approved', decision_note = ?, handled_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$note === '' ? null : $note, $actorId, $id]);
            self::event($id, 'approved', $note !== '' ? $note : 'Your request was approved. Records we must keep by law are kept; everything else will be deleted or anonymised.', true, $actorId);
            Audit::record('privacy.deletion_approved', 'deletion_request', $id, [], [], [], $actorId);
        });
        PrivacyMail::statusChanged((string) $r['requester_email'], (string) $r['requester_name'], (string) $r['reference'], 'Approved', 'We approved your request. Records that the law or a contract requires us to keep are kept; everything else will be deleted or anonymised, and we will send you a summary when it is done.', (string) ($r['track_hash'] ?? ''));
    }

    public static function reject(int $actorId, int $id, string $reason): void
    {
        self::owner($actorId);
        $r = self::find($id);
        $reason = trim($reason);
        if (!in_array($r['status'], ['verified', 'needs_clarification', 'awaiting_verification', 'approved'], true) || $reason === '' || mb_strlen($reason) > 1000 || preg_match('/[<>]/', $reason)) {
            throw new ValidationException(['Give the reason the requester will read (up to 1000 characters, without < or >).']);
        }
        Database::transaction(function () use ($actorId, $id, $reason): void {
            Database::run("UPDATE deletion_requests SET status = 'rejected', decision_note = ?, handled_by = ?, completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $actorId, $id]);
            self::event($id, 'rejected', $reason, true, $actorId);
            Audit::record('privacy.deletion_rejected', 'deletion_request', $id, [], ['reason' => $reason], [], $actorId);
        });
        PrivacyMail::statusChanged((string) $r['requester_email'], (string) $r['requester_name'], (string) $r['reference'], 'Not approved', $reason, (string) ($r['track_hash'] ?? ''));
    }

    public static function addNote(int $actorId, int $id, string $note): void
    {
        self::owner($actorId);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['Write a note of up to 1000 characters without < or >.']);
        }
        self::find($id);
        Database::run('UPDATE deletion_requests SET internal_note = CONCAT(COALESCE(internal_note, \'\'), ?, CHAR(10)), updated_at = UTC_TIMESTAMP() WHERE id = ?', ['[' . gmdate('Y-m-d H:i') . '] ' . $note, $id]);
        self::event($id, 'internal_note', $note, false, $actorId);
    }

    /** Carries out an approved request. @return array<string,mixed> the recorded result */
    public static function execute(int $actorId, int $id): array
    {
        self::owner($actorId);
        $req = self::find($id);
        if ($req['status'] !== 'approved') {
            throw new ValidationException(['Only an approved request can be processed.']);
        }
        $plan = self::plan($req);
        if ($plan['blocked'] !== '') {
            throw new ValidationException([$plan['blocked']]);
        }
        $email = (string) $req['requester_email'];
        $uid = (int) $plan['user_id'];
        $cid = (int) $plan['client_id'];
        $done = ['deleted' => [], 'anonymised' => [], 'retained' => $plan['retain']];
        Database::transaction(function () use ($actorId, $id, $req, $plan, $email, $uid, $cid, &$done): void {
            $del = static function (string $label, string $sql, array $p) use (&$done): void {
                $n = Database::run($sql, $p)->rowCount();
                if ($n > 0) {
                    $done['deleted'][$label] = ($done['deleted'][$label] ?? 0) + $n;
                }
            };
            if ($uid > 0) {
                $del('Notifications', 'DELETE FROM notifications WHERE user_id = ?', [$uid]);
                $del('Phone and browser notification registrations', 'DELETE FROM push_subscriptions WHERE user_id = ?', [$uid]);
                $del('Sign-in links', 'DELETE FROM auth_tokens WHERE user_id = ?', [$uid]);
                $del('Recovery codes', 'DELETE FROM user_recovery_codes WHERE user_id = ?', [$uid]);
            }
            $del('Sign-in attempt records', 'DELETE FROM login_attempts WHERE email = ?', [$email]);
            if ($cid > 0) {
                $del('Staff notes about you', 'DELETE FROM client_notes WHERE client_id = ?', [$cid]);
            }
            // enquiries that never became a project: delete them with their files
            foreach (Database::all('SELECT id FROM enquiries WHERE (email = ? OR client_id = ?) AND converted_project_id IS NULL', [$email, $cid]) as $e) {
                foreach (Database::all('SELECT f.id FROM enquiry_files ef JOIN stored_files f ON f.id = ef.file_id WHERE ef.enquiry_id = ?', [$e['id']]) as $f) {
                    $meta = FileStore::find((int) $f['id']);
                    Database::run('DELETE FROM enquiry_files WHERE file_id = ?', [$f['id']]);
                    if ($meta !== null) {
                        FileStore::discard($meta);
                        Database::run('DELETE FROM stored_files WHERE id = ?', [$f['id']]);
                        $done['deleted']['Files you uploaded with an enquiry'] = ($done['deleted']['Files you uploaded with an enquiry'] ?? 0) + 1;
                    }
                }
                try {
                    Database::connection()->exec('SAVEPOINT del_enquiry');
                    Database::run('DELETE FROM enquiries WHERE id = ?', [$e['id']]);
                    Database::connection()->exec('RELEASE SAVEPOINT del_enquiry');
                    $done['deleted']['Enquiries that did not become a project'] = ($done['deleted']['Enquiries that did not become a project'] ?? 0) + 1;
                } catch (\Throwable) {
                    Database::connection()->exec('ROLLBACK TO SAVEPOINT del_enquiry'); // still referenced by something that must stay: anonymise it instead
                    Database::run("UPDATE enquiries SET contact_name = 'Deleted', email = CONCAT('deleted-', id, '@invalid.invalid'), phone = NULL, phone_digits = NULL, company_name = NULL, company_norm = NULL, description = '[removed at the requester\\'s request]', submitted_ip_hash = NULL WHERE id = ?", [$e['id']]);
                    $done['anonymised']['Enquiries that could not be deleted'] = ($done['anonymised']['Enquiries that could not be deleted'] ?? 0) + 1;
                }
            }
            $n = Database::run('UPDATE enquiries SET phone = NULL, phone_digits = NULL, submitted_ip_hash = NULL WHERE (email = ? OR client_id = ?) AND converted_project_id IS NOT NULL', [$email, $cid])->rowCount();
            if ($n > 0) {
                $done['anonymised']['Enquiries that became a project (phone and tracking data removed)'] = $n;
            }
            $del('Job applications', 'DELETE FROM site_job_applications WHERE email = ?', [$email]);
            $n = Database::run("UPDATE partner_applications SET phone = NULL, email = CONCAT('deleted-', id, '@invalid.invalid') WHERE email = ?", [$email])->rowCount();
            if ($n > 0) {
                $done['anonymised']['Partner applications'] = $n;
            }
            if ($cid > 0) {
                if ($plan['financial']) { // the invoices need a customer: the identity on them stays, everything else goes
                    Database::run("UPDATE clients SET phone = NULL, phone_digits = NULL, website = NULL, status = 'archived', archived_at = COALESCE(archived_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$cid]);
                    $done['anonymised']['Client profile (phone and website removed; invoice identity kept)'] = 1;
                } else {
                    Database::run("UPDATE clients SET contact_name = 'Deleted client', company_name = NULL, company_norm = NULL, email = CONCAT('deleted-client-', id, '@invalid.invalid'), phone = NULL, phone_digits = NULL, website = NULL, tax_id = NULL, address = NULL, status = 'archived', archived_at = COALESCE(archived_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$cid]);
                    $done['anonymised']['Client profile (all personal details replaced)'] = 1;
                }
            }
            if ($uid > 0) {
                Database::run(
                    "UPDATE users SET name = 'Deleted user', email = CONCAT('deleted-', id, '@invalid.invalid'), phone = NULL, password_hash = ?, status = 'archived', email_verified_at = NULL, google_sub = NULL, totp_secret_enc = NULL, totp_enabled_at = NULL,
                         last_login_ip = NULL, session_version = session_version + 1, deactivated_at = COALESCE(deactivated_at, UTC_TIMESTAMP()), deactivation_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                    [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'Data deletion request ' . $req['reference'], $uid],
                );
                $done['anonymised']['Your sign-in account (name, email, phone, password, Google link, two-factor secret)'] = 1;
            }
            $done['completed_at'] = gmdate('Y-m-d H:i:s');
            Database::run("UPDATE deletion_requests SET status = 'completed', result_json = ?, completed_at = UTC_TIMESTAMP(), handled_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [json_encode($done, JSON_UNESCAPED_UNICODE), $actorId, $id]);
            self::event($id, 'completed', 'Your data was deleted or anonymised as far as the law allows. See the summary.', true, $actorId);
            Audit::record('privacy.deletion_completed', 'deletion_request', $id, [], ['deleted' => array_sum($done['deleted']), 'anonymised' => count($done['anonymised']), 'retained' => count($done['retained'])], [], $actorId);
        });
        PrivacyMail::completed((string) $req['requester_email'], (string) $req['requester_name'], (string) $req['reference'], $done);

        return $done;
    }
}
