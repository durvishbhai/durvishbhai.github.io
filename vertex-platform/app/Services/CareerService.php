<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Job and freelance applications from the public Careers page. A visitor can submit (validated, consent recorded, optional résumé in the private store);
 * only people with `careers.view` can read them and `careers.manage` changes their status. Nothing here promises selection, payment or employment.
 */
final class CareerService
{
    public const STATUSES = ['new' => 'New', 'reviewing' => 'Under review', 'shortlisted' => 'Shortlisted', 'not_selected' => 'Not selected', 'withdrawn' => 'Withdrawn', 'archived' => 'Archived'];

    public const EXPERIENCE = ['fresher' => 'Fresher or student', 'lt1' => 'Less than 1 year', '1to3' => '1 to 3 years', '3to5' => '3 to 5 years', '5plus' => 'More than 5 years'];
    public const AVAILABILITY = ['immediate' => 'Immediately', '2weeks' => 'Within 2 weeks', '1month' => 'Within a month', 'flexible' => 'Flexible / to be discussed'];

    /** @return array<string,string> the roles a visitor may apply for: published openings, the listed categories and "Other" */
    public static function roleChoices(): array
    {
        $out = [];
        if (SiteHiring::isOpen()) {
            foreach (SiteHiring::roleTitles() as $t) {
                $out[$t] = $t;
            }
        }
        foreach (SiteContent::published('jobs') as $j) {
            $out[(string) $j['title']] = (string) $j['title'];
        }
        foreach (SiteText::items(SiteSections::get('careers', 'categories')['items'] ?? '') as $c) {
            $out[$c] = $c;
        }
        $out['Other'] = 'Other';

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $file one $_FILES entry
     * @return array{public_id:string,reference:string}
     */
    public static function apply(array $in, ?array $file, bool $consent, string $ip, bool $requireHttpUpload = true): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $name = $try(fn () => FieldRules::line($in['full_name'] ?? '', 'Full name', 2, 120));
        $email = $try(fn () => FieldRules::email($in['email'] ?? ''));
        $phone = $try(fn () => FieldRules::phone($in['phone'] ?? '', false));
        $role = $try(fn () => FieldRules::choice($in['role'] ?? '', self::roleChoices(), 'role', true));
        $skills = $try(fn () => FieldRules::text($in['skills_experience'] ?? '', 'Skills and experience', 20, 4000));
        $intro = $try(fn () => FieldRules::text($in['intro'] ?? '', 'Short introduction', 20, 2000));
        $exp = (string) ($in['experience'] ?? '');
        $avail = (string) ($in['availability'] ?? '');
        if (($exp !== '' && !isset(self::EXPERIENCE[$exp])) || ($avail !== '' && !isset(self::AVAILABILITY[$avail]))) {
            $errors[] = 'Please choose from the listed experience and availability options.';
        }
        if (is_string($skills) && ($exp !== '' || $avail !== '') && !in_array('Please choose from the listed experience and availability options.', $errors, true)) {
            $skills = trim(($exp !== '' ? 'Experience: ' . self::EXPERIENCE[$exp] . ".\n" : '') . ($avail !== '' ? 'Available: ' . self::AVAILABILITY[$avail] . ".\n" : '') . "\n" . $skills);
        }
        $url = trim((string) ($in['portfolio_url'] ?? ''));
        if ($url !== '' && (!preg_match('#^https://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/\S*)?$#', $url) || mb_strlen($url) > 300 || preg_match('/[<>"\']/', $url))) {
            $errors[] = 'The portfolio address must start with https://';
        }
        if (!$consent) {
            $errors[] = 'Please confirm that you agree to the privacy notice.';
        }
        $hasFile = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $meta = null;
        if ($hasFile) {
            $meta = FileStore::accept((array) $file, 'career', $requireHttpUpload);
        }
        try {
            return Database::transaction(function () use ($name, $email, $phone, $role, $skills, $intro, $url, $meta, $ip): array {
                $fileId = $meta === null ? null : FileStore::insert($meta, null);
                $job = Database::one("SELECT id FROM site_jobs WHERE title = ? AND status = 'published'", [$role]);
                $ref = CrmReference::next('career');
                $pub = uuid_hex();
                Database::run('INSERT INTO site_job_applications (public_id, reference, job_id, role_applied, full_name, email, phone, skills_experience, portfolio_url, intro, file_id, consent_at, ip_hash, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [$pub, $ref, $job['id'] ?? null, $role, $name, $email, $phone === '' ? null : $phone, $skills, $url === '' ? null : $url, $intro, $fileId, hash_hmac('sha256', $ip, (string) Config::get('app.key', 'unset'))]);
                $id = Database::insertId();
                Audit::record('career.application_received', 'career_application', $id, [], ['reference' => $ref, 'role' => (string) $role], ['has_file' => $meta !== null], null);
                Notifier::send(Notifier::withPermission('careers.manage'), 'career.application', 'New job application', $ref . ': ' . $name . ' for ' . $role, '/admin/careers/' . $pub, null, 'career_application', $id, "career:{$id}");

                return ['public_id' => $pub, 'reference' => $ref];
            });
        } catch (\Throwable $e) {
            if ($meta !== null) {
                FileStore::discard($meta);
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------------------------------------ staff side

    /** @param array<string,string> $f status, q @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 25): array
    {
        self::authorizeView($userId);
        $w = [];
        $p = [];
        if (isset(self::STATUSES[$f['status'] ?? ''])) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        } else {
            $w[] = "a.status <> 'archived'";
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 60)) . '%';
            $w[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.reference LIKE ? OR a.role_applied LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        $where = implode(' AND ', $w);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM site_job_applications a WHERE {$where}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT a.public_id, a.reference, a.role_applied, a.full_name, a.email, a.status, a.created_at, (a.file_id IS NOT NULL) AS has_file FROM site_job_applications a WHERE {$where} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    private static function authorizeView(int $userId): void
    {
        if (!Gate::can('careers.view', $userId) && !Gate::can('careers.manage', $userId)) {
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        self::authorizeView($userId);

        return (preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM site_job_applications WHERE public_id = ?', [$publicId]) : null) ?? throw new HttpException(404);
    }

    public static function setStatus(int $actorId, array $app, string $status, string $notes): void
    {
        Gate::authorize('careers.manage', $actorId);
        if (!isset(self::STATUSES[$status])) {
            throw new ValidationException(['Choose a status.']);
        }
        $notes = (string) FieldRules::text($notes, 'Internal notes', 2, 3000, false);
        Database::transaction(function () use ($actorId, $app, $status, $notes): void {
            Database::run('UPDATE site_job_applications SET status = ?, internal_notes = ?, reviewed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $notes === '' ? null : $notes, $actorId, $app['id']]);
            Audit::record('career.application_status', 'career_application', (int) $app['id'], ['status' => $app['status']], ['status' => $status], [], $actorId);
        });
    }

    /** The stored résumé / work sample, only for someone who may read applications. @return array<string,mixed> */
    public static function fileFor(int $userId, array $app): array
    {
        self::authorizeView($userId);

        return ($app['file_id'] === null ? null : FileStore::find((int) $app['file_id'])) ?? throw new HttpException(404);
    }
}
