<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\TaskPolicy;
use Vertex\Services\TaskQuery;
use Vertex\Services\TaskService;
use Vertex\Services\TaskWorkflow;

/** Tasks: permissions, the status machine, dependencies/cycles, review rules, scoping, deadlines. */
final class TaskServiceTest extends DatabaseTestCase
{
    private int $admin;
    private int $manager;   // assigned
    private int $manager2;  // NOT assigned
    private int $dev;       // assigned worker
    private int $dev2;      // NOT assigned worker
    private int $support;   // assigned, comment-only
    private int $clientUser;
    private array $proj;
    private int $n = 0;
    private mixed $zoneBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->manager2 = $this->makeUser('mgr2@x.test', ['manager']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->dev2 = $this->makeUser('dev2@x.test', ['website_developer']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
        $this->clientUser = $this->makeUser('cl@x.test', ['client']);
        $this->proj = $this->makeProject($this->clientUser, 'Alpha Site');
        Database::run("UPDATE projects SET status = 'in_progress' WHERE id = ?", [$this->proj['id']]);
        foreach ([$this->manager, $this->dev, $this->support] as $u) {
            ProjectAssignmentService::assign($this->admin, $this->proj['id'], $u, 'member');
        }
    }

    protected function tearDown(): void
    {
        AppTime::freeze(null);
        if ($this->zoneBefore !== null) {
            Config::set('app.timezone', $this->zoneBefore);
            $this->zoneBefore = null;
        }
    }

    private function project(): array
    {
        return ProjectPolicy::find($this->proj['public_id']);
    }

    /** @param array<string,mixed> $over @return array<string,mixed> task row */
    private function task(array $over = [], ?int $actor = null, ?array $project = null): array
    {
        $t = TaskService::create($actor ?? $this->manager, $project ?? $this->project(), $over + ['title' => 'Task number ' . ++$this->n]);

        return TaskPolicy::find($t['public_id']);
    }

    /** @return array<string,mixed> */
    private function fresh(array $t): array
    {
        return TaskPolicy::find($t['public_id']);
    }

    private function setStatus(array $t, string $s): array
    {
        Database::run('UPDATE tasks SET status = ? WHERE id = ?', [$s, $t['id']]);

        return $this->fresh($t);
    }

    private function expect403(callable $fn, string $why): void
    {
        try {
            $fn();
            $this->fail("Expected 403: {$why}");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status, $why);
        }
    }

