<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Website bug-fixing warranty tracking (configurable; nothing about the business's contract is assumed).
 *
 * Dates:  the warranty START is an explicit recorded date with a recorded basis (final delivery / client acceptance / contractual
 *         start). It is never guessed from a project status. With no recorded start the status is "Pending Confirmation" and no
 *         dates are shown or invented.
 * End:    start + N calendar months, same calendar day (clamped to the last day of a shorter month, e.g. 31 Aug + 6 months = 28 Feb),
 *         and that END DATE IS COVERED (inclusive). With WARRANTY_END_DAY_BEFORE=true it is the day before. "Today" is the date in the
 *         application time zone (AppTime), so a warranty never ends a few hours early or late because of UTC.
 * Status: DERIVED on every read from the recorded facts and today's date (no cron): not_applicable, pending_confirmation, scheduled,
 *         active, expired, suspended. Being inside the period does NOT make an issue covered: eligibility is decided per issue.
 * Who:    contractual settings need `warranty.manage` (administrators) and every change is stored in warranty_history and audited.
 */
final class WarrantyService
{
    public const MAX_MONTHS = 60;

    /** The last day covered by a warranty that starts on $start (Y-m-d). */
    public static function endDate(string $start, int $months, ?bool $dayBefore = null): string
    {
        $dayBefore ??= (bool) Config::get('support.warranty_end_day_before', false);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if ($d === false || $d->format('Y-m-d') !== $start || $months < 1) {
            throw new \InvalidArgumentException('Invalid start date or duration.');
        }
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n') - 1 + $months;
        $y += intdiv($m, 12);
        $m = $m % 12 + 1;
        $day = min((int) $d->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
        $end = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day));

        return ($dayBefore ? $end->modify('-1 day') : $end)->format('Y-m-d');
    }

    /**
     * @param array<string,mixed>|null $w project_warranties row (null = none recorded)
     * @param string|null $service the project's service category (used only when no warranty has been recorded)
     * @return array{status:string,label:string}
     */
    public static function statusFor(?array $w, ?string $service = null, ?string $today = null): array
    {
        $today ??= AppTime::today();
        if ($w === null) {
            $s = $service === 'website_development' ? 'pending_confirmation' : 'not_applicable';
        } elseif ($w['eligibility'] === 'not_applicable') {
            $s = 'not_applicable';
        } elseif ((int) $w['suspended'] === 1) {
            $s = 'suspended';
        } elseif ($w['start_date'] === null || $w['end_date'] === null) {
            $s = 'pending_confirmation';
        } elseif ($today < $w['start_date']) {
            $s = 'scheduled';
        } elseif ($today <= $w['end_date']) {
            $s = 'active';
        } else {
            $s = 'expired';
        }

        return ['status' => $s, 'label' => (string) Config::get("workflow.warranty_statuses.{$s}", $s)];
    }

    /**
     * Everything known about a project's warranty, with the derived status and honest hints (never applied automatically).
     *
     * @param array<string,mixed> $project needs id, service_category
     * @return array<string,mixed>
     */
    public static function forProject(array $project): array
    {
        $w = Database::one(
            'SELECT w.*, t.version AS terms_version, t.title AS terms_title, t.terms AS terms_text FROM project_warranties w LEFT JOIN warranty_terms t ON t.id = w.terms_id WHERE w.project_id = ?',
            [$project['id']],
        );
        $st = self::statusFor($w, $project['service_category'] ?? null);

        return ['row' => $w, 'recorded' => $w !== null] + $st + [
            'default_months' => (int) Config::get('support.warranty_default_months', 6),
            'days_left' => $st['status'] === 'active' ? (int) (new \DateTimeImmutable(AppTime::today()))->diff(new \DateTimeImmutable((string) $w['end_date']))->days : null,
        ];
    }

    /** Facts a human may use to choose a start date. Shown as suggestions only; never written by the system. @param array<string,mixed> $project @return array<string,?string> */
    public static function startHints(array $project): array
    {
        $accept = Database::one("SELECT MAX(created_at) AS d FROM client_approvals WHERE project_id = ? AND action = 'approved'", [$project['id']]);
        $published = Database::one('SELECT MAX(published_at) AS d FROM deliverable_versions v JOIN deliverables d ON d.id = v.deliverable_id WHERE d.project_id = ? AND v.published_at IS NOT NULL', [$project['id']]);
        $to = static fn (?string $utc): ?string => $utc === null ? null : (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(AppTime::zone())->format('Y-m-d');

        return [
            'project_completed' => $project['status'] === 'completed' && ($project['completed_at'] ?? null) !== null ? $to((string) $project['completed_at']) : null,
            'last_client_approval' => $to($accept['d'] ?? null),
            'last_deliverable_published' => $to($published['d'] ?? null),
        ];
    }

    /**
     * Records or changes the contractual warranty settings of a project. Needs `warranty.manage`.
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $in eligibility, months, start_basis, start_date, quotation_ref, terms (version public int or ''), project_terms, admin_notes, reason
     */
    public static function record(int $actorId, array $project, array $in): void
    {
        Gate::authorize('warranty.manage', $actorId);
        if (ProjectPolicy::level($actorId, $project) === null) {
            throw new HttpException(404);
        }
        $cur = Database::one('SELECT * FROM project_warranties WHERE project_id = ?', [$project['id']]);

        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $eligibility = $try(static fn () => (string) FieldRules::choice($in['eligibility'] ?? '', ['eligible' => 'Eligible', 'not_applicable' => 'Not applicable'], 'eligibility'));
        $reason = $try(static fn () => (string) FieldRules::line($in['reason'] ?? '', 'Reason for this change', 5, 255, $cur !== null));
        $months = (int) Config::get('support.warranty_default_months', 6);
        $basis = null;
        $start = null;
        $end = null;
        $quotation = null;
        $termsId = null;
        $projectTerms = null;
        $adminNotes = null;
        if ($eligibility === 'eligible') {
            $m = $try(static fn () => FieldRules::minutes($in['months'] ?? '', 'Warranty duration (months)', false, self::MAX_MONTHS));
            if ($m !== null) {
                if ($m < 1) {
                    $errors[] = 'The warranty duration must be at least 1 month.';
                } else {
                    $months = $m;
                }
            }
            $start = $try(static fn () => FieldRules::date($in['start_date'] ?? '', 'Warranty start date'));
            $basis = $try(static fn () => FieldRules::choice($in['start_basis'] ?? '', (array) Config::get('workflow.warranty_start_bases'), 'start-date basis', $start !== null));
            if ($start === null && $basis !== null) {
                $errors[] = 'Enter the start date for that basis, or leave both empty while the date is not confirmed.';
            }
            $quotation = $try(static fn () => FieldRules::line($in['quotation_ref'] ?? '', 'Related quotation or agreement', 2, 120, false));
            $projectTerms = $try(static fn () => FieldRules::text($in['project_terms'] ?? '', 'Project-specific terms', 3, 5000, false));
            $tv = FieldRules::clean($in['terms_version'] ?? '');
            if ($tv !== '') {
                $t = ctype_digit($tv) ? Database::one('SELECT id FROM warranty_terms WHERE version = ?', [(int) $tv]) : null;
                if ($t === null) {
                    $errors[] = 'Choose a recorded terms version.';
                } else {
                    $termsId = (int) $t['id'];
                }
            }
            $end = $start !== null && $errors === [] ? self::endDate($start, $months) : null;
        }
        $adminNotes = $try(static fn () => FieldRules::text($in['admin_notes'] ?? '', 'Admin notes', 2, 3000, false));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $after = ['eligibility' => $eligibility, 'duration_months' => $months, 'start_basis' => $basis, 'start_date' => $start, 'end_date' => $end, 'quotation_ref' => $quotation,
            'terms_id' => $termsId, 'project_terms' => $projectTerms === null ? null : '(recorded)', 'admin_notes' => $adminNotes === null ? null : '(recorded)'];
        $before = $cur === null ? [] : ['eligibility' => $cur['eligibility'], 'duration_months' => (int) $cur['duration_months'], 'start_basis' => $cur['start_basis'], 'start_date' => $cur['start_date'], 'end_date' => $cur['end_date'],
            'quotation_ref' => $cur['quotation_ref'], 'terms_id' => $cur['terms_id'] === null ? null : (int) $cur['terms_id'], 'project_terms' => $cur['project_terms'] === null ? null : '(recorded)', 'admin_notes' => $cur['admin_notes'] === null ? null : '(recorded)'];
        $textChanged = $cur !== null && ((string) $cur['project_terms'] !== (string) $projectTerms || (string) $cur['admin_notes'] !== (string) $adminNotes);
        if ($cur !== null && $before === $after && !$textChanged) {
            return;
        }

        Database::transaction(function () use ($actorId, $project, $cur, $eligibility, $months, $basis, $start, $end, $quotation, $termsId, $projectTerms, $adminNotes, $before, $after, $reason): void {
            if ($cur === null) {
                Database::run(
                    'INSERT INTO project_warranties (project_id, eligibility, duration_months, start_basis, start_date, end_date, quotation_ref, terms_id, project_terms, admin_notes, created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [$project['id'], $eligibility, $months, $basis, $start, $end, $quotation, $termsId, $projectTerms, $adminNotes, $actorId, $actorId],
                );
            } else {
                Database::run(
                    'UPDATE project_warranties SET eligibility = ?, duration_months = ?, start_basis = ?, start_date = ?, end_date = ?, quotation_ref = ?, terms_id = ?, project_terms = ?, admin_notes = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [$eligibility, $months, $basis, $start, $end, $quotation, $termsId, $projectTerms, $adminNotes, $actorId, $cur['id']],
                );
            }
            Database::run('INSERT INTO warranty_history (project_id, actor_id, event, before_json, after_json, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$project['id'], $actorId, $cur === null ? 'recorded' : 'changed', json_encode($before), json_encode($after), $reason === '' ? null : $reason]);
            Audit::record($cur === null ? 'warranty.configured' : 'warranty.changed', 'project', (int) $project['id'], $before, $after, ['reason_recorded' => $reason !== ''], $actorId);
            if ($cur !== null && (($cur['start_date'] ?? null) !== $start || ($cur['end_date'] ?? null) !== $end)) {
                Audit::record('warranty.dates_changed', 'project', (int) $project['id'], ['start_date' => $cur['start_date'], 'end_date' => $cur['end_date']], ['start_date' => $start, 'end_date' => $end], [], $actorId);
            }
            ProjectActivity::log((int) $project['id'], $actorId, 'warranty.recorded', 'Warranty information ' . ($cur === null ? 'recorded' : 'updated'), 'client', 'project', (int) $project['id']);
        });
    }

    /** Suspends or resumes a recorded warranty (only where the contract allows). @param array<string,mixed> $project */
    public static function setSuspended(int $actorId, array $project, bool $suspend, string $reason): void
    {
        Gate::authorize('warranty.manage', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        $cur = Database::one('SELECT * FROM project_warranties WHERE project_id = ?', [$project['id']]) ?? throw new ValidationException(['Record the warranty first.']);
        if (((int) $cur['suspended'] === 1) === $suspend) {
            return;
        }
        Database::transaction(function () use ($actorId, $project, $cur, $suspend, $reason): void {
            Database::run('UPDATE project_warranties SET suspended = ?, suspended_reason = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$suspend ? 1 : 0, $suspend ? $reason : null, $actorId, $cur['id']]);
            Database::run('INSERT INTO warranty_history (project_id, actor_id, event, before_json, after_json, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$project['id'], $actorId, $suspend ? 'suspended' : 'resumed', json_encode(['suspended' => (int) $cur['suspended']]), json_encode(['suspended' => $suspend ? 1 : 0]), $reason]);
            Audit::record($suspend ? 'warranty.suspended' : 'warranty.resumed', 'project', (int) $project['id'], ['suspended' => (int) $cur['suspended']], ['suspended' => $suspend ? 1 : 0], [], $actorId);
        });
    }

    /** Records a new version of the warranty terms wording. @param array<string,mixed> $in title, terms, default_months, source_reference */
    public static function recordTerms(int $actorId, array $in): int
    {
        Gate::authorize('warranty.manage', $actorId);
        $title = (string) FieldRules::line($in['title'] ?? '', 'Title', 3, 160);
        $terms = (string) FieldRules::text($in['terms'] ?? '', 'Terms', 10, 10000);
        $months = FieldRules::minutes($in['default_months'] ?? '', 'Default months', false, self::MAX_MONTHS);
        $ref = FieldRules::line($in['source_reference'] ?? '', 'Source reference', 2, 120, false);

        return Database::transaction(function () use ($actorId, $title, $terms, $months, $ref): int {
            Database::run('UPDATE warranty_terms SET current_flag = NULL WHERE current_flag = 1');
            $v = (int) Database::one('SELECT COALESCE(MAX(version), 0) AS v FROM warranty_terms')['v'] + 1;
            Database::run('INSERT INTO warranty_terms (version, title, terms, default_months, source_reference, current_flag, created_by, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP())', [$v, $title, $terms, $months, $ref, $actorId]);
            Audit::record('warranty.terms_recorded', 'warranty_terms', Database::insertId(), [], ['version' => $v, 'title' => $title], [], $actorId);

            return $v;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function termsVersions(): array
    {
        return Database::all('SELECT id, version, title, default_months, source_reference, current_flag, created_at FROM warranty_terms ORDER BY version DESC');
    }

    /** @param array<string,mixed> $project @return list<array<string,mixed>> */
    public static function history(array $project): array
    {
        return Database::all('SELECT h.event, h.before_json, h.after_json, h.reason, h.created_at, u.name AS actor_name FROM warranty_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.project_id = ? ORDER BY h.id DESC', [$project['id']]);
    }

    /** SQL expression for the derived status of `projects p LEFT JOIN project_warranties w`. */
    public static function statusSql(): string
    {
        return "CASE WHEN w.id IS NULL THEN IF(p.service_category = 'website_development', 'pending_confirmation', 'not_applicable')
                     WHEN w.eligibility = 'not_applicable' THEN 'not_applicable' WHEN w.suspended = 1 THEN 'suspended'
                     WHEN w.start_date IS NULL OR w.end_date IS NULL THEN 'pending_confirmation'
                     WHEN :t1 < w.start_date THEN 'scheduled' WHEN :t2 <= w.end_date THEN 'active' ELSE 'expired' END";
    }

    /**
     * Warranty report over the projects the user may see (needs `warranty.view`).
     *
     * @param array{status?:string,q?:string,expiring?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function report(int $userId, array $f, int $page = 1, int $perPage = 25): array
    {
        $scope = Gate::can('warranty.view', $userId) ? ProjectPolicy::scopeSql($userId) : null;
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        $today = AppTime::today();
        $horizon = AppTime::todayPlus((int) Config::get('support.warranty_expiring_days', 30));
        $status = self::statusSql();
        $named = ['t1' => $today, 't2' => $today];
        // ProjectPolicy::scopeSql uses positional ? placeholders: run it as an inner restriction on ids so both styles never mix.
        $ids = Database::all("SELECT p.id FROM projects p JOIN clients c ON c.id = p.client_id WHERE {$scope[0]} AND p.status <> 'cancelled'", $scope[1]);
        if ($ids === []) {
            return ['rows' => [], 'total' => 0];
        }
        $idList = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $ids));
        $where = ["p.id IN ({$idList})"];
        $params = $named;
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.title LIKE :q OR p.code LIKE :q2)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
        }
        $having = [];
        if (($f['status'] ?? '') !== '') {
            $having[] = 'wstatus = :ws';
            $params['ws'] = $f['status'];
        }
        if (!empty($f['expiring'])) {
            $having[] = "wstatus = 'active' AND w.end_date <= :hz";
            $params['hz'] = $horizon;
        }
        $base = 'FROM projects p JOIN clients c ON c.id = p.client_id LEFT JOIN project_warranties w ON w.project_id = p.id WHERE ' . implode(' AND ', $where);
        $sub = "SELECT p.id, p.public_id, p.code, p.title, p.service_category, c.contact_name, c.company_name, w.duration_months, w.start_basis, w.start_date, w.end_date, w.quotation_ref, {$status} AS wstatus {$base}";
        $outer = 'SELECT * FROM (' . $sub . ') x' . ($having === [] ? '' : ' WHERE ' . str_replace('w.end_date', 'x.end_date', implode(' AND ', $having)));
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM ({$outer}) y", $params)['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all($outer . " ORDER BY FIELD(wstatus,'active','scheduled','pending_confirmation','suspended','expired','not_applicable'), end_date, id LIMIT {$perPage} OFFSET {$offset}", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Warranty information for each of the client's own projects. Internal fields (admin notes) are never included. @return list<array<string,mixed>> */
    public static function forClient(int $userId): array
    {
        if (!Gate::can('warranty.view_own', $userId)) {
            return [];
        }
        $rows = Database::all(
            "SELECT p.id, p.public_id, p.code, p.title, p.service_category FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.archived_at IS NULL AND p.status <> 'cancelled' ORDER BY p.created_at DESC",
            [$userId],
        );
        $out = [];
        foreach ($rows as $p) {
            $w = self::forProject($p);
            $row = $w['row'];
            $out[] = ['project' => $p, 'status' => $w['status'], 'label' => $w['label'], 'start_date' => $row['start_date'] ?? null, 'end_date' => $row['end_date'] ?? null,
                'duration_months' => $row['duration_months'] ?? null, 'basis' => $row['start_basis'] ?? null, 'terms_title' => $row['terms_title'] ?? null,
                'terms_text' => $row['terms_text'] ?? null, 'project_terms' => $row['project_terms'] ?? null, 'days_left' => $w['days_left'], 'recorded' => $w['recorded']];
        }

        return $out;
    }
}
