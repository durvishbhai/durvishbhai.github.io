<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * In-app notification EVENTS. No email is sent by this class and no row claims one was: `email_status` stays 'not_sent'
 * (an email channel is a later integration that can read this table). The text stored here is written for its recipient and
 * contains no internal-only detail when the recipient is a client.
 *
 * Retried requests cannot notify twice: (user_id, dedupe_key) is unique and inserts are INSERT IGNORE. Call inside the
 * transaction that makes the change so a rolled-back change leaves no notification either.
 */
final class Notifier
{
    /**
     * @param list<int> $userIds
     * @param string $dedupe stable key for THIS event instance (e.g. "version:12:published"); combined with the user
     * @return int rows actually created
     */
    public static function send(array $userIds, string $event, string $title, ?string $body, ?string $link, ?int $projectId, ?string $subjectType, ?int $subjectId, string $dedupe, ?int $exceptUserId = null): int
    {
        $created = 0;
        foreach (array_unique($userIds) as $uid) {
            if ($uid === $exceptUserId) {
                continue; // nobody needs a notification about their own action
            }
            $n = Database::run(
                'INSERT IGNORE INTO notifications (user_id, event_type, project_id, subject_type, subject_id, title, body, link, dedupe_key, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$uid, $event, $projectId, $subjectType, $subjectId, mb_substr($title, 0, 160), $body === null ? null : mb_substr($body, 0, 255), $link, hash('sha256', $dedupe)],
            )->rowCount();
            $created += $n;
        }

        return $created;
    }

    /**
     * Active staff who hold $permission AND have staff-level access to the project (view_all, or an active assignment).
     *
     * @return list<int>
     */
    public static function staffWith(string $permission, int $projectId): array
    {
        $rows = Database::all(
            "SELECT DISTINCT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = ?
              WHERE u.status = 'active'
                AND (EXISTS (SELECT 1 FROM user_roles ur2 JOIN role_permissions rp2 ON rp2.role_id = ur2.role_id
                               JOIN permissions p2 ON p2.id = rp2.permission_id AND p2.perm_key = 'projects.view_all' WHERE ur2.user_id = u.id)
                     OR EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.user_id = u.id AND pa.project_id = ? AND pa.revoked_at IS NULL))",
            [$permission, $projectId],
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** Every active user holding $permission (for events that have no project, e.g. a billing ticket). @return list<int> */
    public static function withPermission(string $permission): array
    {
        $rows = Database::all(
            "SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = ? WHERE u.status = 'active'",
            [$permission],
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** The portal login of the project's client, if the client has one. @return list<int> */
    public static function clientOf(int $projectId): array
    {
        $row = Database::one(
            "SELECT c.user_id FROM projects p JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = c.user_id WHERE p.id = ? AND u.status = 'active'",
            [$projectId],
        );

        return $row === null ? [] : [(int) $row['user_id']];
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId])['c'];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, bool $unreadOnly, int $page, int $perPage = 25): array
    {
        $w = 'user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE {$w}", [$userId])['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all("SELECT id, event_type, title, body, link, created_at, read_at FROM notifications WHERE {$w} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", [$userId]);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Marks one (or all, when $id is null) of the USER'S OWN notifications read. */
    public static function markRead(int $userId, ?int $id): void
    {
        if ($id === null) {
            Database::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [$userId]);

            return;
        }
        Database::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND id = ? AND read_at IS NULL', [$userId, $id]);
    }
}
