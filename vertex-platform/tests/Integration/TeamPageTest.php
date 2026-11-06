<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\TaskService;

/** The Team & assignments overview: administrators only, read-only, accurate workload numbers. */
final class TeamPageTest extends HttpTestCase
{
    public function testOnlyAdministratorsSeeTheTeamPageAndTheNumbersAreRight(): void
    {
        PermissionSync::run(true);
        $admin = $this->makeUser('admin@x.test', ['administrator']);
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->makeUser('idle@x.test', ['website_developer']);
        $client = $this->makeUser('cl@x.test', ['client']);
        $staffed = $this->makeProject($client, 'Staffed Project');
        $empty = $this->makeProject($client, 'Nobody Here Project');
        ProjectAssignmentService::assign($admin, $staffed['id'], $mgr, 'lead');
        ProjectAssignmentService::assign($admin, $staffed['id'], $dev, 'member');
        $p = ProjectPolicy::find($staffed['public_id']);
        $a = TaskService::create($admin, $p, ['title' => 'Overdue work', 'assignee' => $this->publicId($dev), 'due_date' => '2020-01-01', 'status' => 'to_do']);
        TaskService::create($admin, $p, ['title' => 'Fine work', 'assignee' => $this->publicId($dev), 'status' => 'to_do']);
        Database::run("UPDATE tasks SET status = 'blocked' WHERE public_id = ?", [$a['public_id']]);

        $page = $this->loggedIn('admin@x.test')->get('/team');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('User dev@x.test', $page['body']);
        $this->assertStringContainsString('Staffed Project', $page['body']);
        $this->assertStringContainsString('Nobody Here Project', $page['body'], 'unstaffed projects are listed');
        $this->assertStringNotContainsString('User idle@x.test', $page['body'], 'people with no assignment are not listed');
        $this->assertMatchesRegularExpression('/User dev@x\.test.*?<td><a href="\/tasks\?assignee=[a-f0-9]{32}">2<\/a><\/td>\s*<td><span class="overdue">1<\/span><\/td>\s*<td>1<\/td>/s', $page['body'], 'open=2, overdue=1, blocked=1');
        $this->assertStringNotContainsString('Add team member', $page['body'], 'read-only');

        foreach (['mgr@x.test', 'dev@x.test', 'cl@x.test'] as $who) {
            $this->assertSame(403, $this->loggedIn($who)->get('/team')['status'], "{$who} may not open the team page");
        }
        $this->assertSame(303, $this->client()->get('/team')['status']);
    }
}
