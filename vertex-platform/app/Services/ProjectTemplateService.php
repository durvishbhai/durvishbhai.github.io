<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Module 16: reusable project templates and project checklists.
 *
 * A template is written as plain lines:
 *     M: Discovery                              a milestone
 *     T: Collect content | +3 | 120 | high      a task in the milestone above (days after the start, minutes, priority: all optional)
 *     C: Domain access confirmed                a checklist item (a scope/acceptance point)
 * Applying a template creates real milestones and tasks through the normal services (so every permission, rule and audit entry applies) and adds the
 * checklist items. Nothing is applied twice by accident: the project must confirm when it already has milestones, tasks or checklist items.
 *
 * Checklists are the project's acceptance list: staff tick items off (optionally against the deliverable version that satisfied them), items can be marked
 * out of scope, and the client sees the list read-only.
 */
final class ProjectTemplateService
{
    public const MAX_ITEMS = 120;

    // ------------------------------------------------------------------------------------------------ templates

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT t.*, (SELECT COUNT(*) FROM project_template_items i WHERE i.template_id = t.id) AS items FROM project_templates t ORDER BY t.status, t.name');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM project_templates WHERE public_id = ?', [$publicId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function items(array $t): array
    {
        return Database::all('SELECT * FROM project_template_items WHERE template_id = ? ORDER BY position', [$t['id']]);
    }

    /** The editable text form of a template. */
    public static function toText(array $t): string
    {
        $out = [];
        foreach (self::items($t) as $i) {
            if ($i['kind'] === 'milestone') {
                $out[] = 'M: ' . $i['title'];
            } elseif ($i['kind'] === 'checklist') {
                $out[] = 'C: ' . $i['title'];
            } else {
                $out[] = 'T: ' . $i['title'] . ' | +' . (int) $i['offset_days'] . ' | ' . (int) $i['estimate_minutes'] . ' | ' . $i['priority'];
            }
        }

        return implode("\n", $out);
    }

    /** @return list<array{kind:string,title:string,offset:?int,minutes:?int,priority:string}> */
    public static function parse(string $text): array
    {
        $items = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([MTC]):\s*(.+)$/i', $line, $m)) {
                $errors[] = 'Line ' . ($n + 1) . ' must start with M:, T: or C:.';
                continue;
            }
            $kind = ['M' => 'milestone', 'T' => 'task', 'C' => 'checklist'][strtoupper($m[1])];
            $parts = array_map('trim', explode('|', $m[2]));
            $title = $parts[0];
            if (mb_strlen($title) < 2 || mb_strlen($title) > 200 || preg_match('/[<>]/', $title)) {
                $errors[] = 'Line ' . ($n + 1) . ': the title must be 2 to 200 characters without < or >.';
                continue;
            }
            $offset = null;
            $minutes = null;
            $priority = 'normal';
            if ($kind === 'task') {
                if (($parts[1] ?? '') !== '' && !preg_match('/^\+?(\d{1,3})$/', $parts[1], $o)) {
                    $errors[] = 'Line ' . ($n + 1) . ': days after the start must be a number such as +3.';
                } elseif (($parts[1] ?? '') !== '') {
                    $offset = (int) $o[1];
                }
                if (($parts[2] ?? '') !== '' && !ctype_digit($parts[2])) {
                    $errors[] = 'Line ' . ($n + 1) . ': minutes must be a number.';
                } elseif (($parts[2] ?? '') !== '') {
                    $minutes = (int) $parts[2];
                }
                if (($parts[3] ?? '') !== '') {
                    if (!in_array(strtolower($parts[3]), ['low', 'normal', 'high', 'urgent'], true)) {
                        $errors[] = 'Line ' . ($n + 1) . ': priority must be low, normal, high or urgent.';
                    } else {
                        $priority = strtolower($parts[3]);
                    }
                }
            }
            $items[] = ['kind' => $kind, 'title' => $title, 'offset' => $offset, 'minutes' => $minutes, 'priority' => $priority];
        }
        if ($items === []) {
            $errors[] = 'Add at least one line.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors[] = 'A template can have at most ' . self::MAX_ITEMS . ' lines.';
        }
        if ($errors !== []) {
            throw new ValidationException(array_slice($errors, 0, 10));
        }

