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
        if ($id === null) {
            Database::run('INSERT INTO appointment_slots (label, start_time, end_time, weekdays, active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$label === '' ? null : $label, $start, $end, $mask, $active, $sort]);
            $id = Database::insertId();
            Audit::record('appointment_slot.created', 'appointment_slot', $id, [], ['start' => $start, 'end' => $end, 'weekdays' => $mask], [], $actorId);
        } else {
            self::slot($id) ?? throw new ValidationException(['That time no longer exists.']);
            Database::run('UPDATE appointment_slots SET label = ?, start_time = ?, end_time = ?, weekdays = ?, active = ?, sort_order = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$label === '' ? null : $label, $start, $end, $mask, $active, $sort, $id]);
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
