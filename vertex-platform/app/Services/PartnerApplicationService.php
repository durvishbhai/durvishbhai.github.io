<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;

/**
 * Referral Partner applications.
 *  - Anyone can apply (public form: CSRF, honeypot, rate limit, explicit terms agreement). A submission NEVER creates or activates an account.
 *  - Only `referrals.decide` (Administrator) approves or rejects; a rejection needs a reason. An approval creates the partner record and then either
 *      (a) gives the referral_partner role to the account the applicant was SIGNED IN with when applying, or
 *      (b) creates a pending account that the applicant activates with the normal single-use invitation link.
 *    An existing account is never linked to an application just because the e-mail address matches: its owner must apply while signed in.
 *  - One open application per e-mail address (UNIQUE active_key); a second submission is accepted silently as a no-op so the form cannot be used to
 *    discover who has applied.
 *  - Terms are recorded by the business (versioned). Until a version exists, applications are closed: no legal text is invented here.
 */
final class PartnerApplicationService
{
    /** @return array<string,mixed>|null */
    public static function currentTerms(): ?array
    {
        return Database::one('SELECT id, version, title, terms, created_at FROM partner_terms WHERE current_flag = 1');
    }

    /** @param array<string,mixed> $in title, terms @return int version */
    public static function recordTerms(int $actorId, array $in): int
    {
        Gate::authorize('referrals.decide', $actorId);
        $title = (string) FieldRules::line($in['title'] ?? '', 'Title', 3, 160);
        $terms = (string) FieldRules::text($in['terms'] ?? '', 'Terms', 20, 20000);

        return Database::transaction(function () use ($actorId, $title, $terms): int {
            Database::run('UPDATE partner_terms SET current_flag = NULL WHERE current_flag = 1');
            $v = (int) Database::one('SELECT COALESCE(MAX(version), 0) AS v FROM partner_terms')['v'] + 1;
            Database::run('INSERT INTO partner_terms (version, title, terms, current_flag, created_by, created_at) VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())', [$v, $title, $terms, $actorId]);
            Audit::record('partner.terms_recorded', 'partner_terms', Database::insertId(), [], ['version' => $v, 'title' => $title], [], $actorId);

            return $v;
        });
    }

