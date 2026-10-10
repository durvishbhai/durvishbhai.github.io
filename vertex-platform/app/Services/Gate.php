<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;

/**
 * Authorization by permission key. A user's permissions are the union of the permissions of the roles they hold.
 * There is no super-user bypass: Admin passes only because the permission is explicitly granted to Admin.
 *
 * Row-level rules ("is this user assigned to THIS project?") are NOT answered here; they live in per-module policy
 * classes (Phase 2+) and must be called in addition to Gate::can() for any scoped permission.
 */
final class Gate
{
    /** @var array<int,array<string,true>> */
    private static array $cache = [];

    public static function can(string $permission, ?int $userId = null): bool
    {
        $userId ??= Auth::id();
        if ($userId === null) {
            return false;
        }

        return isset(self::permissionsFor($userId)[$permission]);
    }

    /** @param list<string> $permissions */
    public static function canAny(array $permissions, ?int $userId = null): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p, $userId)) {
                return true;
            }
        }

        return false;
    }

    public static function authorize(string $permission, ?int $userId = null): void
    {
        if (!self::can($permission, $userId)) {
            throw new HttpException(403);
        }
    }

    public static function hasRole(string $slug, ?int $userId = null): bool
    {
        $userId ??= Auth::id();

        return $userId !== null && in_array($slug, self::roleSlugs($userId), true);
    }

    /** @return list<string> */
    public static function roleSlugs(int $userId): array
    {
        $rows = Database::all(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.slug',
            [$userId],
        );

        return array_map(static fn (array $r): string => (string) $r['slug'], $rows);
    }

    /** @return array<string,true> */
    public static function permissionsFor(int $userId): array
    {
        if (!isset(self::$cache[$userId])) {
            $rows = Database::all(
                'SELECT DISTINCT p.perm_key
                   FROM user_roles ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE ur.user_id = ?',
                [$userId],
            );
            $set = [];
            foreach ($rows as $r) {
                $set[(string) $r['perm_key']] = true;
            }
            self::$cache[$userId] = $set;
        }

        return self::$cache[$userId];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
