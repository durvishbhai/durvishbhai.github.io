<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Project lifecycle. Every mutation: permission + project access check, validation, one transaction, timeline entry
 * (project_activity) and security audit entry. See config/workflow.php for the explicit status machine.
 */
final class ProjectService
{
    /**
     * Creates a DRAFT project for an existing client record.
     *
     * @param array<string,mixed> $in title, service_category, description, scope, start_date, target_date, priority, budget
     * @return array{id:int,public_id:string,code:string}
     */
    public static function create(int $actorId, int $clientId, array $in, ?int $enquiryId = null): array
    {
        Gate::authorize('projects.create', $actorId);
        $client = Database::one('SELECT id, status, archived_at FROM clients WHERE id = ?', [$clientId]) ?? throw new ValidationException(['Choose a valid client.']);
        if ($client['status'] !== 'active' || $client['archived_at'] !== null) {
            throw new ValidationException(['Projects can only be created for active clients.']);
        }
        $d = self::validate($in);

        return Database::transaction(function () use ($actorId, $clientId, $d, $enquiryId): array {
            $pub = uuid_hex();
            $code = CrmReference::next('project');
            Database::run(
                "INSERT INTO projects (public_id, code, client_id, enquiry_id, title, service_category, description, scope, status, priority, start_date, target_date, budget_minor,
                                       created_by, status_changed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $code, $clientId, $enquiryId, $d['title'], $d['service_category'], $d['description'], $d['scope'], $d['priority'], $d['start_date'], $d['target_date'], $d['budget_minor'], $actorId],
            );
            $id = Database::insertId();
            ProjectActivity::log($id, $actorId, 'project.created', "Project {$code} created" . ($enquiryId !== null ? ' from an enquiry' : ''), 'client');
            Audit::record('project.created', 'project', $id, [], ['code' => $code, 'client_id' => $clientId, 'status' => 'draft', 'priority' => $d['priority']], ['enquiry_id' => $enquiryId], $actorId);

            return ['id' => $id, 'public_id' => $pub, 'code' => $code];
        });
    }

    // ------------------------------------------------------------------ editing

    /**
     * Edits project details. Budget is a financial field and needs `projects.record_approval`; a request that tries to
     * change it without that permission is refused (not silently ignored).
     *
     * @param array<string,mixed> $project a row from ProjectPolicy::find()
     * @param array<string,mixed> $in
     */
    public static function update(int $actorId, array $project, array $in): void
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'projects.edit');
        self::assertOpen($project);
        $d = self::validate($in);
        $budgetChanged = array_key_exists('budget', $in) && (int) ($d['budget_minor'] ?? 0) !== (int) ($project['budget_minor'] ?? 0);
        if ($budgetChanged && !Gate::can('projects.record_approval', $actorId)) {
            throw new HttpException(403, 'Only an administrator can change a project budget.');
        }
        if (!$budgetChanged || !array_key_exists('budget', $in)) {
            $d['budget_minor'] = $project['budget_minor'] === null ? null : (int) $project['budget_minor'];
        }
        $changed = [];
        foreach (['title', 'service_category', 'description', 'scope', 'start_date', 'target_date', 'budget_minor'] as $f) {
            if ((string) $d[$f] !== (string) $project[$f]) {
                $changed[] = $f;
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $project, $d, $changed): void {
            Database::run(
                'UPDATE projects SET title = ?, service_category = ?, description = ?, scope = ?, start_date = ?, target_date = ?, budget_minor = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$d['title'], $d['service_category'], $d['description'], $d['scope'], $d['start_date'], $d['target_date'], $d['budget_minor'], $project['id']],
            );
            if (in_array('target_date', $changed, true)) {
                ProjectActivity::log((int) $project['id'], $actorId, 'project.deadline_changed', 'Target delivery date: ' . ($project['target_date'] ?? 'not set') . ' → ' . ($d['target_date'] ?? 'not set'), 'client', 'project', (int) $project['id'], ['from' => $project['target_date'], 'to' => $d['target_date']]);
            }
            if (in_array('start_date', $changed, true)) {
                ProjectActivity::log((int) $project['id'], $actorId, 'project.start_changed', 'Start date: ' . ($project['start_date'] ?? 'not set') . ' → ' . ($d['start_date'] ?? 'not set'), 'client');
            }
            $others = array_diff($changed, ['target_date', 'start_date']);
            if ($others !== []) {
                ProjectActivity::log((int) $project['id'], $actorId, 'project.updated', 'Project details updated: ' . implode(', ', array_map(static fn (string $f): string => str_replace('_', ' ', $f), $others)));
            }
            Audit::record('project.updated', 'project', (int) $project['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    // ------------------------------------------------------------------ status machine

    /**
     * Statuses this user may move the project to right now (permission + project access + machine).
     *
     * @param array<string,mixed> $project
     * @return array<string,string> status => label
     */
    public static function allowedTransitions(int $userId, array $project): array
    {
        if ($project['archived_at'] !== null || !in_array(ProjectPolicy::level($userId, $project), ['all', 'assigned'], true)) {
            return [];
        }
        $out = [];
        foreach ((array) Config::get("workflow.project_transitions.{$project['status']}", []) as $to => $perm) {
            if (Gate::can($perm, $userId)) {
                $out[$to] = (string) Config::get("workflow.project_statuses.{$to}", $to);
            }
        }

        return $out;
    }

    /**
     * Moves the project along the explicit status machine. Completion is NEVER automatic (not even with every task done):
     * it needs `projects.complete` and $confirmed = true. No transition marks anything as paid.
     *
     * @param array<string,mixed> $project
     */
    public static function transition(int $actorId, array $project, string $to, string $note = '', bool $confirmed = false): void
    {
        if (!in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        self::assertOpen($project, false);
        $from = (string) $project['status'];
        $perm = Config::get("workflow.project_transitions.{$from}.{$to}");
        if ($perm === null) {
            throw new ValidationException(['A project that is ' . Config::get("workflow.project_statuses.{$from}", $from) . ' cannot move to ' . Config::get("workflow.project_statuses.{$to}", $to) . '.']);
        }
        Gate::authorize((string) $perm, $actorId);

        $override = in_array("{$from}>{$to}", (array) Config::get('workflow.project_manual_payment_override', []), true);
        if (in_array($to, (array) Config::get('workflow.project_transition_requires_note', []), true) || $override) {
            $note = (string) FieldRules::line($note, $override ? 'Note (who confirmed payment, and how)' : 'Reason', 3, 500);
        } else {
            $note = (string) FieldRules::line($note, 'Note', 3, 500, false);
        }
        if ($to === 'completed' && !$confirmed) {
            throw new ValidationException(['Completing a project is a deliberate decision: tick the confirmation box to continue.']);
        }
        if ($to === 'in_progress' && !ProjectAssignmentService::hasMembers((int) $project['id'])) {
            throw new ValidationException(['Assign at least one team member before moving the project to In Progress.']);
        }

        Database::transaction(function () use ($actorId, $project, $from, $to, $note, $override): void {
            $locked = Database::one('SELECT status FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            if ($locked['status'] !== $from) {
                throw new ValidationException(['The project status changed while you were working. Reload and try again.']);
            }
            $openTasks = (int) Database::one("SELECT COUNT(*) AS c FROM tasks WHERE project_id = ? AND archived_at IS NULL AND status NOT IN ('completed','cancelled')", [$project['id']])['c'];
            $done = $to === 'completed';
            Database::run(
                'UPDATE projects SET status = ?, status_changed_at = UTC_TIMESTAMP(), completed_at = ?, completed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to, $done ? gmdate('Y-m-d H:i:s') : null, $done ? $actorId : null, $project['id']],
            );
            $labels = (array) Config::get('workflow.project_statuses');
            ProjectActivity::log((int) $project['id'], $actorId, 'project.status_changed', "Status: {$labels[$from]} → {$labels[$to]}" . ($note !== '' ? " ({$note})" : ''), 'client', 'project', (int) $project['id'], [
                'from' => $from, 'to' => $to, 'note' => $note !== '' ? $note : null, 'open_tasks_at_change' => $openTasks,
                'manual_payment_override' => $override ? 'Payment not verified by the system (billing is not connected); confirmed manually.' : null,
            ]);
            Audit::record('project.status_changed', 'project', (int) $project['id'], ['status' => $from], ['status' => $to], ['manual_payment_override' => $override, 'open_tasks' => $openTasks], $actorId);
        });
    }

    public static function setArchived(int $actorId, array $project, bool $archive): void
    {
        Gate::authorize('projects.archive', $actorId);
        if (ProjectPolicy::level($actorId, $project) !== 'all') {
            throw new HttpException(403);
        }
        if ($archive) {
            if (!in_array($project['status'], ['completed', 'cancelled'], true)) {
                throw new ValidationException(['Only completed or cancelled projects can be archived.']);
            }
            if ($project['archived_at'] !== null) {
                return;
            }
        } elseif ($project['archived_at'] === null) {
            return;
        }
        Database::transaction(function () use ($actorId, $project, $archive): void {
            Database::run('UPDATE projects SET archived_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$archive ? gmdate('Y-m-d H:i:s') : null, $project['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, $archive ? 'project.archived' : 'project.restored', $archive ? 'Project archived' : 'Project restored from archive');
            Audit::record($archive ? 'project.archived' : 'project.restored', 'project', (int) $project['id'], [], [], [], $actorId);
        });
    }

    // ------------------------------------------------------------------ notes & client feedback

    /**
     * kind: internal (staff only) | client_visible (staff, shown to the client) | client_feedback (the client, on own project).
     *
     * @param array<string,mixed> $project
     */
    public static function addNote(int $actorId, array $project, string $kind, string $body): void
    {
        match ($kind) {
            'internal' => ProjectPolicy::authorizeStaff($actorId, $project, 'projects.notes_internal'),
            'client_visible' => ProjectPolicy::authorizeStaff($actorId, $project, 'projects.notes_client'),
            'client_feedback' => ProjectPolicy::level($actorId, $project) === 'own' && Gate::can('projects.give_feedback', $actorId) ? null : throw new HttpException(403),
            default => throw new ValidationException(['Unknown note type.']),
        };
        self::assertOpen($project, false);
        $body = (string) FieldRules::text($body, $kind === 'client_feedback' ? 'Feedback' : 'Note', 2, 4000);
        Database::transaction(function () use ($actorId, $project, $kind, $body): void {
            Database::run('INSERT INTO project_notes (project_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$project['id'], $actorId, $kind, $body]);
            $noteId = Database::insertId();
            $summary = match ($kind) {
                'internal' => 'Internal note added',
                'client_visible' => 'Note shared with the client',
                default => 'Client feedback received',
            };
            ProjectActivity::log((int) $project['id'], $actorId, $kind === 'client_feedback' ? 'project.client_feedback' : 'project.note', $summary, $kind === 'internal' ? 'internal' : 'client', 'note', $noteId);
            Audit::record('project.note_added', 'project', (int) $project['id'], [], [], ['kind' => $kind, 'length' => mb_strlen($body)], $actorId);
        });
    }

    /**
     * Notes the viewer may read. Staff with projects.notes_internal see everything; other staff see client-visible notes
     * and client feedback; the client sees client-visible notes and feedback, NEVER internal notes.
     *
     * @return list<array<string,mixed>>
     */
    public static function notes(int $userId, array $project): array
    {
        $level = ProjectPolicy::level($userId, $project);
        if ($level === null) {
            return [];
        }
        $internal = $level !== 'own' && Gate::can('projects.notes_internal', $userId);
        $kinds = $internal ? ['internal', 'client_visible', 'client_feedback'] : ['client_visible', 'client_feedback'];
        $in = implode(',', array_fill(0, count($kinds), '?'));

        return Database::all(
            "SELECT n.id, n.kind, n.body, n.created_at, u.name AS author_name FROM project_notes n LEFT JOIN users u ON u.id = n.author_id
              WHERE n.project_id = ? AND n.kind IN ({$in}) ORDER BY n.id DESC LIMIT 100",
            array_merge([$project['id']], $kinds),
        );
    }

    /** @return array{done:int,total:int,percent:int} progress derived from tasks (cancelled and archived tasks are ignored) */
    public static function progress(int $projectId): array
    {
        $r = Database::one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'completed'), 0) AS done FROM tasks WHERE project_id = ? AND archived_at IS NULL AND status <> 'cancelled'",
            [$projectId],
        );
        $total = (int) $r['total'];
        $done = (int) $r['done'];

        return ['done' => $done, 'total' => $total, 'percent' => $total === 0 ? 0 : (int) round($done * 100 / $total)];
    }

    /** @param array<string,mixed> $project */
    private static function assertOpen(array $project, bool $blockClosed = true): void
    {
        if ($project['archived_at'] !== null) {
            throw new ValidationException(['This project is archived and read-only.']);
        }
        if ($blockClosed && in_array($project['status'], ['completed', 'cancelled'], true)) {
            throw new ValidationException(['A ' . $project['status'] . ' project cannot be edited. Reopen it first.']);
        }
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function validate(array $in): array
    {
        $services = (array) config('workflow.services');
        $priorities = (array) config('workflow.priorities');
        $start = FieldRules::date($in['start_date'] ?? '', 'Start date');
        $target = FieldRules::date($in['target_date'] ?? '', 'Target delivery date');
        if ($start !== null && $target !== null && $target < $start) {
            throw new ValidationException(['The target delivery date cannot be before the start date.']);
        }

        return [
            'title' => FieldRules::line($in['title'] ?? '', 'Project name', 3, 200),
            'service_category' => FieldRules::choice($in['service_category'] ?? '', $services, 'service category', false),
            'description' => FieldRules::text($in['description'] ?? '', 'Description', 3, 5000, false),
            'scope' => FieldRules::text($in['scope'] ?? '', 'Scope', 3, 10000, false),
            'priority' => FieldRules::choice($in['priority'] ?? 'normal', $priorities, 'priority', false, 'normal'),
            'start_date' => $start,
            'target_date' => $target,
            'budget_minor' => FieldRules::moneyMinor($in['budget'] ?? '', 'Budget'),
        ];
    }
}
