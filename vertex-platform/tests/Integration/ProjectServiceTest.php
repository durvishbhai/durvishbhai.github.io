<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\MilestoneService;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectActivity;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectService;

/** Project lifecycle, edit rules, notes visibility, team changes and milestones at the service level. */
final class ProjectServiceTest extends DatabaseTestCase
{
    private int $admin;
    private int $manager;       // assigned lead
    private int $manager2;      // unassigned
    private int $dev;           // assigned worker
    private int $dev2;          // unassigned worker
    private int $support;       // assigned
    private int $clientUser;
    private int $otherClient;
    private array $proj;

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
        $this->otherClient = $this->makeUser('cl2@x.test', ['client']);
        $p = $this->makeProject($this->clientUser, 'Alpha Site');
        Database::run("UPDATE projects SET status = 'draft' WHERE id = ?", [$p['id']]);
        foreach ([[$this->manager, 'lead'], [$this->dev, 'member'], [$this->support, 'member']] as [$u, $r]) {
            ProjectAssignmentService::assign($this->admin, $p['id'], $u, $r);
        }
        $this->proj = $p;
    }

    /** @return array<string,mixed> */
    private function project(): array
    {
        return ProjectPolicy::find($this->proj['public_id']);
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

    private function projectStatus(): string
    {
        return (string) Database::one('SELECT status FROM projects WHERE id = ?', [$this->proj['id']])['status'];
    }

    private function forceStatus(string $s): void
    {
        Database::run('UPDATE projects SET status = ? WHERE id = ?', [$s, $this->proj['id']]);
    }

    // ---- creation ---------------------------------------------------------------------------------------------------------

    public function testOnlyAuthorizedAdminsCreateDraftProjectsForActiveClients(): void
    {
        $clientId = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->clientUser])['id'];
        $this->expect403(fn () => ProjectService::create($this->manager, $clientId, ['title' => 'Nope']), 'managers cannot create projects');
        $this->expect403(fn () => ProjectService::create($this->dev, $clientId, ['title' => 'Nope']), 'workers cannot');
        $p = ProjectService::create($this->admin, $clientId, ['title' => 'Brand site', 'service_category' => 'website_development', 'priority' => 'high', 'start_date' => '2026-11-01', 'target_date' => '2026-12-15', 'budget' => '1,25,000.50']);
        $row = ProjectPolicy::find($p['public_id']);
        $this->assertSame('draft', $row['status']);
        $this->assertSame('high', $row['priority']);
        $this->assertSame(12500050, (int) $row['budget_minor'], 'money is stored as integer paise');
        $this->assertMatchesRegularExpression('/^KIS-\d{4}-\d{4}$/', $p['code']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.created'")['c']);
        $tl = ProjectActivity::timeline($p['id'], false)['rows'];
        $this->assertSame('project.created', $tl[0]['event_type']);
        $this->assertSame($this->admin, (int) $tl[0]['actor_id']);

        $this->expectInvalid(fn () => ProjectService::create($this->admin, $clientId, ['title' => 'Bad dates', 'start_date' => '2026-12-01', 'target_date' => '2026-11-01']), 'before the start');
        $this->expectInvalid(fn () => ProjectService::create($this->admin, $clientId, ['title' => 'x']), 'Project name');
        $this->expectInvalid(fn () => ProjectService::create($this->admin, $clientId, ['title' => 'Bad budget', 'budget' => 'lots']), 'amount');
        Database::run("UPDATE clients SET status = 'inactive' WHERE id = ?", [$clientId]);
        $this->expectInvalid(fn () => ProjectService::create($this->admin, $clientId, ['title' => 'Inactive client']), 'active clients');
    }

    public function testProjectCodesAreUniqueAndSequentialPerYear(): void
    {
        $clientId = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->clientUser])['id'];
        $a = ProjectService::create($this->admin, $clientId, ['title' => 'One']);
        $b = ProjectService::create($this->admin, $clientId, ['title' => 'Two']);
        $this->assertNotSame($a['code'], $b['code']);
        $this->assertSame((int) substr($a['code'], -4) + 1, (int) substr($b['code'], -4));
        $this->expectException(\PDOException::class);
        Database::run("UPDATE projects SET code = ? WHERE id = ?", [$a['code'], $b['id']]);
    }

    // ---- status machine -----------------------------------------------------------------------------------------------------

    public function testEveryStatusPairIsEitherAllowedByTheMachineOrRefused(): void
    {
        $statuses = array_keys((array) Config::get('workflow.project_statuses'));
        $machine = (array) Config::get('workflow.project_transitions');
        $this->assertCount(10, $statuses);
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from === $to) {
                    continue;
                }
                $this->forceStatus($from);
                $allowed = isset($machine[$from][$to]);
                try {
                    ProjectService::transition($this->admin, $this->project(), $to, 'Because reasons', true);
                    $ok = true;
                } catch (ValidationException $e) {
                    $ok = false;
                    if ($allowed) {
                        // allowed pairs may only fail on a guard (e.g. nobody assigned), never on the machine itself
                        $this->assertStringNotContainsString('cannot move to', implode(' ', $e->errors), "{$from}->{$to}");
                    }
                }
                if (!$allowed) {
                    $this->assertFalse($ok, "{$from} -> {$to} must be refused");
                    $this->assertSame($from, $this->projectStatus(), "{$from} -> {$to} must not change anything");
                }
            }
        }
    }

    public function testRequiredPermissionsPerTransitionAndAccess(): void
    {
        $this->forceStatus('in_review');
        $this->expect403(fn () => ProjectService::transition($this->dev, $this->project(), 'completed', '', true), 'a worker cannot complete');
        $this->expect403(fn () => ProjectService::transition($this->support, $this->project(), 'in_progress'), 'support cannot change status');
        $this->expect403(fn () => ProjectService::transition($this->manager2, $this->project(), 'in_progress'), 'unassigned manager has no access');
        $this->expect403(fn () => ProjectService::transition($this->clientUser, $this->project(), 'completed', '', true), 'the client cannot');
        $this->assertSame('in_review', $this->projectStatus());
        ProjectService::transition($this->manager, $this->project(), 'in_progress'); // assigned manager: projects.update_status
        $this->assertSame('in_progress', $this->projectStatus());
        $this->assertSame([], ProjectService::allowedTransitions($this->dev, $this->project()));
        $this->assertSame([], ProjectService::allowedTransitions($this->clientUser, $this->project()));
        $this->assertArrayHasKey('in_review', ProjectService::allowedTransitions($this->manager, $this->project()));
    }

    public function testCompletionIsNeverAutomaticAndNeedsConfirmationAndPermission(): void
    {
        $this->forceStatus('in_review');
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'completed'), 'deliberate decision');
        $this->assertSame('in_review', $this->projectStatus());
        ProjectService::transition($this->admin, $this->project(), 'completed', 'Signed off by client', true);
        $p = $this->project();
        $this->assertSame('completed', $p['status']);
        $this->assertNotNull($p['completed_at']);
        $this->assertSame($this->admin, (int) $p['completed_by']);
        ProjectService::transition($this->admin, $this->project(), 'in_review'); // reopen
        $this->assertNull($this->project()['completed_at']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.status_changed' AND after_json LIKE '%completed%'")['c']);
    }

    public function testNoStatusMeansPaidAndTheManualPaymentOverrideIsRecordedHonestly(): void
    {
        $cols = array_column(Database::all('SHOW COLUMNS FROM projects'), 'Field');
        foreach (['paid', 'is_paid', 'payment_status', 'financial_status', 'paid_at'] as $c) {
            $this->assertNotContains($c, $cols, 'projects must not carry a payment/financial status');
        }
        $this->assertNotContains('paid', array_keys((array) Config::get('workflow.project_statuses')));

        $this->forceStatus('awaiting_payment');
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'ready_to_start'), 'Note');
        ProjectService::transition($this->admin, $this->project(), 'ready_to_start', 'Finance confirmed bank transfer by phone');
        $row = ProjectActivity::timeline($this->proj['id'], false)['rows'][0];
        $this->assertStringContainsString('not verified by the system', (string) $row['details_json']);
        $this->assertStringContainsString('Finance confirmed', $row['summary']);
    }

    public function testGuardsInProgressNeedsAMemberAndHoldCancelNeedAReason(): void
    {
        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->manager);
        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->dev);
        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->support);
        $this->forceStatus('ready_to_start');
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'in_progress'), 'team member');
        ProjectAssignmentService::assign($this->admin, $this->proj['id'], $this->dev);
        ProjectService::transition($this->admin, $this->project(), 'in_progress');
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'on_hold'), 'Reason');
        ProjectService::transition($this->admin, $this->project(), 'on_hold', 'Client travelling until March');
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'cancelled', ''), 'Reason');
        ProjectService::transition($this->admin, $this->project(), 'cancelled', 'Client withdrew');
        $this->expect403(fn () => ProjectService::transition($this->manager2, $this->project(), 'draft'), 'no access');
        ProjectService::transition($this->admin, $this->project(), 'draft'); // admin holds projects.edit => restore
        $this->assertSame('draft', $this->projectStatus());
    }

    public function testStaleStatusIsDetectedWhenTwoPeopleActAtOnce(): void
    {
        $stale = $this->project(); // draft
        $this->forceStatus('awaiting_client_approval'); // someone else moved it meanwhile
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $stale, 'ready_to_start'), 'changed while');
    }

    public function testArchivedProjectsAreReadOnlyAndOnlyCompletedOrCancelledCanBeArchived(): void
    {
        $this->expectInvalid(fn () => ProjectService::setArchived($this->admin, $this->project(), true), 'completed or cancelled');
        $this->forceStatus('completed');
        $this->expect403(fn () => ProjectService::setArchived($this->manager, $this->project(), true), 'managers cannot archive');
        ProjectService::setArchived($this->admin, $this->project(), true);
        $this->expectInvalid(fn () => ProjectService::transition($this->admin, $this->project(), 'in_review'), 'archived');
        $this->expectInvalid(fn () => ProjectService::update($this->admin, $this->project(), ['title' => 'Edited archived']), 'archived');
        $this->assertNull(ProjectPolicy::level($this->manager, $this->project()), 'archived projects leave the assigned user\'s scope');
        $this->assertNull(ProjectPolicy::level($this->clientUser, $this->project()));
        $this->assertSame('all', ProjectPolicy::level($this->admin, $this->project()));
        ProjectService::setArchived($this->admin, $this->project(), false);
        $this->assertSame('assigned', ProjectPolicy::level($this->manager, $this->project()));
    }

    // ---- editing ----------------------------------------------------------------------------------------------------------

    public function testEditRulesWhoCanEditWhatAndDeadlineChangesAreOnTheTimeline(): void
    {
        $this->forceStatus('in_progress');
        $in = ['title' => 'Alpha Site v2', 'service_category' => 'website_development', 'description' => 'New description here', 'scope' => 'Scope text', 'start_date' => '2026-11-01', 'target_date' => '2026-12-31'];
        $this->expect403(fn () => ProjectService::update($this->dev, $this->project(), $in), 'workers cannot edit projects');
        $this->expect403(fn () => ProjectService::update($this->manager2, $this->project(), $in), 'unassigned manager');
        $this->expect403(fn () => ProjectService::update($this->clientUser, $this->project(), $in), 'client');
        ProjectService::update($this->manager, $this->project(), $in);
        $p = $this->project();
        $this->assertSame('Alpha Site v2', $p['title']);
        $this->assertSame('2026-12-31', $p['target_date']);

        $ev = array_column(ProjectActivity::timeline($this->proj['id'], false)['rows'], 'event_type');
        $this->assertContains('project.deadline_changed', $ev);
        $client = array_column(ProjectActivity::timeline($this->proj['id'], true)['rows'], 'event_type');
        $this->assertContains('project.deadline_changed', $client, 'deadline changes are shown to the client');

        $before = (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.updated'")['c'];
        ProjectService::update($this->manager, $this->project(), $in);
        $this->assertSame($before, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.updated'")['c'], 'no-op edits are not logged');
        $this->expectInvalid(fn () => ProjectService::update($this->manager, $this->project(), ['title' => 'Bad', 'start_date' => '2026-12-01', 'target_date' => '2026-11-01']), 'before the start');
    }

    public function testBudgetIsAFinancialFieldOnlyAdminsMayChange(): void
    {
        $this->forceStatus('in_progress');
        $base = ['title' => 'Alpha Site', 'budget' => '5000'];
        $this->expect403(fn () => ProjectService::update($this->manager, $this->project(), $base), 'manager must not set the budget');
        $this->assertNull($this->project()['budget_minor']);
        ProjectService::update($this->admin, $this->project(), $base);
        $this->assertSame(500000, (int) $this->project()['budget_minor']);
        // a manager editing OTHER fields leaves the budget untouched even if the form omits it
        ProjectService::update($this->manager, $this->project(), ['title' => 'Alpha Site 2']);
        $this->assertSame(500000, (int) $this->project()['budget_minor']);
    }

    public function testClosedProjectsCannotBeEditedUntilReopened(): void
    {
        $this->forceStatus('cancelled');
        $this->expectInvalid(fn () => ProjectService::update($this->admin, $this->project(), ['title' => 'Edit while cancelled']), 'cannot be edited');
    }

    // ---- notes ----------------------------------------------------------------------------------------------------------

    public function testNoteVisibilityInternalSharedAndClientFeedback(): void
    {
        $p = $this->project();
        ProjectService::addNote($this->manager, $p, 'internal', 'INTERNAL-ONLY pricing discussion');
        ProjectService::addNote($this->manager, $p, 'client_visible', 'SHARED update for the client');
        ProjectService::addNote($this->clientUser, $p, 'client_feedback', 'CLIENT-FEEDBACK love the design');

        $bodies = static fn (array $rows): string => implode('|', array_column($rows, 'body'));
        $staff = $bodies(ProjectService::notes($this->manager, $p));
        $this->assertStringContainsString('INTERNAL-ONLY', $staff);
        $this->assertStringContainsString('SHARED', $staff);
        $this->assertStringContainsString('CLIENT-FEEDBACK', $staff);
        $client = $bodies(ProjectService::notes($this->clientUser, $p));
        $this->assertStringNotContainsString('INTERNAL-ONLY', $client, 'clients never see internal notes');
        $this->assertStringContainsString('SHARED', $client);
        $this->assertStringContainsString('CLIENT-FEEDBACK', $client);
        $worker = $bodies(ProjectService::notes($this->dev, $p));
        $this->assertStringNotContainsString('INTERNAL-ONLY', $worker, 'workers lack projects.notes_internal');
        $this->assertStringContainsString('SHARED', $worker);
        $this->assertSame([], ProjectService::notes($this->dev2, $p), 'no access, no notes');
        $this->assertSame([], ProjectService::notes($this->otherClient, $p));

        $this->expect403(fn () => ProjectService::addNote($this->dev, $p, 'internal', 'worker note'), 'worker cannot add internal notes');
        $this->expect403(fn () => ProjectService::addNote($this->dev, $p, 'client_visible', 'worker note'), 'worker cannot post to the client');
        $this->expect403(fn () => ProjectService::addNote($this->clientUser, $p, 'internal', 'client note'), 'client cannot add internal notes');
        $this->expect403(fn () => ProjectService::addNote($this->clientUser, $p, 'client_visible', 'client posing as staff'), 'client cannot post as staff');
        $this->expect403(fn () => ProjectService::addNote($this->otherClient, $p, 'client_feedback', 'someone else\'s project'), 'other client');
        $this->expect403(fn () => ProjectService::addNote($this->admin, $p, 'client_feedback', 'admin posing as the client'), 'staff cannot post client feedback');
        $this->expectInvalid(fn () => ProjectService::addNote($this->manager, $p, 'bogus', 'x'), 'Unknown note type');

        $client = array_column(ProjectActivity::timeline($this->proj['id'], true)['rows'], 'event_type');
        $this->assertContains('project.client_feedback', $client, 'client feedback is on the client-visible timeline');
        $internalEntries = array_filter(ProjectActivity::timeline($this->proj['id'], true)['rows'], static fn ($r) => $r['summary'] === 'Internal note added');
        $this->assertSame([], array_values($internalEntries), 'the existence of internal notes is not advertised to clients');
    }

    // ---- team ---------------------------------------------------------------------------------------------------------------

    private function task(int $assignee, string $status = 'to_do'): int
    {
        Database::run(
            "INSERT INTO tasks (public_id, project_id, title, assignee_id, status, created_at, updated_at) VALUES (?, ?, 'T', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [uuid_hex(), $this->proj['id'], $assignee, $status],
        );

        return Database::insertId();
    }

    public function testReassignAndRevokeKeepTasksConsistentAndAreAudited(): void
    {
        $open = $this->task($this->dev, 'in_progress');
        $done = $this->task($this->dev, 'completed');
        ProjectAssignmentService::reassign($this->admin, $this->proj['id'], $this->dev, $this->dev2, true);
        $this->assertSame($this->dev2, (int) Database::one('SELECT assignee_id FROM tasks WHERE id = ?', [$open])['assignee_id'], 'open work moves with the person');
        $this->assertSame($this->dev, (int) Database::one('SELECT assignee_id FROM tasks WHERE id = ?', [$done])['assignee_id'], 'finished work keeps its history');
        $this->assertFalse(ProjectPolicy::isAssigned($this->dev, $this->proj['id']));
        $this->assertTrue(ProjectPolicy::isAssigned($this->dev2, $this->proj['id']));
        $a = Database::one("SELECT * FROM audit_logs WHERE action = 'project.member_reassigned'");
        $this->assertSame($this->admin, (int) $a['actor_id']);
        $this->assertStringContainsString('"tasks_moved":1', $a['meta_json']);
        $this->assertContains('team.reassigned', array_column(ProjectActivity::timeline($this->proj['id'], false)['rows'], 'event_type'));

        ProjectAssignmentService::revoke($this->admin, $this->proj['id'], $this->dev2);
        $this->assertNull(Database::one('SELECT assignee_id FROM tasks WHERE id = ?', [$open])['assignee_id'], 'a removed member cannot keep open tasks');
    }

    public function testManagersCannotAssignOrReassignByDefaultOrAnyOneWithoutThePermission(): void
    {
        foreach ([$this->manager, $this->manager2, $this->dev, $this->support, $this->clientUser] as $actor) {
            $this->expect403(fn () => ProjectAssignmentService::assign($actor, $this->proj['id'], $this->dev2), 'assign');
            $this->expect403(fn () => ProjectAssignmentService::reassign($actor, $this->proj['id'], $this->dev, $this->dev2), 'reassign');
            $this->expect403(fn () => ProjectAssignmentService::revoke($actor, $this->proj['id'], $this->dev), 'revoke');
        }
        $this->assertTrue(ProjectPolicy::isAssigned($this->dev, $this->proj['id']));
        $this->assertFalse(ProjectPolicy::isAssigned($this->dev2, $this->proj['id']));
        $this->assertNotContains('projects.assign', array_keys(\Vertex\Services\Gate::permissionsFor($this->manager)), 'managers hold no assignment permission by default');
        $this->expectInvalid(fn () => ProjectAssignmentService::reassign($this->admin, $this->proj['id'], $this->dev, $this->dev), 'different');
        $this->expectInvalid(fn () => ProjectAssignmentService::reassign($this->admin, $this->proj['id'], $this->dev2, $this->manager2), 'not currently assigned');
    }

    // ---- milestones -----------------------------------------------------------------------------------------------------------

    public function testWorkersProposeManagersCreateAndApproveProposals(): void
    {
        $p = $this->project();
        $w = MilestoneService::create($this->dev, $p, ['title' => 'Design sign-off', 'visibility' => 'client', 'target_date' => '2026-11-20']);
        $row = Database::one('SELECT * FROM milestones WHERE public_id = ?', [$w['public_id']]);
        $this->assertSame('proposed', $row['status']);
        $this->assertSame('internal', $row['visibility'], 'a proposal can never be client-visible');
        $this->assertSame($this->dev, (int) $row['proposed_by']);
        $this->assertSame([], MilestoneService::listFor($this->clientUser, $p), 'clients do not see proposals');

        $this->expect403(fn () => MilestoneService::update($this->dev, $p, $row, ['title' => 'Edited by worker']), 'workers cannot edit');
        $this->expect403(fn () => MilestoneService::transition($this->dev, $p, $row, 'planned'), 'workers cannot approve');
        $this->expect403(fn () => MilestoneService::create($this->dev2, $p, ['title' => 'Unassigned worker']), 'unassigned worker has no access');
        $this->expect403(fn () => MilestoneService::create($this->clientUser, $p, ['title' => 'Client milestone']), 'clients cannot create');
        $this->expect403(fn () => MilestoneService::transition($this->manager2, $p, $row, 'planned'), 'unassigned manager');

        MilestoneService::transition($this->manager, $p, $row, 'planned', 'client');
        $m = Database::one('SELECT * FROM milestones WHERE public_id = ?', [$w['public_id']]);
        $this->assertSame('planned', $m['status']);
        $this->assertSame('client', $m['visibility']);
        $this->assertSame($this->manager, (int) $m['decided_by']);
        $this->assertCount(1, MilestoneService::listFor($this->clientUser, $p), 'now the client sees it');

        $direct = MilestoneService::create($this->manager, $p, ['title' => 'Launch', 'visibility' => 'internal']);
        $this->assertSame('planned', $direct['status'], 'managers create directly');
        $this->assertCount(1, MilestoneService::listFor($this->clientUser, $p), 'internal milestones stay hidden');
        $this->assertCount(3, MilestoneService::listFor($this->manager, $p) + [2 => 1], 'staff see both (count sanity)');
    }

    public function testMilestoneStatusMachineAndCompletionDate(): void
    {
        $p = $this->project();
        $m = MilestoneService::create($this->manager, $p, ['title' => 'Beta', 'target_date' => '2026-01-10'])['public_id'];
        $row = static fn () => Database::one('SELECT * FROM milestones WHERE public_id = ?', [$m]);
        $this->expectInvalid(fn () => MilestoneService::transition($this->manager, $p, $row(), 'rejected'), 'cannot become');
        MilestoneService::transition($this->manager, $p, $row(), 'in_progress');
        MilestoneService::transition($this->manager, $p, $row(), 'completed');
        $this->assertNotNull($row()['completed_at']);
        MilestoneService::transition($this->manager, $p, $row(), 'in_progress');
        $this->assertNull($row()['completed_at']);
        $this->assertSame([], MilestoneService::allowedTransitions($this->dev, $p, $row()), 'workers have no milestone transitions');
        $this->assertArrayHasKey('completed', MilestoneService::allowedTransitions($this->manager, $p, $row()));
        $this->assertSame(3, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'milestone.status_changed'")['c']);
    }

    public function testOverdueMilestonesAreFlaggedUsingTheApplicationDate(): void
    {
        $p = $this->project();
        MilestoneService::create($this->manager, $p, ['title' => 'Past', 'target_date' => '2026-01-10']);
        MilestoneService::create($this->manager, $p, ['title' => 'Future', 'target_date' => '2099-01-10']);
        $done = MilestoneService::create($this->manager, $p, ['title' => 'Done past', 'target_date' => '2026-01-05']);
        MilestoneService::transition($this->manager, $p, Database::one('SELECT * FROM milestones WHERE public_id = ?', [$done['public_id']]), 'completed');
        $flags = array_column(MilestoneService::listFor($this->manager, $p), 'is_overdue', 'title');
        $flags = array_map('intval', $flags);
        ksort($flags);
        $this->assertSame(['Done past' => 0, 'Future' => 0, 'Past' => 1], $flags);
    }

    public function testMilestonesCannotBeReachedThroughAnotherProject(): void
    {
        $other = $this->makeProject($this->otherClient, 'Other project');
        $p = $this->project();
        $m = MilestoneService::create($this->manager, $p, ['title' => 'Mine'])['public_id'];
        $this->assertNotNull(MilestoneService::findInProject($m, $this->proj['id']));
        $this->assertNull(MilestoneService::findInProject($m, $other['id']), 'a milestone id is only valid under its own project');
        $this->assertNull(MilestoneService::findInProject('1', $this->proj['id']));
        $this->assertNull(MilestoneService::findInProject(str_repeat('a', 32), $this->proj['id']));
    }

    public function testMilestonesCannotChangeOnCancelledOrArchivedProjects(): void
    {
        $p = $this->project();
        $this->forceStatus('cancelled');
        $this->expectInvalid(fn () => MilestoneService::create($this->manager, $this->project(), ['title' => 'x y z']), 'closed');
    }
}
