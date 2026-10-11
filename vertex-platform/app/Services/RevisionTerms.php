<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Revision policy FOUNDATION: what the agreement says about included revisions. Nothing is shipped: with no policy configured
 * the system invents NO allowance (no universal number) and classification is a manual, reasoned decision.
 *
 * Resolution order for a project: project-specific terms, then its service's terms, then the default. A policy may carry a numeric
 * allowance (included_revisions), free-text terms and the quotation/contract reference they come from. Rows are versioned (a change
 * inserts a new version and supersedes the old one) and every change is audited.
 *
 * When a NUMERIC allowance applies the count of already-included revisions on the project is shown as advice, and a classification
 * that contradicts it needs `revisions.override` (see RevisionService::classify). Without a numeric allowance the system never
 * decides: classifiers decide and explain.
 */
final class RevisionTerms
{
    /**
     * @param array<string,mixed> $project ProjectPolicy row (needs id, service_category)
     * @return array{policy:?array<string,mixed>,limit:?int,used:int,remaining:?int,source:string}
     */
    public static function forProject(array $project, ?int $excludeRevisionId = null): array
    {
        $row = Database::one("SELECT * FROM revision_policies WHERE scope_key = ? AND current_flag = 1", ['project:' . $project['id']]);
        $source = 'project';
        if ($row === null && ($project['service_category'] ?? null) !== null) {
            $row = Database::one("SELECT * FROM revision_policies WHERE scope_key = ? AND current_flag = 1", ['service:' . $project['service_category']]);
            $source = 'service';
        }
        if ($row === null) {
            $row = Database::one("SELECT * FROM revision_policies WHERE scope_key = 'default' AND current_flag = 1");
            $source = 'default';
        }
        $used = (int) Database::one(
            "SELECT COUNT(*) AS c FROM revision_requests WHERE project_id = ? AND scope_decision = 'included' AND status NOT IN ('cancelled','rejected')" . ($excludeRevisionId === null ? '' : ' AND id <> ?'),
            $excludeRevisionId === null ? [$project['id']] : [$project['id'], $excludeRevisionId],
        )['c'];
        $limit = $row === null || $row['included_revisions'] === null ? null : (int) $row['included_revisions'];

        return ['policy' => $row, 'limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used), 'source' => $row === null ? 'none' : $source];
    }

    /** All current policies. @return list<array<string,mixed>> */
    public static function current(): array
    {
        return Database::all(
            'SELECT rp.*, pr.code AS project_code, pr.title AS project_title, u.name AS author FROM revision_policies rp
               LEFT JOIN projects pr ON pr.id = rp.project_id LEFT JOIN users u ON u.id = rp.created_by
              WHERE rp.current_flag = 1 ORDER BY FIELD(rp.scope, \'default\', \'service\', \'project\'), rp.scope_key',
        );
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 60): array
    {
        return Database::all('SELECT rp.*, pr.code AS project_code, u.name AS author FROM revision_policies rp LEFT JOIN projects pr ON pr.id = rp.project_id LEFT JOIN users u ON u.id = rp.created_by ORDER BY rp.id DESC LIMIT ' . max(1, min(300, $limit)));
    }

    /**
     * Writes a new version of a policy. Needs `revisions.policy` (sensitive; the controller also asks for the actor's password).
     * An empty allowance means "not specified": the system will not assume a number.
     *
     * @param array<string,mixed> $in scope (default|service|project), service, project (public id), included (int|''), terms, source_reference, reason
     * @return bool true when a new version was written
     */
    public static function set(int $actorId, array $in): bool
    {
        Gate::authorize('revisions.policy', $actorId);
        $scope = (string) FieldRules::choice($in['scope'] ?? '', ['default' => 'Default', 'service' => 'Service', 'project' => 'Project'], 'scope');
        $service = null;
        $projectId = null;
        $key = 'default';
        if ($scope === 'service') {
            $service = (string) FieldRules::choice($in['service'] ?? '', (array) Config::get('workflow.services'), 'service');
            $key = 'service:' . $service;
        } elseif ($scope === 'project') {
            $pp = FieldRules::clean($in['project'] ?? '');
            $row = preg_match('/^[a-f0-9]{32}$/', $pp) ? Database::one('SELECT id FROM projects WHERE public_id = ?', [$pp]) : null;
            if ($row === null) {
                throw new ValidationException(['Choose a project.']);
            }
            $projectId = (int) $row['id'];
            $key = 'project:' . $projectId;
        }
        $included = FieldRules::clean($in['included'] ?? '');
        if ($included !== '' && (!ctype_digit($included) || (int) $included > 1000)) {
            throw new ValidationException(['The included revisions must be a whole number between 0 and 1000, or left empty when the agreement does not specify one.']);
        }
        $terms = FieldRules::text($in['terms'] ?? '', 'Terms', 3, 3000, false);
        $ref = FieldRules::line($in['source_reference'] ?? '', 'Quotation / contract reference', 2, 120, false);
        $reason = (string) FieldRules::line($in['reason'] ?? '', 'Reason for the change', 5, 255);
        if ($included === '' && $terms === null) {
            throw new ValidationException(['Give an allowance, written terms, or both.']);
        }
        $count = $included === '' ? null : (int) $included;

        return Database::transaction(function () use ($actorId, $scope, $service, $projectId, $key, $count, $terms, $ref, $reason): bool {
            $cur = Database::one('SELECT * FROM revision_policies WHERE scope_key = ? AND current_flag = 1 FOR UPDATE', [$key]);
            if ($cur !== null && ($cur['included_revisions'] === null ? null : (int) $cur['included_revisions']) === $count && (string) $cur['terms'] === (string) $terms && (string) $cur['source_reference'] === (string) $ref) {
                return false;
            }
            $version = (int) Database::one('SELECT COALESCE(MAX(version), 0) AS v FROM revision_policies WHERE scope_key = ?', [$key])['v'] + 1;
            if ($cur !== null) {
                Database::run('UPDATE revision_policies SET current_flag = NULL, superseded_at = UTC_TIMESTAMP() WHERE id = ?', [$cur['id']]);
            }
            Database::run(
                'INSERT INTO revision_policies (scope, scope_key, service_category, project_id, included_revisions, terms, source_reference, version, current_flag, reason, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())',
                [$scope, $key, $service, $projectId, $count, $terms, $ref, $version, $reason, $actorId],
            );
            Audit::record('revision_policy.changed', 'revision_policy', Database::insertId(),
                $cur === null ? [] : ['included_revisions' => $cur['included_revisions'], 'source_reference' => $cur['source_reference'], 'version' => (int) $cur['version']],
                ['included_revisions' => $count, 'source_reference' => $ref, 'version' => $version], ['scope_key' => $key], $actorId);

            return true;
        });
    }

    /** @throws HttpException */
    public static function authorizeView(int $userId): void
    {
        if (!Gate::canAny(['revisions.policy', 'revisions.classify', 'revisions.override'], $userId)) {
            throw new HttpException(403);
        }
    }
}
