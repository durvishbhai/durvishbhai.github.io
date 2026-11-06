<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Role/permission management. Rules enforced here (server side):
 *  - needs `roles.manage`;
 *  - the role must be unlocked (Super Admin is locked) and strictly below the actor's level;
 *  - adding/removing any SENSITIVE permission additionally needs `roles.manage_sensitive`;
 *  - an actor can never grant a permission they do not hold themselves (no self-escalation through a lower role);
 *  - unknown permission keys are rejected; every change is audited with before/after sets.
 */
final class RoleService
{
    /**
     * Every role the platform knows: the built-in ones from config plus custom roles created in the admin area (is_system = 0).
     * Custom roles are always assignable in the UI, never need a phone number, and hold exactly the grants stored in the database.
     *
     * @return array<string,array{name:string,description:string,level:int,assignable_in_ui:bool,phone_required:bool,custom:bool}>
     */
    public static function definitions(): array
    {
        $out = [];
        foreach (Config::get('permissions.roles', []) as $slug => $def) {
            $out[$slug] = ['name' => $def['name'], 'description' => $def['description'], 'level' => (int) $def['level'], 'assignable_in_ui' => (bool) $def['assignable_in_ui'], 'phone_required' => (bool) $def['phone_required'], 'custom' => false];
        }
        foreach (Database::all('SELECT slug, name, description, level FROM roles WHERE is_system = 0 ORDER BY level DESC, name') as $r) {
            $out[$r['slug']] ??= ['name' => $r['name'], 'description' => (string) $r['description'], 'level' => (int) $r['level'], 'assignable_in_ui' => true, 'phone_required' => false, 'custom' => true];
        }

        return $out;
    }

    /** Highest level a custom role may have for this actor: strictly below their own, and never above Manager (60) so a custom role cannot out-rank staff. */
    public static function maxCustomLevel(int $actorId): int
    {
        return min(Gate::level($actorId) - 1, 80);
    }

