<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Who may see a client record:
 *   'all'      clients.view_all
 *   'assigned' clients.view_assigned AND the user is assigned (not revoked) to at least one project of that client
 *   'own'      clients.view_own AND the record is linked to the user's portal login
 * Archived/merged clients are visible only at level 'all'. Notes are a separate permission (clients.notes_view) and are
 * never available at level 'own'.
 */
final class ClientPolicy
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one('SELECT * FROM clients WHERE public_id = ?', [$publicId]);
    }

    /** @param array<string,mixed> $client @return 'all'|'assigned'|'own'|null */
    public static function level(int $userId, array $client): ?string
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if (Gate::can('clients.view_all', $userId)) {
            return 'all';
        }
        if ($client['archived_at'] !== null || $client['status'] === 'archived') {
            return null;
        }
        if (Gate::can('clients.view_assigned', $userId) && Database::one(
            'SELECT 1 AS x FROM projects p JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = ? AND pa.revoked_at IS NULL
              WHERE p.client_id = ? AND p.archived_at IS NULL LIMIT 1',
            [$userId, $client['id']],
        ) !== null) {
            return 'assigned';
        }
        if (Gate::can('clients.view_own', $userId) && $client['user_id'] !== null && (int) $client['user_id'] === $userId) {
            return 'own';
        }

        return null;
    }

    /** @param array<string,mixed> $client */
    public static function canView(int $userId, array $client): bool
    {
        return self::level($userId, $client) !== null;
    }

    /** @param array<string,mixed> $client */
    public static function canStaff(int $userId, array $client, string $permission): bool
    {
        return in_array(self::level($userId, $client), ['all', 'assigned'], true) && Gate::can($permission, $userId);
    }

    /** @param array<string,mixed> $client */
    public static function authorizeStaff(int $userId, array $client, string $permission): void
    {
        if (!self::canStaff($userId, $client, $permission)) {
            Logger::security('Client ability denied', ['user' => $userId, 'client' => (int) $client['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        $c = self::find($publicId);
        if ($c === null || !self::canView($userId, $c)) {
            if ($c !== null) {
                Logger::security('Client access denied', ['user' => $userId, 'client' => (int) $c['id']]);
            }
            throw new HttpException(404);
        }

        return $c;
    }

    /**
     * SQL restricting `clients c` to what the user may see; null = nothing.
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    public static function scopeSql(int $userId): ?array
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if (Gate::can('clients.view_all', $userId)) {
            return ['1=1', []];
        }
        $or = [];
        $params = [];
        if (Gate::can('clients.view_assigned', $userId)) {
            $or[] = 'EXISTS (SELECT 1 FROM projects p JOIN project_assignments pa ON pa.project_id = p.id AND pa.user_id = ? AND pa.revoked_at IS NULL WHERE p.client_id = c.id AND p.archived_at IS NULL)';
            $params[] = $userId;
        }
        if (Gate::can('clients.view_own', $userId)) {
            $or[] = 'c.user_id = ?';
            $params[] = $userId;
        }
        if ($or === []) {
            return null;
        }

        return ['(' . implode(' OR ', $or) . ") AND c.archived_at IS NULL AND c.status <> 'archived'", $params];
    }
}
