<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Module 27: support SLA timers and automatic escalation.
 *  - Each priority has a first-response time, a resolution time and an escalation delay (hours; editable by the owner).
 *  - The due times are stamped from the moment the ticket was SUBMITTED, in calendar hours, and then never move when the policy changes later.
 *  - When a due time passes with no response / no resolution the ticket is marked breached, the assignee and the people who manage tickets are told, and
 *    after the escalation delay it is escalated automatically (the same escalation staff can do by hand, with a reason that says it was automatic).
 *  - Runs from the daily/hourly scheduler; the ticket page shows the timers, and the support report shows how often the SLA was met.
 */
final class SlaService
{
    private const OPEN = "('draft','closed','resolved','rejected','unable_to_reproduce','fixed')";

    /** @return array<string,array<string,mixed>> priority => policy */
    public static function policies(): array
    {
        $out = [];
        foreach (Database::all("SELECT * FROM sla_policies ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low')") as $r) {
            $out[$r['priority']] = $r;
        }

        return $out;
    }

    /** @param array<string,array{first:string,resolution:string,escalate:string}> $in priority => hours */
    public static function savePolicies(int $actorId, array $in): void
    {
        Gate::authorize('sla.manage', $actorId);
        $errors = [];
        $clean = [];
        foreach (['urgent', 'high', 'normal', 'low'] as $p) {
            $row = [];
            foreach (['first' => [1, 720], 'resolution' => [1, 4320], 'escalate' => [1, 720]] as $k => [$min, $max]) {
                $v = trim((string) ($in[$p][$k] ?? ''));
                if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                    $errors[] = ucfirst($p) . ': ' . $k . ' hours must be a whole number from ' . $min . ' to ' . $max . '.';
                }
                $row[$k] = (int) $v;
            }
            if ($errors === [] && $row['resolution'] < $row['first']) {
                $errors[] = ucfirst($p) . ': the resolution time cannot be shorter than the first response time.';
            }
            $clean[$p] = $row;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = array_map(static fn (array $r): array => [(int) $r['first_response_hours'], (int) $r['resolution_hours'], (int) $r['escalate_after_hours']], self::policies());
        Database::transaction(function () use ($actorId, $clean, $before): void {
            foreach ($clean as $p => $r) {
                Database::run('UPDATE sla_policies SET first_response_hours = ?, resolution_hours = ?, escalate_after_hours = ?, updated_at = UTC_TIMESTAMP() WHERE priority = ?', [$r['first'], $r['resolution'], $r['escalate'], $p]);
            }
            Audit::record('sla.policy_changed', 'sla_policy', null, $before, array_map(static fn (array $r): array => [$r['first'], $r['resolution'], $r['escalate']], $clean), [], $actorId);
        });
    }

    /** Gives every submitted ticket its due times (once). @return int tickets stamped */
    public static function stampPending(): int
    {
        return Database::run(
            "UPDATE tickets t JOIN sla_policies p ON p.priority = t.priority
                SET t.first_response_due_at = DATE_ADD(t.submitted_at, INTERVAL p.first_response_hours HOUR), t.resolution_due_at = DATE_ADD(t.submitted_at, INTERVAL p.resolution_hours HOUR)
              WHERE t.submitted_at IS NOT NULL AND t.first_response_due_at IS NULL AND t.status <> 'draft'",
        )->rowCount();
    }

    /**
     * @param array<string,mixed> $t a ticket row
     * @return array{first:array{due:?string,state:string,minutes:?int},resolution:array{due:?string,state:string,minutes:?int}} state: met|pending|late|none
     */
    public static function timers(array $t): array
    {
        $now = time();
        $mk = static function (?string $due, ?string $doneAt, bool $over) use ($now): array {
            if ($due === null) {
                return ['due' => null, 'state' => 'none', 'minutes' => null];
            }
            $dueTs = strtotime($due . ' UTC');
            if ($doneAt !== null) {
                return ['due' => $due, 'state' => strtotime($doneAt . ' UTC') <= $dueTs ? 'met' : 'late', 'minutes' => null];
            }

            return ['due' => $due, 'state' => $now > $dueTs ? 'late' : 'pending', 'minutes' => (int) round(($dueTs - $now) / 60)];
        };

        return [
            'first' => $mk($t['first_response_due_at'] ?? null, $t['first_staff_response_at'] ?? null, false),
            'resolution' => $mk($t['resolution_due_at'] ?? null, $t['resolved_at'] ?? ($t['closed_at'] ?? null), false),
        ];
    }

