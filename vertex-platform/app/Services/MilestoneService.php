<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Project milestones.
 *  - Who may create: `milestones.create` creates a PLANNED milestone directly; `milestones.propose` creates a PROPOSED one
 *    that stays internal until someone with `milestones.approve` accepts (or rejects) it. Workers get propose only.
 *  - Who may change: `milestones.edit` (edit, status). Proposals are decided with `milestones.approve`.
 *  - Visibility: 'internal' (staff) or 'client' (also shown in the client portal, once planned or later).
 *  - Every operation requires staff-level access to the PROJECT (assigned or all), checked here and in the middleware.
 */
final class MilestoneService
{
    /**
     * @param array<string,mixed> $project
     * @param array<string,mixed> $in title, description, target_date, visibility
     * @return array{id:int,public_id:string,status:string}
     */
    public static function create(int $actorId, array $project, array $in): array
    {
        if (!in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        self::assertOpen($project);
        $direct = Gate::can('milestones.create', $actorId);
        if (!$direct && !Gate::can('milestones.propose', $actorId)) {
            throw new HttpException(403);
        }
        $d = self::validate($in);
        $status = $direct ? 'planned' : 'proposed';
        $visibility = $direct ? $d['visibility'] : 'internal'; // a proposal is never client-visible until approved

        return Database::transaction(function () use ($actorId, $project, $d, $status, $visibility, $direct): array {
            $pub = uuid_hex();
            Database::run(
                'INSERT INTO milestones (public_id, project_id, title, description, target_date, status, visibility, proposed_by, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$pub, $project['id'], $d['title'], $d['description'], $d['target_date'], $status, $visibility, $direct ? null : $actorId, $direct ? $actorId : null],
            );
            $id = Database::insertId();
            ProjectActivity::log((int) $project['id'], $actorId, $direct ? 'milestone.created' : 'milestone.proposed',
                ($direct ? 'Milestone created: ' : 'Milestone proposed: ') . $d['title'], $visibility === 'client' ? 'client' : 'internal', 'milestone', $id);
            Audit::record($direct ? 'milestone.created' : 'milestone.proposed', 'milestone', $id, [], ['status' => $status, 'visibility' => $visibility], ['project_id' => (int) $project['id']], $actorId);

            return ['id' => $id, 'public_id' => $pub, 'status' => $status];
        });
    }

    /** @param array<string,mixed> $milestone @param array<string,mixed> $project @param array<string,mixed> $in */
    public static function update(int $actorId, array $project, array $milestone, array $in): void
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'milestones.edit');
        self::assertOpen($project);
        if ($milestone['archived_at'] !== null) {
            throw new ValidationException(['This milestone is archived.']);
        }
        $d = self::validate($in);
        if ($milestone['status'] === 'proposed') {
            $d['visibility'] = 'internal';
        }
        $changed = [];
        foreach (['title', 'description', 'target_date', 'visibility'] as $f) {
            if ((string) $d[$f] !== (string) $milestone[$f]) {
                $changed[] = $f;
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $project, $milestone, $d, $changed): void {
            Database::run('UPDATE milestones SET title = ?, description = ?, target_date = ?, visibility = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$d['title'], $d['description'], $d['target_date'], $d['visibility'], $milestone['id']]);
            $client = $d['visibility'] === 'client' || $milestone['visibility'] === 'client';
            if (in_array('target_date', $changed, true)) {
                ProjectActivity::log((int) $project['id'], $actorId, 'milestone.deadline_changed', "Milestone \"{$d['title']}\" target date: " . ($milestone['target_date'] ?? 'not set') . ' → ' . ($d['target_date'] ?? 'not set'), $client ? 'client' : 'internal', 'milestone', (int) $milestone['id']);
            }
            if (array_diff($changed, ['target_date']) !== []) {
                ProjectActivity::log((int) $project['id'], $actorId, 'milestone.updated', "Milestone updated: {$d['title']}", $client ? 'client' : 'internal', 'milestone', (int) $milestone['id']);
            }
            Audit::record('milestone.updated', 'milestone', (int) $milestone['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    /**
     * Allowed target statuses for this user. @return array<string,string> status => label
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $milestone
     */
    public static function allowedTransitions(int $userId, array $project, array $milestone): array
    {
        if ($project['archived_at'] !== null || $milestone['archived_at'] !== null || !in_array(ProjectPolicy::level($userId, $project), ['all', 'assigned'], true)) {
            return [];
        }
        $out = [];
        foreach ((array) Config::get("workflow.milestone_transitions.{$milestone['status']}", []) as $to => $perm) {
            if (Gate::can($perm, $userId)) {
                $out[$to] = (string) Config::get("workflow.milestone_statuses.{$to}", $to);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $project
     * @param array<string,mixed> $milestone
     * @param string|null $visibility only used when approving a proposal
     */
    public static function transition(int $actorId, array $project, array $milestone, string $to, ?string $visibility = null): void
    {
        if (!in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        self::assertOpen($project);
        $from = (string) $milestone['status'];
        $perm = Config::get("workflow.milestone_transitions.{$from}.{$to}");
        if ($perm === null) {
            throw new ValidationException(["A {$from} milestone cannot become {$to}."]);
        }
        Gate::authorize((string) $perm, $actorId);
        $vis = $milestone['visibility'];
        if ($from === 'proposed' && $to === 'planned') {
            $vis = $visibility === 'client' ? 'client' : 'internal';
        }
        Database::transaction(function () use ($actorId, $project, $milestone, $from, $to, $vis): void {
            $locked = Database::one('SELECT status FROM milestones WHERE id = ? FOR UPDATE', [$milestone['id']]);
            if ($locked['status'] !== $from) {
                throw new ValidationException(['The milestone changed while you were working. Reload and try again.']);
            }
            $done = $to === 'completed';
            Database::run(
                'UPDATE milestones SET status = ?, visibility = ?, completed_at = ?, decided_by = IF(?, ?, decided_by), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to, $vis, $done ? gmdate('Y-m-d H:i:s') : null, $from === 'proposed' ? 1 : 0, $actorId, $milestone['id']],
            );
            $labels = (array) Config::get('workflow.milestone_statuses');
            $summary = $from === 'proposed'
                ? ($to === 'planned' ? "Milestone approved: {$milestone['title']}" : "Milestone proposal rejected: {$milestone['title']}")
                : "Milestone \"{$milestone['title']}\": {$labels[$from]} → {$labels[$to]}";
            $clientVisible = $vis === 'client' && $to !== 'rejected';
            ProjectActivity::log((int) $project['id'], $actorId, 'milestone.status_changed', $summary, $clientVisible ? 'client' : 'internal', 'milestone', (int) $milestone['id'], ['from' => $from, 'to' => $to]);
            Audit::record('milestone.status_changed', 'milestone', (int) $milestone['id'], ['status' => $from], ['status' => $to], ['project_id' => (int) $project['id'], 'visibility' => $vis], $actorId);
        });
    }

    public static function setArchived(int $actorId, array $project, array $milestone): void
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'milestones.edit');
        self::assertOpen($project);
        if ($milestone['archived_at'] !== null) {
            return;
        }
        Database::transaction(function () use ($actorId, $project, $milestone): void {
            Database::run('UPDATE milestones SET archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$milestone['id']]);
            Database::run('UPDATE tasks SET milestone_id = NULL WHERE milestone_id = ?', [$milestone['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, 'milestone.archived', "Milestone archived: {$milestone['title']}", 'internal', 'milestone', (int) $milestone['id']);
            Audit::record('milestone.archived', 'milestone', (int) $milestone['id'], [], [], [], $actorId);
        });
    }

    /** A milestone, but only if it belongs to THIS project (prevents crossing projects by swapping ids). @return array<string,mixed>|null */
    public static function findInProject(string $publicId, int $projectId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one('SELECT * FROM milestones WHERE public_id = ? AND project_id = ?', [$publicId, $projectId]);
    }

    /**
     * Milestones the viewer may see. Clients see only client-visible milestones that are planned/in progress/completed.
     *
     * @return list<array<string,mixed>>
     */
    public static function listFor(int $userId, array $project, bool $includeArchived = false): array
    {
        $level = ProjectPolicy::level($userId, $project);
        if ($level === null) {
            return [];
        }
        $where = 'm.project_id = ?' . ($includeArchived ? '' : ' AND m.archived_at IS NULL');
        if ($level === 'own') {
            $where .= " AND m.visibility = 'client' AND m.status IN ('planned','in_progress','completed')";
        }

        return Database::all(
            "SELECT m.*, (SELECT COUNT(*) FROM tasks t WHERE t.milestone_id = m.id AND t.archived_at IS NULL AND t.status <> 'cancelled') AS task_total,
                    (SELECT COUNT(*) FROM tasks t WHERE t.milestone_id = m.id AND t.archived_at IS NULL AND t.status = 'completed') AS task_done,
                    (m.target_date IS NOT NULL AND m.target_date < ? AND m.status NOT IN ('completed','cancelled','rejected')) AS is_overdue
               FROM milestones m WHERE {$where} ORDER BY (m.target_date IS NULL), m.target_date, m.id",
            [AppTime::today(), $project['id']],
        );
    }

    /**
     * @param array<string,mixed> $in
     * @return array{title:string,description:?string,target_date:?string,visibility:string}
     */
    private static function validate(array $in): array
    {
        $vis = FieldRules::choice($in['visibility'] ?? 'internal', ['internal' => 'Internal', 'client' => 'Client'], 'visibility', false, 'internal');

        return [
            'title' => (string) FieldRules::line($in['title'] ?? '', 'Milestone title', 3, 160),
            'description' => FieldRules::text($in['description'] ?? '', 'Description', 3, 3000, false),
            'target_date' => FieldRules::date($in['target_date'] ?? '', 'Target date'),
            'visibility' => (string) $vis,
        ];
    }

    /** @param array<string,mixed> $project */
    private static function assertOpen(array $project): void
    {
        if ($project['archived_at'] !== null || in_array($project['status'], ['cancelled'], true)) {
            throw new ValidationException(['This project is closed; milestones cannot be changed.']);
        }
    }
}
