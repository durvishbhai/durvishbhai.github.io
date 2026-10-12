<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\Audit;
use Vertex\Services\BugEligibilityService;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\ClientApprovalService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryService;
use Vertex\Services\PriceChangeService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectService;
use Vertex\Services\RevisionAccess;
use Vertex\Services\RevisionQuery;
use Vertex\Services\RevisionService;
use Vertex\Services\TaskPolicy;
use Vertex\Services\TaskQuery;
use Vertex\Services\TaskService;
use Vertex\Services\TicketAccess;
use Vertex\Services\TicketQuery;
use Vertex\Services\TicketService;
use Vertex\Services\WarrantyService;

/**
 * One customer journey through every module that exists in this repository, and a second client who must never see any of it:
 * CRM enquiry -> won -> converted to a project -> assignments -> approved baseline -> tasks -> deliverable -> client approval ->
 * revision -> chargeable proposal -> bug report -> warranty -> support ticket. Ends with the audit chain and a scan proving the
 * unbuilt modules (invoices, quotations, a payment gateway and its webhooks) leave no trace.
 */
final class EndToEndWorkflowTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        Config::set('app.timezone', 'Asia/Kolkata');
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    public function testTheWholeJourneyWorksAndStaysIsolatedFromAnotherClient(): void
    {
        // ---- CRM: enquiry -> won -> converted into a project for the existing client
        $enq = EnquiryService::create($this->admin, 'manual', ['contact_name' => 'Clara Client', 'email' => 'alpha@client.test', 'phone' => '+91 98000 11111', 'company_name' => 'Alpha Studio',
            'service' => 'website_development', 'description' => 'We need a catalogue website with about ten pages and an enquiry form.', 'budget_range' => '25k_100k', 'expected_deadline' => '']);
        $enq = EnquiryPolicy::find($enq['public_id']);
        EnquiryService::moveStage($this->admin, $enq, 'won', null, true);
        $clientPublic = Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $conv = EnquiryService::convert($this->admin, EnquiryPolicy::find($enq['public_id']), ['client' => $clientPublic]);
        $project = Database::one('SELECT * FROM projects WHERE id = ?', [$conv['project_id']]);
        Database::run("UPDATE projects SET service_category = 'website_development' WHERE id = ?", [$project['id']]);
        $this->assertSame(Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'], $project['client_id']);

        // ---- projects: assignment, status, approved baseline, priority change decided by the client
        foreach ([$this->manager, $this->dev] as $u) {
            ProjectAssignmentService::assign($this->admin, (int) $project['id'], $u, 'member');
        }
        $p = fn () => ProjectPolicy::find($project['public_id']);
        ProjectService::transition($this->admin, $p(), 'ready_to_start', 'Scope agreed', true);
        ProjectService::transition($this->admin, $p(), 'in_progress', 'Kick-off done', true);
        PriceChangeService::recordApproval($this->admin, $p(), ['quotation_ref' => 'QT-E2E-001', 'base' => '50000', 'surcharge' => '0']);
        $approved = (int) $p()['approved_amount_minor'];
        $this->assertSame(5000000, $approved);
        \Vertex\Services\PricingService::setRule($this->admin, ['service' => '*', 'priority' => 'urgent', 'type' => 'percent', 'fixed' => '', 'percent' => '25', 'reason' => 'Rush surcharge']);
        $res = PriceChangeService::changePriority($this->manager, $p(), 'urgent', 'Client needs it sooner');
        $this->assertFalse($res['applied'], 'a priced change waits for the client');
        $this->assertNotSame('urgent', $p()['priority']);
        $this->assertSame($approved, (int) $p()['approved_amount_minor'], 'the approved price is untouched while the proposal is pending');
        PriceChangeService::respond($this->client, $p(), $res['request'], 'approve', 'OK');
        $this->assertSame('urgent', $p()['priority']);

        // ---- tasks
        $task = TaskService::create($this->manager, $p(), ['title' => 'Build the homepage']);
        $task = TaskPolicy::find($task['public_id']);
        TaskService::assign($this->manager, $task, Database::one('SELECT public_id FROM users WHERE id = ?', [$this->dev])['public_id']);
        TaskService::transition($this->manager, TaskPolicy::find($task['public_id']), 'to_do');
        TaskService::transition($this->dev, TaskPolicy::find($task['public_id']), 'in_progress');
        TaskService::logTime($this->dev, TaskPolicy::find($task['public_id']), '90', AppTime::today(), 'Layout work');
        $this->assertSame(90, (int) Database::one('SELECT SUM(minutes) s FROM task_time_entries')['s']);

        // ---- deliverable: upload (draft) -> internal QA -> publish -> the client approves that exact version
        $d = DeliverableService::create($this->dev, $p(), ['title' => 'Homepage mock-up', 'category' => 'graphic_design'], $this->png('home.png', 'e2e'), false);
        $d = DeliverablePolicy::find($d['public_id']);
        $this->assertNull(Database::one('SELECT 1 x FROM deliverables WHERE id = ? AND first_published_at IS NOT NULL', [$d['id']]), 'upload never publishes');
        $this->assertFalse(DeliverablePolicy::canView($this->client, $d), 'the client sees nothing before publication');
        $v = Database::one('SELECT * FROM deliverable_versions WHERE deliverable_id = ?', [$d['id']]);
        DeliverableService::submitForReview($this->dev, $d, $v);
        DeliverableService::review($this->manager, DeliverablePolicy::find($d['public_id']), Database::one('SELECT * FROM deliverable_versions WHERE id = ?', [$v['id']]), 'approved', 'Matches the brief');
        DeliverableService::publish($this->admin, DeliverablePolicy::find($d['public_id']), Database::one('SELECT * FROM deliverable_versions WHERE id = ?', [$v['id']]));
        $d = DeliverablePolicy::find($d['public_id']);
        $this->assertTrue(DeliverablePolicy::canView($this->client, $d));
        $this->assertTrue(ClientApprovalService::respond($this->client, $d, $v['public_id'], 'approved', 'Looks great', bin2hex(random_bytes(16))));

        // ---- revision -> chargeable -> client decides the extra cost
        $revisionsBefore = Database::all('SELECT * FROM price_revisions ORDER BY id');
        $approved = (int) $p()['approved_amount_minor'];
        $rev = RevisionService::submit($this->client, DeliverablePolicy::find($d['public_id']), ['description' => 'Please add a new pricing page to the design.'], [], false);
        $r = RevisionAccess::find($rev['public_id']);
        RevisionService::transition($this->manager, $r, 'under_review');
        RevisionService::classify($this->admin, RevisionAccess::find($r['public_id']), 'chargeable', ['reason' => 'A new page is outside the agreed scope', 'scope_description' => 'Design a pricing page', 'client_reason' => 'Not in the agreed page list', 'amount' => '6000', 'days' => '2']);
        $cr = RevisionAccess::findChange(Database::one('SELECT public_id FROM change_requests ORDER BY id DESC LIMIT 1')['public_id']);
        ChangeRequestService::send($this->admin, $cr);
        ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($cr['public_id']), 'approve', 'Go ahead');
        $this->assertSame($approved, (int) $p()['approved_amount_minor'], 'approving extra work never edits the approved project price');
        $this->assertSame($revisionsBefore, Database::all('SELECT * FROM price_revisions ORDER BY id'), 'no price revision was written by the extra-work approval');

        // ---- warranty and a bug report
        WarrantyService::record($this->admin, $p(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-5), 'start_basis' => 'client_acceptance', 'quotation_ref' => 'QT-E2E-001']);
        $bugRes = TicketService::createBug($this->client, ['project' => $project['public_id'], 'title' => 'Menu overlaps logo', 'description' => 'On narrow screens the menu overlaps the logo.',
            'steps' => 'Open the site on a phone.', 'expected' => 'No overlap', 'actual' => 'Overlap', 'severity' => 'normal', 'token' => bin2hex(random_bytes(16))], [], false, 'UA', false);
        $bug = TicketAccess::find($bugRes['public_id']);
        $this->assertSame('awaiting_technical_review', $bug['eligibility']);
        BugEligibilityService::decide($this->manager, $bug, ['decision' => 'covered', 'reason' => 'This is a defect in work we delivered, so it is covered.', 'terms' => 'Warranty terms', 'considerations' => []]);
        foreach (['triaged', 'confirmed'] as $to) {
            TicketService::transition($this->manager, TicketAccess::find($bug['public_id']), $to);
        }
        Database::run('UPDATE tickets SET assigned_to = ? WHERE id = ?', [$this->dev, $bug['id']]);
        foreach (['in_progress', 'fixed'] as $to) {
            TicketService::transition($this->dev, TicketAccess::find($bug['public_id']), $to, 'Fixed the menu CSS');
        }
        TicketService::transition($this->manager, TicketAccess::find($bug['public_id']), 'client_verification');
        TicketService::transition($this->client, TicketAccess::find($bug['public_id']), 'closed');
        $this->assertSame('closed', TicketAccess::find($bug['public_id'])['status']);

        // ---- general ticket the worker must not see
        $sup = TicketService::createSupport($this->client, ['category' => 'billing', 'subject' => 'Invoice question', 'description' => 'Please explain the charge on my last statement.', 'project' => $project['public_id']], [], false);
        $this->assertNull(TicketAccess::role($this->dev, TicketAccess::find($sup['public_id'])));
        $this->assertSame('staff', TicketAccess::role($this->manager, TicketAccess::find($sup['public_id'])));

        // ---- even the Owner (who holds every permission key) cannot act AS a client: ownership is checked per record
        $owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->assertTrue(\Vertex\Services\Gate::can('revisions.respond_cost', $owner));
        $cr2 = RevisionAccess::findChange($cr['public_id']);
        foreach ([fn () => ChangeRequestService::clientDecide($owner, $cr2, 'approve', ''),
            fn () => ClientApprovalService::respond($owner, DeliverablePolicy::find($d['public_id']), $v['public_id'], 'approved', 'x', bin2hex(random_bytes(16))),
            fn () => TicketService::createBug($owner, ['project' => $project['public_id']], [], false, '', false)] as $i => $act) {
            try {
                $act();
                $this->fail("owner action #{$i} should have been refused");
            } catch (\Vertex\Core\HttpException | \Vertex\Core\ValidationException $e) {
                $this->addToAssertionCount(1);
            }
        }

        // ---- the other client sees none of it, in any module
        $this->assertSame([$this->bravo['public_id']], array_column(ProjectPolicy::visibleTo($this->client2)['rows'], 'public_id'), 'client2 only sees their own project');
        $this->assertNull(TicketAccess::role($this->client2, TicketAccess::find($bug['public_id'])));
        $this->assertFalse(DeliverablePolicy::canView($this->client2, $d));
        $this->assertNull(RevisionAccess::role($this->client2, $r));
        $this->assertNull(RevisionAccess::changeRole($this->client2, RevisionAccess::findChange($cr['public_id'])));
        $this->assertSame(0, TicketQuery::paginate($this->client2, [])['total']);
        $this->assertSame(0, RevisionQuery::paginate($this->client2, [])['total']);
        $this->assertSame([], WarrantyService::forClient($this->partner));
        $this->assertStringNotContainsString('Alpha', json_encode(WarrantyService::forClient($this->client2)));
        $this->assertFalse(TaskPolicy::canView($this->client2, TaskPolicy::find($task['public_id'])));
        $this->assertFalse(TaskPolicy::canView($this->client, TaskPolicy::find($task['public_id'])), 'clients never reach internal tasks');
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->client2}"));

        // ---- audit trail: every step above is on the chain, the chain verifies, no free text or secrets stored
        $this->assertTrue(Audit::verify()['ok']);
        $actions = array_column(Database::all('SELECT DISTINCT action FROM audit_logs'), 'action');
        foreach (['project.created', 'pricing.baseline_recorded', 'ticket.created', 'ticket.eligibility_decided', 'change_request.client_approved', 'warranty.configured', 'ticket.closed', 'revision.classified'] as $a) {
            $this->assertContains($a, $actions, "audit entry {$a}");
        }
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) c FROM audit_logs WHERE after_json LIKE '%password%' OR meta_json LIKE '%password%'"));

        // ---- modules that are NOT built leave no trace and nothing pretends to be money
        $tables = array_column(Database::all('SHOW TABLES'), array_key_first(Database::one('SHOW TABLES')));
        foreach ($tables as $t) {
            $this->assertDoesNotMatchRegularExpression('/invoice|webhook|quotation/', (string) $t, "table {$t} suggests an unbuilt module exists");
        }
    }

    private function scalar(string $sql): int
    {
        return (int) Database::one($sql)['c'];
    }
}
