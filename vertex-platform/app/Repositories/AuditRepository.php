<?php

declare(strict_types=1);

namespace Vertex\Repositories;

use Vertex\Core\Database;

/** Read-only access to audit_logs. There is deliberately no write/update/delete method here (see Services\Audit). */
final class AuditRepository
{
    /**
     * @param array{q?:string,action?:string,entity?:string,from?:string,to?:string} $f dates are UTC 'Y-m-d H:i:s'
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        $like = static fn (string $s): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
        if (($f['q'] ?? '') !== '') {
            $where[] = '(u.email LIKE ? OR u.name LIKE ? OR a.request_id = ? OR a.ip = ?)';
            array_push($params, $like($f['q']), $like($f['q']), $f['q'], $f['q']);
        }
        if (($f['action'] ?? '') !== '') {
            $where[] = 'a.action LIKE ?';
            $params[] = $like($f['action']);
        }
        if (($f['entity'] ?? '') !== '') {
            $where[] = 'a.entity_type = ?';
            $params[] = $f['entity'];
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = 'a.created_at < ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE {$w}", $params)['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.meta_json, a.ip, a.request_id,
                    a.actor_id, u.name AS actor_name, u.email AS actor_email
               FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
              WHERE {$w} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<string> */
    public function entityTypes(): array
    {
        return array_map(static fn (array $r): string => (string) $r['entity_type'], Database::all('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'));
    }
}
