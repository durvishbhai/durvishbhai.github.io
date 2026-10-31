<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Project delivery appointments.
 *
 * A project has its own preparation period (set by the team). After it, the client may book ONE delivery date. Each date has a capacity (default 2);
 * confirmed appointments plus reserved capacity may never exceed it. Every rule is enforced here, on the server, inside a transaction:
 *
 *   lock order (always the same, so transactions cannot deadlock):  project row  ->  date rows in ascending order  ->  audit chain
 *
 * `delivery_date_capacity` has one row per date; `SELECT ... FOR UPDATE` on it is the mutex that serialises everybody booking that date. Counts read
 * after taking the lock use locking reads too, because a plain SELECT in a REPEATABLE READ transaction may show a snapshot older than the lock.
 */
final class DeliveryService
{
    public const SOURCES = ['client_dashboard' => 'Online Dashboard', 'public_link' => 'Public Booking Link', 'manual' => 'Manual / Offline Booking', 'admin_reserved' => 'Admin Reserved Slot'];
    public const STATUSES = ['confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    /** Dates further away than this are never stored, so the per-date table cannot be grown by hand-made requests. */
    private const HORIZON_DAYS = 730;
    private const HOLDING = "('confirmed','completed')";

    // ------------------------------------------------------------------------------------------------ small helpers

    public static function validDate(string $d): bool
    {
        $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $x !== false && $x->format('Y-m-d') === $d;
    }

    public static function defaultCapacity(): int
    {
        return max(0, min(50, (int) Config::get('delivery.default_capacity', 2)));
    }

    public static function sourceLabel(string $s): string
    {
        return self::SOURCES[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    private static function userName(?int $uid): string
    {
        if ($uid === null) {
            return 'Client';
        }
        $u = Database::one('SELECT name FROM users WHERE id = ?', [$uid]);

        return $u === null ? 'Team member' : (string) $u['name'];
    }

    private static function addDays(string $ymd, int $days): string
    {
        return (new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
    }

    // ------------------------------------------------------------------------------------------------ project plan (preparation period and eligibility)

    /** @return array<string,mixed>|null */
    public static function settingsRow(int $projectId, bool $lock = false): ?array
    {
        return Database::one('SELECT * FROM project_delivery_settings WHERE project_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$projectId]);
    }

    /** @return array<string,mixed>|null the confirmed appointment of a project, if any */
    public static function activeFor(int $projectId, bool $lock = false): ?array
    {
        return Database::one("SELECT * FROM delivery_appointments WHERE project_id = ? AND status = 'confirmed' ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''), [$projectId]);
    }

    /** @return array<string,mixed>|null the latest appointment of a project that is not cancelled (confirmed or completed) */
    public static function currentFor(int $projectId): ?array
    {
        return Database::one("SELECT * FROM delivery_appointments WHERE project_id = ? AND status <> 'cancelled' ORDER BY (status = 'confirmed') DESC, id DESC LIMIT 1", [$projectId]);
    }

    /**
     * Where a project stands. state: not_configured | disabled | closed | preparing | booked | open.
     *
     * @param array<string,mixed> $project a projects row
     * @return array{state:string,configured:bool,row:?array<string,mixed>,eligible_from:?string,earliest:?string,latest:?string,message:string,active:?array<string,mixed>}
     */
    public static function plan(array $project, bool $lock = false): array
    {
        $today = AppTime::today();
        $row = self::settingsRow((int) $project['id'], $lock);
        $out = ['state' => 'not_configured', 'configured' => $row !== null, 'row' => $row, 'eligible_from' => null, 'earliest' => null, 'latest' => null, 'message' => '', 'active' => self::activeFor((int) $project['id'], $lock)];
        if ($row === null) {
            $out['message'] = 'Delivery appointments are not open for this project yet. Our team will open booking when the project is ready.';

            return $out;
        }
        $from = max((string) $row['eligibility_date'], (string) ($row['earliest_date'] ?? ''));
        $notice = AppTime::todayPlus(max(0, (int) Config::get('delivery.min_notice_days', 1)));
        $earliest = max($from, $notice);
        $latest = (string) ($row['latest_date'] ?? '') !== '' ? (string) $row['latest_date'] : AppTime::todayPlus(max(1, (int) Config::get('delivery.max_days_ahead', 180)));
        $latest = min($latest, AppTime::todayPlus(self::HORIZON_DAYS));
        $out['eligible_from'] = $from;
        $out['earliest'] = $earliest;
        $out['latest'] = $latest;
        if (in_array((string) $project['status'], ['draft', 'cancelled', 'completed'], true) || $project['archived_at'] !== null) {
            $out['state'] = 'closed';
            $out['message'] = 'Delivery appointments are not available for this project in its current state.';
        } elseif ((int) $row['booking_enabled'] !== 1) {
            $out['state'] = 'disabled';
            $out['message'] = 'Delivery booking is not open for this project at the moment. Please contact our team.';
        } elseif ($from > $today) {
            $out['state'] = 'preparing';
            $out['message'] = 'Your project is being prepared. Delivery appointments will become available from ' . local_date($from, 'j F Y') . '.';
        } elseif ($earliest > $latest) {
            $out['state'] = 'closed';
            $out['message'] = 'The booking window for this project has ended. Please contact our team.';
        } elseif ($out['active'] !== null && (int) $row['allow_multiple'] !== 1) {
            $out['state'] = 'booked';
            $out['message'] = 'Your delivery appointment is booked.';
        } else {
            $out['state'] = 'open';
            $out['message'] = 'Choose a delivery date between ' . local_date($earliest, 'j F Y') . ' and ' . local_date($latest, 'j F Y') . '.';
        }

        return $out;
    }

    /**
     * Saves the project's preparation period. The eligibility date is always recalculated from the start date and the days; appointments that are
     * already confirmed are never touched, only counted so the team can see which of them now fall before the new earliest date.
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $in preparation_days, prep_start_date?, earliest_date?, latest_date?, booking_enabled?, allow_multiple?
     * @return array{earlier:int,changed:bool}
     */
    public static function savePlan(int $actorId, array $project, array $in): array
    {
        Gate::authorize('delivery.configure', $actorId);
        $errors = [];
        $daysRaw = trim((string) ($in['preparation_days'] ?? ''));
        if (!ctype_digit($daysRaw) || (int) $daysRaw > 730) {
            $errors[] = 'Preparation time must be a whole number of days from 0 to 730.';
        }
        $old = self::settingsRow((int) $project['id']);
        $start = trim((string) ($in['prep_start_date'] ?? ''));
        $start = $start !== '' ? $start : (string) ($old['prep_start_date'] ?? AppTime::today());
        $opt = static function (mixed $v) use (&$errors): ?string {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (!self::validDate($v)) {
                $errors[] = 'Dates must look like 2026-12-31.';

                return null;
            }

            return $v;
        };
        $earliest = $opt($in['earliest_date'] ?? '');
        $latest = $opt($in['latest_date'] ?? '');
        if (!self::validDate($start)) {
            $errors[] = 'The preparation start date is not a valid date.';
        }
        if ($errors === [] && $latest !== null && $latest < max($earliest ?? '', $start)) {
            $errors[] = 'The latest selectable date must be after the earliest date.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $days = (int) $daysRaw;
        $eligibility = self::addDays($start, $days);
        $enabled = !empty($in['booking_enabled']) && $in['booking_enabled'] !== '0' ? 1 : 0;
        $multiple = Gate::hasRole('super_admin', $actorId) ? (!empty($in['allow_multiple']) && $in['allow_multiple'] !== '0' ? 1 : 0) : (int) ($old['allow_multiple'] ?? 0);

        $earlier = Database::transaction(function () use ($actorId, $project, $old, $days, $start, $eligibility, $earliest, $latest, $enabled, $multiple): int {
            Database::run('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            Database::run(
                'INSERT INTO project_delivery_settings (project_id, preparation_days, prep_start_date, eligibility_date, earliest_date, latest_date, booking_enabled, allow_multiple, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE preparation_days = VALUES(preparation_days), prep_start_date = VALUES(prep_start_date), eligibility_date = VALUES(eligibility_date), earliest_date = VALUES(earliest_date),
                    latest_date = VALUES(latest_date), booking_enabled = VALUES(booking_enabled), allow_multiple = VALUES(allow_multiple), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                [$project['id'], $days, $start, $eligibility, $earliest, $latest, $enabled, $multiple, $actorId],
            );
            $newFrom = max($eligibility, $earliest ?? '');
            $n = (int) Database::one("SELECT COUNT(*) AS c FROM delivery_appointments WHERE project_id = ? AND status = 'confirmed' AND appointment_date < ?", [$project['id'], $newFrom])['c'];
            $before = $old === null ? 'not set up' : ($old['preparation_days'] . ' days, from ' . max((string) $old['eligibility_date'], (string) ($old['earliest_date'] ?? '')));
            self::log(null, (int) $project['id'], null, 'plan_saved', $before, $days . ' days, from ' . $newFrom . ($enabled ? '' : ', booking off'), $actorId, null);
            Audit::record('delivery.plan_saved', 'project', (int) $project['id'], $old ?? [], ['preparation_days' => $days, 'eligibility_date' => $eligibility, 'earliest_date' => $earliest, 'latest_date' => $latest, 'booking_enabled' => $enabled, 'allow_multiple' => $multiple], [], $actorId);

            return $n;
        });
        $changed = $old === null || (string) $old['eligibility_date'] !== $eligibility || (string) ($old['earliest_date'] ?? '') !== (string) $earliest || (int) $old['booking_enabled'] !== $enabled || (string) ($old['latest_date'] ?? '') !== (string) $latest;
        if ($changed) {
            DeliveryMail::availabilityChanged($project);
        }

        return ['earlier' => $earlier, 'changed' => $changed];
    }

    // ------------------------------------------------------------------------------------------------ dates: capacity, reservations, calendar

    /** @return array{cap:int,blocked:bool,reason:?string,used:int,reserved:int,remaining:int} */
    public static function day(string $ymd, bool $lock = false): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $cap = Database::one('SELECT max_capacity, blocked, block_reason FROM delivery_date_capacity WHERE appointment_date = ?' . $suffix, [$ymd]);
        $used = count(Database::all('SELECT id FROM delivery_appointments WHERE appointment_date = ? AND status IN ' . self::HOLDING . $suffix, [$ymd]));
        $reserved = 0;
        foreach (Database::all('SELECT reserved_slots FROM delivery_capacity_reservations WHERE appointment_date = ? AND released_at IS NULL' . $suffix, [$ymd]) as $r) {
            $reserved += (int) $r['reserved_slots'];
        }

        return self::summarise($cap, $used, $reserved);
    }

    /** @param array<string,mixed>|null $cap @return array{cap:int,blocked:bool,reason:?string,used:int,reserved:int,remaining:int} */
    private static function summarise(?array $cap, int $used, int $reserved): array
    {
        $max = $cap !== null && $cap['max_capacity'] !== null ? (int) $cap['max_capacity'] : self::defaultCapacity();
        $blocked = $cap !== null && (int) $cap['blocked'] === 1;

        return ['cap' => $max, 'blocked' => $blocked, 'reason' => $cap['block_reason'] ?? null, 'used' => $used, 'reserved' => $reserved, 'remaining' => $blocked ? 0 : max(0, $max - $used - $reserved)];
    }

    /**
     * Capacity facts for every date from $from to $to (inclusive), in three queries.
     *
     * @return array<string,array{cap:int,blocked:bool,reason:?string,used:int,reserved:int,remaining:int}>
     */
    public static function days(string $from, string $to): array
    {
        $caps = [];
        foreach (Database::all('SELECT * FROM delivery_date_capacity WHERE appointment_date BETWEEN ? AND ?', [$from, $to]) as $r) {
            $caps[(string) $r['appointment_date']] = $r;
        }
        $used = [];
        foreach (Database::all('SELECT appointment_date, COUNT(*) AS c FROM delivery_appointments WHERE appointment_date BETWEEN ? AND ? AND status IN ' . self::HOLDING . ' GROUP BY appointment_date', [$from, $to]) as $r) {
            $used[(string) $r['appointment_date']] = (int) $r['c'];
        }
        $res = [];
        foreach (Database::all('SELECT appointment_date, SUM(reserved_slots) AS s FROM delivery_capacity_reservations WHERE appointment_date BETWEEN ? AND ? AND released_at IS NULL GROUP BY appointment_date', [$from, $to]) as $r) {
            $res[(string) $r['appointment_date']] = (int) $r['s'];
        }
        $out = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            $out[$d] = self::summarise($caps[$d] ?? null, $used[$d] ?? 0, $res[$d] ?? 0);
        }

        return $out;
    }

    /**
     * A month for the calendar. Each day: state = past | before | after | blocked | full | available, plus what is left. Names of other clients never appear.
     *
     * @param array{earliest:?string,latest:?string}|null $plan null = no booking window (the team's overview)
     * @return array{month:string,label:string,prev:?string,next:?string,weeks:list<list<?array<string,mixed>>>}
     */
    public static function month(string $ym, ?array $plan): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            $ym = substr(AppTime::today(), 0, 7);
        }
        $first = $ym . '-01';
        $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $info = self::days($first, $last);
        $today = AppTime::today();
        $lead = (int) (new \DateTimeImmutable($first))->format('N') - 1;
        $cells = array_fill(0, $lead, null);
        foreach ($info as $d => $i) {
            if ($d < $today) {
                $state = 'past';
            } elseif ($plan !== null && $plan['earliest'] !== null && $d < $plan['earliest']) {
                $state = 'before';
            } elseif ($plan !== null && $plan['latest'] !== null && $d > $plan['latest']) {
                $state = 'after';
            } elseif ($i['blocked']) {
                $state = 'blocked';
            } elseif ($i['remaining'] <= 0) {
                $state = 'full';
            } else {
                $state = 'available';
            }
            $cells[] = $i + ['date' => $d, 'day' => (int) substr($d, 8, 2), 'state' => $state];
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        $prev = (new \DateTimeImmutable($first))->modify('-1 month')->format('Y-m');
        $next = (new \DateTimeImmutable($first))->modify('+1 month')->format('Y-m');

        return ['month' => $ym, 'label' => (new \DateTimeImmutable($first))->format('F Y'), 'prev' => $prev >= substr($today, 0, 7) || $plan === null ? $prev : null, 'next' => $next <= substr(AppTime::todayPlus(self::HORIZON_DAYS), 0, 7) ? $next : null, 'weeks' => array_chunk($cells, 7)];
    }

    /** Takes the date's mutex (creating its row the first time) and returns the date's facts, read with locks. @return array{cap:int,blocked:bool,reason:?string,used:int,reserved:int,remaining:int} */
    private static function lockDay(string $ymd): array
    {
        if (Database::one('SELECT appointment_date FROM delivery_date_capacity WHERE appointment_date = ? FOR UPDATE', [$ymd]) === null) {
            self::ensureDay($ymd); // callers create the row before their transaction; this is only a safety net
            Database::run('SELECT appointment_date FROM delivery_date_capacity WHERE appointment_date = ? FOR UPDATE', [$ymd]);
        }

        return self::day($ymd, true);
    }

    /** Creates the date's row in its own statement, BEFORE the transaction that locks it (INSERT IGNORE followed by a lock inside one transaction can deadlock). */
    private static function ensureDay(string ...$dates): void
    {
        foreach ($dates as $d) {
            Database::run('INSERT IGNORE INTO delivery_date_capacity (appointment_date, created_at, updated_at) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$d]);
        }
    }

    /** Runs a booking transaction again when the database picked it as the loser of a lock conflict (deadlock or lock wait timeout). @template T @param callable():T $fn @return T */
    private static function retrying(callable $fn): mixed
    {
        for ($try = 1;; $try++) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (!in_array($code, [1213, 1205], true) || $try >= 4 || Database::connection()->inTransaction()) {
                    throw $e;
                }
                usleep(random_int(20000, 90000));
            }
        }
    }

    private static function requireBookableDate(string $ymd): void
    {
        if (!self::validDate($ymd)) {
            throw new ValidationException(['Please choose a date from the calendar.']);
        }
        if ($ymd < AppTime::today()) {
            throw new ValidationException(['That date has already passed.']);
        }
        if ($ymd > AppTime::todayPlus(self::HORIZON_DAYS)) {
            throw new ValidationException(['That date is too far ahead.']);
        }
    }

    /** The server-side date rules for one project. Throws when the date may not be chosen. @param array{state:string,earliest:?string,latest:?string,eligible_from:?string} $plan */
    private static function requireAllowed(array $plan, string $ymd, bool $override): void
    {
        if ($override) {
            return;
        }
        if (in_array($plan['state'], ['not_configured', 'disabled', 'closed'], true)) {
            throw new ValidationException([$plan['message'] ?? 'Delivery booking is not open for this project.']);
        }
        if ($plan['eligible_from'] !== null && $ymd < $plan['eligible_from']) {
            throw new ValidationException(['Your project is still being prepared: the first date you can choose is ' . local_date((string) ($plan['eligible_from'] ?? $plan['earliest']), 'j F Y') . '.']);
        }
        if ($plan['earliest'] !== null && $ymd < $plan['earliest']) {
            throw new ValidationException(['The first date you can choose is ' . local_date($plan['earliest'], 'j F Y') . '.']);
        }
        if ($plan['latest'] !== null && $ymd > $plan['latest']) {
            throw new ValidationException(['The last date you can choose is ' . local_date($plan['latest'], 'j F Y') . '.']);
        }
    }

    private static function requireRoom(string $ymd, array $day): void
    {
        if ($day['blocked']) {
            throw new ValidationException([local_date($ymd, 'j F Y') . ' is not available. Please choose another date.']);
        }
        if ($day['remaining'] <= 0) {
            throw new ValidationException([local_date($ymd, 'j F Y') . ' is fully booked. Please choose another date.']);
        }
    }

    // ------------------------------------------------------------------------------------------------ booking

    /**
     * Books a delivery date. $source is one of SOURCES. Staff sources ('manual', 'admin_reserved') need delivery.manage; 'admin_reserved' uses up one reserved
     * slot of that date (total capacity is unchanged). A staff member holding delivery.configure may pass 'override_eligibility' to book inside the preparation period.
     *
     * @param array<string,mixed> $project a projects row
     * @param array{client_notes?:string,internal_notes?:string,override_eligibility?:bool} $opts
     * @return array<string,mixed> the appointment row
     */
    public static function book(array $project, string $ymd, string $source, ?int $actorId, array $opts = []): array
    {
        if (!isset(self::SOURCES[$source])) {
            throw new ValidationException(['Unknown booking source.']);
        }
        $staff = in_array($source, ['manual', 'admin_reserved'], true);
        if ($staff && ($actorId === null || !Gate::can('delivery.manage', $actorId) || !in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true))) {
            throw new HttpException(403);
        }
        $override = $staff && !empty($opts['override_eligibility']) && Gate::can('delivery.configure', (int) $actorId);
        self::requireBookableDate($ymd);
        $clientNotes = trim((string) ($opts['client_notes'] ?? ''));
        $internal = trim((string) ($opts['internal_notes'] ?? ''));
        if (mb_strlen($clientNotes) > 500 || mb_strlen($internal) > 2000 || preg_match('/[<>\0]/', $clientNotes . $internal)) {
            throw new ValidationException(['Notes are too long or contain characters that are not allowed.']);
        }

        self::ensureDay($ymd);
        $id = self::retrying(fn (): int => Database::transaction(function () use ($project, $ymd, $source, $actorId, $override, $clientNotes, $internal): int {
            Database::run('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            $fresh = Database::one('SELECT * FROM projects WHERE id = ?', [$project['id']]) ?? throw new ValidationException(['Project not found.']);
            $plan = self::plan($fresh, true);
            self::requireAllowed($plan, $ymd, $override);
            if ($plan['active'] !== null && (int) ($plan['row']['allow_multiple'] ?? 0) !== 1) {
                throw new ValidationException(['This project already has a delivery appointment (' . $plan['active']['booking_reference'] . ') for ' . local_date((string) $plan['active']['appointment_date'], 'j F Y') . '. Please reschedule it instead.']);
            }
            $day = self::lockDay($ymd);
            if ($source === 'admin_reserved') {
                if ($day['blocked']) {
                    throw new ValidationException([local_date($ymd, 'j F Y') . ' is blocked. Unblock it first.']);
                }
                if (!self::takeReservedSlot($ymd)) {
                    throw new ValidationException(['No capacity is reserved on ' . local_date($ymd, 'j F Y') . '. Reserve it first, or book it as a manual booking.']);
                }
            } else {
                self::requireRoom($ymd, $day);
            }
            $ref = self::newReference();
            Database::run(
                'INSERT INTO delivery_appointments (booking_reference, project_id, client_id, appointment_date, booking_source, status, client_notes, internal_notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'confirmed\', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$ref, $fresh['id'], $fresh['client_id'], $ymd, $source, $clientNotes === '' ? null : $clientNotes, $internal === '' ? null : $internal, $source === 'client_dashboard' || $source === 'public_link' ? null : $actorId],
            );
            $aid = Database::insertId();
            self::log($aid, (int) $fresh['id'], $ymd, 'booked', null, $ymd . ' (' . self::sourceLabel($source) . ')', $actorId, $source === 'public_link' ? 'Public booking link' : null);
            Audit::record('delivery.booked', 'delivery_appointment', $aid, [], ['reference' => $ref, 'date' => $ymd, 'source' => $source], [], $actorId);

            return $aid;
        }));
        $appt = self::appointment($id) ?? throw new \RuntimeException('Appointment vanished.');
        DeliveryMail::booked($appt, $staff ? self::userName($actorId) : null);

        return $appt;
    }

    private static function takeReservedSlot(string $ymd): bool
    {
        $r = Database::one('SELECT id, reserved_slots FROM delivery_capacity_reservations WHERE appointment_date = ? AND released_at IS NULL AND reserved_slots > 0 ORDER BY id LIMIT 1 FOR UPDATE', [$ymd]);
        if ($r === null) {
            return false;
        }
        if ((int) $r['reserved_slots'] <= 1) {
            Database::run('UPDATE delivery_capacity_reservations SET reserved_slots = 0, released_at = UTC_TIMESTAMP() WHERE id = ?', [$r['id']]);
        } else {
            Database::run('UPDATE delivery_capacity_reservations SET reserved_slots = reserved_slots - 1 WHERE id = ?', [$r['id']]);
        }

        return true;
    }

    private static function newReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $ref = 'KDA-' . strtoupper(bin2hex(random_bytes(4)));
            if (Database::one('SELECT id FROM delivery_appointments WHERE booking_reference = ?', [$ref]) === null) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not create a booking reference.');
    }

    /**
     * Moves a confirmed appointment to another date. The new date is validated before anything changes; the old date is freed only when the move succeeds.
     *
     * @param array<string,mixed> $appt
     * @return array<string,mixed> the updated row
     */
    public static function reschedule(array $appt, string $ymd, ?int $actorId, bool $staff, bool $override = false): array
    {
        if ($staff) {
            $project = ProjectPolicy::findById((int) $appt['project_id']) ?? throw new HttpException(404);
            if (!Gate::can('delivery.manage', (int) $actorId) || !in_array(ProjectPolicy::level((int) $actorId, $project), ['all', 'assigned'], true)) {
                throw new HttpException(403);
            }
            $override = $override && Gate::can('delivery.configure', (int) $actorId);
        } else {
            $override = false;
        }
        self::requireBookableDate($ymd);
        self::ensureDay($ymd, (string) $appt['appointment_date']);
        $old = self::retrying(fn (): string => Database::transaction(function () use ($appt, $ymd, $actorId, $staff, $override): string {
            Database::run('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$appt['project_id']]);
            $cur = Database::one('SELECT * FROM delivery_appointments WHERE id = ? FOR UPDATE', [$appt['id']]) ?? throw new HttpException(404);
            if ($cur['status'] !== 'confirmed') {
                throw new ValidationException(['Only a confirmed appointment can be rescheduled.']);
            }
            if ((string) $cur['appointment_date'] === $ymd) {
                throw new ValidationException(['That is already the appointment date. Choose a different date.']);
            }
            $project = Database::one('SELECT * FROM projects WHERE id = ?', [$cur['project_id']]) ?? throw new HttpException(404);
            $plan = self::plan($project, true);
            if (!$override) {
                // the project's own rules apply to the new date; "already booked" must not stop moving the existing appointment
                if ($plan['state'] === 'booked') {
                    $plan['state'] = 'open';
                }
                self::requireAllowed($plan, $ymd, false);
            }
            $dates = [(string) $cur['appointment_date'], $ymd];
            sort($dates);
            foreach ($dates as $d) {
                $day = self::lockDay($d);
                if ($d === $ymd) {
                    self::requireRoom($ymd, $day);
                }
            }
            Database::run('UPDATE delivery_appointments SET appointment_date = ?, reschedule_count = reschedule_count + 1, change_request_kind = NULL, change_request_note = NULL, change_requested_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$ymd, $cur['id']]);
            self::log((int) $cur['id'], (int) $cur['project_id'], $ymd, 'rescheduled', (string) $cur['appointment_date'], $ymd, $actorId, $staff ? null : 'Client');
            Audit::record('delivery.rescheduled', 'delivery_appointment', (int) $cur['id'], ['date' => $cur['appointment_date']], ['date' => $ymd], [], $actorId);

            return (string) $cur['appointment_date'];
        }));
        $new = self::appointment((int) $appt['id']) ?? throw new HttpException(404);
        DeliveryMail::rescheduled($new, $old, $staff ? self::userName($actorId) : 'the client');

        return $new;
    }

    /** @param array<string,mixed> $appt */
    public static function cancel(array $appt, ?int $actorId, bool $staff, string $reason = ''): void
    {
        if ($staff) {
            $project = ProjectPolicy::findById((int) $appt['project_id']) ?? throw new HttpException(404);
            if (!Gate::can('delivery.manage', (int) $actorId) || !in_array(ProjectPolicy::level((int) $actorId, $project), ['all', 'assigned'], true)) {
                throw new HttpException(403);
            }
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 300 || preg_match('/[<>\0]/', $reason)) {
            throw new ValidationException(['The reason is too long or contains characters that are not allowed.']);
        }
        self::ensureDay((string) $appt['appointment_date']);
        self::retrying(fn () => Database::transaction(function () use ($appt, $actorId, $staff, $reason): void {
            Database::run('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$appt['project_id']]);
            $cur = Database::one('SELECT * FROM delivery_appointments WHERE id = ? FOR UPDATE', [$appt['id']]) ?? throw new HttpException(404);
            if ($cur['status'] !== 'confirmed') {
                throw new ValidationException(['Only a confirmed appointment can be cancelled.']);
            }
            self::lockDay((string) $cur['appointment_date']);
            Database::run("UPDATE delivery_appointments SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), change_request_kind = NULL, change_request_note = NULL, change_requested_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$cur['id']]);
            self::log((int) $cur['id'], (int) $cur['project_id'], (string) $cur['appointment_date'], 'cancelled', (string) $cur['appointment_date'], $reason === '' ? 'cancelled' : $reason, $actorId, $staff ? null : 'Client');
            Audit::record('delivery.cancelled', 'delivery_appointment', (int) $cur['id'], ['date' => $cur['appointment_date']], ['status' => 'cancelled'], [], $actorId);
        }));
        DeliveryMail::cancelled(self::appointment((int) $appt['id']) ?? $appt, $staff ? self::userName($actorId) : 'the client', $reason);
    }

    /** @param array<string,mixed> $appt */
    public static function complete(array $appt, int $actorId): void
    {
        $project = ProjectPolicy::findById((int) $appt['project_id']) ?? throw new HttpException(404);
        if (!Gate::can('delivery.manage', $actorId) || !in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        Database::transaction(function () use ($appt, $actorId): void {
            $cur = Database::one('SELECT * FROM delivery_appointments WHERE id = ? FOR UPDATE', [$appt['id']]) ?? throw new HttpException(404);
            if ($cur['status'] !== 'confirmed') {
                throw new ValidationException(['Only a confirmed appointment can be marked completed.']);
            }
            Database::run("UPDATE delivery_appointments SET status = 'completed', completed_at = UTC_TIMESTAMP(), change_request_kind = NULL, change_request_note = NULL, change_requested_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$cur['id']]);
            self::log((int) $cur['id'], (int) $cur['project_id'], (string) $cur['appointment_date'], 'completed', 'confirmed', 'completed', $actorId, null);
            Audit::record('delivery.completed', 'delivery_appointment', (int) $cur['id'], [], ['status' => 'completed'], [], $actorId);
        });
        DeliveryMail::completed(self::appointment((int) $appt['id']) ?? $appt);
    }

    /** @param array<string,mixed> $appt */
    public static function addNote(array $appt, int $actorId, string $note): void
    {
        $project = ProjectPolicy::findById((int) $appt['project_id']) ?? throw new HttpException(404);
        if (!Gate::can('delivery.manage', $actorId) || !in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000 || preg_match('/[<>\0]/', $note)) {
            throw new ValidationException(['Write a note of up to 1000 characters without < or >.']);
        }
        Database::transaction(function () use ($appt, $actorId, $note): void {
            $line = '[' . gmdate('Y-m-d H:i') . ' UTC · ' . self::userName($actorId) . '] ' . $note;
            Database::run("UPDATE delivery_appointments SET internal_notes = CONCAT(COALESCE(CONCAT(internal_notes, CHAR(10)), ''), ?), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$line, $appt['id']]);
            self::log((int) $appt['id'], (int) $appt['project_id'], (string) $appt['appointment_date'], 'note_added', null, mb_substr($note, 0, 200), $actorId, null);
        });
    }

    // ------------------------------------------------------------------------------------------------ the client's own changes

    /** Whether the client may move or cancel on their own right now. @param array<string,mixed> $appt @return array{self:bool,reason:string,left:int} */
    public static function clientMay(array $appt, string $kind): array
    {
        $cutoff = max(0, (int) Config::get('delivery.client_cutoff_days', 3));
        $left = (int) ((strtotime((string) $appt['appointment_date']) - strtotime(AppTime::today())) / 86400);
        if ($appt['status'] !== 'confirmed') {
            return ['self' => false, 'reason' => 'This appointment can no longer be changed.', 'left' => $left];
        }
        if ($left < $cutoff) {
            return ['self' => false, 'reason' => 'Changes within ' . $cutoff . ' day(s) of the appointment need our team. Send a request and we will get back to you.', 'left' => $left];
        }
        if ($kind === 'reschedule' && (int) $appt['reschedule_count'] >= max(0, (int) Config::get('delivery.max_reschedules', 2))) {
            return ['self' => false, 'reason' => 'You have already moved this appointment ' . (int) $appt['reschedule_count'] . ' time(s). Send a request and our team will help.', 'left' => $left];
        }

        return ['self' => true, 'reason' => '', 'left' => $left];
    }

    /** @param array<string,mixed> $appt */
    public static function clientReschedule(array $appt, string $ymd, int $userId): array
    {
        $may = self::clientMay($appt, 'reschedule');
        if (!$may['self']) {
            throw new ValidationException([$may['reason']]);
        }

        return self::reschedule($appt, $ymd, $userId, false);
    }

    /** @param array<string,mixed> $appt */
    public static function clientCancel(array $appt, int $userId): void
    {
        $may = self::clientMay($appt, 'cancel');
        if (!$may['self']) {
            throw new ValidationException([$may['reason']]);
        }
        self::cancel($appt, $userId, false);
    }

    /** The client asks the team to change the appointment. @param array<string,mixed> $appt */
    public static function requestChange(array $appt, string $kind, string $note, ?int $userId, string $label = 'Client'): void
    {
        if (!in_array($kind, ['reschedule', 'cancel'], true)) {
            throw new ValidationException(['Choose what you want to ask for.']);
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500 || preg_match('/[<>\0]/', $note)) {
            throw new ValidationException(['Tell us briefly what you need (up to 500 characters, without < or >).']);
        }
        Database::transaction(function () use ($appt, $kind, $note, $userId, $label): void {
            $cur = Database::one('SELECT * FROM delivery_appointments WHERE id = ? FOR UPDATE', [$appt['id']]) ?? throw new HttpException(404);
            if ($cur['status'] !== 'confirmed') {
                throw new ValidationException(['This appointment can no longer be changed.']);
            }
            Database::run('UPDATE delivery_appointments SET change_request_kind = ?, change_request_note = ?, change_requested_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$kind, $note, $cur['id']]);
            self::log((int) $cur['id'], (int) $cur['project_id'], (string) $cur['appointment_date'], 'change_requested', null, $kind . ': ' . mb_substr($note, 0, 300), $userId, $label);
        });
        DeliveryMail::changeRequested(self::appointment((int) $appt['id']) ?? $appt);
    }

    /** The team closes a request without changing the appointment. @param array<string,mixed> $appt */
    public static function dismissRequest(array $appt, int $actorId): void
    {
        $project = ProjectPolicy::findById((int) $appt['project_id']) ?? throw new HttpException(404);
        if (!Gate::can('delivery.manage', $actorId) || !in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        Database::run('UPDATE delivery_appointments SET change_request_kind = NULL, change_request_note = NULL, change_requested_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$appt['id']]);
        self::log((int) $appt['id'], (int) $appt['project_id'], (string) $appt['appointment_date'], 'request_closed', (string) $appt['change_request_kind'], 'no change made', $actorId, null);
    }

    // ------------------------------------------------------------------------------------------------ capacity controls (delivery.configure)

    public static function setDateCapacity(int $actorId, string $ymd, ?int $max): void
    {
        Gate::authorize('delivery.configure', $actorId);
        self::requireBookableDate($ymd);
        if ($max !== null && ($max < 0 || $max > 50)) {
            throw new ValidationException(['Capacity must be between 0 and 50.']);
        }
        self::ensureDay($ymd);
        Database::transaction(function () use ($actorId, $ymd, $max): void {
            $before = self::lockDay($ymd);
            Database::run('UPDATE delivery_date_capacity SET max_capacity = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE appointment_date = ?', [$max, $actorId, $ymd]);
            self::log(null, null, $ymd, 'capacity_set', (string) $before['cap'], $max === null ? 'default (' . self::defaultCapacity() . ')' : (string) $max, $actorId, null);
            Audit::record('delivery.capacity_set', 'delivery_date', null, ['capacity' => $before['cap']], ['date' => $ymd, 'capacity' => $max], [], $actorId);
        });
    }

    /** Marks a date fully booked. Appointments already confirmed on it stay. @return int how many confirmed appointments are on that date */
    public static function blockDate(int $actorId, string $ymd, string $reason): int
    {
        Gate::authorize('delivery.configure', $actorId);
        self::requireBookableDate($ymd);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255 || preg_match('/[<>\0]/', $reason)) {
            throw new ValidationException(['Give an internal reason (up to 255 characters, without < or >).']);
        }
        self::ensureDay($ymd);
        $n = Database::transaction(function () use ($actorId, $ymd, $reason): int {
            $day = self::lockDay($ymd);
            Database::run('UPDATE delivery_date_capacity SET blocked = 1, block_reason = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE appointment_date = ?', [$reason, $actorId, $ymd]);
            self::log(null, null, $ymd, 'date_blocked', null, $reason, $actorId, null);
            Audit::record('delivery.date_blocked', 'delivery_date', null, [], ['date' => $ymd, 'reason' => $reason], [], $actorId);

            return $day['used'];
        });
        DeliveryMail::dateBlocked($ymd, $reason, $n, self::userName($actorId));

        return $n;
    }

    public static function unblockDate(int $actorId, string $ymd): void
    {
        Gate::authorize('delivery.configure', $actorId);
        if (!self::validDate($ymd)) {
            throw new ValidationException(['Choose a valid date.']);
        }
        self::ensureDay($ymd);
        Database::transaction(function () use ($actorId, $ymd): void {
            self::lockDay($ymd);
            Database::run('UPDATE delivery_date_capacity SET blocked = 0, block_reason = NULL, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE appointment_date = ?', [$actorId, $ymd]);
            self::log(null, null, $ymd, 'date_unblocked', null, null, $actorId, null);
            Audit::record('delivery.date_unblocked', 'delivery_date', null, [], ['date' => $ymd], [], $actorId);
        });
    }

    /** Keeps capacity back for internal planning. It cannot take slots that confirmed appointments already hold. */
    public static function reserve(int $actorId, string $ymd, int $slots, string $reason): int
    {
        Gate::authorize('delivery.configure', $actorId);
        self::requireBookableDate($ymd);
        $reason = trim($reason);
        if ($slots < 1 || $slots > 50) {
            throw new ValidationException(['Reserve between 1 and 50 slots.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255 || preg_match('/[<>\0]/', $reason)) {
            throw new ValidationException(['Give an internal reason (up to 255 characters, without < or >).']);
        }

        self::ensureDay($ymd);
        return Database::transaction(function () use ($actorId, $ymd, $slots, $reason): int {
            $day = self::lockDay($ymd);
            $free = max(0, $day['cap'] - $day['used'] - $day['reserved']);
            if ($slots > $free) {
                throw new ValidationException(['Only ' . $free . ' slot(s) are free on ' . local_date($ymd, 'j F Y') . ', so ' . $slots . ' cannot be reserved.']);
            }
            Database::run('INSERT INTO delivery_capacity_reservations (appointment_date, reserved_slots, reason, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$ymd, $slots, $reason, $actorId]);
            $id = Database::insertId();
            self::log(null, null, $ymd, 'capacity_reserved', null, $slots . ' slot(s): ' . $reason, $actorId, null);
            Audit::record('delivery.capacity_reserved', 'delivery_date', $id, [], ['date' => $ymd, 'slots' => $slots, 'reason' => $reason], [], $actorId);

            return $id;
        });
    }

    public static function releaseReservation(int $actorId, int $id): void
    {
        Gate::authorize('delivery.configure', $actorId);
        Database::transaction(function () use ($actorId, $id): void {
            $r = Database::one('SELECT * FROM delivery_capacity_reservations WHERE id = ? AND released_at IS NULL FOR UPDATE', [$id]) ?? throw new ValidationException(['That reservation is already released.']);
            self::lockDay((string) $r['appointment_date']);
            Database::run('UPDATE delivery_capacity_reservations SET released_at = UTC_TIMESTAMP(), released_by = ? WHERE id = ?', [$actorId, $id]);
            self::log(null, null, (string) $r['appointment_date'], 'reservation_released', (string) $r['reserved_slots'] . ' slot(s)', null, $actorId, null);
            Audit::record('delivery.reservation_released', 'delivery_date', $id, ['slots' => $r['reserved_slots']], [], [], $actorId);
        });
    }

    /**
     * The Owner's delivery settings (default capacity, notification address, public booking mode, client change rules), stored with the other settings.
     *
     * @param array<string,mixed> $in
     */
    public static function saveDefaults(int $actorId, array $in): void
    {
        Gate::authorize('delivery.configure', $actorId);
        $errors = [];
        $int = static function (string $k, int $min, int $max, string $label) use ($in, &$errors): ?string {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $errors[] = $label . ' must be a whole number from ' . $min . ' to ' . $max . '.';

                return null;
            }

            return $v;
        };
        $w = [];
        $w['delivery.default_capacity'] = $int('default_capacity', 0, 50, 'Daily capacity');
        $w['delivery.client_cutoff_days'] = $int('client_cutoff_days', 0, 60, 'Client change cut-off');
        $w['delivery.max_reschedules'] = $int('max_reschedules', 0, 10, 'Client reschedules');
        $w['delivery.max_days_ahead'] = $int('max_days_ahead', 7, 730, 'Booking window');
        $mail = trim((string) ($in['notify_email'] ?? ''));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The notification address is not a valid email address.';
        } elseif (array_key_exists('notify_email', $in)) {
            $w['delivery.notify_email'] = $mail;
        }
        $mode = (string) ($in['public_mode'] ?? '');
        if ($mode !== '') {
            if (!in_array($mode, ['off', 'otp', 'token', 'both'], true)) {
                $errors[] = 'Choose how the public booking page verifies people.';
            } else {
                $w['delivery.public_mode'] = $mode;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::transaction(function () use ($actorId, $w): void {
            $changed = [];
            foreach ($w as $key => $v) {
                if ($v === null) {
                    continue;
                }
                $old = Database::one('SELECT value_text FROM app_settings WHERE setting_key = ?', [$key]);
                if ($old !== null && (string) $old['value_text'] === (string) $v) {
                    continue;
                }
                Database::run('INSERT INTO app_settings (setting_key, value_text, is_secret, updated_by, updated_at) VALUES (?, ?, 0, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()', [$key, (string) $v, $actorId]);
                $changed[] = $key;
            }
            if ($changed !== []) {
                self::log(null, null, null, 'settings_changed', null, implode(', ', $changed), $actorId, null);
                Audit::record('settings.changed', 'settings', null, [], [], ['group' => 'delivery', 'keys' => $changed], $actorId);
            }
        });
        Settings::reload();
    }

    // ------------------------------------------------------------------------------------------------ reading

    /** @return array<string,mixed>|null */
    public static function appointment(int $id): ?array
    {
        return Database::one(
            'SELECT a.*, p.code AS project_code, p.title AS project_title, p.public_id AS project_public_id, c.contact_name, c.company_name, c.email AS client_email, c.user_id AS client_user_id
               FROM delivery_appointments a JOIN projects p ON p.id = a.project_id JOIN clients c ON c.id = a.client_id WHERE a.id = ?',
            [$id],
        );
    }

    /** @return array<string,mixed>|null */
    public static function byReference(string $ref): ?array
    {
        $r = Database::one('SELECT id FROM delivery_appointments WHERE booking_reference = ?', [strtoupper(trim($ref))]);

        return $r === null ? null : self::appointment((int) $r['id']);
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $appointmentId): array
    {
        return Database::all(
            'SELECT l.*, u.name AS user_name FROM delivery_appointment_logs l LEFT JOIN users u ON u.id = l.performed_by WHERE l.appointment_id = ? ORDER BY l.id DESC',
            [$appointmentId],
        );
    }

    /** @return list<array<string,mixed>> the team-wide log (capacity, blocks, settings) */
    public static function generalLog(int $limit = 40): array
    {
        return Database::all(
            'SELECT l.*, u.name AS user_name FROM delivery_appointment_logs l LEFT JOIN users u ON u.id = l.performed_by WHERE l.appointment_id IS NULL ORDER BY l.id DESC LIMIT ' . max(1, min(200, $limit)),
        );
    }

    /**
     * What this staff member may see: everything with delivery.view, assigned projects with delivery.view_assigned, nothing otherwise.
     *
     * @return array{sql:string,params:list<mixed>}|null null = no access
     */
    public static function scope(int $userId): ?array
    {
        if (Gate::can('delivery.view', $userId)) {
            return ['sql' => '1 = 1', 'params' => []];
        }
        if (Gate::can('delivery.view_assigned', $userId)) {
            return ['sql' => 'EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.project_id = a.project_id AND pa.user_id = ? AND pa.revoked_at IS NULL)', 'params' => [$userId]];
        }

        return null;
    }

    /**
     * @param array{from?:string,to?:string,status?:string,source?:string,q?:string,project?:string,client?:string,requests?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function search(int $userId, array $f, int $page, int $perPage): array
    {
        $scope = self::scope($userId) ?? throw new HttpException(403);
        $w = [$scope['sql']];
        $p = $scope['params'];
        if (($f['from'] ?? '') !== '' && self::validDate($f['from'])) {
            $w[] = 'a.appointment_date >= ?';
            $p[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '' && self::validDate($f['to'])) {
            $w[] = 'a.appointment_date <= ?';
            $p[] = $f['to'];
        }
        if (isset(self::STATUSES[$f['status'] ?? ''])) {
            $w[] = 'a.status = ?';
            $p[] = $f['status'];
        }
        if (isset(self::SOURCES[$f['source'] ?? ''])) {
            $w[] = 'a.booking_source = ?';
            $p[] = $f['source'];
        }
        if (($f['project'] ?? '') !== '') {
            $w[] = '(p.code LIKE ? OR p.title LIKE ?)';
            $like = '%' . addcslashes($f['project'], '%_\\') . '%';
            array_push($p, $like, $like);
        }
        if (($f['client'] ?? '') !== '') {
            $w[] = '(c.contact_name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ?)';
            $like = '%' . addcslashes($f['client'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        if (!empty($f['requests'])) {
            $w[] = "a.change_request_kind IS NOT NULL AND a.status = 'confirmed'";
        }
        $from = ' FROM delivery_appointments a JOIN projects p ON p.id = a.project_id JOIN clients c ON c.id = a.client_id WHERE ' . implode(' AND ', $w);
        $total = (int) Database::one('SELECT COUNT(*) AS c' . $from, $p)['c'];
        $rows = Database::all(
            'SELECT a.*, p.code AS project_code, p.title AS project_title, p.public_id AS project_public_id, c.contact_name, c.company_name, c.email AS client_email' . $from
            . ' ORDER BY a.appointment_date DESC, a.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $p,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Headline numbers for the team's overview. @return array<string,int> */
    public static function stats(int $userId): array
    {
        $scope = self::scope($userId) ?? throw new HttpException(403);
        $today = AppTime::today();
        $q = static fn (string $extra, array $params = []): int => (int) Database::one('SELECT COUNT(*) AS c FROM delivery_appointments a WHERE ' . $scope['sql'] . ' AND ' . $extra, array_merge($scope['params'], $params))['c'];
        $horizon = AppTime::todayPlus(self::HORIZON_DAYS);
        $days = self::days($today, $horizon);
        $full = $reserved = 0;
        foreach ($days as $d) {
            if ($d['blocked'] || ($d['remaining'] <= 0 && ($d['used'] > 0 || $d['reserved'] > 0))) {
                $full++;
            }
            if ($d['reserved'] > 0) {
                $reserved++;
            }
        }

        return [
            'today' => $q("a.status = 'confirmed' AND a.appointment_date = ?", [$today]),
            'upcoming' => $q("a.status = 'confirmed' AND a.appointment_date > ?", [$today]),
            'confirmed' => $q("a.status = 'confirmed'"),
            'requests' => $q("a.status = 'confirmed' AND a.change_request_kind IS NOT NULL"),
            'offline' => $q("a.status = 'confirmed' AND a.booking_source IN ('manual','admin_reserved')"),
            'completed' => $q("a.status = 'completed'"),
            'cancelled' => $q("a.status = 'cancelled'"),
            'full_dates' => $full,
            'reserved_dates' => $reserved,
        ];
    }

    /** Appointments on one date (staff overview). @return list<array<string,mixed>> */
    public static function onDate(int $userId, string $ymd): array
    {
        $scope = self::scope($userId) ?? throw new HttpException(403);

        return Database::all(
            'SELECT a.*, p.code AS project_code, p.title AS project_title, p.public_id AS project_public_id, c.contact_name, c.company_name FROM delivery_appointments a JOIN projects p ON p.id = a.project_id JOIN clients c ON c.id = a.client_id
              WHERE ' . $scope['sql'] . " AND a.appointment_date = ? AND a.status <> 'cancelled' ORDER BY a.id",
            array_merge($scope['params'], [$ymd]),
        );
    }

    /** @return list<array<string,mixed>> */
    public static function reservations(string $from, string $to): array
    {
        return Database::all('SELECT r.*, u.name AS user_name FROM delivery_capacity_reservations r LEFT JOIN users u ON u.id = r.created_by WHERE r.released_at IS NULL AND r.appointment_date BETWEEN ? AND ? ORDER BY r.appointment_date, r.id', [$from, $to]);
    }

    /** @return list<array<string,mixed>> dates with an override or a block, from today on */
    public static function overrides(): array
    {
        return Database::all('SELECT * FROM delivery_date_capacity WHERE appointment_date >= ? AND (max_capacity IS NOT NULL OR blocked = 1) ORDER BY appointment_date', [AppTime::today()]);
    }

    /** Projects a staff member can create a manual booking for. @return list<array<string,mixed>> */
    public static function bookableProjects(int $userId): array
    {
        $assigned = Gate::can('projects.view_all', $userId) ? '1 = 1' : 'EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.user_id = ? AND pa.revoked_at IS NULL)';
        $params = Gate::can('projects.view_all', $userId) ? [] : [$userId];

        return Database::all(
            "SELECT p.id, p.code, p.title, p.public_id, c.contact_name, c.company_name FROM projects p JOIN clients c ON c.id = p.client_id
              WHERE p.archived_at IS NULL AND p.status NOT IN ('draft','cancelled','completed') AND " . $assigned . ' ORDER BY p.code DESC LIMIT 500',
            $params,
        );
    }

    // ------------------------------------------------------------------------------------------------ history

    public static function log(?int $appointmentId, ?int $projectId, ?string $ymd, string $action, ?string $previous, ?string $new, ?int $actorId, ?string $label): void
    {
        Database::run(
            'INSERT INTO delivery_appointment_logs (appointment_id, project_id, appointment_date, action, previous_value, new_value, performed_by, performer_label, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$appointmentId, $projectId, $ymd, $action, $previous === null ? null : mb_substr($previous, 0, 500), $new === null ? null : mb_substr($new, 0, 500), $actorId, $label === null ? null : mb_substr($label, 0, 120)],
        );
    }

    /** "dd Mon yyyy" with the weekday, for emails and pages. */
    public static function nice(string $ymd): string
    {
        return local_date($ymd, 'l, j F Y');
    }
}
