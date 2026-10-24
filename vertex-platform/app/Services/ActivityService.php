<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * "Who did what": a readable view over the append-only audit log, per account and across all accounts, for the Owner (`activity.view_all`) and for a person's
 * own account. Read-only: it never writes, and the audit rows are the single source of truth.
 */
final class ActivityService
{
    /** key => [label, action prefixes] */
    public const CATEGORIES = [
        'signin' => ['Sign-ins and security', ['auth.', 'security.']],
        'account' => ['Accounts and roles', ['user.', 'role.']],
        'money' => ['Money', ['invoice.', 'payment', 'client_payment.', 'receipt.', 'credit_note.', 'payout.', 'commission.', 'gateway.', 'billing.', 'renewal.', 'quotation.', 'coupon.', 'campaign.']],
        'work' => ['Projects and delivery', ['project.', 'task.', 'milestone.', 'deliverable.', 'revision.', 'change_request.', 'scope_request.', 'checklist.', 'attachment.', 'warranty.', 'agreement.', 'pricing.', 'ticket.', 'sla.']],
        'crm' => ['Clients, enquiries and partners', ['enquiry.', 'client.', 'referral.', 'partner.', 'career.', 'crm.']],
        'website' => ['Website and forms', ['cms.', 'site.', 'form.']],
        'system' => ['System and settings', ['settings.', 'data.', 'backup.', 'recycle.', 'audit.', 'automation.', 'ai.']],
    ];

    private const LABELS = [
        'auth.login.success' => 'Signed in', 'auth.login.failed' => 'Failed sign-in attempt', 'auth.login.locked' => 'Sign-in blocked (too many attempts)', 'auth.login.blocked' => 'Sign-in refused (account not active)',
        'auth.login.password_ok_2fa_pending' => 'Password accepted, waiting for the verification code', 'auth.logout' => 'Signed out', 'auth.google.linked' => 'Google account linked',
        'auth.password_reset.requested' => 'Asked for a password reset', 'auth.password_reset.completed' => 'Reset the password', 'auth.2fa.failed' => 'Wrong verification code', 'auth.2fa.locked' => 'Verification locked (too many wrong codes)',
        'auth.2fa.disabled' => 'Turned two-factor login off', 'auth.2fa.reset_by_admin' => 'Two-factor login reset by an administrator', 'auth.2fa.recovery_used' => 'Used a recovery code', 'auth.register.duplicate' => 'Sign-up with an existing email',
        'user.registered' => 'Signed up', 'user.registration_approved' => 'Account approved', 'user.password_changed' => 'Changed the password', 'user.deactivated' => 'Account deactivated', 'user.reactivated' => 'Account reactivated',
        'user.roles_changed' => 'Roles changed', 'user.created' => 'Account created', 'user.archived' => 'Account archived',
    ];

    /** A readable sentence-style label for an audit action: "invoice.issued" => "Invoice issued". */
    public static function label(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }
        $t = str_replace(['_', '.'], ' ', $action);

