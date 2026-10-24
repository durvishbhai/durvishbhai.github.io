<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The approved Referral Partner behind a login. Every partner page and service call starts here: the partner is always derived from the SIGNED-IN
 * user, never from a request parameter, so one partner can never address another's records.
 */
final class PartnerService
{
    /** @return array<string,mixed>|null the partner row of this user (any status) */
    public static function forUser(int $userId): ?array
    {
        return Database::one(
            "SELECT p.*, u.name, u.email FROM referral_partners p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? AND u.status = 'active'",
            [$userId],
        );
    }

    /** @return array<string,mixed> an ACTIVE partner or HTTP 404 (not a partner) / 403 (suspended) */
    public static function require(int $userId, string $permission = 'referrals.view_own'): array
    {
        $p = Gate::can($permission, $userId) ? self::forUser($userId) : null;
        if ($p === null) {
            throw new HttpException(404);
        }
        if ($p['status'] !== 'active') {
            throw new HttpException(403, 'Your partner account is suspended. Please contact us.');
        }

        return $p;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT p.*, u.name, u.email, u.phone FROM referral_partners p JOIN users u ON u.id = p.user_id WHERE p.public_id = ?',
            [$publicId],
        ) : null;
    }

    /**
     * @param array{q?:string,status?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::canAny(['referrals.review', 'commissions.view_all', 'payouts.view_all'], $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(p.reference LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['active', 'suspended'], true)) {
            $w[] = 'p.status = ?';
            $p[] = $f['status'];
        }
        $cond = implode(' AND ', $w);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM referral_partners p JOIN users u ON u.id = p.user_id WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT p.public_id, p.reference, p.status, p.created_at, u.name, u.email,
                    (SELECT COUNT(*) FROM partner_referrals r WHERE r.partner_id = p.id) AS referrals
               FROM referral_partners p JOIN users u ON u.id = p.user_id WHERE {$cond} ORDER BY p.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array<string,mixed> $partner */
    public static function setSuspended(int $actorId, array $partner, bool $suspend, string $reason): void
    {
        Gate::authorize('referrals.decide', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        Database::transaction(function () use ($actorId, $partner, $suspend, $reason): void {
            $cur = Database::one('SELECT status FROM referral_partners WHERE id = ? FOR UPDATE', [$partner['id']]);
            $to = $suspend ? 'suspended' : 'active';
            if ($cur === null || $cur['status'] === $to) {
                return;
            }
            Database::run('UPDATE referral_partners SET status = ?, suspended_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $suspend ? $reason : null, $partner['id']]);
            if (!empty($partner['application_id'])) {
                Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('application', ?, ?, ?, UTC_TIMESTAMP())", [(int) $partner['application_id'], $actorId, ($suspend ? 'Partner suspended: ' : 'Partner reactivated: ') . $reason]);
            }
            Audit::record($suspend ? 'partner.suspended' : 'partner.reactivated', 'referral_partner', (int) $partner['id'], ['status' => $cur['status']], ['status' => $to], ['reason_recorded' => true], $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ payout details

    /**
     * Stores the partner's payout details ENCRYPTED. Only a masked hint is readable afterwards. The caller must have verified the account password.
     *
     * @param array<string,mixed> $partner
     * @param array<string,mixed> $in method (bank_transfer|upi|other), account_name, identifier, bank_name
     */
    public static function saveDetails(int $userId, array $partner, array $in): void
    {
        $p = self::require($userId, 'payouts.request');
        if ((int) $p['id'] !== (int) $partner['id']) {
            throw new HttpException(404);
        }
        $method = (string) FieldRules::choice($in['method'] ?? '', ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'other' => 'Other'], 'payment method');
        $name = (string) FieldRules::line($in['account_name'] ?? '', 'Account holder name', 2, 120);
        $identifier = (string) FieldRules::line($in['identifier'] ?? '', $method === 'upi' ? 'UPI ID' : 'Account number or identifier', 4, 80);
        $bank = FieldRules::line($in['bank_name'] ?? '', 'Bank name and branch / IFSC', 2, 160, $method === 'bank_transfer');
        if (!RateLimiter::hit('partner_details_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $sealed = SecretBox::seal(json_encode(['method' => $method, 'name' => $name, 'identifier' => $identifier, 'bank' => $bank], JSON_THROW_ON_ERROR));
        $hint = self::hint($method, $identifier);
        Database::transaction(function () use ($userId, $p, $method, $sealed, $hint): void {
            Database::run('UPDATE referral_partners SET payout_method = ?, payout_details_enc = ?, payout_details_hint = ?, payout_details_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$method, $sealed, $hint, $p['id']]);
            // the log records THAT details changed and which method, never the details
            Audit::record('partner.payout_details_changed', 'referral_partner', (int) $p['id'], ['had_details' => $p['payout_details_enc'] !== null], ['method' => $method, 'hint' => $hint], [], $userId);
        });
    }

    /** "UPI ••••ab@bank" / "Bank transfer •••• 1234": enough to recognise, never enough to use. */
    private static function hint(string $method, string $identifier): string
    {
        $tail = mb_substr(preg_replace('/\s+/', '', $identifier) ?? '', -4);

        return ($method === 'upi' ? 'UPI' : ($method === 'bank_transfer' ? 'Bank transfer' : 'Other')) . ' •••• ' . $tail;
    }

    /**
     * The cleartext details of ONE payout request, for the person who must pay it. Needs `payouts.record`, only while the request is approved/processing/paid,
     * and every view is audited.
     *
     * @param array<string,mixed> $payout
     * @return array<string,mixed>|null
     */
    public static function revealForPayout(int $actorId, array $payout): ?array
    {
        Gate::authorize('payouts.record', $actorId);
        if (!in_array($payout['status'], ['approved', 'processing', 'paid'], true) || $payout['details_enc'] === null) {
            return null;
        }
        $plain = SecretBox::open((string) $payout['details_enc']);
        Audit::record('payout.details_viewed', 'payout_request', (int) $payout['id'], [], [], ['partner_id' => (int) $payout['partner_id']], $actorId);

        return $plain === null ? null : (array) json_decode($plain, true);
    }
}
