<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\AutomationService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\FeedbackPointService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectTemplateService;
use Vertex\Services\RevisionService;
use Vertex\Services\ScopeRequestService;
use Vertex\Services\SlaService;
use Vertex\Services\TicketService;

/** Phase 11: project templates and checklists, client scope requests, timestamped feedback, SLA timers, workflow rules. */
final class Phase11KitTest extends HttpTestCase
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

    private function invalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }

    private function forbidden(callable $fn, int $status = 403): void
    {
        try {
            $fn();
            $this->fail("HTTP {$status} expected");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    private function project(): array
    {
        return ProjectPolicy::findById((int) $this->alpha['id']);
    }

    private function template(string $name = 'Website kit'): array
    {
        $pub = ProjectTemplateService::save($this->owner, null, ['name' => $name, 'description' => 'Standard website', 'service_category' => 'website_development',
            'items' => "M: Discovery\nT: Collect content | +3 | 120 | high\nT: Sitemap | +5\nM: Build\nT: Home page | +10 | 480\nC: Domain access confirmed\nC: Logo received"]);

        return ProjectTemplateService::find($pub);
    }

    // ------------------------------------------------------------------------------------------------ templates and checklists

    public function testTemplatesParseStrictlyAndApplyThroughTheRealServices(): void
    {
        foreach (["", "X: nonsense", "T: Task | abc", "T: Task | +3 | xx", "T: Task | +3 | 60 | huge", "M: <b>"] as $bad) {
            $this->invalid(fn () => ProjectTemplateService::save($this->owner, null, ['name' => 'Bad one', 'items' => $bad]), '');
        }
        $this->forbidden(fn () => ProjectTemplateService::save($this->manager, null, ['name' => 'Mine', 'items' => 'M: Something']));
        $t = $this->template();
        $this->assertStringContainsString("T: Collect content | +3 | 120 | high", ProjectTemplateService::toText($t));
        $res = ProjectTemplateService::apply($this->manager, $this->project(), $t, true);
        $this->assertSame(['milestones' => 2, 'tasks' => 3, 'checklist' => 2], $res);
        $tasks = Database::all('SELECT t.title, t.priority, t.estimate_minutes, t.due_date, m.title AS milestone FROM tasks t LEFT JOIN milestones m ON m.id = t.milestone_id WHERE t.project_id = ? ORDER BY t.id', [$this->alpha['id']]);
        $this->assertSame(['Discovery', 'Discovery', 'Build'], array_column($tasks, 'milestone'));
        $this->assertSame('high', $tasks[0]['priority']);
        $this->assertSame(120, (int) $tasks[0]['estimate_minutes']);
        $this->assertNotNull($tasks[0]['due_date']);
        $this->assertSame(['done' => 0, 'total' => 2], ProjectTemplateService::progress($this->project()));
        $this->invalid(fn () => ProjectTemplateService::apply($this->manager, $this->project(), $t, false), 'already has');
        $this->forbidden(fn () => ProjectTemplateService::apply($this->client, $this->project(), $t, true));
        $this->forbidden(fn () => ProjectTemplateService::apply($this->manager2, $this->project(), $t, true)); // not assigned to this project
        ProjectTemplateService::retire($this->owner, $t, true);
        $this->invalid(fn () => ProjectTemplateService::apply($this->manager, $this->project(), ProjectTemplateService::find($t['public_id']), true), 'retired');
    }

    public function testChecklistProgressScopeAndClientView(): void
    {
        ProjectTemplateService::apply($this->manager, $this->project(), $this->template(), true);
        $p = $this->project();
        $items = ProjectTemplateService::checklist($p);
        $this->assertCount(2, $items);
        $d = $this->makeDeliverable();
        $ver = $this->ver($d, 1);
        ProjectTemplateService::changeItem($this->manager, $p, (int) $items[0]['id'], 'done', (int) $ver['id']);
        $this->assertSame(['done' => 1, 'total' => 2], ProjectTemplateService::progress($p));
        $this->assertSame((int) $ver['id'], (int) ProjectTemplateService::checklist($p)[0]['delivered_in_version_id']);
        $this->invalid(fn () => ProjectTemplateService::changeItem($this->manager, $p, (int) $items[1]['id'], 'done', 999999), 'version');
        ProjectTemplateService::changeItem($this->manager, $p, (int) $items[1]['id'], 'out_of_scope');
        $this->assertSame(['done' => 1, 'total' => 1], ProjectTemplateService::progress($p), 'out-of-scope points do not count');
        $this->assertCount(1, ProjectTemplateService::checklist($p, true), 'the client does not see out-of-scope points');
        $this->forbidden(fn () => ProjectTemplateService::addItem($this->client, $p, 'Client tries'));
        $this->forbidden(fn () => ProjectTemplateService::addItem($this->dev2, $p, 'Not on this project'));
        ProjectTemplateService::addItem($this->manager, $p, 'Extra acceptance point');
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/projects/' . $p['public_id'] . '/checklist');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Domain access confirmed', $page['body']);
        $this->assertStringNotContainsString('Logo received', $page['body']);
        $this->assertSame(403, $c->post('/projects/' . $p['public_id'] . '/checklist', ['action' => 'add', 'title' => 'x'], '/account')['status']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/projects/' . $p['public_id'] . '/checklist')['status']);
    }

    // ------------------------------------------------------------------------------------------------ scope requests

    public function testClientScopeRequestFlowToAChangeProposal(): void
    {
        $p = $this->project();
        $r = ScopeRequestService::submit($this->client, $p, 'Please add a members-only area with login to the website.', AppTime::todayPlus(30), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertMatchesRegularExpression('/^SCR-\d{4}-\d{5}$/', $r['reference']);
        $again = ScopeRequestService::submit($this->client, $p, 'Please add a members-only area with login to the website.', '', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame($r['public_id'], $again['public_id'], 'a double submit is one request');
        $this->invalid(fn () => ScopeRequestService::submit($this->client, $p, 'short', ''), '15');
        $this->forbidden(fn () => ScopeRequestService::submit($this->client2, $p, 'Another client asking for things', ''), 404);
        $this->forbidden(fn () => ScopeRequestService::submit($this->manager, $p, 'Staff cannot ask as a client', ''), 403);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'scope.requested'")['c'], 'assigned staff are told');
        $s = ScopeRequestService::find($r['public_id']);
        $this->forbidden(fn () => ScopeRequestService::requireStaff($this->client, $s['public_id']), 404);
        $this->forbidden(fn () => ScopeRequestService::requireStaff($this->manager2, $s['public_id']), 404); // not on the project
        $this->invalid(fn () => ScopeRequestService::convert($this->manager, $s, '', ''), 'assessment');
        ScopeRequestService::assess($this->manager, $s, '25000', '10', 'Needs a login system');
        $s = ScopeRequestService::find($r['public_id']);
        $this->assertSame([2500000, 10, 'assessing'], [(int) $s['est_amount_minor'], (int) $s['est_days'], $s['status']]);
        $crPub = ScopeRequestService::convert($this->manager, $s, '', '');
        $cr = Database::one('SELECT * FROM change_requests WHERE public_id = ?', [$crPub]);
        $this->assertSame(['draft', 2500000, 10], [$cr['status'], (int) $cr['proposed_amount_minor'], (int) $cr['proposed_days']]);
        $this->assertStringContainsString('members-only area', $cr['scope_description']);
        $this->assertSame('quoted', ScopeRequestService::find($r['public_id'])['status']);
        $this->invalid(fn () => ScopeRequestService::convert($this->manager, ScopeRequestService::find($r['public_id']), '', ''), 'assessment');
        // decline path + withdraw
        $b = ScopeRequestService::submit($this->client, $p, 'Could you also translate the site into Hindi?', '');
        ScopeRequestService::decline($this->manager, ScopeRequestService::find($b['public_id']), 'Translation is outside what we offer.');
        $this->assertSame('declined', ScopeRequestService::find($b['public_id'])['status']);
        $c = ScopeRequestService::submit($this->client, $p, 'Add a blog section with categories please', '');
        ScopeRequestService::withdraw($this->client, ScopeRequestService::find($c['public_id']));
        $this->assertSame('withdrawn', ScopeRequestService::find($c['public_id'])['status']);
        $cl = $this->loggedIn('alpha@client.test');
        $this->assertSame(200, $cl->get('/projects/' . $p['public_id'] . '/scope-request')['status']);
        $this->assertStringContainsString('SCR-', $cl->get('/my/scope-requests')['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/scope-requests/' . $r['public_id'])['status']);
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get('/scope-requests/' . $r['public_id'])['status']);
    }

    // ------------------------------------------------------------------------------------------------ timestamped feedback

    public function testFeedbackPointsWithTimestamps(): void
    {
        $this->assertSame([75, 75, 4515, null], [FeedbackPointService::parseTime('1:15'), FeedbackPointService::parseTime('75'), FeedbackPointService::parseTime('1:15:15'), FeedbackPointService::parseTime('')]);
        $this->assertSame(['1:15', '1:15:15'], [FeedbackPointService::format(75), FeedbackPointService::format(4515)]);
        foreach (['1:75', 'abc', '99:99:99', '-5', '1:2:3:4'] as $bad) {
            $this->invalid(fn () => FeedbackPointService::parseTime($bad), 'Time must');
        }
        $d = $this->makeDeliverable();
        $this->publishVersion($d, 1);
        $rev = RevisionService::submit($this->client, DeliverablePolicy::find($d['public_id']), ['description' => 'Several changes are needed in the video.'], [], false);
        $r = \Vertex\Services\RevisionAccess::find($rev['public_id']);
        FeedbackPointService::add($this->client, $r, '0:45', 'Remove the second logo');
        FeedbackPointService::add($this->client, $r, '', 'Overall the music is too loud');
        $points = FeedbackPointService::forRevision($r);
        $this->assertSame([45, null], array_map(static fn (array $p): ?int => $p['at_seconds'] === null ? null : (int) $p['at_seconds'], $points));
        $this->assertSame([1, 2], array_map(static fn (array $p): int => (int) $p['position'], $points));
        $this->forbidden(fn () => FeedbackPointService::add($this->client2, $r, '', 'Not my revision'), 404);
        $this->forbidden(fn () => FeedbackPointService::add($this->manager, $r, '', 'Staff cannot add as a client'), 403);
        $this->forbidden(fn () => FeedbackPointService::resolve($this->client, $r, (int) $points[0]['id'], true), 403);
        FeedbackPointService::resolve($this->manager, $r, (int) $points[0]['id'], true);
        $this->assertNotNull(FeedbackPointService::forRevision($r)[0]['resolved_at']);
        $this->forbidden(fn () => FeedbackPointService::resolve($this->manager2, $r, (int) $points[0]['id'], false), 404);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertStringContainsString('Remove the second logo', $c->get('/revisions/' . $r['public_id'])['body']);
        $c->post('/revisions/' . $r['public_id'] . '/feedback', ['at' => '2:00', 'note' => 'Fix the subtitle spelling'], '/account');
        $this->assertCount(3, FeedbackPointService::forRevision($r));
    }

    // ------------------------------------------------------------------------------------------------ SLA

    public function testSlaTimersBreachAndAutomaticEscalation(): void
    {
        $this->invalid(fn () => SlaService::savePolicies($this->owner, ['urgent' => ['first' => '0', 'resolution' => '24', 'escalate' => '4']] + array_fill_keys(['high', 'normal', 'low'], ['first' => '4', 'resolution' => '48', 'escalate' => '8'])), 'first hours');
        $this->invalid(fn () => SlaService::savePolicies($this->owner, ['urgent' => ['first' => '10', 'resolution' => '5', 'escalate' => '4']] + array_fill_keys(['high', 'normal', 'low'], ['first' => '4', 'resolution' => '48', 'escalate' => '8'])), 'shorter');
        $this->forbidden(fn () => SlaService::savePolicies($this->manager, []));
        $t = TicketService::createSupport($this->client, ['category' => 'billing', 'subject' => 'Question about the bill', 'description' => 'I would like to understand a line on my statement.', 'token' => bin2hex(random_bytes(16))], [], false);
        $row = Database::one('SELECT * FROM tickets WHERE public_id = ?', [$t['public_id']]);
        $this->assertNull($row['first_response_due_at']);
        $this->assertGreaterThanOrEqual(1, SlaService::stampPending());
        $row = Database::one('SELECT * FROM tickets WHERE id = ?', [$row['id']]);
        $hours = (int) Database::one('SELECT first_response_hours h FROM sla_policies WHERE priority = ?', [$row['priority']])['h'];
        $this->assertSame($hours * 3600, strtotime($row['first_response_due_at'] . ' UTC') - strtotime($row['submitted_at'] . ' UTC'));
        $this->assertSame('pending', SlaService::timers($row)['first']['state']);
        $before = SlaService::check();
        $this->assertSame(0, $before['breached'], 'nothing is late yet');
        $future = gmdate('Y-m-d H:i:s', strtotime('+3 days'));
        $r1 = SlaService::check($future);
        $this->assertSame(1, $r1['breached']);
        $this->assertSame(0, SlaService::check($future)['breached'], 'a breach is recorded once');
        $this->assertNotNull(Database::one('SELECT sla_breached_at FROM tickets WHERE id = ?', [$row['id']])['sla_breached_at']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'ticket.sla_breached'")['c']);
        $r2 = SlaService::check(gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $this->assertSame(1, $r2['escalated']);
        $esc = Database::one('SELECT escalated_at, escalated_by, escalation_reason FROM tickets WHERE id = ?', [$row['id']]);
        $this->assertNotNull($esc['escalated_at']);
        $this->assertNull($esc['escalated_by']);
        $this->assertStringContainsString('Automatic', $esc['escalation_reason']);
        Database::run("UPDATE tickets SET first_response_due_at = '2000-01-01 00:00:00' WHERE id = ?", [$row['id']]); // the real clock is not in the future: show the "late" state
        $this->assertSame('late', SlaService::timers(Database::one('SELECT * FROM tickets WHERE id = ?', [$row['id']]))['first']['state']);
        $s = $this->loggedIn('admin@x.test')->get('/tickets/' . $t['public_id']);
        $this->assertStringContainsString('SLA: first reply', $s['body']);
        $this->assertStringContainsString('MISSED', $s['body']);
        // a ticket that got its reply in time is not a breach
        $t2 = TicketService::createSupport($this->client, ['category' => 'billing', 'subject' => 'Second question', 'description' => 'Another question for the team about my invoice.', 'token' => bin2hex(random_bytes(16))], [], false);
        SlaService::stampPending();
        Database::run('UPDATE tickets SET first_staff_response_at = UTC_TIMESTAMP() WHERE public_id = ?', [$t2['public_id']]);
        SlaService::check(gmdate('Y-m-d H:i:s', strtotime('+30 days')));
        $this->assertSame(2, (int) SlaService::compliance('2000-01-01', '2100-01-01')['total']);
    }

    // ------------------------------------------------------------------------------------------------ automation rules

    public function testWorkflowRulesNotifyOnceAndEscalate(): void
    {
        $this->forbidden(fn () => AutomationService::save($this->manager, null, ['name' => 'Mine', 'trigger_type' => 'task_overdue', 'threshold' => '1', 'notify_target' => 'owner']));
        $this->invalid(fn () => AutomationService::save($this->owner, null, ['name' => 'x', 'trigger_type' => 'nonsense', 'threshold' => 'abc', 'notify_target' => 'owner']), 'rule name');
        $this->invalid(fn () => AutomationService::save($this->owner, null, ['name' => 'Bad target', 'trigger_type' => 'task_overdue', 'threshold' => '1', 'notify_target' => 'permission:does.not.exist']), 'valid target');
        $this->invalid(fn () => AutomationService::save($this->owner, null, ['name' => 'Escalation needs time', 'trigger_type' => 'task_overdue', 'threshold' => '1', 'notify_target' => 'owner', 'escalate_to' => 'role:administrator', 'escalate_after' => '']), 'longer');
        $pub = AutomationService::save($this->owner, null, ['name' => 'Overdue tasks', 'trigger_type' => 'task_overdue', 'threshold' => '2', 'notify_target' => 'owner', 'escalate_to' => 'role:administrator', 'escalate_after' => '3', 'enabled' => '1']);
        // an overdue task assigned to the developer
        $task = \Vertex\Services\TaskService::create($this->manager, $this->project(), ['title' => 'Build the contact page', 'description' => '', 'milestone' => '', 'assignee' => '', 'start_date' => '', 'due_date' => AppTime::todayPlus(-1), 'priority' => 'normal', 'estimate_minutes' => '', 'review_required' => '', 'status' => 'to_do']);
        Database::run('UPDATE tasks SET assignee_id = ? WHERE public_id = ?', [$this->dev, $task['public_id']]);
        $this->assertSame(0, AutomationService::run()['notified'], 'only 1 day late, the rule waits for 2');
        Database::run('UPDATE tasks SET due_date = ? WHERE public_id = ?', [AppTime::todayPlus(-2), $task['public_id']]);
        $r = AutomationService::run();
        $this->assertSame(1, $r['notified'], 'the assignee is told');
        $this->assertSame(0, AutomationService::run()['notified'], 'and not again');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'automation.task_overdue'", [$this->dev])['c']);
        Database::run('UPDATE tasks SET due_date = ? WHERE public_id = ?', [AppTime::todayPlus(-6), $task['public_id']]);
        $r = AutomationService::run();
        $this->assertGreaterThanOrEqual(1, $r['notified'], 'after 2 + 3 days the administrators are told too');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND title LIKE 'Escalation:%'", [$this->admin])['c']);
        $rule = AutomationService::find($pub);
        $this->assertSame(1, (int) $rule['last_count']);
        AutomationService::save($this->owner, $rule, ['name' => 'Overdue tasks', 'trigger_type' => 'task_overdue', 'threshold' => '2', 'notify_target' => 'owner', 'enabled' => '']);
        $this->assertSame(0, AutomationService::run()['rules'], 'a rule that is switched off is not run');
        // permission target
        AutomationService::save($this->owner, null, ['name' => 'Unverified payments', 'trigger_type' => 'payment_unverified', 'threshold' => '0', 'notify_target' => 'permission:payments.reconcile', 'enabled' => '1']);
        $inv = \Vertex\Services\InvoiceService::create($this->finance, (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'], ['title' => 'Inv', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        \Vertex\Services\BusinessProfile::save($this->admin, ['trade_name' => 'Vertex', 'address' => '12 Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Pay by UPI x', 'invoice_footer' => 'Thanks']);
        \Vertex\Services\InvoiceService::issue($this->admin, \Vertex\Services\InvoiceService::find($inv['public_id']));
        \Vertex\Services\ClientPaymentService::record($this->finance, ['invoice' => $inv['public_id'], 'role' => 'other', 'gross' => '100', 'method' => 'cash', 'external_ref' => 'CASH-XYZ', 'received_on' => AppTime::today()]);
        AutomationService::run();
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'automation.payment_unverified'")['c']);
        // gates
        $o = $this->loggedIn('owner@x.test');
        $this->assertSame(200, $o->get('/admin/automation')['status']);
        $this->assertSame(200, $o->get('/admin/sla')['status']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/admin/automation')['status']);
    }
}
