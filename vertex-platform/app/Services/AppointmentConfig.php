<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * What a visitor may choose when asking to meet the founder: the available times (with the weekdays each one runs on) and the places for in-person meetings.
 * The team adds, edits, hides and deletes both. One place option is permanent and cannot be removed: "We will discuss it later".
 */
final class AppointmentConfig
{
    public const LATER = 'later';
    public const LATER_LABEL = 'We will discuss it later';
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function host(): string
    {
        return (string) Config::get('appointments.host_name', 'Durvish Jawale') . ' (' . (string) Config::get('appointments.host_title', 'Founder & CEO') . ')';
    }

    // ------------------------------------------------------------------------------------------------ reading

    /** @return list<array<string,mixed>> each with a readable `text` and `days` */
    public static function slots(bool $onlyActive = false): array
    {
        $rows = Database::all('SELECT * FROM appointment_slots' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, start_time, id');
        foreach ($rows as &$r) {
            $r['text'] = self::slotText($r);
            $r['days'] = self::daysText((int) $r['weekdays']);
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function slot(int $id, bool $onlyActive = false): ?array
    {
        $r = Database::one('SELECT * FROM appointment_slots WHERE id = ?' . ($onlyActive ? ' AND active = 1' : ''), [$id]);
        if ($r !== null) {
            $r['text'] = self::slotText($r);
            $r['days'] = self::daysText((int) $r['weekdays']);
        }

        return $r;
    }

    /** @return list<array<string,mixed>> */
    public static function places(bool $onlyActive = false): array
    {
        return Database::all('SELECT * FROM meeting_places' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return array<string,mixed>|null */
    public static function place(int $id, bool $onlyActive = false): ?array
    {
        return Database::one('SELECT * FROM meeting_places WHERE id = ?' . ($onlyActive ? ' AND active = 1' : ''), [$id]);
    }

    /** "10:00 AM - 11:30 AM" unless the team gave the time its own label. @param array<string,mixed> $s */
    public static function slotText(array $s): string
    {
        $fmt = static fn (string $t): string => date('g:i A', (int) strtotime('1970-01-01 ' . $t));
        $range = $fmt((string) $s['start_time']) . ' - ' . $fmt((string) $s['end_time']);

        return trim((string) ($s['label'] ?? '')) !== '' ? $s['label'] . ' (' . $range . ')' : $range;
    }

    public static function daysText(int $mask): string
    {
        if ($mask === 127) {
            return 'Every day';
        }
        $out = [];
        foreach (self::DAYS as $n => $d) {
            if ($mask & (1 << ($n - 1))) {
                $out[] = $d;
            }
        }

        return $out === [] ? 'No days' : implode(', ', $out);
    }

    /** Does this slot run on the weekday of $ymd? @param array<string,mixed> $slot */
    public static function runsOn(array $slot, string $ymd): bool
    {
        $n = (int) gmdate('N', (int) strtotime($ymd . ' 12:00:00 UTC'));

        return ((int) $slot['weekdays'] & (1 << ($n - 1))) !== 0;
    }

    /** @return array<int,string> ISO weekday number => short name */
    public static function dayNames(): array
    {
        return self::DAYS;
    }

    // ------------------------------------------------------------------------------------------------ ranges inside a window

    public const MAX_OPEN_REQUESTS = 3;

    /** "HH:MM" or "HH:MM:SS" => minutes since midnight. */
    public static function mins(string $t): int
    {
        return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    }

    /** minutes since midnight => "11:30" */
    public static function hhmm(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    /** "11:30" => "11:30 AM" */
    public static function clock(string $t): string
    {
        return date('g:i A', (int) strtotime('1970-01-01 ' . substr($t, 0, 5)));
    }

    /** @return list<array<string,mixed>> the active windows that run on the weekday of $ymd */
    public static function windowsFor(string $ymd): array
    {
        return array_values(array_filter(self::slots(true), static fn (array $s): bool => self::runsOn($s, $ymd)));
    }

    /**
     * Ranges already held on a date (a request that is still alive: not released, not declined, not archived).
     *
     * @return list<array{id:int,start:string,end:string}> times as HH:MM
     */
    public static function booked(string $ymd): array
    {
        $rows = Database::all(
            "SELECT b.id, b.start_time, b.end_time FROM appointment_bookings b LEFT JOIN enquiries e ON e.id = b.enquiry_id LEFT JOIN crm_stages s ON s.id = e.stage_id
              WHERE b.meeting_date = ? AND b.released_at IS NULL AND (e.id IS NULL OR (e.archived_at IS NULL AND s.kind <> 'lost')) ORDER BY b.start_time",
            [$ymd],
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'start' => substr((string) $r['start_time'], 0, 5), 'end' => substr((string) $r['end_time'], 0, 5)], $rows);
    }

    /** Free stretches of a window on a date. @param array<string,mixed> $slot @return list<array{start:string,end:string}> */
    public static function free(array $slot, string $ymd): array
    {
        $cursor = self::mins((string) $slot['start_time']);
        $end = self::mins((string) $slot['end_time']);
        $out = [];
        foreach (self::booked($ymd) as $b) {
            $bs = max(self::mins($b['start']), $cursor);
            $be = min(self::mins($b['end']), $end);
            if ($bs > $cursor) {
                $out[] = ['start' => self::hhmm($cursor), 'end' => self::hhmm(min($bs, $end))];
            }
            $cursor = max($cursor, $be);
        }
        if ($cursor < $end) {
            $out[] = ['start' => self::hhmm($cursor), 'end' => self::hhmm($end)];
        }

        return $out;
    }

    /** "11:00 AM - 12:00 PM, 2:00 PM - 11:00 PM" or "" */
    public static function freeText(array $slot, string $ymd): string
    {
        return implode(', ', array_map(static fn (array $r): string => self::clock($r['start']) . ' - ' . self::clock($r['end']), self::free($slot, $ymd)));
    }

    /**
     * Checks the range a visitor chose: inside the window, on its steps, long enough and not too long, and not overlapping a range that is already held.
     *
     * @param array<string,mixed> $slot @return array{0:string,1:string} start and end as HH:MM:SS
     * @throws ValidationException
     */
    public static function checkRange(array $slot, string $ymd, string $start, string $end): array
    {
        $ok = static fn (string $t): bool => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok($start) || !$ok($end)) {
            throw new ValidationException(['Choose a start time and an end time.']);
        }
        $s = self::mins($start);
        $e = self::mins($end);
        $ws = self::mins((string) $slot['start_time']);
        $we = self::mins((string) $slot['end_time']);
        $step = max(1, (int) $slot['step_minutes']);
        if ($e <= $s) {
            throw new ValidationException(['The end time must be after the start time.']);
        }
        if ($s < $ws || $e > $we) {
            throw new ValidationException(['Choose a time between ' . self::clock((string) $slot['start_time']) . ' and ' . self::clock((string) $slot['end_time']) . '.']);
        }
        if (($s - $ws) % $step !== 0 || ($e - $ws) % $step !== 0) {
            throw new ValidationException(['Times must be in steps of ' . $step . ' minutes.']);
        }
        if ($e - $s < (int) $slot['min_minutes']) {
            throw new ValidationException(['The meeting must be at least ' . (int) $slot['min_minutes'] . ' minutes long.']);
        }
        if ((int) $slot['max_minutes'] > 0 && $e - $s > (int) $slot['max_minutes']) {
            throw new ValidationException(['The meeting can be at most ' . (int) $slot['max_minutes'] . ' minutes long.']);
        }
        foreach (self::booked($ymd) as $b) {
            if ($s < self::mins($b['end']) && $e > self::mins($b['start'])) {
                $free = self::freeText($slot, $ymd);
                throw new ValidationException(['That time is already taken on ' . local_date($ymd) . '.' . ($free !== '' ? ' Times still free that day: ' . $free . '.' : ' Nothing is free that day: please choose another date.')]);
            }
        }

        return [$start . ':00', $end . ':00'];
    }

    /** How many unfinished requests this email address already holds. */
    public static function openRequests(string $email): int
    {
        return (int) Database::one(
            "SELECT COUNT(*) AS c FROM appointment_bookings b JOIN enquiries e ON e.id = b.enquiry_id JOIN crm_stages s ON s.id = e.stage_id
              WHERE e.email = ? AND b.released_at IS NULL AND b.meeting_date >= ? AND e.archived_at IS NULL AND s.kind <> 'lost'",
            [mb_strtolower($email), AppTime::today()],
        )['c'];
    }

    /**
     * Locks the range for a request. Call inside the transaction that creates the enquiry: it re-checks under a lock on the window, so two people sending
     * overlapping ranges at the same moment cannot both succeed (the second is refused and its enquiry is rolled back).
     */
    public static function lock(int $enquiryId, int $slotId, string $ymd, string $start, string $end): void
    {
        Database::all('SELECT id FROM appointment_slots ORDER BY id FOR UPDATE'); // one mutex for every window, so overlapping windows cannot be double booked either
        $slot = Database::one('SELECT * FROM appointment_slots WHERE id = ?', [$slotId]) ?? throw new ValidationException(['Please choose one of the available times.']);
        $slot['text'] = self::slotText($slot);
        $slot['days'] = self::daysText((int) $slot['weekdays']);
        [$s, $e] = self::checkRange($slot, $ymd, $start, $end);
        Database::run('INSERT INTO appointment_bookings (enquiry_id, slot_id, meeting_date, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$enquiryId, $slotId, $ymd, $s, $e]);
    }

    /** Frees a held range (a request that was declined or never real). */
    public static function release(int $actorId, int $bookingId): void
    {
        if (!Gate::can('appointments.configure', $actorId) && !Gate::can('appointments.manage', $actorId)) {
            throw new \Vertex\Core\HttpException(403);
        }
        Database::run('UPDATE appointment_bookings SET released_at = UTC_TIMESTAMP() WHERE id = ? AND released_at IS NULL', [$bookingId]);
        Audit::record('appointment.released', 'appointment_booking', $bookingId, [], [], [], $actorId);
    }

    /** What the form needs for one date: the windows that run that weekday and the ranges already held. @return array<string,mixed> */
    public static function availability(string $ymd): array
    {
        $windows = [];
        foreach (self::windowsFor($ymd) as $w) {
            $windows[] = ['id' => (int) $w['id'], 'text' => self::slotText($w), 'start' => substr((string) $w['start_time'], 0, 5), 'end' => substr((string) $w['end_time'], 0, 5),
                'step' => (int) $w['step_minutes'], 'min' => (int) $w['min_minutes'], 'max' => (int) $w['max_minutes']];
        }

        return ['date' => $ymd, 'windows' => $windows, 'booked' => array_map(static fn (array $b): array => ['start' => $b['start'], 'end' => $b['end']], self::booked($ymd))];
    }

    // ------------------------------------------------------------------------------------------------ editing (Administration → Appointments)

    /** @param array<string,mixed> $in @return int the slot id */
    public static function saveSlot(int $actorId, ?int $id, array $in): int
    {
        Gate::authorize('appointments.configure', $actorId);
        $time = static function (mixed $v, string $label): string {
            $v = trim((string) $v);
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v)) {
                throw new ValidationException(["{$label} must be a time like 10:00."]);
            }

            return $v . ':00';
        };
        $errors = [];
        $start = $end = '';
        try {
            $start = $time($in['start_time'] ?? '', 'Start time');
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        try {
            $end = $time($in['end_time'] ?? '', 'End time');
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        if ($errors === [] && $end <= $start) {
            $errors[] = 'The end time must be after the start time.';
        }
        $label = FieldRules::line($in['label'] ?? '', 'Name', 2, 80, false) ?? '';
        $mask = 0;
        foreach ((array) ($in['days'] ?? []) as $d) {
            if (is_numeric($d) && (int) $d >= 1 && (int) $d <= 7) {
                $mask |= 1 << ((int) $d - 1);
            }
        }
        if ($mask === 0) {
            $errors[] = 'Choose at least one weekday.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $active = !empty($in['active']) ? 1 : 0;
        $sort = max(0, min(9999, (int) ($in['sort_order'] ?? 0)));
        $step = (int) ($in['step_minutes'] ?? 30);
        $min = (int) ($in['min_minutes'] ?? 30);
        $max = (int) ($in['max_minutes'] ?? 0);
        $window = (self::mins($end) - self::mins($start));
        if (!in_array($step, [5, 10, 15, 20, 30, 60], true)) {
            $errors[] = 'Choose a step of 5, 10, 15, 20, 30 or 60 minutes.';
        } elseif ($window % $step !== 0) {
            $errors[] = 'The window (' . $window . ' minutes) must be a whole number of steps of ' . $step . ' minutes.';
        }
        if ($min < 5 || $min > $window) {
            $errors[] = 'The shortest meeting must be at least 5 minutes and no longer than the window.';
        }
        if ($max !== 0 && ($max < $min || $max > $window)) {
            $errors[] = 'The longest meeting must be 0 (no limit) or between the shortest meeting and the window.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($id === null) {
            Database::run('INSERT INTO appointment_slots (label, start_time, end_time, weekdays, step_minutes, min_minutes, max_minutes, active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$label === '' ? null : $label, $start, $end, $mask, $step, $min, $max, $active, $sort]);
            $id = Database::insertId();
            Audit::record('appointment_slot.created', 'appointment_slot', $id, [], ['start' => $start, 'end' => $end, 'weekdays' => $mask], [], $actorId);
        } else {
            self::slot($id) ?? throw new ValidationException(['That time no longer exists.']);
            Database::run('UPDATE appointment_slots SET label = ?, start_time = ?, end_time = ?, weekdays = ?, step_minutes = ?, min_minutes = ?, max_minutes = ?, active = ?, sort_order = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$label === '' ? null : $label, $start, $end, $mask, $step, $min, $max, $active, $sort, $id]);
            Audit::record('appointment_slot.updated', 'appointment_slot', $id, [], ['start' => $start, 'end' => $end, 'weekdays' => $mask, 'active' => $active], [], $actorId);
        }

        return $id;
    }

    public static function deleteSlot(int $actorId, int $id): void
    {
        Gate::authorize('appointments.configure', $actorId);
        // earlier requests keep their own text, so deleting a time never changes history
        Database::run('DELETE FROM appointment_slots WHERE id = ?', [$id]);
        Audit::record('appointment_slot.deleted', 'appointment_slot', $id, [], [], [], $actorId);
    }

    /** @param array<string,mixed> $in @return int the place id */
    public static function savePlace(int $actorId, ?int $id, array $in): int
    {
        Gate::authorize('appointments.configure', $actorId);
        $name = (string) FieldRules::line($in['name'] ?? '', 'Place name', 2, 160);
        if (mb_strtolower($name) === mb_strtolower(self::LATER_LABEL)) {
            throw new ValidationException(['"' . self::LATER_LABEL . '" is always offered; you do not need to add it.']);
        }
        $details = FieldRules::line($in['details'] ?? '', 'Details', 2, 300, false) ?? '';
        $active = !empty($in['active']) ? 1 : 0;
        $sort = max(0, min(9999, (int) ($in['sort_order'] ?? 0)));
        $dup = Database::one('SELECT id FROM meeting_places WHERE name = ?' . ($id !== null ? ' AND id <> ' . (int) $id : ''), [$name]);
        if ($dup !== null) {
            throw new ValidationException(['That place is already in the list.']);
        }
        if ($id === null) {
            Database::run('INSERT INTO meeting_places (name, details, active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$name, $details === '' ? null : $details, $active, $sort]);
            $id = Database::insertId();
            Audit::record('meeting_place.created', 'meeting_place', $id, [], ['name' => $name], [], $actorId);
        } else {
            self::place($id) ?? throw new ValidationException(['That place no longer exists.']);
            Database::run('UPDATE meeting_places SET name = ?, details = ?, active = ?, sort_order = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $details === '' ? null : $details, $active, $sort, $id]);
            Audit::record('meeting_place.updated', 'meeting_place', $id, [], ['name' => $name, 'active' => $active], [], $actorId);
        }

        return $id;
    }

    public static function deletePlace(int $actorId, int $id): void
    {
        Gate::authorize('appointments.configure', $actorId);
        Database::run('DELETE FROM meeting_places WHERE id = ?', [$id]);
        Audit::record('meeting_place.deleted', 'meeting_place', $id, [], [], [], $actorId);
    }
}
