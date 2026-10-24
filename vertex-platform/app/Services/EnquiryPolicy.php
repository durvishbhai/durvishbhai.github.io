<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Who may see an enquiry:
 *   'all'      holds enquiries.view_all
 *   'assigned' holds enquiries.view_assigned AND is the enquiry's internal owner
 *   'own'      holds enquiries.view_own AND is the referral partner who submitted it (restricted view: no internal data)
 * Archived enquiries are visible only at level 'all'. Unknown and forbidden both return 404 (no probing).
 */
final class EnquiryPolicy
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(
            'SELECT e.*, s.code AS stage_code, s.name AS stage_name, s.kind AS stage_kind, o.name AS owner_name
               FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id LEFT JOIN users o ON o.id = e.owner_id
              WHERE e.public_id = ?',
            [$publicId],
        );
    }

    /** @param array<string,mixed> $enq @return 'all'|'assigned'|'own'|null */
    public static function level(int $userId, array $enq): ?string
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if (Gate::can('enquiries.view_all', $userId)) {
            return 'all';
        }
        if ($enq['archived_at'] !== null) {
            return null;
        }
        if (Gate::can('enquiries.view_assigned', $userId) && $enq['owner_id'] !== null && (int) $enq['owner_id'] === $userId) {
            return 'assigned';
        }
        if (Gate::can('enquiries.view_own', $userId) && $enq['referral_partner_id'] !== null && (int) $enq['referral_partner_id'] === $userId) {
            return 'own';
        }

        return null;
    }

    /** @param array<string,mixed> $enq */
    public static function canView(int $userId, array $enq): bool
    {
        return self::level($userId, $enq) !== null;
    }

    /** Internal staff level ('all'/'assigned') AND a permission. @param array<string,mixed> $enq */
    public static function canStaff(int $userId, array $enq, string $permission): bool
    {
        return in_array(self::level($userId, $enq), ['all', 'assigned'], true) && Gate::can($permission, $userId);
    }

    /** @param array<string,mixed> $enq */
    public static function authorizeStaff(int $userId, array $enq, string $permission): void
    {
        if (!self::canStaff($userId, $enq, $permission)) {
            Logger::security('Enquiry ability denied', ['user' => $userId, 'enquiry' => (int) $enq['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        $enq = self::find($publicId);
        if ($enq === null || !self::canView($userId, $enq)) {
            if ($enq !== null) {
                Logger::security('Enquiry access denied', ['user' => $userId, 'enquiry' => (int) $enq['id']]);
            }
            throw new HttpException(404);
        }

        return $enq;
    }

    /**
     * SQL restricting `enquiries e` to what the user may see; null = nothing.
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    public static function scopeSql(int $userId): ?array
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if (Gate::can('enquiries.view_all', $userId)) {
            return ['1=1', []];
        }
        $or = [];
        $params = [];
        if (Gate::can('enquiries.view_assigned', $userId)) {
            $or[] = 'e.owner_id = ?';
            $params[] = $userId;
        }
        if (Gate::can('enquiries.view_own', $userId)) {
            $or[] = 'e.referral_partner_id = ?';
            $params[] = $userId;
        }
        if ($or === []) {
            return null;
        }

        return ['(' . implode(' OR ', $or) . ') AND e.archived_at IS NULL', $params];
    }
}