    /**
     * Creates a custom role. The starting permissions are the ones in $permissions (optionally seeded from $copyFrom); the actor can only hand out what they
     * hold themselves, sensitive ones need `roles.manage_sensitive`, and the role can never outrank its creator. Audited.
     *
     * @param list<string> $permissions
     */
    public static function create(int $actorId, string $name, string $description, int $level, ?string $copyFrom, array $permissions): string
    {
        Gate::authorize('roles.create', $actorId);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $description = trim($description);
        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            $errors[] = 'The role name must be 3 to 60 characters.';
        }
        if (mb_strlen($description) > 255) {
            $errors[] = 'The description is too long (255 characters at most).';
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        $slug = substr($slug, 0, 40);
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $slug)) {
            $errors[] = 'The role name must start with a letter.';
        }
        if (isset(self::definitions()[$slug]) || in_array($slug, ['admin', 'staff', 'owner', 'super_admin'], true)) {
            $errors[] = 'A role with that name already exists.';
        }
        if ($level < 10 || $level > self::maxCustomLevel($actorId)) {
            $errors[] = 'The level must be between 10 and ' . self::maxCustomLevel($actorId) . ' (below your own level).';
        }
        $valid = [];
        foreach (Database::all('SELECT perm_key, is_sensitive FROM permissions') as $r) {
            $valid[$r['perm_key']] = (bool) $r['is_sensitive'];
        }
        if ($copyFrom !== null && $copyFrom !== '') {
            $base = self::find($copyFrom);
            if ($base === null || (int) $base['level'] >= Gate::level($actorId)) {
                $errors[] = 'Choose a role below your own level to copy from.';
            } else {
                $permissions = array_merge($permissions, self::grantedKeys((int) $base['id']));
            }
        }
        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        foreach ($permissions as $k) {
            if (!array_key_exists($k, $valid)) {
                $errors[] = 'Unknown permission selected.';
                break;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        // A copy silently drops what the actor may not grant; an explicit pick of such a permission is refused loudly.
        $granted = [];
        foreach ($permissions as $k) {
            $canGrant = Gate::can($k, $actorId) && (!$valid[$k] || Gate::can('roles.manage_sensitive', $actorId));
            if ($canGrant) {
                $granted[] = $k;
            }
        }
        $dropped = array_values(array_diff($permissions, $granted));
        Database::transaction(function () use ($actorId, $slug, $name, $description, $level, $granted, $dropped): void {
            Database::run('INSERT INTO roles (slug, name, description, level, is_system, is_locked, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$slug, $name, $description === '' ? null : $description, $level]);
            $roleId = Database::insertId();
            foreach ($granted as $k) {
                Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE perm_key = ?', [$roleId, $k]);
            }
            sort($granted);
            Audit::record('role.created', 'role', $roleId, [], ['slug' => $slug, 'name' => $name, 'level' => $level, 'permissions' => $granted], ['not_granted' => $dropped], $actorId);
        });
        Gate::flush();

        return $slug;
    }

    /** Deletes a custom role that nobody holds. Built-in roles can never be deleted. */
    public static function delete(int $actorId, string $slug): void
    {
        Gate::authorize('roles.create', $actorId);
        $role = self::find($slug) ?? throw new HttpException(404);
        if ((int) $role['is_system'] === 1 || (int) $role['is_locked'] === 1 || (int) $role['level'] >= Gate::level($actorId)) {
            throw new HttpException(403, 'This role cannot be deleted.');
        }
        $users = (int) Database::one('SELECT COUNT(*) AS c FROM user_roles WHERE role_id = ?', [$role['id']])['c'];
        if ($users > 0) {
            throw new ValidationException(["{$users} user(s) still hold this role. Move them to another role first."]);
        }
        Database::transaction(function () use ($actorId, $role): void {
            $before = self::grantedKeys((int) $role['id']);
            Database::run('DELETE FROM roles WHERE id = ?', [$role['id']]);
            Audit::record('role.deleted', 'role', (int) $role['id'], ['slug' => $role['slug'], 'name' => $role['name'], 'level' => (int) $role['level'], 'permissions' => $before], [], [], $actorId);
        });
        Gate::flush();
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all(
            'SELECT r.id, r.slug, r.name, r.description, r.level, r.is_locked, r.is_system,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
               FROM roles r ORDER BY r.level DESC, r.name',
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(string $slug): ?array
    {
        return Database::one('SELECT * FROM roles WHERE slug = ?', [$slug]);
    }

    /** @return list<string> */
    public static function grantedKeys(int $roleId): array
    {
        $rows = Database::all(
            'SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.perm_key',
            [$roleId],
        );

        return array_map(static fn (array $r): string => (string) $r['perm_key'], $rows);
    }

    /** @return array<string,list<array{perm_key:string,description:string,is_sensitive:bool}>> grouped by module */
    public static function catalogue(): array
    {
        $out = [];
        foreach (Database::all('SELECT perm_key, module, description, is_sensitive FROM permissions ORDER BY module, perm_key') as $r) {
            $out[$r['module']][] = ['perm_key' => $r['perm_key'], 'description' => $r['description'], 'is_sensitive' => (bool) $r['is_sensitive']];
        }

        return $out;
    }

    /** Can this actor open the editor for this role at all? (display only; save re-checks) */
    public static function canEdit(int $actorId, array $role): bool
    {
        return Gate::can('roles.manage', $actorId) && !$role['is_locked'] && (int) $role['level'] < Gate::level($actorId);
    }

    /** @param list<string> $desired permission keys the role should hold afterwards */
    public static function setPermissions(int $actorId, string $slug, array $desired): void
    {
        Gate::authorize('roles.manage', $actorId);
        $role = self::find($slug) ?? throw new HttpException(404);
        if ($role['is_locked'] || (int) $role['level'] >= Gate::level($actorId)) {
            Logger::security('Role edit denied', ['actor' => $actorId, 'role' => $slug]);
            throw new HttpException(403, 'You cannot edit this role.');
        }

        $valid = [];
        foreach (Database::all('SELECT perm_key, is_sensitive FROM permissions') as $r) {
            $valid[$r['perm_key']] = (bool) $r['is_sensitive'];
        }
        $desired = array_values(array_unique(array_filter($desired, 'is_string')));
        foreach ($desired as $k) {
            if (!array_key_exists($k, $valid)) {
                throw new ValidationException(['Unknown permission selected.']);
            }
        }

        $before = self::grantedKeys((int) $role['id']);
        $added = array_values(array_diff($desired, $before));
        $removed = array_values(array_diff($before, $desired));
        if ($added === [] && $removed === []) {
            return;
        }

        $touchesSensitive = array_filter(array_merge($added, $removed), static fn (string $k): bool => $valid[$k]);
        if ($touchesSensitive !== [] && !Gate::can('roles.manage_sensitive', $actorId)) {
            Logger::security('Sensitive permission change denied', ['actor' => $actorId, 'role' => $slug, 'keys' => array_values($touchesSensitive)]);
            throw new HttpException(403, 'Only the owner can change sensitive permissions.');
        }
        foreach ($added as $k) {
            if (!Gate::can($k, $actorId)) {
                Logger::security('Permission escalation denied', ['actor' => $actorId, 'role' => $slug, 'key' => $k]);
                throw new HttpException(403, 'You cannot grant a permission you do not hold yourself.');
            }
        }

        Database::transaction(function () use ($actorId, $role, $slug, $before, $desired, $added, $removed): void {
            Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
            foreach ($desired as $k) {
                Database::run(
                    'INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE perm_key = ?',
                    [$role['id'], $k],
                );
            }
            Database::run('UPDATE roles SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$role['id']]);
            sort($desired);
            Audit::record('role.permissions_changed', 'role', (int) $role['id'], ['permissions' => $before], ['permissions' => $desired], ['role' => $slug, 'added' => $added, 'removed' => $removed], $actorId);
        });
        Gate::flush();
    }

    /**
     * Form submissions only contain the boxes the actor could tick. Sensitive boxes are disabled for actors without
     * `roles.manage_sensitive`, so their current state must be carried over rather than treated as "unticked".
     *
     * @param list<string> $submitted
     * @return list<string>
     */
    public static function preserveUneditable(int $actorId, string $slug, array $submitted): array
    {
        if (Gate::can('roles.manage_sensitive', $actorId)) {
            return $submitted;
        }
        $role = self::find($slug) ?? throw new HttpException(404);
        $sensitive = [];
        foreach (Database::all('SELECT perm_key FROM permissions WHERE is_sensitive = 1') as $r) {
            $sensitive[$r['perm_key']] = true;
        }
        $keepSensitive = array_filter(self::grantedKeys((int) $role['id']), static fn (string $k): bool => isset($sensitive[$k]));
        $submittedPlain = array_filter($submitted, static fn (string $k): bool => !isset($sensitive[$k]));

        // The UI never submits sensitive boxes for these actors (they are disabled). Seeing a sensitive key that is not
        // already granted means the request was crafted: refuse loudly and leave a trace instead of silently ignoring it.
        $crafted = array_values(array_diff(array_filter($submitted, static fn (string $k): bool => isset($sensitive[$k])), $keepSensitive));
        if ($crafted !== []) {
            Logger::security('Crafted sensitive permission grant', ['actor' => $actorId, 'role' => $slug, 'keys' => $crafted]);
            Audit::record('security.sensitive_grant_blocked', 'role', (int) $role['id'], [], [], ['role' => $slug, 'keys' => $crafted], $actorId);
            throw new HttpException(403, 'Only the owner can change sensitive permissions.');
        }

        return array_values(array_merge($submittedPlain, $keepSensitive));
    }
}
