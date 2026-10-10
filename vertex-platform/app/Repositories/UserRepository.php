<?php

declare(strict_types=1);

namespace Vertex\Repositories;

use Vertex\Core\Database;

final class UserRepository
{
    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    /**
     * @param list<string> $roleSlugs
     */
    public function create(string $name, string $email, string $passwordHash, string $status, array $roleSlugs, ?int $assignedBy = null): int
    {
        return Database::transaction(function () use ($name, $email, $passwordHash, $status, $roleSlugs, $assignedBy): int {
            Database::run(
                'INSERT INTO users (public_id, name, email, password_hash, status, password_changed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [uuid_hex(), $name, mb_strtolower($email), $passwordHash, $status],
            );
            $id = Database::insertId();
            foreach ($roleSlugs as $slug) {
                $role = Database::one('SELECT id FROM roles WHERE slug = ?', [$slug]);
                if ($role === null) {
                    throw new \RuntimeException("Unknown role '{$slug}'. Run permissions:sync first.");
                }
                Database::run(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                    [$id, $role['id'], $assignedBy],
                );
            }

            return $id;
        });
    }

    public function touchLogin(int $id, string $ip): void
    {
        Database::run('UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?', [$ip, $id]);
    }

    public function updatePasswordHash(int $id, string $hash, bool $markChanged = false): void
    {
        Database::run(
            'UPDATE users SET password_hash = ?, password_changed_at = IF(?, UTC_TIMESTAMP(), password_changed_at), updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$hash, $markChanged ? 1 : 0, $id],
        );
    }
}
