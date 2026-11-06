<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;

/**
 * The recycle bin is a single view of everything that was ARCHIVED (nothing in this platform is hard-deleted). Restoring clears the archive stamp
 * and writes an audit entry with who restored what and why. Needs `recycle.view` to look and `recycle.restore` to restore.
 */
final class RecycleBin
{
    /** @return array<string,array{label:string,table:string,title:string,restore:string}> */
    public static function types(): array
    {
        return [
            'clients' => ['label' => 'Clients', 'table' => 'clients', 'title' => "COALESCE(NULLIF(company_name, ''), contact_name)", 'restore' => "status = 'active', archived_at = NULL"],
            'enquiries' => ['label' => 'CRM enquiries', 'table' => 'enquiries', 'title' => "CONCAT(reference, ' - ', contact_name)", 'restore' => 'archived_at = NULL'],
            'projects' => ['label' => 'Projects', 'table' => 'projects', 'title' => "CONCAT(code, ' - ', title)", 'restore' => 'archived_at = NULL'],
            'milestones' => ['label' => 'Milestones', 'table' => 'milestones', 'title' => 'title', 'restore' => 'archived_at = NULL'],
            'tasks' => ['label' => 'Tasks', 'table' => 'tasks', 'title' => 'title', 'restore' => 'archived_at = NULL'],
            'deliverables' => ['label' => 'Deliverables', 'table' => 'deliverables', 'title' => 'title', 'restore' => 'archived_at = NULL'],
        ];
    }

    /** @return list<array{type:string,label:string,id:string,title:string,archived_at:string}> newest first */
    public static function items(int $userId, string $type = ''): array
    {
        Gate::authorize('recycle.view', $userId);
        $out = [];
        foreach (self::types() as $key => $t) {
            if ($type !== '' && $type !== $key) {
                continue;
            }
            $rows = Database::all("SELECT public_id, {$t['title']} AS title, archived_at FROM {$t['table']} WHERE archived_at IS NOT NULL ORDER BY archived_at DESC LIMIT 200");
            foreach ($rows as $r) {
                $out[] = ['type' => $key, 'label' => $t['label'], 'id' => (string) $r['public_id'], 'title' => (string) $r['title'], 'archived_at' => (string) $r['archived_at']];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['archived_at'], $a['archived_at']));

        return array_slice($out, 0, 300);
    }

    public static function restore(int $userId, string $type, string $publicId, string $reason): void
    {
        Gate::authorize('recycle.restore', $userId);
        $t = self::types()[$type] ?? throw new HttpException(404);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \Vertex\Core\ValidationException(['Say why you are restoring this record (3 characters or more).']);
        }
        Database::transaction(function () use ($userId, $type, $t, $publicId, $reason): void {
            $row = Database::one("SELECT id, archived_at FROM {$t['table']} WHERE public_id = ? AND archived_at IS NOT NULL FOR UPDATE", [$publicId]) ?? throw new HttpException(404);
            Database::run("UPDATE {$t['table']} SET {$t['restore']}, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$row['id']]);
            Audit::record('recycle.restored', $type, (int) $row['id'], ['archived_at' => $row['archived_at']], ['archived_at' => null], ['reason' => $reason], $userId);
        });
    }
}
