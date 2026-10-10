<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Makes the roles / permissions / role_permissions tables match config/permissions.php.
 * Idempotent. Permissions removed from config are removed from the DB (and therefore from every role).
 * Custom (non-system) roles created later through the UI are never touched.
 */
final class PermissionSync
{
    /** @return array{roles:int,permissions:int,grants:int} */
    public static function run(): array
    {
        $catalogue = Config::get('permissions.catalogue', []);
        $roles = Config::get('permissions.roles', []);

        return Database::transaction(function () use ($catalogue, $roles): array {
            $known = [];
            foreach ($catalogue as $module => $items) {
                foreach ($items as $key => $description) {
                    $known[] = $key;
                    Database::run(
                        'INSERT INTO permissions (perm_key, module, description) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description)',
                        [$key, $module, $description],
                    );
                }
            }
            if ($known !== []) {
                $in = implode(',', array_fill(0, count($known), '?'));
                Database::run("DELETE FROM permissions WHERE perm_key NOT IN ($in)", $known);
            }

            $grants = 0;
            foreach ($roles as $slug => $def) {
                Database::run(
                    'INSERT INTO roles (slug, name, description, is_system, created_at, updated_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), updated_at = UTC_TIMESTAMP()',
                    [$slug, $def['name'], $def['description']],
                );
                $roleId = (int) Database::one('SELECT id FROM roles WHERE slug = ?', [$slug])['id'];
                Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                foreach (array_unique($def['permissions']) as $perm) {
                    $p = Database::one('SELECT id FROM permissions WHERE perm_key = ?', [$perm]);
                    if ($p === null) {
                        throw new \RuntimeException("Role '{$slug}' references unknown permission '{$perm}'.");
                    }
                    Database::run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $p['id']]);
                    $grants++;
                }
            }
            Gate::flush();
            Audit::record('permissions.sync', 'role', null, [], [], ['roles' => count($roles), 'permissions' => count($known), 'grants' => $grants], null, 'cli');

            return ['roles' => count($roles), 'permissions' => count($known), 'grants' => $grants];
        });
    }
}