    /**
     * @param array<string,mixed> $in full_name, email, phone, organization, professional_info, referral_source, agree
     * @param array<string,mixed>|null $file one $_FILES entry (optional photo or supporting document)
     * @return array{reference:?string,duplicate:bool}
     */
    public static function submit(?int $signedInUserId, array $in, ?array $file, string $ip, bool $requireHttpUpload = true): array
    {
        $terms = self::currentTerms();
        if ($terms === null) {
            throw new ValidationException(['Referral Partner applications are not open yet.']);
        }
        $account = null;
        if ($signedInUserId !== null) {
            $account = (new UserRepository())->findById($signedInUserId);
            if ($account === null || $account['status'] !== 'active') {
                $account = null;
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
        $name = $try(static fn () => (string) FieldRules::line($in['full_name'] ?? ($account['name'] ?? ''), 'Full name', 2, 120));
        $email = $account !== null ? (string) $account['email'] : $try(static fn () => (string) FieldRules::email($in['email'] ?? ''));
        $phone = $try(static fn () => (string) FieldRules::phone($in['phone'] ?? '', true));
        $org = $try(static fn () => FieldRules::line($in['organization'] ?? '', 'Business or organization', 2, 160, false));
        $info = $try(static fn () => (string) FieldRules::text($in['professional_info'] ?? '', 'Professional or promotional information', 20, 3000));
        $source = $try(static fn () => (string) FieldRules::text($in['referral_source'] ?? '', 'How you were introduced to us', 5, 1000));
        if (($in['agree'] ?? '') !== '1') {
            $errors[] = 'You must agree to the Referral Partner Terms.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $email = mb_strtolower((string) $email);

        // Already a partner, or an open application: accept silently. Nothing here tells the visitor which one it was.
        $already = Database::one(
            'SELECT 1 AS x FROM partner_applications WHERE active_key = ? UNION SELECT 1 FROM referral_partners p JOIN users u ON u.id = p.user_id WHERE u.email = ?',
            [$email, $email],
        );
        if ($already !== null) {
            \Vertex\Core\Logger::security('Partner application ignored: duplicate', ['ip_hash' => substr(hash('sha256', $ip), 0, 12)]);

            return ['reference' => null, 'duplicate' => true];
        }
        if ($account === null && (new UserRepository())->findByEmail($email) !== null) {
            // An account exists for this address but the visitor is not signed in as its owner: record the application WITHOUT linking it to the account.
            $account = null;
        }
        $meta = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? FileStore::accept($file, 'partner', $requireHttpUpload) : null;
        try {
            return Database::transaction(function () use ($account, $name, $email, $phone, $org, $info, $source, $terms, $meta, $ip): array {
                $fileId = $meta === null ? null : FileStore::insert($meta, $account['id'] ?? null);
                $ref = CrmReference::next('partner_app');
                $ipHash = hash_hmac('sha256', $ip, (string) Config::get('app.key', 'unset'));
                try {
                    Database::run(
                        "INSERT INTO partner_applications (public_id, reference, full_name, email, phone, organization, professional_info, referral_source, doc_file_id, terms_version, terms_accepted_at,
                                user_id, status, active_key, submitted_ip_hash, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'received', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                        [uuid_hex(), $ref, $name, $email, $phone, $org, $info, $source, $fileId, $terms['version'], $account['id'] ?? null, $email, $ipHash],
                    );
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) === 1062) {
                        throw new \RuntimeException('duplicate', 0, $e);
                    }
                    throw $e;
                }
                $id = Database::insertId();
                Audit::record('partner.application_received', 'partner_application', $id, [], ['reference' => $ref, 'terms_version' => (int) $terms['version'], 'linked_account' => $account !== null], [], $account['id'] ?? null);
                Notifier::send(Notifier::withPermission('referrals.review'), 'partner.application_received', 'New Referral Partner application', $ref, '/referrals/applications/' . Database::one('SELECT public_id FROM partner_applications WHERE id = ?', [$id])['public_id'], null, 'partner_application', $id, "partner_app:{$id}:received");

                return ['reference' => $ref, 'duplicate' => false];
            });
        } catch (\RuntimeException $e) {
            if ($meta !== null) {
                FileStore::discard($meta);
            }
            if ($e->getMessage() === 'duplicate') {
                return ['reference' => null, 'duplicate' => true];
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($meta !== null) {
                FileStore::discard($meta);
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------------------------------------ staff

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT a.*, f.public_id AS file_public_id, f.original_name AS file_name, du.name AS decided_by_name, rp.public_id AS partner_public_id
               FROM partner_applications a LEFT JOIN stored_files f ON f.id = a.doc_file_id LEFT JOIN users du ON du.id = a.decided_by LEFT JOIN referral_partners rp ON rp.id = a.partner_id
              WHERE a.public_id = ?',
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> 404 for anyone without `referrals.review` */
    public static function requireView(int $userId, string $publicId): array
    {
        $a = Gate::can('referrals.review', $userId) ? self::find($publicId) : null;
        if ($a === null) {
            throw new HttpException(404);
        }

        return $a;
    }

    /**
     * @param array{q?:string,status?:string,from?:string,to?:string} $f
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
            $w[] = '(a.reference LIKE ? OR a.full_name LIKE ? OR a.email LIKE ? OR a.organization LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['received', 'under_review', 'approved', 'rejected'], true)) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        }
        [$w, $p] = self::dateRange($w, $p, 'a.created_at', $f);
        $cond = implode(' AND ', $w);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM partner_applications a WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT a.public_id, a.reference, a.full_name, a.email, a.organization, a.status, a.created_at, a.decided_at FROM partner_applications a WHERE {$cond}
                               ORDER BY FIELD(a.status, 'received', 'under_review', 'approved', 'rejected'), a.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param list<string> $w
     * @param list<mixed> $p
     * @param array<string,mixed> $f
     * @return array{0:list<string>,1:list<mixed>}
     */
    public static function dateRange(array $w, array $p, string $column, array $f): array
    {
        $day = static fn (string $d): string => (new \DateTimeImmutable($d . ' 00:00:00', AppTime::zone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) && checkdate((int) substr((string) $f['from'], 5, 2), (int) substr((string) $f['from'], 8, 2), (int) substr((string) $f['from'], 0, 4))) {
            $w[] = "{$column} >= ?";
            $p[] = $day((string) $f['from']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) && checkdate((int) substr((string) $f['to'], 5, 2), (int) substr((string) $f['to'], 8, 2), (int) substr((string) $f['to'], 0, 4))) {
            $w[] = "{$column} < ?";
            $p[] = $day(date('Y-m-d', strtotime($f['to'] . ' +1 day')));
        }

        return [$w, $p];
    }

    /** @param array<string,mixed> $a */
    public static function markUnderReview(int $actorId, array $a): void
    {
        Gate::authorize('referrals.decide', $actorId);
        Database::transaction(function () use ($actorId, $a): void {
            $row = Database::one('SELECT status FROM partner_applications WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($row === null || $row['status'] !== 'received') {
                throw new ValidationException(['Only a newly received application can be moved to Under Review.']);
            }
            Database::run("UPDATE partner_applications SET status = 'under_review', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$a['id']]);
            Audit::record('partner.application_under_review', 'partner_application', (int) $a['id'], ['status' => 'received'], ['status' => 'under_review'], [], $actorId);
        });
    }

    /**
     * @param array<string,mixed> $a
     * @return array{partner_id:int,invite_sent:?bool,linked_existing:bool}
     */
    public static function approve(int $actorId, array $a, string $note = ''): array
    {
        Gate::authorize('referrals.decide', $actorId);
        $note = (string) FieldRules::line($note, 'Note', 2, 500, false);
        $repo = new UserRepository();

        return Database::transaction(function () use ($actorId, $a, $note, $repo): array {
            $row = Database::one('SELECT * FROM partner_applications WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($row === null || !in_array($row['status'], ['received', 'under_review'], true)) {
                throw new ValidationException(['This application has already been decided.']);
            }
            $invite = null;
            $linked = false;
            if ($row['user_id'] !== null) {
                $userId = (int) $row['user_id'];
                $target = $repo->findById($userId);
                if ($target === null || $target['status'] !== 'active') {
                    throw new ValidationException(['The applicant\'s account is not active.']);
                }
                if (Gate::level($actorId) <= Gate::level($userId) || $userId === $actorId) {
                    throw new HttpException(403);
                }
                $roles = array_column($repo->rolesOf($userId), 'slug');
                if (!in_array('referral_partner', $roles, true)) {
                    $repo->addRoles($userId, ['referral_partner'], $actorId);
                    Audit::record('user.roles_changed', 'user', $userId, ['roles' => $roles], ['roles' => [...$roles, 'referral_partner']], ['via' => 'partner_application'], $actorId);
                }
                $linked = true;
            } else {
                if ($repo->findByEmail((string) $row['email']) !== null) {
                    throw new ValidationException(['An account with this e-mail address already exists. Ask the applicant to sign in and apply from their own account, or reject this application.']);
                }
                $created = UserService::adminCreate($actorId, ['name' => $row['full_name'], 'email' => $row['email'], 'phone' => $row['phone'], 'roles' => ['referral_partner']]);
                $userId = $created['user_id'];
                $invite = $created['invite_sent'];
            }
            Database::run('UPDATE users SET phone = COALESCE(NULLIF(phone, \'\'), ?) WHERE id = ?', [$row['phone'], $userId]);
            $ref = CrmReference::next('partner');
            Database::run(
                "INSERT INTO referral_partners (public_id, reference, user_id, application_id, status, terms_version, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [uuid_hex(), $ref, $userId, $row['id'], $row['terms_version'], $actorId],
            );
            $partnerId = Database::insertId();
            Database::run("UPDATE partner_applications SET status = 'approved', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ?, partner_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$actorId, $note === '' ? null : $note, $partnerId, $row['id']]);
            if ($note !== '') {
                Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('application', ?, ?, ?, UTC_TIMESTAMP())", [$row['id'], $actorId, $note]);
            }
            Audit::record('partner.application_approved', 'partner_application', (int) $row['id'], ['status' => $row['status']], ['status' => 'approved', 'partner_reference' => $ref],
                ['linked_existing_account' => $linked, 'partner_id' => $partnerId], $actorId);
            Notifier::send([$userId], 'partner.application_approved', 'Your Referral Partner application was approved', "{$row['reference']}: you can now sign in and use the Partner portal.", '/partner', null, 'partner_application', (int) $row['id'], "partner_app:{$row['id']}:approved");

            return ['partner_id' => $partnerId, 'invite_sent' => $invite, 'linked_existing' => $linked];
        });
    }

    /** @param array<string,mixed> $a */
    public static function reject(int $actorId, array $a, string $reason): void
    {
        Gate::authorize('referrals.decide', $actorId);
        $reason = (string) FieldRules::text($reason, 'Rejection reason', 10, 500);
        Database::transaction(function () use ($actorId, $a, $reason): void {
            $row = Database::one('SELECT * FROM partner_applications WHERE id = ? FOR UPDATE', [$a['id']]);
            if ($row === null || !in_array($row['status'], ['received', 'under_review'], true)) {
                throw new ValidationException(['This application has already been decided.']);
            }
            Database::run("UPDATE partner_applications SET status = 'rejected', active_key = NULL, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $reason, $row['id']]);
            Audit::record('partner.application_rejected', 'partner_application', (int) $row['id'], ['status' => $row['status']], ['status' => 'rejected'], ['reason_recorded' => true], $actorId);
            if ($row['user_id'] !== null) {
                Notifier::send([(int) $row['user_id']], 'partner.application_rejected', 'Your Referral Partner application was not approved', $row['reference'] . ': ' . mb_substr($reason, 0, 180), '/partner', null, 'partner_application', (int) $row['id'], "partner_app:{$row['id']}:rejected");
            }
        });
    }

    /** @param array<string,mixed> $a */
    public static function addNote(int $actorId, array $a, string $body): void
    {
        Gate::authorize('referrals.review', $actorId);
        $body = (string) FieldRules::text($body, 'Note', 2, 3000);
        Database::transaction(function () use ($actorId, $a, $body): void {
            Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('application', ?, ?, ?, UTC_TIMESTAMP())", [$a['id'], $actorId, $body]);
            Audit::record('partner.application_note', 'partner_application', (int) $a['id'], [], ['length' => mb_strlen($body)], [], $actorId);
        });
    }

    /** @param array<string,mixed> $a @return list<array<string,mixed>> */
    public static function notes(int $userId, array $a): array
    {
        return Gate::can('referrals.review', $userId) ? Database::all("SELECT n.body, n.created_at, u.name AS author FROM partner_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.subject_type = 'application' AND n.subject_id = ? ORDER BY n.id", [$a['id']]) : [];
    }

    /** The signed-in applicant's own application(s), newest first (never anyone else's). @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::all('SELECT reference, status, decision_reason, created_at, decided_at FROM partner_applications WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$userId]);
    }
}
