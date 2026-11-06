<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Logger;

/**
 * Sends an email copy of each in-app notification, in the standard KIS look (EmailTemplate), through the one mail system (Mailer).
 *
 * Notifier::send() calls enqueue() for every notification row it really creates (a repeated event is ignored by the database, so it is never emailed twice).
 * The queue is emptied after the response is sent (web) or by flush() / the scheduled task (command line), so a slow mail server never slows a payment
 * or a form, and a notification whose transaction was rolled back (no row) sends nothing. `email_status` and `email_attempts` on the row record the outcome;
 * failed rows are retried by retryFailed() up to `notifications.max_attempts`.
 *
 * The text of a client email is the notification text, which is already written for its recipient. Details cards add facts read from the stored record
 * (never a gateway secret, a card number or an internal note).
 */
final class EmailChannel
{
    /** @var list<int> */
    private static array $queue = [];
    /** @var list<callable> */
    private static array $later = [];
    private static bool $hooked = false;

    public static function enqueue(int $notificationId): void
    {
        if ($notificationId <= 0) {
            return;
        }
        self::$queue[] = $notificationId;
        self::hook();
    }

    /**
     * Runs $send after the response has been delivered (so a slow mail server never delays the person). On the command line it runs at once.
     * A failure inside it is logged and never reaches the workflow that asked.
     */
    public static function afterResponse(callable $send): void
    {
        if (PHP_SAPI === 'cli') {
            self::runLater($send);

            return;
        }
        self::$later[] = $send;
        self::hook();
    }

