<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Module 28: workflow rules (deadline alerts, overdue payments, stalled approvals, ...) with configurable escalation.
 *
 * A rule says: "when THIS has been true for N days/hours, tell THESE people; if it is still true after M more, tell THOSE people". The scheduler checks every
 * rule; each person is told once per item and stage (the notification's dedupe key), so running it twice does nothing. Rules only notify: they never
 * approve, pay, edit or delete anything.
 */
final class AutomationService
{
    /** @return array<string,array{label:string,unit:string,help:string,owner:string}> */
    public static function triggers(): array
    {
        return [
            'task_due_soon' => ['label' => 'A task is due soon', 'unit' => 'days before the due date', 'help' => 'Deadline alert for open tasks.', 'owner' => 'the task assignee'],
            'task_overdue' => ['label' => 'A task is overdue', 'unit' => 'days after the due date', 'help' => 'Open tasks past their due date.', 'owner' => 'the task assignee'],
            'invoice_overdue' => ['label' => 'An invoice is overdue', 'unit' => 'days after the due date', 'help' => 'Issued invoices that are not fully paid.', 'owner' => 'the client'],
            'payment_unverified' => ['label' => 'A payment is waiting to be verified', 'unit' => 'hours since it was recorded', 'help' => 'Recorded or claimed payments nobody has checked against the bank.', 'owner' => 'the person who recorded it'],
            'deliverable_pending_review' => ['label' => 'A deliverable is waiting for review', 'unit' => 'days since it was submitted', 'help' => 'Work waiting for the internal quality review.', 'owner' => 'the uploader'],
            'enquiry_followup_overdue' => ['label' => 'A CRM follow-up is overdue', 'unit' => 'days after the follow-up date', 'help' => 'Open enquiries whose next follow-up date has passed.', 'owner' => 'the enquiry owner'],
            'quotation_expiring' => ['label' => 'A quotation is about to expire', 'unit' => 'days before it expires', 'help' => 'Sent quotations the client has not answered.', 'owner' => 'the person who created it'],
            'agreement_unsigned' => ['label' => 'An agreement is still unsigned', 'unit' => 'days since it was sent', 'help' => 'Online agreements waiting for a signature.', 'owner' => 'the person who created it'],
        ];
    }

    /** Targets: owner (the item's own person), client, permission:<key>, role:<slug>. */
    public static function targetLabel(string $t): string
    {
        return match (true) {
            $t === 'owner' => 'The person responsible for the item',
            $t === 'client' => 'The client',
            str_starts_with($t, 'permission:') => 'Everyone who holds ' . substr($t, 11),
            str_starts_with($t, 'role:') => 'Everyone with the role ' . substr($t, 5),
            default => $t,
        };
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT * FROM automation_rules ORDER BY enabled DESC, id');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM automation_rules WHERE public_id = ?', [$publicId]) : null;
    }

    /** @param array<string,mixed> $in */
    public static function save(int $actorId, ?array $existing, array $in): string
    {
        Gate::authorize('automation.manage', $actorId);
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            $errors[] = 'The rule name must be 3 to 160 characters.';
        }
        $trigger = (string) ($in['trigger_type'] ?? '');
        if (!isset(self::triggers()[$trigger])) {
            $errors[] = 'Choose what the rule watches for.';
        }
        $threshold = trim((string) ($in['threshold'] ?? ''));
        if (!ctype_digit($threshold) || (int) $threshold > 3650 || ($threshold === '0' && !in_array($trigger, ['task_overdue', 'invoice_overdue', 'task_due_soon', 'payment_unverified'], true))) {
            $errors[] = 'The time must be a whole number (0 to 3650).';
        }
        $notify = self::normaliseTarget((string) ($in['notify_target'] ?? ''), $errors, 'Who to tell');
        $esc = trim((string) ($in['escalate_to'] ?? '')) === '' ? null : self::normaliseTarget((string) $in['escalate_to'], $errors, 'Who to escalate to');
        $escAfter = null;
        if ($esc !== null) {
            $v = trim((string) ($in['escalate_after'] ?? ''));
            if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 3650) {
                $errors[] = 'Say how much longer to wait before escalating (1 or more).';
            } else {
                $escAfter = (int) $v;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $enabled = !empty($in['enabled']) ? 1 : 0;

        return Database::transaction(function () use ($actorId, $existing, $name, $trigger, $threshold, $notify, $esc, $escAfter, $enabled): string {
            if ($existing === null) {
                $pub = uuid_hex();
                Database::run('INSERT INTO automation_rules (public_id, name, trigger_type, threshold, notify_target, escalate_to, escalate_after, enabled, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [$pub, $name, $trigger, (int) $threshold, $notify, $esc, $escAfter, $enabled, $actorId]);
                Audit::record('automation.created', 'automation_rule', Database::insertId(), [], ['trigger' => $trigger, 'threshold' => (int) $threshold], [], $actorId);

                return $pub;
            }
            Database::run('UPDATE automation_rules SET name = ?, trigger_type = ?, threshold = ?, notify_target = ?, escalate_to = ?, escalate_after = ?, enabled = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$name, $trigger, (int) $threshold, $notify, $esc, $escAfter, $enabled, $existing['id']]);
            Audit::record('automation.updated', 'automation_rule', (int) $existing['id'], ['trigger' => $existing['trigger_type'], 'threshold' => (int) $existing['threshold'], 'enabled' => (int) $existing['enabled']], ['trigger' => $trigger, 'threshold' => (int) $threshold, 'enabled' => $enabled], [], $actorId);

            return (string) $existing['public_id'];
        });
    }

    public static function delete(int $actorId, array $rule): void
    {
        Gate::authorize('automation.manage', $actorId);
        Database::run('DELETE FROM automation_rules WHERE id = ?', [$rule['id']]);
        Audit::record('automation.deleted', 'automation_rule', (int) $rule['id'], ['name' => $rule['name']], [], [], $actorId);
    }

    /** @param list<string> $errors */
    private static function normaliseTarget(string $t, array &$errors, string $label): string
    {
        $t = trim($t);
        if ($t === 'owner' || $t === 'client') {
            return $t;
        }
        if (str_starts_with($t, 'permission:') && Database::one('SELECT 1 AS x FROM permissions WHERE perm_key = ?', [substr($t, 11)]) !== null) {
            return $t;
        }
        if (str_starts_with($t, 'role:') && RoleService::find(substr($t, 5)) !== null) {
            return $t;
        }
        $errors[] = "{$label}: choose a valid target.";

        return 'owner';
    }

    // ------------------------------------------------------------------------------------------------ running

    /**
     * What currently matches a rule: one row per item with its age in the rule's unit.
     *
     * @return list<array{type:string,id:int,title:string,link:string,project:?int,owner:?int,client:?int,age:int}>
     */
    private static function matches(array $rule, string $today, string $now): array
    {
        $t = (int) $rule['threshold'];
        $esc = $rule['escalate_after'] === null ? 0 : (int) $rule['escalate_after'];
        $reach = $t + $esc; // fetch everything that has reached the first or the escalation stage
        $rows = match ($rule['trigger_type']) {
            'task_due_soon' => Database::all("SELECT t.id, t.title, t.public_id, t.project_id, t.assignee_id AS owner, DATEDIFF(t.due_date, ?) AS age FROM tasks t WHERE t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled') AND t.due_date IS NOT NULL AND DATEDIFF(t.due_date, ?) BETWEEN 0 AND ?", [$today, $today, $t]),
            'task_overdue' => Database::all("SELECT t.id, t.title, t.public_id, t.project_id, t.assignee_id AS owner, DATEDIFF(?, t.due_date) AS age FROM tasks t WHERE t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled') AND t.due_date IS NOT NULL AND DATEDIFF(?, t.due_date) >= ?", [$today, $today, $t]),
            'invoice_overdue' => Database::all("SELECT i.id, CONCAT(i.reference, ' ', i.title) AS title, i.public_id, i.project_id, i.created_by AS owner, c.user_id AS client, DATEDIFF(?, i.due_date) AS age FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('issued','partially_paid') AND i.due_date IS NOT NULL AND DATEDIFF(?, i.due_date) >= ?", [$today, $today, $t]),
            'payment_unverified' => Database::all("SELECT p.id, CONCAT(p.reference, ' ', p.method) AS title, p.public_id, p.project_id, p.recorded_by AS owner, TIMESTAMPDIFF(HOUR, p.created_at, ?) AS age FROM client_payments p WHERE p.status = 'pending_verification' AND TIMESTAMPDIFF(HOUR, p.created_at, ?) >= ?", [$now, $now, $t]),
            'deliverable_pending_review' => Database::all("SELECT d.id, d.title, d.public_id, d.project_id, d.created_by AS owner, DATEDIFF(?, d.updated_at) AS age FROM deliverables d WHERE d.status = 'pending_review' AND d.archived_at IS NULL AND DATEDIFF(?, d.updated_at) >= ?", [$today, $today, $t]),
            'enquiry_followup_overdue' => Database::all("SELECT e.id, CONCAT(e.reference, ' ', e.contact_name) AS title, e.public_id, NULL AS project_id, e.owner_id AS owner, DATEDIFF(?, e.next_followup_date) AS age FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id WHERE e.archived_at IS NULL AND s.kind = 'open' AND e.next_followup_date IS NOT NULL AND DATEDIFF(?, e.next_followup_date) >= ?", [$today, $today, $t]),
            'quotation_expiring' => Database::all("SELECT q.id, CONCAT(q.reference, ' ', q.title) AS title, q.public_id, q.project_id, q.created_by AS owner, c.user_id AS client, DATEDIFF(q.valid_until, ?) AS age FROM quotations q JOIN clients c ON c.id = q.client_id WHERE q.status = 'sent' AND q.valid_until IS NOT NULL AND DATEDIFF(q.valid_until, ?) BETWEEN 0 AND ?", [$today, $today, $t]),
            'agreement_unsigned' => Database::all("SELECT a.id, CONCAT(a.reference, ' ', a.title) AS title, a.public_id, a.project_id, a.created_by AS owner, c.user_id AS client, DATEDIFF(?, a.sent_at) AS age FROM agreements a JOIN clients c ON c.id = a.client_id WHERE a.status IN ('sent','partially_signed') AND a.mode = 'online' AND DATEDIFF(?, a.sent_at) >= ?", [$today, $today, $t]),
            default => [],
        };
        $links = ['task_due_soon' => '/tasks/', 'task_overdue' => '/tasks/', 'invoice_overdue' => '/invoices/', 'payment_unverified' => '/client-payments/', 'deliverable_pending_review' => '/deliverables/', 'enquiry_followup_overdue' => '/crm/enquiries/', 'quotation_expiring' => '/quotations/', 'agreement_unsigned' => '/agreements/'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['type' => (string) $rule['trigger_type'], 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'link' => $links[$rule['trigger_type']] . $r['public_id'], 'project' => $r['project_id'] === null ? null : (int) $r['project_id'],
                'owner' => $r['owner'] === null ? null : (int) $r['owner'], 'client' => isset($r['client']) && $r['client'] !== null ? (int) $r['client'] : null, 'age' => (int) $r['age']];
        }

        return $out;
    }

    /** @return list<int> */
    private static function recipients(string $target, array $item): array
    {
        if ($target === 'owner') {
            return $item['owner'] === null ? [] : [$item['owner']];
        }
        if ($target === 'client') {
            return $item['client'] === null ? [] : [$item['client']];
        }
        if (str_starts_with($target, 'permission:')) {
            return Notifier::withPermission(substr($target, 11));
        }
        if (str_starts_with($target, 'role:')) {
            return array_map(static fn (array $r): int => (int) $r['id'], Database::all("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = ? AND u.status = 'active'", [substr($target, 5)]));
        }

        return [];
    }

    /** Runs every enabled rule. @return array{rules:int,notified:int,errors:int} */
    public static function run(?string $today = null): array
    {
        $today ??= AppTime::today();
        $now = $today === AppTime::today() ? gmdate('Y-m-d H:i:s') : $today . ' 12:00:00';
        $sum = ['rules' => 0, 'notified' => 0, 'errors' => 0];
        $triggers = self::triggers();
        foreach (Database::all('SELECT * FROM automation_rules WHERE enabled = 1 ORDER BY id') as $rule) {
            try {
                $sum['rules']++;
                $count = 0;
                $items = self::matches($rule, $today, $now);
                foreach ($items as $item) {
                    $stage2 = $rule['escalate_to'] !== null && $rule['escalate_after'] !== null && $item['age'] >= (int) $rule['threshold'] + (int) $rule['escalate_after'] && !in_array($rule['trigger_type'], ['task_due_soon', 'quotation_expiring'], true);
                    $label = $triggers[$rule['trigger_type']]['label'];
                    $title = ($stage2 ? 'Escalation: ' : '') . $label . ': ' . $item['title'];
                    $count += Notifier::send(self::recipients((string) $rule['notify_target'], $item), 'automation.' . $rule['trigger_type'], $title, 'Rule: ' . $rule['name'], $item['link'], $item['project'], $item['type'], $item['id'], 'auto:' . $rule['id'] . ':' . $item['id'] . ':1');
                    if ($stage2) {
                        $count += Notifier::send(self::recipients((string) $rule['escalate_to'], $item), 'automation.' . $rule['trigger_type'], $title, 'Escalated by rule: ' . $rule['name'], $item['link'], $item['project'], $item['type'], $item['id'], 'auto:' . $rule['id'] . ':' . $item['id'] . ':2');
                    }
                }
                Database::run('UPDATE automation_rules SET last_run_at = UTC_TIMESTAMP(), last_count = ? WHERE id = ?', [count($items), $rule['id']]);
                $sum['notified'] += $count;
            } catch (\Throwable $e) {
                $sum['errors']++;
                Logger::error('Automation rule failed', ['rule' => (int) $rule['id'], 'error' => $e->getMessage()]);
            }
        }

        return $sum;
    }
}
