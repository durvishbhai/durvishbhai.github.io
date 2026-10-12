<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Logger;

/**
 * The scheduled task (`php bin/console.php schedule:run`, meant for cron, see docs/15). It moves renewals along the calendar, sends reminders and releases
 * commission hold periods. It is idempotent: every reminder is claimed in renewal_reminders under a UNIQUE key BEFORE anything is sent, so a second run, an
 * overlapping run or a run after a missed day cannot remind twice. Each run is recorded in scheduler_runs so the dashboards can show when it last ran:
 * nothing in the product says reminders are running unless this task has really run.
 */
final class RenewalScheduler
{
    public const JOB = 'daily';

    /** @return array{ran:bool,advanced:int,reminders:int,commission_releases:int,errors:int} */
    public static function run(?string $today = null): array
    {
        $today ??= AppTime::today();
        $summary = ['ran' => false, 'advanced' => 0, 'reminders' => 0, 'commission_releases' => 0, 'errors' => 0];
        // One run at a time. GET_LOCK is released when the connection closes, so a crashed run cannot block the next one.
        $lock = Database::one("SELECT GET_LOCK('vertex_scheduler', 0) AS got")['got'] ?? 0;
        if ((int) $lock !== 1) {
            return $summary;
        }
        try {
            Database::run('INSERT INTO scheduler_runs (job, started_at, ok) VALUES (?, UTC_TIMESTAMP(), 0)', [self::JOB]);
            $runId = Database::insertId();
            $summary['ran'] = true;
            foreach (Database::all("SELECT id FROM renewal_services WHERE status <> 'cancelled' ORDER BY id") as $row) {
                try {
                    $summary['advanced'] += count(RenewalService::advance((int) $row['id'], $today, null));
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Logger::error('Renewal advance failed', ['service_id' => (int) $row['id'], 'error' => $e->getMessage()]);
                }
            }
            foreach (Database::all("SELECT s.*, c.user_id AS client_user_id FROM renewal_services s JOIN clients c ON c.id = s.client_id WHERE s.status NOT IN ('cancelled','on_hold') ORDER BY s.id") as $s) {
                try {
                    $summary['reminders'] += self::remind($s, $today);
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Logger::error('Renewal reminder failed', ['service_id' => (int) $s['id'], 'error' => $e->getMessage()]);
                }
            }
            try {
                foreach (Database::all("SELECT DISTINCT partner_id FROM commission_entries WHERE status = 'approved' AND payable_after IS NOT NULL AND payable_after <= ?", [$today]) as $p) {
                    Database::transaction(static fn () => CommissionService::releaseDue((int) $p['partner_id']));
                    $summary['commission_releases']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Logger::error('Commission release failed', ['error' => $e->getMessage()]);
            }
            Database::run('UPDATE scheduler_runs SET finished_at = UTC_TIMESTAMP(), ok = ?, summary = ? WHERE id = ?', [$summary['errors'] === 0 ? 1 : 0, json_encode($summary), $runId]);
        } finally {
            Database::run("SELECT RELEASE_LOCK('vertex_scheduler')");
        }

        return $summary;
    }

    /** @return array{at:?string,ok:bool,hours:?int,summary:?string} */
    public static function lastRun(): array
    {
        $r = Database::one('SELECT started_at, ok, summary, TIMESTAMPDIFF(HOUR, started_at, UTC_TIMESTAMP()) AS hours FROM scheduler_runs WHERE job = ? ORDER BY id DESC LIMIT 1', [self::JOB]);

        return $r === null ? ['at' => null, 'ok' => false, 'hours' => null, 'summary' => null] : ['at' => (string) $r['started_at'], 'ok' => (int) $r['ok'] === 1, 'hours' => (int) $r['hours'], 'summary' => $r['summary']];
    }

    /** True when the task has run recently enough (within 36 hours) for the dashboards to say reminders are active. */
    public static function healthy(): bool
    {
        $r = self::lastRun();

        return $r['at'] !== null && $r['hours'] !== null && $r['hours'] <= 36;
    }

    // ------------------------------------------------------------------------------------------------ reminders

    /** @param array<string,mixed> $s service row with client_user_id @return int reminders created */
    private static function remind(array $s, string $today): int
    {
        $days = (int) ((strtotime($s['expires_on']) - strtotime($today)) / 86400);
        $lead = (array) Config::get('renewals.lead');
        $n = 0;

        // 1. the expiry countdown: only the smallest offset reached and not yet sent
        $offsets = (array) Config::get('renewals.reminder_days');
        sort($offsets);
        $offset = null;
        foreach ($offsets as $o) {
            if ($days <= $o) {
                $offset = (int) $o;
                break;
            }
        }
        if ($offset !== null) {
            $kind = $days < 0 ? 'expired' : 'expiry';
            $text = $days < 0 ? 'has expired' : ($days === 0 ? 'expires today' : "expires in {$days} day" . ($days === 1 ? '' : 's'));
            $title = $s['identifier'] . ' ' . $text;
            $n += self::staff($s, $kind, $offset, 'renewal.expiry', $title, 'Renewal ' . $s['reference'] . ' · ' . RenewalService::label($s['status']) . '. Expiry ' . $s['expires_on'] . '.', ['renewals.manage']);
            if ($days < 0) {
                $n += self::staff($s, 'expired_admin', 0, 'renewal.expired', $title, 'Decide whether to renew it or cancel the service.', ['renewals.approve']);
            }
            if ((int) $s['client_reminders'] === 1 && in_array($s['status'], (array) Config::get('renewals.client_reminder_statuses'), true) && $s['client_user_id']) {
                $n += self::client($s, $offset, $days, $text);
            }
        }
        // 2. workflow nudges, once per cycle
        if ($s['status'] === 'awaiting_admin_review' && $days <= 3) {
            $n += self::staff($s, 'approval_pending', 3, 'renewal.approval_pending', 'Approval pending: ' . $s['identifier'], 'Expires ' . $s['expires_on'] . '.', ['renewals.approve']);
        }
        if ($s['status'] === 'approved_for_renewal' && $days <= (int) $lead['invoice_due_days']) {
            $n += self::staff($s, 'invoice_due', (int) $lead['invoice_due_days'], 'renewal.invoice_due', 'Invoice still to be recorded: ' . $s['identifier'], 'Expires ' . $s['expires_on'] . '.', ['renewals.manage']);
        }
        if (in_array($s['status'], ['payment_received', 'renewal_in_progress'], true) && $days <= (int) $lead['confirmation_due_days']) {
            $n += self::staff($s, 'confirmation_outstanding', (int) $lead['confirmation_due_days'], 'renewal.confirmation_outstanding', 'Provider confirmation outstanding: ' . $s['identifier'], 'Paid, but not yet confirmed by the provider. Expires ' . $s['expires_on'] . '.', ['renewals.manage', 'renewals.confirm_provider']);
        }

        return $n;
    }

    /** @param list<string> $permissions */
    private static function staff(array $s, string $kind, int $offset, string $event, string $title, string $body, array $permissions): int
    {
        return Database::transaction(function () use ($s, $kind, $offset, $event, $title, $body, $permissions): int {
            if (!self::claim($s, $kind, $offset, 'staff', 'in_app', 'created')) {
                return 0;
            }
            $ids = [];
            foreach ($permissions as $p) {
                array_push($ids, ...Notifier::withPermission($p));
            }
            $sent = Notifier::send($ids, $event, $title, $body, '/renewals/' . $s['public_id'], null, 'renewal_service', (int) $s['id'], "rnw-rem:{$s['id']}:{$s['expires_on']}:{$kind}:{$offset}");
            Database::run("UPDATE renewal_reminders SET recipients = ?, updated_at = UTC_TIMESTAMP() WHERE service_id = ? AND expires_on = ? AND kind = ? AND offset_days = ? AND audience = 'staff' AND channel = 'in_app'", [$sent, $s['id'], $s['expires_on'], $kind, $offset]);

            return 1;
        });
    }

    /** Client reminder: in the portal always; by email only when enabled, configured and the address exists. Generic wording: never a price that was not approved. */
    private static function client(array $s, int $offset, int $days, string $text): int
    {
        $type = strtolower((string) Config::get("renewals.types.{$s['service_type']}"));
        $n = 0;
        $n += Database::transaction(function () use ($s, $offset, $text, $type): int {
            if (!self::claim($s, 'expiry', $offset, 'client', 'in_app', 'created')) {
                return 0;
            }
            $sent = Notifier::send([(int) $s['client_user_id']], 'renewal.client_reminder', 'Your ' . $type . ' ' . $s['identifier'] . ' ' . $text, 'Our team will contact you about renewing it. You can see its status in the portal.', '/my/services/' . $s['public_id'], null, 'renewal_service', (int) $s['id'], "rnw-rem-c:{$s['id']}:{$s['expires_on']}:{$offset}");
            Database::run("UPDATE renewal_reminders SET recipients = ?, updated_at = UTC_TIMESTAMP() WHERE service_id = ? AND expires_on = ? AND kind = 'expiry' AND offset_days = ? AND audience = 'client' AND channel = 'in_app'", [$sent, $s['id'], $s['expires_on'], $offset]);

            return 1;
        });
        if ((bool) Config::get('renewals.client_email')) {
            $n += self::clientEmail($s, $offset, $type, $text);
        }

        return $n;
    }

    private static function clientEmail(array $s, int $offset, string $type, string $text): int
    {
        $user = Database::one("SELECT name, email FROM users WHERE id = ? AND status = 'active'", [$s['client_user_id']]);
        if ($user === null) {
            return 0;
        }
        $row = Database::one("SELECT id, result, attempts FROM renewal_reminders WHERE service_id = ? AND expires_on = ? AND kind = 'expiry' AND offset_days = ? AND audience = 'client' AND channel = 'email'", [$s['id'], $s['expires_on'], $offset]);
        if ($row !== null && ($row['result'] !== 'failed' || (int) $row['attempts'] >= (int) Config::get('renewals.email_max_attempts'))) {
            return 0;
        }
        $result = 'not_configured';
        if (Mailer::enabled()) {
            $result = Mailer::send((string) $user['email'], "Your {$type} {$s['identifier']} {$text}", "Hello {$user['name']},\n\nYour {$type} {$s['identifier']} {$text} (expiry date {$s['expires_on']}).\nOur team will contact you about renewing it. You can follow its status after signing in to the client portal.\n\nVertex Integrated Solutions\n") ? 'sent' : 'failed';
        }
        if ($row === null) {
            self::claim($s, 'expiry', $offset, 'client', 'email', $result);
        } else {
            Database::run('UPDATE renewal_reminders SET result = ?, attempts = attempts + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$result, $row['id']]);
        }

        return $result === 'sent' ? 1 : 0;
    }

    /** Claims a reminder slot. False when it was already claimed (so nothing may be sent). */
    private static function claim(array $s, string $kind, int $offset, string $audience, string $channel, string $result): bool
    {
        return Database::run('INSERT IGNORE INTO renewal_reminders (service_id, expires_on, kind, offset_days, audience, channel, result, attempts, recipients, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$s['id'], $s['expires_on'], $kind, $offset, $audience, $channel, $result])->rowCount() === 1;
    }
}
