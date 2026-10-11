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
    public function findByPublicId(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one('SELECT * FROM users WHERE public_id = ?', [$publicId]);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    /**
     * Inserts a user and its role rows. Callers are responsible for authorization and auditing.
     *
     * @param list<string> $roleSlugs
     */
    public function create(string $name, string $email, string $passwordHash, string $status, array $roleSlugs, ?int $assignedBy = null, ?string $phone = null, bool $emailVerified = false): int
    {
        return Database::transaction(function () use ($name, $email, $passwordHash, $status, $roleSlugs, $assignedBy, $phone, $emailVerified): int {
            Database::run(
                'INSERT INTO users (public_id, name, email, phone, password_hash, status, email_verified_at, password_changed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [uuid_hex(), $name, mb_strtolower(trim($email)), $phone, $passwordHash, $status, $emailVerified ? gmdate('Y-m-d H:i:s') : null],
            );
            $id = Database::insertId();
            $this->addRoles($id, $roleSlugs, $assignedBy);

            return $id;
        });
    }

    /** @param list<string> $roleSlugs */
    public function addRoles(int $userId, array $roleSlugs, ?int $assignedBy): void
    {
        foreach ($roleSlugs as $slug) {
            $role = Database::one('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if ($role === null) {
                throw new \RuntimeException("Unknown role '{$slug}'. Run permissions:sync first.");
            }
            Database::run(
                'INSERT INTO user_roles (user_id, role_id, assigned_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [$userId, $role['id'], $assignedBy],
            );
        }
    }

    /** @param list<string> $roleSlugs */
    public function replaceRoles(int $userId, array $roleSlugs, ?int $assignedBy): void
    {
        Database::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        $this->addRoles($userId, $roleSlugs, $assignedBy);
    }

    /** @return list<array{slug:string,name:string,level:int}> */
    public function rolesOf(int $userId): array
    {
        $rows = Database::all(
            'SELECT r.slug, r.name, r.level FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.level DESC, r.slug',
            [$userId],
        );

        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'level' => (int) $r['level']], $rows);
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

    /** Sets a new password, signs out every session (session_version + 1) and marks email verified. */
    public function setPassword(int $id, string $hash): void
    {
        Database::run(
            'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), session_version = session_version + 1,
                    email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$hash, $id],
        );
    }

    public function bumpSessionVersion(int $id): void
    {
        Database::run('UPDATE users SET session_version = session_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    /**
     * @param array{q?:string,role?:string,status?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like);
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['role'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles x JOIN roles xr ON xr.id = x.role_id WHERE x.user_id = u.id AND xr.slug = ?)';
            $params[] = $filters['role'];
        }
        $w = implode(' AND ', $where);

        $total = (int) Database::one("SELECT COUNT(*) AS c FROM users u WHERE {$w}", $params)['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT u.id, u.public_id, u.name, u.email, u.status, u.email_verified_at, u.last_login_at,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_names,
                    (SELECT COALESCE(MAX(r.level), 0) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS top_level
               FROM users u WHERE {$w} ORDER BY u.created_at DESC, u.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }
}
