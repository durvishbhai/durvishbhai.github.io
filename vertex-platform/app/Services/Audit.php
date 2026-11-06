<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\Request;

/**
 * Append-only, hash-chained audit trail.
 *
 * Each row stores prev_hash (the previous row's row_hash) and row_hash = SHA-256 over its own canonical content.
 * Editing or deleting any historical row breaks every later hash, which `console audit:verify` detects.
 * The application has no code path that UPDATEs or DELETEs audit_logs.
 *
 * before/after/meta are stored as exact JSON text (LONGTEXT, not JSON type) so the hashed bytes are reproducible.
 * Sensitive keys are redacted before storage. Financial modules MUST call Audit::record() inside the same
 * transaction as the change they describe.
 */
final class Audit
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const REDACT = ['password', 'password_hash', 'token', 'secret', 'api_key', 'card', 'cvv', 'authorization'];

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $meta
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $before = [],
        array $after = [],
        array $meta = [],
        ?int $actorId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): int {
        $actorId ??= Auth::id();

        return Database::transaction(function () use ($action, $entityType, $entityId, $before, $after, $meta, $actorId, $ip, $userAgent): int {
            $last = Database::one('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $prev = $last['row_hash'] ?? self::GENESIS;

            $row = [
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_json' => $before === [] ? null : self::encode(self::redact($before)),
                'after_json' => $after === [] ? null : self::encode(self::redact($after)),
                'meta_json' => $meta === [] ? null : self::encode(self::redact($meta)),
                'ip' => $ip ?? (PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null)),
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : (isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null),
                'request_id' => Request::id(),
                'prev_hash' => $prev,
            ];
            $row['row_hash'] = self::hash($row);

            Database::run(
                'INSERT INTO audit_logs (created_at, actor_id, action, entity_type, entity_id, before_json, after_json, meta_json, ip, user_agent, request_id, prev_hash, row_hash)
                 VALUES (:created_at, :actor_id, :action, :entity_type, :entity_id, :before_json, :after_json, :meta_json, :ip, :user_agent, :request_id, :prev_hash, :row_hash)',
                $row,
            );

            return Database::insertId();
        });
    }

    /**
     * Recompute the whole chain.
     *
     * @return array{ok:bool,checked:int,broken_at:?int,reason:?string}
     */
    public static function verify(): array
    {
        $prev = self::GENESIS;
        $checked = 0;
        $lastId = 0;
        while (true) {
            $rows = Database::all('SELECT * FROM audit_logs WHERE id > ? ORDER BY id ASC LIMIT 500', [$lastId]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $lastId = (int) $r['id'];
                if (!hash_equals($prev, (string) $r['prev_hash'])) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $lastId, 'reason' => 'prev_hash does not match previous row (row removed or reordered)'];
                }
                if (!hash_equals(self::hash($r), (string) $r['row_hash'])) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $lastId, 'reason' => 'row content does not match its hash (row modified)'];
                }
                $prev = (string) $r['row_hash'];
                $checked++;
            }
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /** @param array<string,mixed> $r */
    private static function hash(array $r): string
    {
        $canonical = json_encode([
            (string) $r['prev_hash'],
            // DB returns "Y-m-d H:i:s.uuuuuu"; PHP produces the same format.
            (string) $r['created_at'],
            $r['actor_id'] === null ? null : (int) $r['actor_id'],
            (string) $r['action'],
            $r['entity_type'],
            $r['entity_id'] === null ? null : (int) $r['entity_id'],
            $r['before_json'],
            $r['after_json'],
            $r['meta_json'],
            $r['ip'],
            $r['request_id'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::redact($v);
                continue;
            }
            $lk = strtolower((string) $k);
            foreach (self::REDACT as $needle) {
                if (str_contains($lk, $needle)) {
                    $data[$k] = '[REDACTED]';
                    break;
                }
            }
        }

        return $data;
    }
}