        return $items;
    }

    /** @param array<string,mixed> $in */
    public static function save(int $actorId, ?array $existing, array $in): string
    {
        Gate::authorize('templates.manage', $actorId);
        $name = (string) FieldRules::line($in['name'] ?? '', 'Template name', 3, 160);
        $desc = FieldRules::line($in['description'] ?? '', 'Description', 2, 500, false);
        $cat = FieldRules::choice($in['service_category'] ?? '', (array) Config::get('workflow.services'), 'service', false);
        $items = self::parse((string) ($in['items'] ?? ''));
        if (Database::one('SELECT 1 AS x FROM project_templates WHERE name = ? AND id <> ?', [$name, $existing['id'] ?? 0]) !== null) {
            throw new ValidationException(['A template with that name already exists.']);
        }

        return Database::transaction(function () use ($actorId, $existing, $name, $desc, $cat, $items): string {
            if ($existing === null) {
                $pub = uuid_hex();
                Database::run('INSERT INTO project_templates (public_id, name, service_category, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$pub, $name, $cat, $desc, $actorId]);
                $id = Database::insertId();
            } else {
                $pub = (string) $existing['public_id'];
                $id = (int) $existing['id'];
                Database::run('UPDATE project_templates SET name = ?, service_category = ?, description = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $cat, $desc, $id]);
                Database::run('DELETE FROM project_template_items WHERE template_id = ?', [$id]);
            }
            foreach ($items as $pos => $i) {
                Database::run('INSERT INTO project_template_items (template_id, position, kind, title, offset_days, estimate_minutes, priority) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $pos + 1, $i['kind'], $i['title'], $i['offset'], $i['minutes'], $i['priority']]);
            }
            Audit::record($existing === null ? 'project_template.created' : 'project_template.updated', 'project_template', $id, [], ['name' => $name, 'items' => count($items)], [], $actorId);

            return $pub;
        });
    }

    public static function retire(int $actorId, array $t, bool $retire): void
    {
        Gate::authorize('templates.manage', $actorId);
        Database::run('UPDATE project_templates SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$retire ? 'retired' : 'active', $t['id']]);
        Audit::record($retire ? 'project_template.retired' : 'project_template.reactivated', 'project_template', (int) $t['id'], [], [], [], $actorId);
    }

    /** Creates the template's milestones, tasks and checklist items in a project. @return array{milestones:int,tasks:int,checklist:int} */
    public static function apply(int $actorId, array $project, array $template, bool $confirmExisting): array
    {
        Gate::authorize('templates.apply', $actorId);
        if ($template['status'] !== 'active') {
            throw new ValidationException(['That template is retired.']);
        }
        if (!in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
        $existing = (int) Database::one('SELECT (SELECT COUNT(*) FROM milestones WHERE project_id = ? AND archived_at IS NULL) + (SELECT COUNT(*) FROM tasks WHERE project_id = ? AND archived_at IS NULL) + (SELECT COUNT(*) FROM project_checklist_items WHERE project_id = ?) AS n', [$project['id'], $project['id'], $project['id']])['n'];
        if ($existing > 0 && !$confirmExisting) {
            throw new ValidationException(["This project already has {$existing} milestone(s), task(s) or checklist item(s). Tick the box to add the template's items as well."]);
        }
        $start = $project['start_date'] ?? AppTime::today();
        $counts = ['milestones' => 0, 'tasks' => 0, 'checklist' => 0];

        return Database::transaction(function () use ($actorId, $project, $template, $start, $counts): array {
            $milestone = null;
            $pos = (int) Database::one('SELECT COALESCE(MAX(position), 0) AS p FROM project_checklist_items WHERE project_id = ?', [$project['id']])['p'];
            foreach (self::items($template) as $i) {
                if ($i['kind'] === 'milestone') {
                    $m = MilestoneService::create($actorId, $project, ['title' => $i['title'], 'description' => '', 'target_date' => '', 'visibility' => 'client']);
                    $milestone = $m['public_id'];
                    $counts['milestones']++;
                } elseif ($i['kind'] === 'task') {
                    $due = $i['offset_days'] !== null ? gmdate('Y-m-d', strtotime($start . ' +' . (int) $i['offset_days'] . ' day')) : '';
                    TaskService::create($actorId, $project, ['title' => $i['title'], 'description' => '', 'milestone' => $milestone ?? '', 'assignee' => '', 'start_date' => '', 'due_date' => $due, 'priority' => $i['priority'],
                        'estimate_minutes' => $i['estimate_minutes'] === null ? '' : (string) $i['estimate_minutes'], 'review_required' => '', 'status' => 'backlog']);
                    $counts['tasks']++;
                } else {
                    Database::run('INSERT INTO project_checklist_items (project_id, position, title, in_scope, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())', [$project['id'], ++$pos, $i['title']]);
                    $counts['checklist']++;
                }
            }
            ProjectActivity::log((int) $project['id'], $actorId, 'project.template_applied', 'Template applied: ' . $template['name'], 'internal', 'project_template', (int) $template['id']);
            Audit::record('project_template.applied', 'project', (int) $project['id'], [], $counts, ['template' => $template['name']], $actorId);

            return $counts;
        });
    }

    // ------------------------------------------------------------------------------------------------ checklist

    /** @return list<array<string,mixed>> */
    public static function checklist(array $project, bool $clientView = false): array
    {
        return Database::all(
            'SELECT ci.*, u.name AS done_by_name, dv.version_no, d.title AS deliverable_title FROM project_checklist_items ci LEFT JOIN users u ON u.id = ci.done_by
               LEFT JOIN deliverable_versions dv ON dv.id = ci.delivered_in_version_id LEFT JOIN deliverables d ON d.id = dv.deliverable_id
              WHERE ci.project_id = ?' . ($clientView ? ' AND ci.in_scope = 1' : '') . ' ORDER BY ci.position',
            [$project['id']],
        );
    }

    /** @return array{done:int,total:int} counts only items that are in scope */
    public static function progress(array $project): array
    {
        $r = Database::one('SELECT COUNT(*) AS total, COALESCE(SUM(done_at IS NOT NULL), 0) AS done FROM project_checklist_items WHERE project_id = ? AND in_scope = 1', [$project['id']]);

        return ['done' => (int) $r['done'], 'total' => (int) $r['total']];
    }

    public static function addItem(int $actorId, array $project, string $title): void
    {
        self::authorizeEdit($actorId, $project);
        $title = (string) FieldRules::line($title, 'Checklist item', 2, 200);
        Database::transaction(function () use ($actorId, $project, $title): void {
            Database::one('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            $n = (int) Database::one('SELECT COUNT(*) AS n FROM project_checklist_items WHERE project_id = ?', [$project['id']])['n'];
            if ($n >= 200) {
                throw new ValidationException(['A project can have at most 200 checklist items.']);
            }
            $pos = (int) Database::one('SELECT COALESCE(MAX(position), 0) AS p FROM project_checklist_items WHERE project_id = ?', [$project['id']])['p'] + 1;
            Database::run('INSERT INTO project_checklist_items (project_id, position, title, in_scope, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())', [$project['id'], $pos, $title]);
            Audit::record('checklist.item_added', 'project', (int) $project['id'], [], ['title' => $title], [], $actorId);
        });
    }

    /** @param 'done'|'undo'|'out_of_scope'|'in_scope'|'delete' $action */
    public static function changeItem(int $actorId, array $project, int $itemId, string $action, ?int $versionId = null): void
    {
        self::authorizeEdit($actorId, $project);
        $item = Database::one('SELECT * FROM project_checklist_items WHERE id = ? AND project_id = ?', [$itemId, $project['id']]) ?? throw new HttpException(404);
        if ($versionId !== null && Database::one('SELECT 1 AS x FROM deliverable_versions dv JOIN deliverables d ON d.id = dv.deliverable_id WHERE dv.id = ? AND d.project_id = ?', [$versionId, $project['id']]) === null) {
            throw new ValidationException(["Choose a deliverable version of this project."]);
        }
        match ($action) {
            'done' => Database::run('UPDATE project_checklist_items SET done_at = UTC_TIMESTAMP(), done_by = ?, delivered_in_version_id = ? WHERE id = ?', [$actorId, $versionId, $itemId]),
            'undo' => Database::run('UPDATE project_checklist_items SET done_at = NULL, done_by = NULL, delivered_in_version_id = NULL WHERE id = ?', [$itemId]),
            'out_of_scope' => Database::run('UPDATE project_checklist_items SET in_scope = 0 WHERE id = ?', [$itemId]),
            'in_scope' => Database::run('UPDATE project_checklist_items SET in_scope = 1 WHERE id = ?', [$itemId]),
            'delete' => Database::run('DELETE FROM project_checklist_items WHERE id = ?', [$itemId]),
            default => throw new HttpException(422),
        };
        Audit::record('checklist.item_' . $action, 'project', (int) $project['id'], [], [], ['title' => $item['title']], $actorId);
    }

    private static function authorizeEdit(int $actorId, array $project): void
    {
        Gate::authorize('checklists.manage', $actorId);
        if (!in_array(ProjectPolicy::level($actorId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(403);
        }
    }
}