        return ucfirst(trim($t));
    }

    public static function categoryOf(string $action): string
    {
        foreach (self::CATEGORIES as $key => [, $prefixes]) {
            foreach ($prefixes as $p) {
                if (str_starts_with($action, $p)) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /** @return array{signed_in_24h:int,failed_24h:int,actions_24h:int,accounts:array<string,int>,total_accounts:int} */
    public static function overview(): array
    {
        $one = static fn (string $sql, array $p = []): int => (int) (Database::one($sql, $p)['c'] ?? 0);
        $accounts = [];
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM users GROUP BY status') as $r) {
            $accounts[(string) $r['status']] = (int) $r['c'];
        }

        return [
            'signed_in_24h' => $one("SELECT COUNT(DISTINCT actor_id) AS c FROM audit_logs WHERE action = 'auth.login.success' AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"),
            'failed_24h' => $one("SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('auth.login.failed','auth.login.locked','auth.2fa.failed') AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"),
            'actions_24h' => $one('SELECT COUNT(*) AS c FROM audit_logs WHERE created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)'),
            'accounts' => $accounts,
            'total_accounts' => array_sum($accounts),
        ];
    }

    /**
     * Every account with its last sign-in and how active it was in the last 30 days.
     *
     * @param array{q?:string,group?:string,status?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function accounts(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like);
        }
        $group = $f['group'] ?? '';
        $roleExists = static fn (string $cond): string => "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND {$cond})";
        if ($group === 'clients') {
            $where[] = $roleExists("r.slug = 'client'");
        } elseif ($group === 'partners') {
            $where[] = $roleExists("r.slug = 'referral_partner'");
        } elseif ($group === 'team') {
            $where[] = $roleExists("r.slug NOT IN ('client','referral_partner')");
        }
        if (in_array($f['status'] ?? '', ['active', 'pending', 'deactivated', 'suspended', 'archived'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $f['status'];
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM users u WHERE {$w}", $params)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT u.id, u.public_id, u.name, u.email, u.status, u.created_at, u.last_login_at, u.last_login_ip, u.totp_enabled_at,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM audit_logs a WHERE a.actor_id = u.id AND a.action = 'auth.login.success' AND a.created_at > (UTC_TIMESTAMP() - INTERVAL 30 DAY)) AS signins_30d,
                    (SELECT COUNT(*) FROM audit_logs a WHERE a.actor_id = u.id AND a.created_at > (UTC_TIMESTAMP() - INTERVAL 30 DAY)) AS actions_30d
               FROM users u WHERE {$w} ORDER BY u.last_login_at IS NULL, u.last_login_at DESC, u.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function account(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(
            "SELECT u.id, u.public_id, u.name, u.email, u.phone, u.status, u.created_at, u.last_login_at, u.last_login_ip, u.totp_enabled_at, u.self_registered, u.google_sub IS NOT NULL AS has_google,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
               FROM users u WHERE u.public_id = ?",
            [$publicId],
        );
    }

    /**
     * Audit rows, newest first.
     *
     * @param array{user?:int,direction?:string,category?:string,from?:string,to?:string,q?:string} $f direction: by (the account did it) | to (done to the account) | both. Dates are UTC 'Y-m-d H:i:s'.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function feed(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        $uid = (int) ($f['user'] ?? 0);
        if ($uid > 0) {
            $dir = $f['direction'] ?? 'both';
            $by = 'a.actor_id = ?';
            $to = "(a.entity_type = 'user' AND a.entity_id = ?)";
            if ($dir === 'by') {
                $where[] = $by;
                $params[] = $uid;
            } elseif ($dir === 'to') {
                $where[] = $to . ' AND (a.actor_id IS NULL OR a.actor_id <> ?)';
                array_push($params, $uid, $uid);
            } else {
                $where[] = "({$by} OR {$to})";
                array_push($params, $uid, $uid);
            }
        }
        $cat = $f['category'] ?? '';
        if (isset(self::CATEGORIES[$cat])) {
            $or = [];
            foreach (self::CATEGORIES[$cat][1] as $p) {
                $or[] = 'a.action LIKE ?';
                $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $p) . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR a.action LIKE ? OR a.ip = ?)';
            array_push($params, $like, $like, $like, $f['q']);
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = 'a.created_at < ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE {$w}", $params)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.meta_json, a.ip, a.user_agent, a.request_id,
                    a.actor_id, u.public_id AS actor_public, u.name AS actor_name, u.email AS actor_email,
                    t.public_id AS target_public, t.name AS target_name
               FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id LEFT JOIN users t ON (a.entity_type = 'user' AND t.id = a.entity_id)
              WHERE {$w} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );
        foreach ($rows as &$r) {
            $r['label'] = self::label((string) $r['action']);
            $r['category'] = self::categoryOf((string) $r['action']);
            $r['device'] = $r['user_agent'] ? EmailTemplate::browser((string) $r['user_agent']) : '';
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
