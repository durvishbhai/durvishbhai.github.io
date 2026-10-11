<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Reconciles config/permissions.php (catalogue + DEFAULT grants) with the database, which is the live source of truth.
 *
 *  - Catalogue permissions are inserted/updated; those removed from config are deleted (and so lose all grants).
 *  - Missing system roles are created with their default grants.
 *  - Existing roles keep their current grants (admins may have edited them in the UI); they only receive the defaults
 *    for permissions that are NEW in this run, so a deploy that adds a feature does not undo anyone's edits.
 *  - The locked Super Admin role is always granted the full catalogue.
 *  - $resetRoles restores the config defaults for every non-locked system role (explicit, audited).
 */
final class PermissionSync
{
    /** @return array{roles:int,permissions:int,new_permissions:int,grants:int} */
    public static function run(bool $resetRoles = false): array
    {
        $catalogue = Config::get('permissions.catalogue', []);
        $sensitive = array_flip(Config::get('permissions.sensitive', []));
        $roles = Config::get('permissions.roles', []);

        return Database::transaction(function () use ($catalogue, $sensitive, $roles, $resetRoles): array {
            $existing = [];
            foreach (Database::all('SELECT perm_key FROM permissions') as $r) {
                $existing[$r['perm_key']] = true;
            }

            $known = [];
            $new = [];
            foreach ($catalogue as $module => $items) {
                foreach ($items as $key => $description) {
                    $known[] = $key;
                    if (!isset($existing[$key])) {
                        $new[$key] = true;
                    }
                    Database::run(
                        'INSERT INTO permissions (perm_key, module, description, is_sensitive) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description), is_sensitive = VALUES(is_sensitive)',
                        [$key, $module, $description, isset($sensitive[$key]) ? 1 : 0],
                    );
                }
            }
            if ($known !== []) {
                $in = implode(',', array_fill(0, count($known), '?'));
                Database::run("DELETE FROM permissions WHERE perm_key NOT IN ($in)", $known);
            }

            $permId = [];
            foreach (Database::all('SELECT id, perm_key FROM permissions') as $r) {
                $permId[$r['perm_key']] = (int) $r['id'];
            }

            foreach ($roles as $slug => $def) {
                $row = Database::one('SELECT id FROM roles WHERE slug = ?', [$slug]);
                $created = $row === null;
                if ($created) {
                    Database::run(
                        'INSERT INTO roles (slug, name, description, level, is_system, is_locked, created_at, updated_at)
                         VALUES (?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                        [$slug, $def['name'], $def['description'], $def['level'], $def['locked'] ? 1 : 0],
                    );
                    $roleId = Database::insertId();
                } else {
                    $roleId = (int) $row['id'];
                    Database::run(
                        'UPDATE roles SET name = ?, description = ?, level = ?, is_system = 1, is_locked = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                        [$def['name'], $def['description'], $def['level'], $def['locked'] ? 1 : 0, $roleId],
                    );
                }

                $defaults = array_values(array_unique($def['permissions']));
                foreach ($defaults as $perm) {
                    if (!isset($permId[$perm])) {
                        throw new \RuntimeException("Role '{$slug}' references unknown permission '{$perm}'.");
                    }
                }

                if ($def['locked']) {
                    $toGrant = array_keys($permId); // Super Admin: always everything
                } elseif ($created || $resetRoles) {
                    Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                    $toGrant = $defaults;
                } else {
                    $toGrant = array_values(array_filter($defaults, static fn (string $k): bool => isset($new[$k])));
                }
                foreach ($toGrant as $perm) {
                    Database::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permId[$perm]]);
                }
            }

            $grants = (int) Database::one('SELECT COUNT(*) AS c FROM role_permissions')['c'];
            Gate::flush();
            Audit::record(
                'permissions.sync', 'role', null, [], [],
                ['roles' => count($roles), 'permissions' => count($known), 'new_permissions' => count($new), 'reset_roles' => $resetRoles],
                null, 'cli',
            );

            return ['roles' => count($roles), 'permissions' => count($known), 'new_permissions' => count($new), 'grants' => $grants];
        });
    }
}