    /** Marks breaches, notifies and escalates. @return array{breached:int,escalated:int} */
    public static function check(?string $nowUtc = null): array
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');
        self::stampPending();
        $out = ['breached' => 0, 'escalated' => 0];
        $open = self::OPEN;
        $rows = Database::all(
            "SELECT t.*, p.escalate_after_hours FROM tickets t JOIN sla_policies p ON p.priority = t.priority
              WHERE t.status NOT IN {$open} AND t.sla_breached_at IS NULL
                AND ((t.first_staff_response_at IS NULL AND t.first_response_due_at < ?) OR (t.resolved_at IS NULL AND t.resolution_due_at < ?))",
            [$now, $now],
        );
        foreach ($rows as $t) {
            try {
                Database::transaction(function () use ($t, $now, &$out): void {
                    $n = Database::run('UPDATE tickets SET sla_breached_at = ? WHERE id = ? AND sla_breached_at IS NULL', [$now, $t['id']])->rowCount();
                    if ($n !== 1) {
                        return;
                    }
                    $what = $t['first_staff_response_at'] === null && $t['first_response_due_at'] < $now ? 'first response' : 'resolution';
                    TicketService::activity((int) $t['id'], null, 'sla_breached', null, null, "SLA missed: {$what} was due " . $t['first_response_due_at'], 'internal');
                    Audit::record('ticket.sla_breached', 'ticket', (int) $t['id'], [], ['what' => $what], [], null);
                    $who = array_values(array_unique(array_filter([$t['assigned_to'] === null ? null : (int) $t['assigned_to'], ...Notifier::withPermission('tickets.assign')])));
                    Notifier::send($who, 'ticket.sla_breached', 'SLA missed: ' . $t['reference'], "The {$what} time for \"{$t['subject']}\" has passed.", '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket', (int) $t['id'], 'sla:' . $t['id'] . ':breach');
                    $out['breached']++;
                });
            } catch (\Throwable $e) {
                Logger::error('SLA breach handling failed', ['ticket' => (int) $t['id'], 'error' => $e->getMessage()]);
            }
        }
        $late = Database::all(
            "SELECT t.*, p.escalate_after_hours FROM tickets t JOIN sla_policies p ON p.priority = t.priority
              WHERE t.status NOT IN {$open} AND t.sla_breached_at IS NOT NULL AND t.escalated_at IS NULL AND TIMESTAMPDIFF(HOUR, t.sla_breached_at, ?) >= p.escalate_after_hours",
            [$now],
        );
        foreach ($late as $t) {
            try {
                Database::transaction(function () use ($t, &$out): void {
                    $n = Database::run("UPDATE tickets SET escalated_at = UTC_TIMESTAMP(), escalated_by = NULL, escalation_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND escalated_at IS NULL", ['Automatic: the SLA was missed and no one acted for ' . $t['escalate_after_hours'] . ' hour(s).', $t['id']])->rowCount();
                    if ($n !== 1) {
                        return;
                    }
                    TicketService::activity((int) $t['id'], null, 'escalated', null, null, 'Automatic escalation after a missed SLA.', 'internal');
                    Audit::record('ticket.escalated', 'ticket', (int) $t['id'], [], ['escalated' => true], ['automatic' => true], null);
                    $admins = array_values(array_unique([...Notifier::withPermission('tickets.assign'), ...Notifier::withPermission('tickets.view_all')]));
                    Notifier::send($admins, 'ticket.escalated', 'Automatically escalated: ' . $t['reference'], "{$t['subject']} missed its SLA.", '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket', (int) $t['id'], "ticket:{$t['id']}:escalated");
                    $out['escalated']++;
                });
            } catch (\Throwable $e) {
                Logger::error('SLA escalation failed', ['ticket' => (int) $t['id'], 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** @return array{total:int,first_met:int,first_late:int,resolved_met:int,resolved_late:int} */
    public static function compliance(string $from, string $to): array
    {
        $r = Database::one(
            "SELECT COUNT(*) total, COALESCE(SUM(first_staff_response_at IS NOT NULL AND first_staff_response_at <= first_response_due_at), 0) first_met,
                    COALESCE(SUM(first_response_due_at IS NOT NULL AND ((first_staff_response_at IS NULL AND first_response_due_at < UTC_TIMESTAMP()) OR first_staff_response_at > first_response_due_at)), 0) first_late,
                    COALESCE(SUM(resolved_at IS NOT NULL AND resolved_at <= resolution_due_at), 0) resolved_met,
                    COALESCE(SUM(resolution_due_at IS NOT NULL AND ((resolved_at IS NULL AND resolution_due_at < UTC_TIMESTAMP()) OR resolved_at > resolution_due_at)), 0) resolved_late
               FROM tickets WHERE submitted_at BETWEEN ? AND ? AND first_response_due_at IS NOT NULL",
            [$from . ' 00:00:00', $to . ' 23:59:59'],
        );

        return ['total' => (int) $r['total'], 'first_met' => (int) $r['first_met'], 'first_late' => (int) $r['first_late'], 'resolved_met' => (int) $r['resolved_met'], 'resolved_late' => (int) $r['resolved_late']];
    }
}
