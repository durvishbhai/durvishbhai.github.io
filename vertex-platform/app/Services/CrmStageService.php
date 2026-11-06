<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * Pipeline stages are data, not code. Admins (crm.configure) may rename, reorder and (de)activate OPEN stages.
 * The Won and Lost stages carry the business meaning (`kind`) and cannot be deactivated; stages are never deleted.
 */
final class CrmStageService
{
    /** @return list<array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        return Database::all('SELECT * FROM crm_stages ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY position, id');
    }

    /** @return array<string,mixed>|null */
    public static function byCode(string $code): ?array
    {
        return Database::one('SELECT * FROM crm_stages WHERE code = ?', [$code]);
    }

    /** @return array<string,mixed> the stage new enquiries start in (first active open stage) */
    public static function initial(): array
    {
        return Database::one("SELECT * FROM crm_stages WHERE kind = 'open' AND is_active = 1 ORDER BY position, id LIMIT 1")
            ?? throw new \RuntimeException('No active open CRM stage is configured.');
    }

    /** @return array<int,int> stage id => number of non-archived enquiries */
    public static function usage(): array
    {
        $out = [];
        foreach (Database::all('SELECT stage_id, COUNT(*) AS c FROM enquiries WHERE archived_at IS NULL GROUP BY stage_id') as $r) {
            $out[(int) $r['stage_id']] = (int) $r['c'];
        }

        return $out;
    }

    /** @param array<int,array{name:string,position:string|int,active?:string|int|bool}> $rows keyed by stage id */
    public static function update(int $actorId, array $rows): void
    {
        Gate::authorize('crm.configure', $actorId);
        $usage = self::usage();
        $positions = [];

        Database::transaction(function () use ($actorId, $rows, $usage, &$positions): void {
            $before = [];
            $after = [];
            foreach (self::all() as $stage) {
                $id = (int) $stage['id'];
                if (!isset($rows[$id])) {
                    continue;
                }
                $in = $rows[$id];
                $name = FieldRules::line($in['name'] ?? '', 'Stage name', 2, 60);
                $pos = (int) ($in['position'] ?? 0);
                if ($pos < 1 || $pos > 999) {
                    throw new ValidationException(['Stage position must be between 1 and 999.']);
                }
                if (isset($positions[$pos])) {
                    throw new ValidationException(['Two stages cannot share the same position.']);
                }
                $positions[$pos] = true;
                $active = !empty($in['active']) ? 1 : 0;
                if ($stage['kind'] !== 'open' && $active === 0) {
                    throw new ValidationException(["The {$stage['name']} stage cannot be deactivated."]);
                }
                if ($stage['kind'] === 'open' && $active === 0 && ($usage[$id] ?? 0) > 0) {
                    throw new ValidationException(["{$stage['name']} still has " . $usage[$id] . ' enquiries. Move them first.']);
                }
                if ($name !== $stage['name'] || $pos !== (int) $stage['position'] || $active !== (int) $stage['is_active']) {
                    $before[$stage['code']] = ['name' => $stage['name'], 'position' => (int) $stage['position'], 'active' => (int) $stage['is_active']];
                    $after[$stage['code']] = ['name' => $name, 'position' => $pos, 'active' => $active];
                    Database::run('UPDATE crm_stages SET name = ?, position = ?, is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $pos, $active, $id]);
                }
            }
            if (!self::hasActiveOpen()) {
                throw new ValidationException(['At least one open stage must stay active.']);
            }
            if ($after !== []) {
                Audit::record('crm.stages_changed', 'crm_stage', null, $before, $after, [], $actorId);
            }
        });
    }

    private static function hasActiveOpen(): bool
    {
        return Database::one("SELECT 1 AS x FROM crm_stages WHERE kind = 'open' AND is_active = 1") !== null;
    }
}