    private function expectInvalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected validation error '{$needle}'");
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' | ', $e->errors));
        }
    }

    // ---- creation & permissions -------------------------------------------------------------------------------------------

    public function testOnlyStaffWithPermissionAndProjectAccessCreateTasks(): void
    {
        foreach ([[$this->dev, 'worker lacks tasks.create'], [$this->support, 'support lacks tasks.create'], [$this->manager2, 'unassigned manager has no project access'], [$this->clientUser, 'clients never create tasks']] as [$u, $why]) {
            $this->expect403(fn () => TaskService::create($u, $this->project(), ['title' => 'Nope here']), $why);
        }
        $t = $this->task(['title' => 'Build header', 'priority' => 'high', 'estimate_minutes' => '90', 'due_date' => '2026-12-01']);
        $this->assertSame('backlog', $t['status']);
        $this->assertSame('high', $t['priority']);
        $this->assertSame(90, (int) $t['estimate_minutes']);
        $this->assertSame($this->manager, (int) $t['created_by']);
        $this->assertSame(1, (int) $t['review_required'], 'review is the default');
        $adm = $this->task([], $this->admin);
        $this->assertSame($this->admin, (int) $adm['created_by'], 'admins (view_all) may create');
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'task.created'")['c']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM project_activity WHERE event_type = 'task.created'")['c']);
    }

    public function testValidation(): void
    {
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'ab']), 'title');
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Valid title', 'start_date' => '2026-12-10', 'due_date' => '2026-12-01']), 'before the start');
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Valid title', 'due_date' => '2026-02-31']), 'valid date');
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Valid title', 'priority' => 'critical']), 'priority');
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Valid title', 'estimate_minutes' => '-5']), 'minutes');
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Bad <b>html</b>']), 'title');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM tasks')['c'], 'nothing is created on failure');
    }

    public function testMilestoneMustBelongToTheSameProject(): void
    {
        $other = $this->makeProject($this->clientUser, 'Other');
        ProjectAssignmentService::assign($this->admin, $other['id'], $this->manager, 'member');
        $m = MilestoneService::create($this->admin, ProjectPolicy::find($other['public_id']), ['title' => 'Elsewhere milestone']);
        $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'Valid title', 'milestone' => $m['public_id']]), 'milestone');
        $mine = MilestoneService::create($this->admin, $this->project(), ['title' => 'Local milestone']);
        $t = $this->task(['milestone' => $mine['public_id']]);
        $this->assertSame($mine['public_id'], $t['milestone_public_id']);
    }

    public function testOnlyTaskAssignersAssignAndOnlyToActiveProjectMembers(): void
    {
        $t = $this->task();
        $this->expect403(fn () => TaskService::assign($this->dev, $t, $this->publicId($this->dev)), 'workers cannot assign tasks, not even to themselves');
        $this->expect403(fn () => TaskService::assign($this->support, $t, $this->publicId($this->support)), 'support cannot assign');
        $this->expectInvalid(fn () => TaskService::assign($this->manager, $t, $this->publicId($this->dev2)), 'member of this project');
        $this->expectInvalid(fn () => TaskService::assign($this->manager, $t, $this->publicId($this->clientUser)), 'member of this project');
        $this->expectInvalid(fn () => TaskService::assign($this->manager, $t, 'not-an-id'), 'member of this project');
        TaskService::assign($this->manager, $t, $this->publicId($this->dev));
        $this->assertSame($this->dev, (int) $this->fresh($t)['assignee_id']);
        TaskService::assign($this->manager, $this->fresh($t), null);
        $this->assertNull($this->fresh($t)['assignee_id']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action IN ('task.assigned','task.unassigned')")['c']);
        // A revoked member can no longer be assigned.
        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->dev);
        $this->expectInvalid(fn () => TaskService::assign($this->manager, $this->fresh($t), $this->publicId($this->dev)), 'member of this project');
        // Creating with an assignee also needs tasks.assign and membership.
        $this->expectInvalid(fn () => $this->task(['assignee' => $this->publicId($this->dev2)]), 'member of this project');
    }

    public function testCreatingWithAnAssigneeNeedsTheAssignPermissionEvenForTaskCreators(): void
    {
        // A custom role set-up: may create tasks but may NOT assign them. The assignee field must not become a back door.
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id AND r.slug = 'manager' JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = 'tasks.assign'");
        Gate::flush();
        $this->expect403(fn () => $this->task(['assignee' => $this->publicId($this->dev)]), 'creating with an assignee needs tasks.assign');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM tasks')['c'], 'nothing was created by the refused attempt');
        $t = $this->task(['title' => 'Unassigned is fine']);
        $this->assertNull($t['assignee_id']);
        $this->expect403(fn () => TaskService::assign($this->manager, $t, $this->publicId($this->dev)), 'nor can they assign afterwards');
    }

    // ---- status machine ---------------------------------------------------------------------------------------------------

    public function testEveryStatusPairMatchesTheConfiguredMachineForAnAdministrator(): void
    {
        $statuses = array_keys((array) Config::get('workflow.task_statuses'));
        $this->assertCount(8, $statuses);
        $t = $this->task([], $this->admin);
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                $row = $this->setStatus($t, $from);
                $listed = Config::get("workflow.task_transitions.{$from}.{$to}") !== null;
                try {
                    TaskService::transition($this->admin, $row, $to, 'because the test says so');
                    $this->assertTrue($listed, "{$from} -> {$to} succeeded but is not in the machine");
                    $this->assertSame($to, $this->fresh($t)['status']);
                } catch (ValidationException $e) {
                    $this->assertFalse($listed && $from === $to, 'sanity');
                    $this->assertFalse($listed, "{$from} -> {$to} is listed but was refused: " . implode(' ', $e->errors));
                    $this->assertSame($from, $this->fresh($t)['status'], 'a refused move changes nothing');
                }
            }
        }
    }

    public function testAssigneeMovesOwnWorkButNotOthersOrPrivilegedMoves(): void
    {
        $mine = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        $other = $this->task(['assignee' => $this->publicId($this->support), 'status' => 'to_do']);
        TaskService::transition($this->dev, $mine, 'in_progress');
        $this->assertSame('in_progress', $this->fresh($mine)['status']);
        $this->expect403(fn () => TaskService::transition($this->dev, $other, 'in_progress'), 'not the assignee');
        $this->expect403(fn () => TaskService::transition($this->dev, $this->fresh($mine), 'cancelled'), 'cancelling needs tasks.edit');
        $this->expect403(fn () => TaskService::transition($this->dev, $this->setStatus($mine, 'backlog'), 'to_do'), 'planning moves need tasks.edit');
        $this->expectInvalid(fn () => TaskService::transition($this->dev, $this->setStatus($mine, 'to_do'), 'completed'), 'cannot move');
        try {
            TaskService::transition($this->dev2, $mine, 'in_progress');
            $this->fail('unassigned worker must not reach the task');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status, 'invisible tasks look like missing ones');
        }
        $this->expect403(fn () => TaskService::transition($this->support, $this->fresh($other), 'in_progress'), 'support has no update rights');
    }

    public function testReviewIsRequiredBeforeCompletionUnlessTheTaskOptsOut(): void
    {
        $t = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        TaskService::transition($this->dev, $t, 'in_progress');
        $this->expectInvalid(fn () => TaskService::transition($this->dev, $this->fresh($t), 'completed'), 'needs review');
        TaskService::transition($this->dev, $this->fresh($t), 'in_review');
        $this->assertSame('pending', $this->fresh($t)['review_status']);
        $this->expect403(fn () => TaskService::transition($this->dev, $this->fresh($t), 'completed'), 'workers cannot approve');
        TaskService::transition($this->manager, $this->fresh($t), 'changes_requested', 'Please fix spacing');
        $f = $this->fresh($t);
        $this->assertSame('changes_requested', $f['status']);
        $this->assertSame('changes_requested', $f['review_status']);
        TaskService::transition($this->dev, $f, 'in_progress');
        TaskService::transition($this->dev, $this->fresh($t), 'in_review');
        TaskService::transition($this->manager, $this->fresh($t), 'completed');
        $f = $this->fresh($t);
        $this->assertSame('completed', $f['status']);
        $this->assertSame('approved', $f['review_status']);
        $this->assertNotNull($f['completed_at']);

        $free = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do', 'review_required' => '0']);
        TaskService::transition($this->dev, $free, 'in_progress');
        TaskService::transition($this->dev, $this->fresh($free), 'completed');
        $this->assertSame('not_required', $this->fresh($free)['review_status']);
    }

    public function testNobodyApprovesTheirOwnWork(): void
    {
        $t = $this->task(['assignee' => $this->publicId($this->manager), 'status' => 'to_do']);
        TaskService::transition($this->manager, $t, 'in_progress');
        TaskService::transition($this->manager, $this->fresh($t), 'in_review');
        $this->expectInvalid(fn () => TaskService::transition($this->manager, $this->fresh($t), 'completed'), 'own work');
        TaskService::transition($this->admin, $this->fresh($t), 'completed');
        $this->assertSame('completed', $this->fresh($t)['status']);
    }

    public function testReasonsAreRequiredAndRecordedAndReopeningClearsCompletion(): void
    {
        $t = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        $this->expectInvalid(fn () => TaskService::transition($this->dev, $t, 'blocked'), 'reason');
        TaskService::transition($this->dev, $t, 'blocked', 'Waiting for the logo files');
        $this->assertSame('blocked', $this->fresh($t)['status']);
        $this->assertStringContainsString('Waiting for the logo files', Database::one('SELECT body FROM task_comments WHERE task_id = ?', [$t['id']])['body']);

        $done = $this->setStatus($this->task([], $this->admin), 'in_review');
        TaskService::transition($this->admin, $done, 'completed');
        $this->assertNotNull($this->fresh($done)['completed_at']);
        $this->expect403(fn () => TaskService::transition($this->dev, $this->fresh($done), 'in_progress'), 'workers cannot reopen');
        TaskService::transition($this->admin, $this->fresh($done), 'in_progress');
        $f = $this->fresh($done);
        $this->assertNull($f['completed_at']);
        $this->assertSame('not_required', $f['review_status']);
    }

    public function testStaleStatusIsRejectedAndNothingChanges(): void
    {
        $t = $this->task(['status' => 'to_do'], $this->manager);
        $stale = $t;
        TaskService::transition($this->manager, $t, 'in_progress');
        $this->expectInvalid(fn () => TaskService::transition($this->manager, $stale, 'blocked', 'late click'), 'changed');
        $this->assertSame('in_progress', $this->fresh($t)['status']);
    }

    public function testTransitionsAreLoggedOnTimelineAndAuditWithBothValues(): void
    {
        $t = $this->task(['status' => 'to_do']);
        TaskService::transition($this->manager, $t, 'in_progress');
        $a = Database::one("SELECT * FROM audit_logs WHERE action = 'task.status_changed'");
        $this->assertNotNull($a);
        $this->assertStringContainsString('to_do', (string) $a['before_json']);
        $this->assertStringContainsString('in_progress', (string) $a['after_json']);
        $act = Database::one("SELECT * FROM project_activity WHERE event_type = 'task.status_changed'");
        $this->assertSame('internal', $act['visibility'], 'task activity is never shown to clients');
        $this->assertSame($this->manager, (int) $act['actor_id']);
    }

    // ---- dependencies -----------------------------------------------------------------------------------------------------

    public function testDependenciesBlockStartingReviewingAndCompleting(): void
    {
        $a = $this->task(['status' => 'to_do']);
        $b = $this->task(['status' => 'to_do']);
        TaskService::addDependency($this->manager, $a, $b['public_id']);
        $this->assertCount(1, TaskWorkflow::unresolvedDependencies((int) $a['id']));
        $this->expectInvalid(fn () => TaskService::transition($this->manager, $this->fresh($a), 'in_progress'), 'unfinished work');
        // blocking/backlog moves are still fine while waiting
        TaskService::transition($this->manager, $this->fresh($a), 'blocked', 'Waiting on B');
        $this->assertSame('blocked', $this->fresh($a)['status']);
        // finish B (review not required)
        Database::run('UPDATE tasks SET review_required = 0 WHERE id = ?', [$b['id']]);
        TaskService::transition($this->manager, $this->fresh($b), 'in_progress');
        TaskService::transition($this->manager, $this->fresh($b), 'completed');
        $this->assertSame([], TaskWorkflow::unresolvedDependencies((int) $a['id']));
        TaskService::transition($this->manager, $this->fresh($a), 'in_progress');
        $this->assertSame('in_progress', $this->fresh($a)['status']);
    }

    public function testCancelledPrerequisitesStillBlockButArchivedOnesDoNot(): void
    {
        $a = $this->task(['status' => 'to_do']);
        $b = $this->task(['status' => 'to_do']);
        TaskService::addDependency($this->manager, $a, $b['public_id']);
        $this->setStatus($b, 'cancelled');
        $this->expectInvalid(fn () => TaskService::transition($this->manager, $this->fresh($a), 'in_progress'), 'unfinished work');
        TaskService::setArchived($this->manager, $this->fresh($b), true);
        TaskService::transition($this->manager, $this->fresh($a), 'in_progress');
        $this->assertSame('in_progress', $this->fresh($a)['status']);
    }

    public function testCircularDependenciesAreRejected(): void
    {
        $a = $this->task();
        $b = $this->task();
        $c = $this->task();
        TaskService::addDependency($this->manager, $a, $b['public_id']); // a waits on b
        TaskService::addDependency($this->manager, $b, $c['public_id']); // b waits on c
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $c, $a['public_id']), 'circular');
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $b, $a['public_id']), 'circular');
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $a, $a['public_id']), 'itself');
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $a, $b['public_id']), 'already exists');
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM task_dependencies')['c']);
        // Non-cyclic additions remain fine, and removing an edge re-allows the opposite direction.
        TaskService::addDependency($this->manager, $a, $c['public_id']);
        TaskService::removeDependency($this->manager, $this->fresh($b), $c['public_id']);
        TaskService::addDependency($this->manager, $c, $b['public_id']);
        $this->assertTrue(TaskService::reaches((int) $c['id'], (int) $b['id']));
        // The picker never offers an option that would be refused.
        $offered = array_column(TaskService::dependencyCandidates($this->fresh($b)), 'public_id');
        $this->assertNotContains($c['public_id'], $offered, 'c already depends on b: offering it would create a cycle');
        $this->assertNotContains($b['public_id'], $offered);
    }

    public function testLongChainsAreCheckedWithoutRecursionAndDatabaseRefusesSelfLoops(): void
    {
        $prev = $this->task();
        $first = $prev;
        for ($i = 0; $i < 60; $i++) {
            $next = $this->task();
            TaskService::addDependency($this->manager, $next, $prev['public_id']);
            $prev = $next;
        }
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $first, $prev['public_id']), 'circular');
        $this->expectException(\PDOException::class);
        Database::run('INSERT INTO task_dependencies (task_id, depends_on_task_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$first['id'], $first['id']]);
    }

    public function testDependenciesCannotCrossProjectsAndNeedEditPermission(): void
    {
        $other = $this->makeProject($this->clientUser, 'Other');
        ProjectAssignmentService::assign($this->admin, $other['id'], $this->manager, 'member');
        $foreign = $this->task([], $this->manager, ProjectPolicy::find($other['public_id']));
        $mine = $this->task();
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $mine, $foreign['public_id']), 'this project');
        $this->expectInvalid(fn () => TaskService::addDependency($this->manager, $mine, str_repeat('a', 32)), 'this project');
        $mine2 = $this->task(['assignee' => $this->publicId($this->dev)]);
        $this->expect403(fn () => TaskService::addDependency($this->dev, $mine2, $mine['public_id']), 'workers cannot edit the graph');
        $this->expect403(fn () => TaskService::removeDependency($this->dev, $mine2, $mine['public_id']), 'nor remove');
    }

    // ---- editing, comments, time, archive, closed projects ----------------------------------------------------------------

    public function testEditArchiveCommentAndTimeRules(): void
    {
        $t = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        $this->expect403(fn () => TaskService::update($this->dev, $t, ['title' => 'Renamed by worker']), 'workers cannot edit');
        $this->expect403(fn () => TaskService::setArchived($this->dev, $t, true), 'workers cannot archive');
        TaskService::update($this->manager, $t, ['title' => 'Renamed task', 'priority' => 'urgent', 'due_date' => '2026-12-24']);
        $f = $this->fresh($t);
        $this->assertSame('Renamed task', $f['title']);
        $this->assertSame('urgent', $f['priority']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM project_activity WHERE event_type = 'task.deadline_changed'")['c']);

        TaskService::addComment($this->dev, $f, 'Started on this');
        TaskService::addComment($this->support, $f, 'Client asked about it');
        $this->expect403(fn () => TaskService::addComment($this->dev2, $f, 'I am not on this project'), 'unassigned worker');
        $this->expect403(fn () => TaskService::addComment($this->clientUser, $f, 'client'), 'client');
        $this->expectInvalid(fn () => TaskService::addComment($this->dev, $f, '   '), 'required');
        $this->assertCount(2, TaskService::detail($f)['comments']);

        TaskService::logTime($this->dev, $f, '45', '', 'wireframes');
        $this->assertSame(45, TaskService::detail($f)['logged_minutes']);
        $this->expect403(fn () => TaskService::logTime($this->support, $f, '10', '', ''), 'support cannot log time');
        $this->expect403(fn () => TaskService::logTime($this->manager, $f, '10', '', ''), 'only the assignee logs time');
        $this->expectInvalid(fn () => TaskService::logTime($this->dev, $f, '0', '', ''), 'at least 1');
        $this->expectInvalid(fn () => TaskService::logTime($this->dev, $f, '10', '2099-01-01', ''), 'future');

        $this->expectInvalid(fn () => TaskService::setArchived($this->manager, $this->setStatus($t, 'in_progress'), true), 'can be archived');
        TaskService::setArchived($this->manager, $this->setStatus($t, 'completed'), true);
        $this->assertNotNull($this->fresh($t)['archived_at']);
        $this->expectInvalid(fn () => TaskService::update($this->manager, $this->fresh($t), ['title' => 'Edit archived']), 'archived');
        TaskService::setArchived($this->manager, $this->fresh($t), false);
        $this->assertNull($this->fresh($t)['archived_at']);
    }

    public function testClosedProjectsFreezeTasks(): void
    {
        $t = $this->task(['status' => 'to_do']);
        foreach (['cancelled', 'completed'] as $status) {
            Database::run('UPDATE projects SET status = ? WHERE id = ?', [$status, $this->proj['id']]);
            $this->expectInvalid(fn () => TaskService::create($this->manager, $this->project(), ['title' => 'New after close']), 'closed');
            $this->expectInvalid(fn () => TaskService::transition($this->manager, $this->fresh($t), 'in_progress'), 'closed');
            $this->assertSame([], TaskWorkflow::allowed($this->manager, $this->fresh($t)));
        }
        Database::run("UPDATE projects SET status = 'in_progress', archived_at = UTC_TIMESTAMP() WHERE id = ?", [$this->proj['id']]);
        $this->assertFalse(TaskPolicy::canView($this->manager, $this->fresh($t)), 'archived projects are invisible to non-admins');
        $this->assertTrue(TaskPolicy::canView($this->admin, $this->fresh($t)));
    }

    public function testPriorityOfATaskNeverTouchesProjectPriorityOrPricing(): void
    {
        $before = Database::one('SELECT priority FROM projects WHERE id = ?', [$this->proj['id']])['priority'];
        $t = $this->task(['priority' => 'low']);
        TaskService::update($this->manager, $t, ['title' => $t['title'], 'priority' => 'urgent']);
        $this->assertSame('urgent', $this->fresh($t)['priority']);
        $this->assertSame($before, Database::one('SELECT priority FROM projects WHERE id = ?', [$this->proj['id']])['priority']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_change_requests')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
    }

    // ---- visibility / scoping ---------------------------------------------------------------------------------------------

    public function testTaskVisibilityFollowsProjectAssignmentExactly(): void
    {
        $t = $this->task(['title' => 'Secret roadmap item']);
        $this->assertTrue(TaskPolicy::canView($this->admin, $t));
        $this->assertTrue(TaskPolicy::canView($this->manager, $t));
        $this->assertTrue(TaskPolicy::canView($this->dev, $t));
        $this->assertTrue(TaskPolicy::canView($this->support, $t));
        $this->assertFalse(TaskPolicy::canView($this->dev2, $t), 'unassigned worker');
        $this->assertFalse(TaskPolicy::canView($this->manager2, $t), 'unassigned manager');
        $this->assertFalse(TaskPolicy::canView($this->clientUser, $t), 'client');
        foreach ([$this->dev2, $this->manager2, $this->clientUser] as $u) {
            try {
                TaskPolicy::requireView($u, $t['public_id']);
                $this->fail('expected 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
            $this->assertSame(0, TaskQuery::paginate($u, [])['total'], 'lists never include other people\'s projects');
            $this->assertSame([], array_merge(...array_values(TaskQuery::board($u, [])['columns'])));
        }
        $this->assertSame(1, TaskQuery::paginate($this->dev, [])['total']);
        // Revoking removes access immediately, including from list queries.
        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->dev);
        $this->assertFalse(TaskPolicy::canView($this->dev, $this->fresh($t)));
        $this->assertSame(0, TaskQuery::paginate($this->dev, [])['total']);
        // A client given tasks.view by mistake still sees nothing: tasks are staff-only.
        Database::run("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = 'client' AND p.perm_key = 'tasks.view'");
        Gate::flush();
        $this->assertFalse(TaskPolicy::canView($this->clientUser, $this->fresh($t)));
        $this->assertSame(0, TaskQuery::paginate($this->clientUser, [])['total']);
    }

    public function testFiltersNarrowButNeverWiden(): void
    {
        $other = $this->makeProject($this->clientUser, 'Not mine');
        $this->task(['title' => 'Alpha urgent', 'priority' => 'urgent', 'assignee' => $this->publicId($this->dev), 'due_date' => '2020-01-01']);
        $this->task(['title' => 'Alpha low', 'priority' => 'low']);
        $this->task(['title' => 'Foreign task'], $this->admin, ProjectPolicy::find($other['public_id']));
        $this->assertSame(2, TaskQuery::paginate($this->dev, [])['total']);
        $this->assertSame(1, TaskQuery::paginate($this->dev, ['priority' => 'urgent'])['total']);
        $this->assertSame(1, TaskQuery::paginate($this->dev, ['assignee' => 'me'])['total']);
        $this->assertSame(1, TaskQuery::paginate($this->dev, ['assignee' => 'none'])['total']);
        $this->assertSame(1, TaskQuery::paginate($this->dev, ['overdue' => true])['total']);
        $this->assertSame(0, TaskQuery::paginate($this->dev, ['project' => $other['public_id']])['total'], 'filtering by a project you cannot see yields nothing');
        $this->assertSame(0, TaskQuery::paginate($this->dev, ['q' => 'Foreign'])['total']);
        $this->assertSame(3, TaskQuery::paginate($this->admin, [])['total']);
        $this->assertSame(1, TaskQuery::paginate($this->admin, ['q' => 'Foreign'])['total']);
        $this->assertSame(0, TaskQuery::paginate($this->admin, ['q' => "%' OR 1=1 --"])['total'], 'search is parameterised and LIKE-escaped');
        $this->assertSame(1, TaskQuery::paginate($this->admin, [], 2, 2)['total'] > 0 ? count(TaskQuery::paginate($this->admin, [], 2, 2)['rows']) : 0, 'pagination offsets');
    }

    // ---- deadlines --------------------------------------------------------------------------------------------------------

    public function testDeadlineDashboardUsesTheApplicationDateAndIgnoresFinishedWork(): void
    {
        $this->zoneBefore = Config::get('app.timezone', 'UTC');
        Config::set('app.timezone', 'Asia/Kolkata');
        AppTime::freeze('2026-06-15 02:00:00'); // 07:30 in Asia/Kolkata, still the 15th
        $this->task(['title' => 'Overdue open', 'due_date' => '2026-06-10']);
        $done = $this->task(['title' => 'Overdue but done', 'due_date' => '2026-06-01']);
        $this->setStatus($done, 'completed');
        $this->task(['title' => 'Due soon', 'due_date' => '2026-06-18']);
        $this->task(['title' => 'Due later', 'due_date' => '2026-07-30']);
        $this->task(['title' => 'Due today', 'due_date' => '2026-06-15']);
        $blocked = $this->task(['title' => 'Stuck']);
        $this->setStatus($blocked, 'blocked');
        $review = $this->task(['title' => 'Needs eyes']);
        $this->setStatus($review, 'in_review');
        MilestoneService::create($this->admin, $this->project(), ['title' => 'Late milestone', 'target_date' => '2026-06-01']);
        MilestoneService::create($this->admin, $this->project(), ['title' => 'Soon milestone', 'target_date' => '2026-06-20']);
        Database::run("UPDATE projects SET target_date = '2026-06-17' WHERE id = ?", [$this->proj['id']]);

        $dl = TaskQuery::deadlines($this->dev);
        $this->assertSame('2026-06-15', $dl['today']);
        $this->assertSame(['Overdue open'], array_column($dl['overdue_tasks'], 'title'));
        $this->assertEqualsCanonicalizing(['Due soon', 'Due today'], array_column($dl['upcoming_tasks'], 'title'));
        $this->assertSame(['Stuck'], array_column($dl['blocked_tasks'], 'title'));
        $this->assertSame(['Needs eyes'], array_column($dl['review_tasks'], 'title'));
        $this->assertSame(['Late milestone'], array_column($dl['overdue_milestones'], 'title'));
        $this->assertSame(['Soon milestone'], array_column($dl['upcoming_milestones'], 'title'));
        $this->assertSame(['Alpha Site'], array_column($dl['projects'], 'title'));

        foreach ([$this->dev2, $this->manager2, $this->clientUser] as $u) {
            $empty = TaskQuery::deadlines($u);
            foreach (['overdue_tasks', 'upcoming_tasks', 'blocked_tasks', 'review_tasks', 'overdue_milestones', 'upcoming_milestones', 'projects'] as $k) {
                $this->assertSame([], $empty[$k], "{$k} must be empty for someone with no access");
            }
        }
        AppTime::freeze('2026-06-15 20:00:00'); // 01:30 on the 16th in Asia/Kolkata: now yesterday's tasks are overdue
        $this->assertSame('2026-06-16', TaskQuery::deadlines($this->dev)['today']);
        $this->assertContains('Due today', array_column(TaskQuery::deadlines($this->dev)['overdue_tasks'], 'title'));
    }

    public function testBoardReturnsAllEightColumnsAndMarksWhatEachUserMayMove(): void
    {
        $t = $this->task(['assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        $board = TaskQuery::board($this->dev, []);
        $this->assertSame(array_keys((array) Config::get('workflow.task_statuses')), array_keys($board['columns']));
        $this->assertCount(1, $board['columns']['to_do']);
        $card = $board['columns']['to_do'][0];
        $this->assertEqualsCanonicalizing(['in_progress', 'blocked'], array_keys(TaskWorkflow::allowedGiven($this->dev, $card, true)));
        $this->assertEqualsCanonicalizing(array_keys(TaskWorkflow::allowedGiven($this->dev, $card, true)), array_keys(TaskWorkflow::allowed($this->dev, $this->fresh($t))), 'fast path agrees with the full check');
        $this->assertSame([], TaskWorkflow::allowedGiven($this->support, $card, true), 'support cannot move anything');
    }
}
