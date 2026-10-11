<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Writes the user-facing project timeline. Always call inside the transaction that makes the change it describes.
 * `client` visibility entries are shown in the client portal; everything else is staff-only.
 */
final class ProjectActivity
{
    /** @param array<string,mixed> $details */
    public static function log(
        int $projectId,
        ?int $actorId,
        string $eventType,
        string $summary,
        string $visibility = 'internal',
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $details = [],
    ): void {
        Database::run(
            'INSERT INTO project_activity (project_id, actor_id, event_type, subject_type, subject_id, summary, details_json, visibility, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $projectId, $actorId, $eventType, $subjectType, $subjectId, mb_substr($summary, 0, 255),
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                $visibility,
            ],
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function timeline(int $projectId, bool $clientView, int $page = 1, int $perPage = 30): array
    {
        $where = 'a.project_id = ?' . ($clientView ? " AND a.visibility = 'client'" : '');
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM project_activity a WHERE {$where}", [$projectId])['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT a.id, a.event_type, a.subject_type, a.subject_id, a.summary, a.details_json, a.visibility, a.created_at,
                    a.actor_id, u.name AS actor_name
               FROM project_activity a LEFT JOIN users u ON u.id = a.actor_id
              WHERE {$where} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$projectId],
        );

        return ['rows' => $rows, 'total' => $total];
    }
}
