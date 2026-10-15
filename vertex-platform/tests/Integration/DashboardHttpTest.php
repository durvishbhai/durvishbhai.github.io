<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\DashboardService;

/** The home screen and the grouped sidebar: every link and figure follows the person's own permissions. */
final class DashboardHttpTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    public function testSidebarIsGroupedAndShowsOnlyWhatTheRoleMayOpen(): void
    {
        $owner = $this->loggedIn('owner@x.test')->get('/dashboard')['body'];
        foreach (['Administration', 'Projects &amp; delivery', 'Money', 'Website', 'Integrations &amp; settings', 'Audit log'] as $needle) {
            $this->assertStringContainsString($needle, $owner);
        }
        $this->assertStringContainsString('aria-current="page"', $owner);

        $client = $this->loggedIn('alpha@client.test')->get('/dashboard')['body'];
        $this->assertStringContainsString('My invoices', $client);
        foreach (['Administration', 'Audit log', 'Integrations &amp; settings', '>Users<', 'Commissions', 'Recycle bin'] as $never) {
            $this->assertStringNotContainsString($never, $client, "a client must not see {$never}");
        }

        $support = $this->loggedIn('sup@x.test')->get('/dashboard')['body'];
        $this->assertStringNotContainsString('>Invoices<', $support);
        $this->assertStringNotContainsString('Administration', $support);
        $this->assertStringContainsString('Search everything', $support);
    }

    public function testClientFiguresCountOnlyTheirOwnRecords(): void
    {
        $mine = DashboardService::build($this->client);
        $projects = array_values(array_filter($mine['stats'], fn ($s) => $s['label'] === 'Active projects'));
        $this->assertCount(1, $projects);
        $own = (int) Database::one("SELECT COUNT(*) c FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.archived_at IS NULL AND p.status IN ('in_progress','ready_to_start','awaiting_client_approval','awaiting_payment')", [$this->client])['c'];
        $this->assertSame((string) $own, $projects[0]['n']);
        $labels = array_column($mine['stats'], 'label');
        foreach (['Unpaid invoices', 'Open enquiries', 'Open tickets', 'Payments to verify', 'Overdue tasks'] as $staffOnly) {
            $this->assertNotContains($staffOnly, $labels);
        }
        $this->assertNotContains('New client', array_column($mine['actions'], 'label'));
    }

    public function testStaffSeeTheirOwnOverdueWorkAndQuickActions(): void
    {
        \Vertex\Services\TaskService::create($this->manager, $this->alphaRow(), ['title' => 'Late thing', 'description' => '', 'milestone' => '', 'assignee' => '', 'start_date' => '', 'due_date' => '2020-01-01', 'priority' => 'normal', 'estimate_minutes' => '', 'review_required' => '', 'status' => 'to_do']);
        $h = DashboardService::build($this->manager);
        $overdue = array_values(array_filter($h['stats'], fn ($s) => $s['label'] === 'Overdue tasks'));
        $this->assertSame('1', $overdue[0]['n']);
        $this->assertSame('bad', $overdue[0]['tone']);
        $this->assertContains('Late thing', array_column($h['todo'], 'title'));
        $page = $this->loggedIn('mgr@x.test')->get('/dashboard')['body'];
        $this->assertStringContainsString('Late thing', $page);
        $this->assertStringContainsString('What needs you', $page);
        // someone with no access to that project sees nothing of it
        $other = DashboardService::build($this->manager2);
        $this->assertNotContains('Late thing', array_column($other['todo'], 'title'));
    }
}