    private static function hook(): void
    {
        if (!self::$hooked && PHP_SAPI !== 'cli') {
            self::$hooked = true;
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                $later = self::$later;
                self::$later = [];
                foreach ($later as $fn) {
                    self::runLater($fn);
                }
                self::flush();
            });
        }
    }

    private static function runLater(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Logger::error('Deferred email failed', ['error' => $e->getMessage()]);
        }
    }

    /** Sends everything queued in this process. @return int emails sent */
    public static function flush(): int
    {
        $ids = array_values(array_unique(self::$queue));
        self::$queue = [];
        $sent = 0;
        foreach ($ids as $id) {
            try {
                $sent += self::deliver($id) ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::error('Notification email failed', ['notification_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Tries again the recent notifications whose email failed. @return int emails sent */
    public static function retryFailed(int $limit = 50): int
    {
        $max = (int) Config::get('notifications.max_attempts', 3);
        $rows = Database::all("SELECT id FROM notifications WHERE email_status = 'failed' AND email_attempts < ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 3 DAY) ORDER BY id LIMIT " . max(1, $limit), [$max]);
        $sent = 0;
        foreach ($rows as $r) {
            try {
                $sent += self::deliver((int) $r['id']) ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::error('Notification email retry failed', ['notification_id' => (int) $r['id'], 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** True when an email was sent now. A missing row, a switched-off audience, a skipped event or an already-sent row sends nothing. */
    public static function deliver(int $id): bool
    {
        if (!Mailer::enabled()) {
            return false;
        }
        $n = Database::one('SELECT n.*, u.name AS user_name, u.email AS user_email, u.status AS user_status FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.id = ?', [$id]);
        if ($n === null || $n['email_status'] === 'sent' || $n['user_status'] !== 'active' || in_array((string) $n['event_type'], (array) Config::get('notifications.skip', []), true)) {
            return false;
        }
        $audience = self::audience((int) $n['user_id']);
        if (!(bool) Config::get($audience === 'staff' ? 'notifications.email_staff' : 'notifications.email_clients', true)) {
            return false;
        }
        $to = (string) $n['user_email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        // Claim this attempt; a second process holding the same row loses the race and sends nothing.
        $claimed = Database::run("UPDATE notifications SET email_attempts = email_attempts + 1 WHERE id = ? AND email_status <> 'sent' AND email_attempts = ?", [$id, (int) $n['email_attempts']])->rowCount();
        if ($claimed !== 1) {
            return false;
        }
        $ok = Mailer::sendTemplate($to, self::spec($n, $audience));
        Database::run($ok ? "UPDATE notifications SET email_status = 'sent', email_sent_at = UTC_TIMESTAMP() WHERE id = ?" : "UPDATE notifications SET email_status = 'failed' WHERE id = ?", [$id]);

        return $ok;
    }

    /** client | partner | staff, from what the account is. */
    public static function audience(int $userId): string
    {
        if (Database::one('SELECT 1 AS x FROM clients WHERE user_id = ? LIMIT 1', [$userId]) !== null) {
            return 'client';
        }
        if (Database::one('SELECT 1 AS x FROM referral_partners WHERE user_id = ? LIMIT 1', [$userId]) !== null) {
            return 'partner';
        }

        return 'staff';
    }

    /**
     * The email for one notification row.
     *
     * @param array<string,mixed> $n
     * @return array<string,mixed>
     */
    public static function spec(array $n, string $audience): array
    {
        $event = (string) $n['event_type'];
        [$category, $tone, $button] = self::style($event);
        $title = trim((string) $n['title']);
        $spec = [
            'subject' => $title, 'category' => $category, 'tone' => $tone, 'heading' => $title, 'name' => (string) $n['user_name'],
            'paragraphs' => $n['body'] === null || trim((string) $n['body']) === '' ? [] : [trim((string) $n['body'])],
            'details' => self::details($n),
        ];
        $link = (string) ($n['link'] ?? '');
        if ($link !== '' && $link[0] === '/') {
            try {
                $spec['button'] = ['label' => $button, 'url' => AccountMail::link($link)];
            } catch (\Throwable) {
                // no APP_URL: send without the button rather than a broken link
            }
        }
        $spec['notes'] = $audience === 'staff'
            ? ['You are receiving this because your team role covers this area. The same notice is under Notifications in the portal.']
            : ['If you did not expect this, or have a question, reply to this email or contact us using the details below.'];

        return $spec;
    }

    /** @return array{0:string,1:string,2:string} category label, tone, button label */
    private static function style(string $event): array
    {
        $e = (array) Config::get("notifications.events.{$event}", []);
        $cat = (array) Config::get('notifications.categories.' . explode('.', $event)[0], ['Notification', 'info']);

        return [(string) ($e['category'] ?? $cat[0]), (string) ($e['tone'] ?? $cat[1]), (string) ($e['button'] ?? 'View details')];
    }

    /**
     * Facts about the record the notification is about. Only plain facts the recipient may see; any lookup problem simply leaves the card out.
     *
     * @param array<string,mixed> $n
     * @return array<string,string>
     */
    private static function details(array $n): array
    {
        $id = (int) ($n['subject_id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        try {
            switch ((string) $n['subject_type']) {
                case 'client_payment':
                    $r = Database::one('SELECT cp.reference, cp.gross_minor, cp.currency, cp.method, cp.external_ref, cp.received_on, cp.status, i.reference AS invoice_ref, p.code AS project_code
                                          FROM client_payments cp LEFT JOIN invoices i ON i.id = cp.invoice_id LEFT JOIN projects p ON p.id = COALESCE(cp.project_id, i.project_id) WHERE cp.id = ?', [$id]);

                    return $r === null ? [] : [
                        'Payment reference' => (string) $r['reference'], 'Invoice' => (string) ($r['invoice_ref'] ?? ''), 'Project' => (string) ($r['project_code'] ?? ''),
                        'Amount' => EmailTemplate::money((int) $r['gross_minor'], (string) $r['currency']), 'Payment method' => self::method((string) $r['method']),
                        'Transaction ID' => (string) $r['external_ref'], 'Date' => EmailTemplate::date((string) $r['received_on']),
                        'Status' => ['verified' => 'Confirmed', 'rejected' => 'Not confirmed', 'pending_verification' => 'Waiting for confirmation', 'reversed' => 'Reversed'][(string) $r['status']] ?? '',
                    ];
                case 'receipt':
                    $r = Database::one('SELECT number, paid_minor, method, txn_id, paid_at, time_known, against, status FROM receipts WHERE id = ?', [$id]);

                    return $r === null ? [] : [
                        'Receipt number' => (string) $r['number'], 'Amount paid' => EmailTemplate::money((int) $r['paid_minor']), 'Payment method' => self::method((string) $r['method']),
                        'Transaction ID' => (string) $r['txn_id'], 'Date' => EmailTemplate::date((string) $r['paid_at'], (bool) $r['time_known']), 'Towards' => (string) ($r['against'] ?? ''),
                        'Status' => $r['status'] === 'valid' ? 'Paid' : 'Cancelled',
                    ];
                case 'invoice':
                    $r = Database::one('SELECT i.reference, i.title, i.total_minor, i.due_date, i.status, p.code AS project_code FROM invoices i LEFT JOIN projects p ON p.id = i.project_id WHERE i.id = ?', [$id]);

                    return $r === null ? [] : [
                        'Invoice number' => (string) $r['reference'], 'For' => (string) $r['title'], 'Project' => (string) ($r['project_code'] ?? ''), 'Amount' => EmailTemplate::money((int) $r['total_minor']),
                        'Due date' => EmailTemplate::date((string) ($r['due_date'] ?? '')),
                        'Status' => ['issued' => 'Awaiting payment', 'partially_paid' => 'Part paid', 'paid' => 'Paid', 'void' => 'Cancelled', 'draft' => 'Draft'][(string) $r['status']] ?? '',
                    ];
                case 'gateway_order':
                    $r = Database::one('SELECT amount_minor, status FROM gateway_orders WHERE id = ?', [$id]);

                    return $r === null ? [] : ['Amount' => EmailTemplate::money((int) $r['amount_minor'])];
                case 'project':
                    $r = Database::one('SELECT code, title, status FROM projects WHERE id = ?', [$id]);

                    return $r === null ? [] : ['Project' => (string) $r['code'], 'Title' => (string) $r['title'], 'Status' => ucfirst(str_replace('_', ' ', (string) $r['status']))];
                case 'renewal_service':
                    $r = Database::one('SELECT identifier, expires_on, service_type FROM renewal_services WHERE id = ?', [$id]);

                    return $r === null ? [] : ['Service' => (string) $r['identifier'], 'Expiry date' => EmailTemplate::date((string) $r['expires_on'])];
            }
        } catch (\Throwable $e) {
            Logger::error('Email details lookup failed', ['subject_type' => (string) ($n['subject_type'] ?? ''), 'error' => $e->getMessage()]);
        }

        return [];
    }

    private static function method(string $code): string
    {
        $all = (array) Config::get('billing.payment_methods', []) + (array) Config::get('renewals.payment_methods', []) + ['razorpay' => 'Razorpay (online)', 'manual_upi' => 'UPI (manual)', 'upi' => 'UPI'];

        return (string) ($all[$code] ?? ucfirst(str_replace('_', ' ', $code)));
    }
}
