<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\MilestoneService;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectService;
use Vertex\Services\TaskPolicy;
use Vertex\Services\TaskService;

/** Projects, milestones, tasks, board and deadlines over real HTTP: scoping, IDOR, CSRF, JSON status endpoint. */
final class ProjectTaskHttpTest extends HttpTestCase
{
    private int $admin;
    private int $manager;    // assigned to Alpha only
    private int $manager2;   // assigned to nothing
    private int $dev;        // assigned to Alpha
    private int $dev2;       // assigned to Bravo only
    private int $support;    // assigned to Alpha, comment-only
    private int $userA;
    private int $userB;
    private array $alpha;
    private array $bravo;
    private array $task;     // Alpha task assigned to dev, in To Do
    private array $bravoTask;

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
        $this->userA = $this->makeUser('alpha@client.test', ['client']);
        $this->userB = $this->makeUser('bravo@client.test', ['client']);
        $this->alpha = $this->makeProject($this->userA, 'Alpha Website');
        $this->bravo = $this->makeProject($this->userB, 'Bravo Video');
        foreach ([$this->manager, $this->dev, $this->support] as $u) {
            ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $u, 'member');
        }
        ProjectAssignmentService::assign($this->admin, $this->bravo['id'], $this->dev2, 'member');
        $t = TaskService::create($this->admin, ProjectPolicy::find($this->alpha['public_id']), ['title' => 'Build homepage', 'assignee' => $this->publicId($this->dev), 'status' => 'to_do']);
        $this->task = TaskPolicy::find($t['public_id']);
        $b = TaskService::create($this->admin, ProjectPolicy::find($this->bravo['public_id']), ['title' => 'BRAVO-ONLY-TASK', 'status' => 'to_do']);
        $this->bravoTask = TaskPolicy::find($b['public_id']);
    }

    private function jsonMove(\Vertex\Tests\Support\HttpClient $c, string $taskId, string $to, string $reason = '', bool $withToken = true): array
    {
        $headers = ['Accept: application/json'];
        if ($withToken) {
            $headers[] = 'X-CSRF-TOKEN: ' . $c->token('/board');
        }
        $r = $c->request('POST', "/tasks/{$taskId}/status", ['status' => $to, 'reason' => $reason], $headers);
        $r['json'] = json_decode($r['body'], true);

        return $r;
    }

    // ---- project list / detail scoping ----------------------------------------------------------------------------------

    public function testProjectListsAreScopedToAccess(): void
    {
        $this->assertStringContainsString('Alpha Website', $this->loggedIn('dev@x.test')->get('/projects')['body']);
        $this->assertStringNotContainsString('Bravo Video', $this->loggedIn('dev@x.test')->get('/projects')['body']);
        $b = $this->loggedIn('dev2@x.test')->get('/projects')['body'];
        $this->assertStringContainsString('Bravo Video', $b);
        $this->assertStringNotContainsString('Alpha Website', $b);
        $none = $this->loggedIn('mgr2@x.test')->get('/projects')['body'];
        $this->assertStringNotContainsString('Alpha Website', $none);
        $this->assertStringNotContainsString('Bravo Video', $none);
        $this->assertStringContainsString('No projects to show', $none);
        $cl = $this->loggedIn('alpha@client.test')->get('/projects')['body'];
        $this->assertStringContainsString('Alpha Website', $cl);
        $this->assertStringNotContainsString('Bravo Video', $cl);
        $adm = $this->loggedIn('admin@x.test')->get('/projects')['body'];
        $this->assertStringContainsString('Alpha Website', $adm);
        $this->assertStringContainsString('Bravo Video', $adm);
        $this->assertStringNotContainsString('Bravo Video', $this->loggedIn('dev@x.test')->get('/projects?q=Bravo')['body'], 'search never widens access');
    }

    public function testGuessingProjectAndTaskUrlsYields404AndNeverLeaksData(): void
    {
        $dev2 = $this->loggedIn('dev2@x.test');
        $a = $this->alpha['public_id'];
        $t = $this->task['public_id'];
        foreach (["/projects/{$a}", "/projects/{$a}/tasks", "/projects/{$a}/milestones", "/projects/{$a}/timeline", "/tasks/{$t}"] as $path) {
            $r = $dev2->get($path);
            $this->assertSame(404, $r['status'], "{$path} must look missing to someone without access");
            $this->assertStringNotContainsString('Build homepage', $r['body']);
            $this->assertStringNotContainsString('Alpha Website', $r['body']);
        }
        foreach (['/projects/' . str_repeat('0', 32), '/projects/not-an-id', '/tasks/' . str_repeat('f', 32), '/tasks/1', '/tasks/..%2F..%2Fetc'] as $path) {
            $this->assertSame(404, $dev2->get($path)['status'], $path);
        }
        $this->assertSame(404, $dev2->post("/tasks/{$t}/comments", ['body' => 'hello'], '/projects')['status'], 'mutations by non-members are also 404');
        $this->assertContains($dev2->post("/projects/{$a}/tasks", ['title' => 'Injected task'], '/projects')['status'], [403, 404]);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM tasks WHERE title = 'Injected task'")['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM task_comments')['c']);
    }

    public function testClientsNeverReachTasksBoardOrDeadlines(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        foreach (['/tasks', '/board', '/deadlines', '/tasks/' . $this->task['public_id'], '/projects/' . $this->alpha['public_id'] . '/tasks'] as $path) {
            $this->assertContains($c->get($path)['status'], [403, 404], $path);
        }
        $page = $c->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringNotContainsString('Build homepage', $page);
        $this->assertStringNotContainsString('>Tasks<', $page, 'no Tasks tab for clients');
        $this->assertSame(403, $c->post('/tasks/' . $this->task['public_id'] . '/status', ['status' => 'in_progress'], '/projects')['status']);
        $this->assertSame('to_do', TaskPolicy::find($this->task['public_id'])['status']);
    }

    // ---- assignment ------------------------------------------------------------------------------------------------------

    public function testOnlyAdministratorsAssignPeopleToProjects(): void
    {
        $url = '/projects/' . $this->alpha['public_id'] . '/assignments';
        $form = ['user' => $this->publicId($this->manager2), 'project_role' => 'member'];
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post($url, $form, '/projects')['status'], 'managers have no assignment right by default');
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post($url, $form, '/projects')['status']);
        $this->assertFalse(ProjectPolicy::isAssigned($this->manager2, (int) $this->alpha['id']));
        $r = $this->loggedIn('admin@x.test')->post($url, $form, '/projects/' . $this->alpha['public_id']);
        $this->assertSame(303, $r['status']);
        $this->assertTrue(ProjectPolicy::isAssigned($this->manager2, (int) $this->alpha['id']));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.member_assigned' AND actor_id = ? AND entity_id = ?", [$this->admin, $this->alpha['id']])['c'] - 3, 'the new assignment (and only it, beyond the three from setUp) is audited with its actor');
        $page = $this->loggedIn('mgr@x.test')->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringNotContainsString('Add team member', $page, 'the control is absent AND the server refuses (above)');
    }

    public function testWorkersCannotAssignTasksOverHttp(): void
    {
        $url = '/tasks/' . $this->task['public_id'] . '/assign';
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post($url, ['assignee' => $this->publicId($this->dev)], '/projects')['status']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post($url, ['assignee' => $this->publicId($this->support)], '/projects')['status']);
        $this->assertSame($this->dev, (int) TaskPolicy::find($this->task['public_id'])['assignee_id']);
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(303, $m->post($url, ['assignee' => $this->publicId($this->support)], '/tasks/' . $this->task['public_id'])['status']);
        $this->assertSame($this->support, (int) TaskPolicy::find($this->task['public_id'])['assignee_id']);
    }

    // ---- milestones ------------------------------------------------------------------------------------------------------

    public function testMilestoneIdsCannotBeSwappedAcrossProjects(): void
    {
        $mB = MilestoneService::create($this->admin, ProjectPolicy::find($this->bravo['public_id']), ['title' => 'BRAVO-MILESTONE']);
        $mA = MilestoneService::create($this->admin, ProjectPolicy::find($this->alpha['public_id']), ['title' => 'Alpha milestone']);
        $admin = $this->loggedIn('admin@x.test');
        $base = '/projects/' . $this->alpha['public_id'] . '/milestones/' . $mB['public_id'];
        $this->assertSame(404, $admin->post($base . '/status', ['status' => 'in_progress'], '/projects')['status'], 'even an admin cannot reach it through the wrong project');
        $this->assertSame(404, $admin->post($base, ['title' => 'Hijacked', 'description' => '', 'target_date' => '', 'visibility' => 'internal'], '/projects')['status']);
        $this->assertSame(404, $admin->post($base . '/archive', [], '/projects')['status']);
        $row = Database::one('SELECT title, status, archived_at FROM milestones WHERE public_id = ?', [$mB['public_id']]);
        $this->assertSame(['BRAVO-MILESTONE', 'planned', null], [$row['title'], $row['status'], $row['archived_at']]);
        $this->assertSame(303, $admin->post('/projects/' . $this->alpha['public_id'] . '/milestones/' . $mA['public_id'] . '/status', ['status' => 'in_progress'], '/projects')['status']);
    }

    public function testMilestonePermissionsOverHttp(): void
    {
        $url = '/projects/' . $this->alpha['public_id'] . '/milestones';
        $dev = $this->loggedIn('dev@x.test');
        $r = $dev->post($url, ['title' => 'Worker proposal', 'visibility' => 'client', 'target_date' => ''], $url);
        $this->assertSame(303, $r['status']);
        $m = Database::one("SELECT * FROM milestones WHERE title = 'Worker proposal'");
        $this->assertSame('proposed', $m['status'], 'workers propose, they do not create');
        $this->assertSame('internal', $m['visibility'], 'a proposal is never client-visible');
        $this->assertSame(403, $dev->post($url . '/' . $m['public_id'] . '/status', ['status' => 'planned'], $url)['status'], 'workers cannot approve');
        $this->assertSame(403, $dev->post($url . '/' . $m['public_id'], ['title' => 'Edited by worker'], $url)['status']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post($url, ['title' => 'Support proposal'], $url)['status']);
        $this->assertSame(303, $this->loggedIn('mgr@x.test')->post($url . '/' . $m['public_id'] . '/status', ['status' => 'planned', 'visibility' => 'client'], $url)['status']);
        $row = Database::one('SELECT status, visibility FROM milestones WHERE id = ?', [$m['id']]);
        $this->assertSame(['planned', 'client'], [$row['status'], $row['visibility']]);
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->get($url)['status']);
    }

    public function testClientSeesOnlyClientVisibleMilestonesAndTimeline(): void
    {
        $p = ProjectPolicy::find($this->alpha['public_id']);
        MilestoneService::create($this->admin, $p, ['title' => 'Public launch', 'visibility' => 'client']);
        MilestoneService::create($this->admin, $p, ['title' => 'INTERNAL-REFACTOR', 'visibility' => 'internal']);
        $page = $this->loggedIn('alpha@client.test')->get('/projects/' . $this->alpha['public_id'] . '/milestones')['body'];
        $this->assertStringContainsString('Public launch', $page);
        $this->assertStringNotContainsString('INTERNAL-REFACTOR', $page);
        $tl = $this->loggedIn('alpha@client.test')->get('/projects/' . $this->alpha['public_id'] . '/timeline')['body'];
        $this->assertStringContainsString('Public launch', $tl);
        $this->assertStringNotContainsString('INTERNAL-REFACTOR', $tl);
        $this->assertStringNotContainsString('Build homepage', $tl, 'task activity is internal');
        $staff = $this->loggedIn('dev@x.test')->get('/projects/' . $this->alpha['public_id'] . '/timeline')['body'];
        $this->assertStringContainsString('INTERNAL-REFACTOR', $staff);
        $this->assertStringContainsString('Build homepage', $staff);
    }

    public function testInternalNotesAndBudgetAreStaffOnly(): void
    {
        $p = ProjectPolicy::find($this->alpha['public_id']);
        ProjectService::addNote($this->admin, $p, 'internal', 'INTERNAL-NOTE-XYZ');
        ProjectService::addNote($this->admin, $p, 'client_visible', 'Hello client note');
        Database::run('UPDATE projects SET budget_minor = 98765400 WHERE id = ?', [$this->alpha['id']]);
        $c = $this->loggedIn('alpha@client.test')->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringContainsString('Hello client note', $c);
        $this->assertStringNotContainsString('INTERNAL-NOTE-XYZ', $c);
        $this->assertStringNotContainsString('987,654', $c, 'budget estimate is not shown to clients');
        $w = $this->loggedIn('dev@x.test')->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringNotContainsString('INTERNAL-NOTE-XYZ', $w, 'workers do not hold projects.notes_internal by default');
        $this->assertStringNotContainsString('987,654', $w, 'nor the budget');
        $m = $this->loggedIn('mgr@x.test')->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringContainsString('INTERNAL-NOTE-XYZ', $m);
        $this->assertStringContainsString('987,654', $m);
    }

    // ---- tasks over HTTP ------------------------------------------------------------------------------------------------

    public function testTaskDetailShowsOnlyControlsTheUserMayUse(): void
    {
        $url = '/tasks/' . $this->task['public_id'];
        $dev = $this->loggedIn('dev@x.test')->get($url);
        $this->assertSame(200, $dev['status']);
        $this->assertStringContainsString('Move task', $dev['body']);
        $this->assertStringContainsString('<option value="in_progress">', $dev['body']);
        $this->assertStringNotContainsString('<option value="cancelled">', $dev['body'], 'cancelling needs edit rights');
        $this->assertStringNotContainsString('Edit task', $dev['body']);
        $this->assertStringNotContainsString('id="asg"', $dev['body']);
        $this->assertStringContainsString('Log time', $dev['body']);
        $sup = $this->loggedIn('sup@x.test')->get($url)['body'];
        $this->assertStringNotContainsString('Move task', $sup);
        $this->assertStringNotContainsString('Log time', $sup);
        $this->assertStringContainsString('Post comment', $sup);
        $mgr = $this->loggedIn('mgr@x.test')->get($url)['body'];
        $this->assertStringContainsString('Edit task', $mgr);
        $this->assertStringContainsString('id="asg"', $mgr);
    }

    public function testTaskStatusFormAndInvalidMoves(): void
    {
        $dev = $this->loggedIn('dev@x.test');
        $url = '/tasks/' . $this->task['public_id'];
        $r = $dev->post($url . '/status', ['status' => 'in_progress'], $url);
        $this->assertSame(303, $r['status']);
        $this->assertSame('in_progress', TaskPolicy::find($this->task['public_id'])['status']);
        $r = $dev->post($url . '/status', ['status' => 'completed'], $url);
        $this->assertSame(303, $r['status'], 'refused moves redirect back with an error');
        $this->assertSame('in_progress', TaskPolicy::find($this->task['public_id'])['status']);
        $this->assertStringContainsString('needs review', $dev->get($url)['body']);
        $dev->post($url . '/status', ['status' => 'not_a_status'], $url);
        $this->assertSame('in_progress', TaskPolicy::find($this->task['public_id'])['status']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post($url . '/status', ['status' => 'blocked', 'reason' => 'x y z'], $url)['status']);
    }

    public function testBoardRendersAllColumnsFiltersAndScopes(): void
    {
        $dev = $this->loggedIn('dev@x.test')->get('/board');
        $this->assertSame(200, $dev['status']);
        foreach (['Backlog', 'To Do', 'In Progress', 'Blocked', 'In Review', 'Changes Requested', 'Completed', 'Cancelled'] as $col) {
            $this->assertStringContainsString($col, $dev['body']);
        }
        $this->assertStringContainsString('Build homepage', $dev['body']);
        $this->assertStringNotContainsString('BRAVO-ONLY-TASK', $dev['body']);
        $this->assertStringContainsString('draggable="true"', $dev['body'], 'the assignee can drag their card');
        $this->assertStringContainsString('js/board.js', $dev['body']);
        $this->assertStringNotContainsString('draggable="true"', $this->loggedIn('sup@x.test')->get('/board')['body'], 'support cannot move anything');
        $this->assertStringNotContainsString('Build homepage', $this->loggedIn('mgr2@x.test')->get('/board')['body']);
        $adm = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('BRAVO-ONLY-TASK', $adm->get('/board')['body']);
        $f = $adm->get('/board?project=' . $this->alpha['public_id'])['body'];
        $this->assertStringContainsString('Build homepage', $f);
        $this->assertStringNotContainsString('BRAVO-ONLY-TASK', $f);
        $this->assertStringNotContainsString('Build homepage', $this->loggedIn('dev@x.test')->get('/board?project=' . $this->bravo['public_id'])['body'], 'filtering to a foreign project reveals nothing');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $dev['body'], 'CSP forbids inline script');
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $dev['body'], 'CSP forbids inline style');
    }

    public function testDragAndDropEndpointValidatesEveryMoveOnTheServer(): void
    {
        $dev = $this->loggedIn('dev@x.test');
        $id = $this->task['public_id'];
        $r = $this->jsonMove($dev, $id, 'in_progress');
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['ok']);
        $this->assertSame('in_progress', $r['json']['status']);
        $this->assertSame('in_progress', TaskPolicy::find($id)['status'], 'persisted in MySQL');
        $this->assertContains('in_review', $r['json']['allowed']);
        $this->assertNotContains('cancelled', $r['json']['allowed']);

        $r = $this->jsonMove($dev, $id, 'completed');
        $this->assertSame(422, $r['status']);
        $this->assertFalse($r['json']['ok']);
        $this->assertStringContainsString('review', $r['json']['error']);
        $this->assertSame('in_progress', TaskPolicy::find($id)['status']);

        $r = $this->jsonMove($dev, $id, 'blocked');
        $this->assertSame(422, $r['status'], 'a reason is mandatory');
        $r = $this->jsonMove($dev, $id, 'blocked', 'Waiting on content');
        $this->assertSame(200, $r['status']);
        $this->assertSame('blocked', TaskPolicy::find($id)['status']);

        $this->assertSame(403, $this->jsonMove($dev, $id, 'cancelled')['status'], 'role forbids it');
        $this->assertSame(422, $this->jsonMove($dev, $id, 'backlog')['status'], 'a transition that is not in the machine at all');
        $this->assertSame(404, $this->jsonMove($this->loggedIn('dev2@x.test'), $id, 'in_progress')['status'], 'no access to the project');
        $this->assertSame(404, $this->jsonMove($dev, $this->bravoTask['public_id'], 'in_progress')['status'], 'cannot move another project\'s task by id');
        $this->assertSame('to_do', TaskPolicy::find($this->bravoTask['public_id'])['status']);
        $this->assertSame(403, $this->jsonMove($this->loggedIn('sup@x.test'), $id, 'in_progress')['status']);

        $noToken = $this->jsonMove($dev, $id, 'in_progress', '', false);
        $this->assertSame(419, $noToken['status'], 'CSRF is required for fetch() too');
        $this->assertSame('blocked', TaskPolicy::find($id)['status']);
    }

    public function testCreatingTasksOverHttpValidatesAndKeepsInput(): void
    {
        $url = '/projects/' . $this->alpha['public_id'] . '/tasks';
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post($url, ['title' => 'Worker task here'], $url)['status']);
        $m = $this->loggedIn('mgr@x.test');
        $bad = $m->post($url, ['title' => 'ab', 'description' => 'KEEP-ME-DESC', 'due_date' => '2026-13-45'], $url);
        $this->assertSame(303, $bad['status']);
        $page = $m->get($url)['body'];
        $this->assertStringContainsString('Task title must be', $page);
        $this->assertStringContainsString('KEEP-ME-DESC', $page, 'inputs are preserved');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM tasks WHERE description = 'KEEP-ME-DESC'")['c']);
        $ok = $m->post($url, ['title' => 'Write copy', 'priority' => 'high', 'due_date' => '2026-12-05', 'review_required' => '1', 'assignee' => $this->publicId($this->dev)], $url);
        $this->assertSame(303, $ok['status']);
        $this->assertStringStartsWith('/tasks/', (string) $ok['location']);
        $row = Database::one("SELECT * FROM tasks WHERE title = 'Write copy'");
        $this->assertSame([$this->dev, 'high', $this->manager], [(int) $row['assignee_id'], $row['priority'], (int) $row['created_by']]);
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post('/tasks/' . $row['public_id'], ['title' => 'Hijack title', 'priority' => 'low'], '/projects')['status']);
    }

    public function testOutputIsEscaped(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $t = $this->task['public_id'];
        $m->post("/tasks/{$t}/comments", ['body' => '<script>alert(1)</script> & <img src=x onerror=alert(2)>'], "/tasks/{$t}");
        $page = $m->get("/tasks/{$t}")['body'];
        $this->assertStringNotContainsString('<script>alert(1)', $page);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertStringNotContainsString('<img src=x', $page);
        $bad = $m->post('/projects/' . $this->alpha['public_id'] . '/tasks', ['title' => '"><script>x()</script>'], '/projects/' . $this->alpha['public_id'] . '/tasks');
        $this->assertSame(303, $bad['status']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM tasks WHERE title LIKE '%script%'")['c'], 'markup in titles is rejected outright');
    }

    public function testTaskAttachmentsAreAuthorizedByTheTaskPolicy(): void
    {
        $dir = sys_get_temp_dir() . '/vtx-task-att-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/spec.txt', "Design notes for the homepage\n");
        file_put_contents($dir . '/evil.php', '<?php echo 1;');
        $url = '/tasks/' . $this->task['public_id'];
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(303, $dev->upload($url . '/attachments', [], ['file' => [$dir . '/spec.txt', 'spec.txt', 'text/plain']], $url)['status']);
        $att = Database::one("SELECT * FROM attachments WHERE parent_type = 'task'");
        $this->assertNotNull($att);
        $dev->upload($url . '/attachments', [], ['file' => [$dir . '/evil.php', 'evil.php', 'application/x-php']], $url);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM attachments WHERE parent_type = 'task'")['c'], 'executables are refused');
        $ok = $dev->get('/attachments/' . $att['public_id']);
        $this->assertSame(200, $ok['status']);
        $this->assertStringContainsString('Design notes', $ok['body']);
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->get('/attachments/' . $att['public_id'])['status'], 'other projects\' staff cannot download');
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get('/attachments/' . $att['public_id'])['status'], 'clients cannot download internal task files');
        $this->assertSame(404, $this->loggedIn('mgr2@x.test')->get('/attachments/' . $att['public_id'])['status']);
        $this->assertSame(404, $dev->get('/attachments/' . str_repeat('a', 32))['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'attachment.downloaded'")['c']);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testDeadlinesPageIsStaffOnlyAndScoped(): void
    {
        Database::run("UPDATE tasks SET due_date = '2020-01-01' WHERE id = ?", [$this->task['id']]);
        Database::run("UPDATE tasks SET due_date = '2020-01-01' WHERE id = ?", [$this->bravoTask['id']]);
        $dev = $this->loggedIn('dev@x.test')->get('/deadlines');
        $this->assertSame(200, $dev['status']);
        $this->assertStringContainsString('Build homepage', $dev['body']);
        $this->assertStringNotContainsString('BRAVO-ONLY-TASK', $dev['body']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/deadlines')['status']);
        $this->assertStringContainsString('No overdue tasks', $this->loggedIn('mgr2@x.test')->get('/deadlines')['body']);
        $this->assertSame(303, $this->client()->get('/deadlines')['status'], 'anonymous visitors are sent to sign in');
    }
}
