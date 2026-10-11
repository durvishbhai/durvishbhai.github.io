<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\Audit;
use Vertex\Services\BugEligibilityService;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\Gate;
use Vertex\Services\RevisionAccess;
use Vertex\Services\TicketAccess;
use Vertex\Services\TicketQuery;
use Vertex\Services\TicketService;
use Vertex\Services\WarrantyService;

/** Bug reports and support tickets: access, isolation, workflow, warranty review, chargeable approval, privacy, notifications. */
final class TicketServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        Config::set('app.timezone', 'Asia/Kolkata');
        Config::set('support.sla', []);
    }

    protected function tearDown(): void
    {
        AppTime::freeze(null);
        $this->tearDownPhase3();
    }

    /** @param class-string<\Throwable> $class */
    private function expectThrows(callable $fn, string $class = HttpException::class, ?int $status = null): void
    {
        try {
            $fn();
            $this->fail('Expected ' . $class);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());
            if ($status !== null) {
                $this->assertSame($status, $e->status);
            }
        }
    }

    /** @return array<string,mixed> */
    private function bugIn(array $over = []): array
    {
        return $over + ['project' => $this->alpha['public_id'], 'title' => 'Contact form shows an error', 'description' => 'When I press send the page shows an error message.', 'steps' => 'Open /contact, fill the form, press Send.',
            'expected' => 'A thank-you message', 'actual' => 'A red error banner', 'affected_url' => 'https://alpha.example/contact', 'browser' => 'Firefox 130', 'device' => 'Windows laptop', 'severity' => 'high', 'token' => bin2hex(random_bytes(16))];
    }

    /** @return array<string,mixed> ticket row as TicketAccess loads it */
    private function bug(array $over = [], bool $draft = false, ?array $files = null): array
    {
        $res = TicketService::createBug($this->client, $this->bugIn($over), $files ?? [], $draft, 'TestAgent/1.0', false);

        return TicketAccess::find($res['public_id']);
    }

    /** @return array<string,mixed> */
    private function support(array $over = []): array
    {
        $res = TicketService::createSupport($this->client, $over + ['category' => 'billing', 'subject' => 'Question about my bill', 'description' => 'I would like to understand a line on my statement.', 'token' => bin2hex(random_bytes(16))], [], false);

        return TicketAccess::find($res['public_id']);
    }

    private function fresh(array $t): array
    {
        return TicketAccess::find($t['public_id']);
    }

    private function setStatus(array $t, string $status): array
    {
        Database::run('UPDATE tickets SET status = ? WHERE id = ?', [$status, $t['id']]);

        return $this->fresh($t);
    }

    private function activeWarranty(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-30), 'start_basis' => 'final_delivery']);
    }

    private function notifs(int $user, ?string $event = null): int
    {
        return (int) Database::one('SELECT COUNT(*) c FROM notifications WHERE user_id = ?' . ($event ? ' AND event_type = ?' : ''), $event ? [$user, $event] : [$user])['c'];
    }

    // ------------------------------------------------------------------------------------------------------ creation

    public function testClientReportsABugOnTheirOwnProjectWithAllTheFields(): void
    {
        $this->activeWarranty();
        $t = $this->bug([], false, [$this->png('shot.png')]);
        $this->assertMatchesRegularExpression('/^BUG-\d{4}-\d{4,}$/', $t['reference']);
        $this->assertSame('bug', $t['kind']);
        $this->assertSame('open', $t['status']);
        $this->assertSame('high', $t['severity']);
        $this->assertSame('normal', $t['priority'], 'priority is the team\'s field and starts neutral');
        $this->assertSame($this->alpha['id'], (int) $t['project_id']);
        $this->assertSame('https://alpha.example/contact', $t['affected_url']);
        $this->assertSame('TestAgent/1.0', $t['captured_user_agent']);
        $this->assertSame('awaiting_technical_review', $t['eligibility'], 'an active warranty only puts it in the review queue');
        $this->assertNotNull($t['submitted_at']);
        $snap = json_decode($t['warranty_snapshot_json'], true);
        $this->assertSame('active', $snap['status']);
        $this->assertCount(1, Database::all('SELECT 1 FROM ticket_files WHERE ticket_id = ?', [$t['id']]));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.created' AND actor_id = ?", [$this->client])['c']);
        $this->assertTrue(Audit::verify()['ok']);
    }

    public function testWarrantyPositionOnlyChoosesTheQueueNeverRejects(): void
    {
        // no warranty recorded (pending), not applicable, expired and scheduled all remain submittable and are never auto-rejected
        $t = $this->bug();
        $this->assertSame('submitted_for_assessment', $t['eligibility']);
        $this->assertSame('open', $t['status']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-400), 'start_basis' => 'final_delivery']);
        $e = $this->bug();
        $this->assertSame('submitted_for_assessment', $e['eligibility']);
        $this->assertSame('expired', json_decode($e['warranty_snapshot_json'], true)['status']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'not_applicable', 'reason' => 'No warranty sold']);
        $n = $this->bug();
        $this->assertSame('submitted_for_assessment', $n['eligibility']);
        $this->assertSame('open', $n['status']);
        $this->assertNull(Database::one("SELECT 1 x FROM tickets WHERE status = 'rejected'"));
    }

    public function testOnlyOwnProjectsAreAcceptedAndOnlyClientsMayReport(): void
    {
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(['project' => $this->bravo['public_id']]), [], false, '', false), ValidationException::class);
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(['project' => 'nope']), [], false, '', false), ValidationException::class);
        foreach ([$this->admin, $this->manager, $this->dev, $this->partner] as $u) {
            $this->expectThrows(fn () => TicketService::createBug($u, $this->bugIn(), [], false, '', false), HttpException::class, 403);
            $this->expectThrows(fn () => TicketService::createSupport($u, ['category' => 'billing', 'subject' => 'Hello there', 'description' => 'A question for the team.'], [], false), HttpException::class, 403);
        }
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->alpha['id']]);
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(), [], false, '', false), ValidationException::class);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM tickets')['c']);
    }

    public function testSubmittingTwiceWithTheSameTokenCreatesOneTicketAndOneNotification(): void
    {
        $in = $this->bugIn();
        $a = TicketService::createBug($this->client, $in, [], false, '', false);
        $b = TicketService::createBug($this->client, $in, [], false, '', false);
        $this->assertTrue($a['created']);
        $this->assertFalse($b['created']);
        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM tickets')['c']);
        $this->assertSame(1, $this->notifs($this->admin, 'bug.submitted'));
        $this->assertSame(1, $this->notifs($this->manager, 'bug.submitted'), 'assigned staff are told too');
        $this->assertSame(0, $this->notifs($this->dev, 'bug.submitted'), 'workers hold no queue permission');
        $this->assertSame(0, $this->notifs($this->client2));
        $this->assertSame(0, $this->notifs($this->partner));
        $this->assertSame(0, $this->notifs($this->client), 'a client is not notified of their own action');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE email_status <> 'not_sent'")['c'], 'no notification claims an email');
        $s1 = TicketService::createSupport($this->client, ['category' => 'billing', 'subject' => 'Same token', 'description' => 'Duplicate submit check.', 'token' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'], [], false);
        $s2 = TicketService::createSupport($this->client, ['category' => 'billing', 'subject' => 'Same token', 'description' => 'Duplicate submit check.', 'token' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'], [], false);
        $this->assertSame($s1['public_id'], $s2['public_id']);
        $this->assertFalse($s2['created']);
    }

    public function testDraftsAreInvisibleToStaffUntilSubmitted(): void
    {
        $d = $this->bug(['title' => 'Half written'], true);
        $this->assertSame('draft', $d['status']);
        $this->assertNull($d['eligibility']);
        $this->assertSame('client', TicketAccess::role($this->client, $d));
        foreach ([$this->admin, $this->manager, $this->support, $this->dev] as $u) {
            $this->assertNull(TicketAccess::role($u, $d), 'staff cannot open a draft');
            $this->assertSame(0, TicketQuery::paginate($u, [])['total']);
        }
        $this->assertSame(0, $this->notifs($this->admin), 'no notification for a draft');
        $this->expectThrows(fn () => TicketService::reply($this->client, $d, 'hello'), ValidationException::class);
        TicketService::submitDraft($this->client, $d, $this->bugIn(['title' => 'Now complete']), true, 'UA/2');
        $s = $this->fresh($d);
        $this->assertSame('open', $s['status']);
        $this->assertSame('Now complete', $s['subject']);
        $this->assertNotNull($s['eligibility']);
        $this->assertSame(1, $this->notifs($this->admin, 'bug.submitted'));
        $this->assertSame(1, TicketQuery::paginate($this->admin, [])['total']);
        $this->expectThrows(fn () => TicketService::submitDraft($this->client, $s, $this->bugIn(), true), HttpException::class, 404);
        $this->expectThrows(fn () => TicketService::submitDraft($this->client2, $d, $this->bugIn(), true), HttpException::class, 404);
    }

    public function testDraftsAllowPartialInputButSubmissionDoesNot(): void
    {
        $d = $this->bug(['description' => '', 'steps' => '', 'expected' => '', 'actual' => ''], true);
        $this->assertSame('draft', $d['status']);
        $this->expectThrows(fn () => TicketService::submitDraft($this->client, $d, $this->bugIn(['steps' => '']), true), ValidationException::class);
        $this->assertSame('draft', $this->fresh($d)['status']);
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(['steps' => '', 'title' => 'ab']), [], false, '', false), ValidationException::class);
    }

    public function testAffectedUrlIsValidated(): void
    {
        foreach (['javascript:alert(1)', 'ftp://x.test/a', 'https://user:pw@x.test/', 'https://x.test/a b', "https://x.test/\"onload=1", 'data:text/html,hi', 'not a url', '//x.test/a'] as $bad) {
            $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(['affected_url' => $bad]), [], false, '', false), ValidationException::class);
        }
        $this->assertSame('http://alpha.example/a?b=1#c', $this->bug(['affected_url' => 'http://alpha.example/a?b=1#c'])['affected_url']);
        $this->assertNull($this->bug(['affected_url' => ''])['affected_url']);
    }

    public function testAttachmentsGoThroughTheFileStoreRules(): void
    {
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(), [$this->tmp('evil.php', '<?php system($_GET[1]);')], false, '', false), ValidationException::class);
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(), [$this->tmp('fake.png', 'this is not an image')], false, '', false), ValidationException::class);
        $this->expectThrows(fn () => TicketService::createBug($this->client, $this->bugIn(), [$this->png('1.png', 'a'), $this->png('2.png', 'b'), $this->png('3.png', 'c'), $this->png('4.png', 'd')], false, '', false), ValidationException::class);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM tickets')['c'], 'a rejected attachment leaves no ticket behind');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c']);
        $t = $this->bug([], false, [$this->png('ok.png')]);
        $f = Database::one('SELECT f.* FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.ticket_id = ?', [$t['id']]);
        $this->assertSame('ok.png', $f['original_name']);
        $this->assertNotSame('ok.png', basename($f['stored_path']), 'stored under a random name');
    }

    public function testSupportTicketsUseCategoriesAndNeverCarryAPriorityFromTheClient(): void
    {
        foreach (array_keys(Config::get('workflow.ticket_categories')) as $cat) {
            if ($cat === 'bug_report') {
                $this->expectThrows(fn () => $this->support(['category' => $cat]), ValidationException::class);
                continue;
            }
            $this->assertSame($cat, $this->support(['category' => $cat])['category']);
        }
        $this->expectThrows(fn () => $this->support(['category' => 'sales']), ValidationException::class);
        $this->expectThrows(fn () => $this->support(['project' => $this->bravo['public_id']]), ValidationException::class);
        $t = $this->support(['priority' => 'urgent', 'severity' => 'critical', 'status' => 'resolved', 'client_id' => 999, 'project' => $this->alpha['public_id']]);
        $this->assertSame('normal', $t['priority']);
        $this->assertNull($t['severity']);
        $this->assertSame('open', $t['status']);
        $this->assertSame($this->alpha['id'], (int) $t['project_id']);
        $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{4,}$/', $t['reference']);
    }

    // ------------------------------------------------------------------------------------------------------ access

    public function testClientsOnlySeeTheirOwnTickets(): void
    {
        $t = $this->bug();
        $s = $this->support();
        $this->assertSame('client', TicketAccess::role($this->client, $t));
        foreach ([$this->client2, $this->partner] as $u) {
            $this->assertNull(TicketAccess::role($u, $t));
            $this->assertNull(TicketAccess::role($u, $s));
            $this->assertSame(0, TicketQuery::paginate($u, [])['total']);
            $this->expectThrows(fn () => TicketAccess::requireView($u, $t['public_id']), HttpException::class, 404);
            $this->expectThrows(fn () => TicketService::reply($u, $t, 'let me in'), HttpException::class, 404);
            $this->expectThrows(fn () => TicketService::transition($u, $t, 'closed', 'x'), HttpException::class, 404);
        }
        $this->expectThrows(fn () => TicketAccess::requireView($this->client2, 'not-an-id'), HttpException::class, 404);
        $this->assertSame(2, TicketQuery::paginate($this->client, [])['total']);
        $other = TicketService::createSupport($this->client2, ['category' => 'billing', 'subject' => 'Bravo question', 'description' => 'A question from the other client.'], [], false);
        $this->assertSame(1, TicketQuery::paginate($this->client2, [])['total']);
        $this->assertSame(2, TicketQuery::paginate($this->client, [])['total'], 'still only their own');
        $this->assertNotNull($other['public_id']);
    }

    public function testStaffAndWorkerVisibilityFollowProjectAssignmentAndCategory(): void
    {
        $bug = $this->bug();
        $billing = $this->support(['category' => 'billing', 'project' => $this->alpha['public_id']]);
        $tech = $this->support(['category' => 'technical_support', 'project' => $this->alpha['public_id']]);
        $general = $this->support(['category' => 'general_enquiry', 'project' => '']);
        $this->assertSame('staff', TicketAccess::role($this->admin, $bug));
        $this->assertSame('staff', TicketAccess::role($this->manager, $billing), 'assigned manager');
        $this->assertNull(TicketAccess::role($this->manager2, $bug), 'unassigned manager cannot open it');
        $this->assertNull(TicketAccess::role($this->manager2, $general));
        $this->assertSame('staff', TicketAccess::role($this->admin, $general));
        $this->assertNull(TicketAccess::role($this->manager, $general), 'a ticket with no project is only for people who see every ticket or are assigned to it');
        // worker: technical categories on assigned projects only
        $this->assertSame('worker', TicketAccess::role($this->dev, $bug));
        $this->assertSame('worker', TicketAccess::role($this->dev, $tech));
        $this->assertNull(TicketAccess::role($this->dev, $billing), 'workers never see billing tickets');
        $this->assertNull(TicketAccess::role($this->dev, $general));
        $this->assertNull(TicketAccess::role($this->dev2, $bug), 'a worker on another project');
        $ids = array_column(TicketQuery::paginate($this->dev, [])['rows'], 'reference');
        $this->assertEqualsCanonicalizing([$bug['reference'], $tech['reference']], $ids);
        $this->assertSame(0, TicketQuery::paginate($this->dev2, [])['total']);
        $this->assertSame(0, TicketQuery::paginate($this->manager2, [])['total']);
        $this->assertSame(4, TicketQuery::paginate($this->admin, [])['total']);
        // assigning a ticket to an unassigned manager opens exactly that ticket
        TicketService::assign($this->admin, $general, Database::one('SELECT public_id FROM users WHERE id = ?', [$this->manager2])['public_id']);
        $this->assertSame('staff', TicketAccess::role($this->manager2, $this->fresh($general)));
        $this->assertNull(TicketAccess::role($this->manager2, $this->fresh($billing)));
    }

    public function testListScopeAndRoleAgreeForEveryUserAndTicket(): void
    {
        $ts = [$this->bug(), $this->support(['category' => 'billing']), $this->support(['category' => 'domain_hosting', 'project' => '']), $this->bug([], true)];
        foreach ([$this->admin, $this->manager, $this->manager2, $this->dev, $this->dev2, $this->support, $this->client, $this->client2, $this->partner] as $u) {
            $listed = array_column(TicketQuery::paginate($u, [], 1, 100)['rows'], 'public_id');
            $viaRole = [];
            foreach ($ts as $t) {
                if (TicketAccess::role($u, $t) !== null) {
                    $viaRole[] = $t['public_id'];
                }
            }
            $this->assertEqualsCanonicalizing($viaRole, $listed, "list and row access disagree for user {$u}");
        }
    }

    // ------------------------------------------------------------------------------------------------------ conversation & privacy

    public function testConversationAuthorsAreServerSetAndNeverCrossRoles(): void
    {
        $t = $this->bug();
        $this->assertTrue(TicketService::reply($this->client, $t, 'Here is more detail.'));
        $this->assertTrue(TicketService::reply($this->manager, $t, 'Thanks, we are looking.'));
        $rows = Database::all('SELECT author_id, author_role FROM ticket_messages ORDER BY id');
        $this->assertSame([[$this->client, 'client'], [$this->manager, 'staff']], array_map(fn ($r) => [(int) $r['author_id'], $r['author_role']], $rows));
        $this->expectThrows(fn () => TicketService::reply($this->dev, $t, 'worker reply'), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::reply($this->dev2, $t, 'x'), HttpException::class, 404);
        $this->expectThrows(fn () => TicketService::reply($this->manager2, $t, 'x'), HttpException::class, 404);
        $this->expectThrows(fn () => TicketService::reply($this->client, $t, ''), ValidationException::class);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM ticket_messages')['c']);
        $this->assertSame(1, $this->notifs($this->client, 'ticket.staff_reply'));
        $this->assertSame([1, 1, 0, 0], [$this->notifs($this->manager, 'ticket.client_reply'), $this->notifs($this->admin, 'ticket.client_reply'), $this->notifs($this->dev, 'ticket.client_reply'), $this->notifs($this->client2)]);
    }

    public function testRetriedMessageIsStoredAndNotifiedOnce(): void
    {
        $t = $this->bug();
        $tok = bin2hex(random_bytes(16));
        $this->assertTrue(TicketService::reply($this->client, $t, 'One message', [], $tok));
        $this->assertFalse(TicketService::reply($this->client, $t, 'One message', [], $tok));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM ticket_messages')['c']);
        TicketService::reply($this->manager, $t, 'Staff answer', [], $tok2 = bin2hex(random_bytes(16)));
        TicketService::reply($this->manager, $t, 'Staff answer', [], $tok2);
        $this->assertSame(1, $this->notifs($this->client, 'ticket.staff_reply'));
    }

    public function testInternalNotesNeverReachTheClientAtTheDataLayer(): void
    {
        $t = $this->bug();
        TicketService::reply($this->client, $t, 'CLIENT-VISIBLE-TEXT');
        TicketService::addNote($this->manager, $t, 'INTERNAL-SECRET-NOTE do not tell the client', 'technical_finding');
        TicketService::attachInternal($this->manager, $t, $this->png('internal-repro.png', 'zz'), false);
        TicketService::setPriority($this->manager, $t, 'urgent', 'INTERNAL-PRIORITY-REASON');
        TicketService::assign($this->admin, $t, Database::one('SELECT public_id FROM users WHERE id = ?', [$this->manager])['public_id']);
        TicketService::escalate($this->manager, $this->fresh($t), 'INTERNAL-ESCALATION-REASON');
        $t = $this->fresh($t);
        $seenByClient = json_encode([
            TicketAccess::forClient($t), TicketQuery::conversation($this->client, $t), TicketQuery::activity($this->client, $t), TicketQuery::notes($this->client, $t),
            TicketQuery::initialFiles($this->client, $t), TicketQuery::paginate($this->client, [])['rows'], TicketQuery::changes($this->client, $t), BugEligibilityService::clientView($t),
        ]);
        foreach (['INTERNAL-SECRET-NOTE', 'INTERNAL-PRIORITY-REASON', 'INTERNAL-ESCALATION-REASON', 'internal-repro.png', 'assignee', 'escalat', 'urgent', 'sup@x.test', 'mgr@x.test'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $seenByClient, "client data must not contain {$needle}");
        }
        $this->assertStringContainsString('CLIENT-VISIBLE-TEXT', $seenByClient);
        $this->assertSame([], TicketQuery::notes($this->client, $t));
        $this->assertSame([], TicketQuery::notes($this->client2, $t));
        $this->assertCount(1, TicketQuery::notes($this->manager, $t), 'staff see the notes');
        $this->assertStringContainsString('INTERNAL-SECRET-NOTE', json_encode(TicketQuery::notes($this->manager, $t)));
        $this->assertSame([], TicketQuery::initialFiles($this->client, $t), 'the internal file is not in the client file list');
        $this->assertCount(1, TicketQuery::initialFiles($this->manager, $t));
        $f = Database::one("SELECT f.public_id FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.visibility = 'internal'");
        $this->assertNull(TicketQuery::fileFor($this->client, $t, $f['public_id']), 'a client cannot fetch an internal file even with its id');
        $this->assertNotNull(TicketQuery::fileFor($this->manager, $t, $f['public_id']));
        $this->assertNull(TicketQuery::fileFor($this->client2, $t, $f['public_id']));
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND (body LIKE '%INTERNAL%' OR title LIKE '%INTERNAL%')", [$this->client])['c']);
    }

    public function testAttachmentFromAnotherTicketCannotBeFetchedThroughThisOne(): void
    {
        $a = $this->bug([], false, [$this->png('a.png', 'a')]);
        $b = $this->bug([], false, [$this->png('b.png', 'b')]);
        $fb = Database::one('SELECT f.public_id FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.ticket_id = ?', [$b['id']]);
        $this->assertNull(TicketQuery::fileFor($this->client, $a, $fb['public_id']));
        $this->assertNotNull(TicketQuery::fileFor($this->client, $b, $fb['public_id']));
        $this->assertNull(TicketQuery::fileFor($this->client, $b, 'zz'));
    }

    public function testNoNotificationBodiesLeakInternalDetailToTheClient(): void
    {
        $t = $this->bug();
        TicketService::transition($this->manager, $t, 'triaged', '', 'INTERNAL-TRIAGE-NOTE');
        TicketService::transition($this->manager, $this->fresh($t), 'need_more_information', 'Which browser did you use?', 'INTERNAL-NMI');
        $rows = Database::all('SELECT title, body FROM notifications WHERE user_id = ?', [$this->client]);
        $this->assertNotEmpty($rows);
        $this->assertStringNotContainsString('INTERNAL', json_encode($rows));
    }

    // ------------------------------------------------------------------------------------------------------ workflow

    /** @return array<string,array<string,string>> */
    private function allTransitions(string $kind): array
    {
        return Config::get($kind === 'bug' ? 'workflow.bug_transitions' : 'workflow.support_transitions');
    }

    public function testBugTransitionMatrixIsEnforcedForEveryPairAndRole(): void
    {
        $actors = ['client' => $this->client, 'manager' => $this->manager, 'support' => $this->support, 'dev' => $this->dev, 'admin' => $this->admin];
        $statuses = array_keys(Config::get('workflow.bug_statuses'));
        $t = $this->bug();
        Database::run('UPDATE tickets SET assigned_to = ? WHERE id = ?', [$this->dev, $t['id']]);
        $checked = 0;
        foreach ($statuses as $from) {
            if ($from === 'draft') {
                continue;
            }
            $cur = $this->setStatus($t, $from);
            $table = $this->allTransitions('bug')[$from] ?? [];
            foreach ($actors as $name => $uid) {
                $allowed = TicketService::allowedMoves($uid, $cur);
                foreach ($statuses as $to) {
                    if ($to === 'draft' || $to === $from) {
                        continue;
                    }
                    $letters = $table[$to] ?? null;
                    $expect = $letters !== null && $letters !== 'X' && $this->mayByLetters($name, $letters);
                    $this->assertSame($expect, array_key_exists($to, $allowed), "allowedMoves {$from}->{$to} for {$name}");
                    $cur = $this->setStatus($t, $from);
                    try {
                        TicketService::transition($uid, $cur, $to, 'A sufficiently long explanation for this move.', '');
                        $this->assertTrue($expect, "{$name} should NOT be able to move {$from}->{$to}");
                    } catch (ValidationException | HttpException $e) {
                        $this->assertFalse($expect, "{$name} should be able to move {$from}->{$to}: " . $e->getMessage());
                    }
                    $checked++;
                }
                $cur = $this->setStatus($t, $from);
            }
        }
        $this->assertGreaterThan(400, $checked);
    }

    /** Who may use a move with these letters (S staff, F fixer, K closer, C client). The dev is the assignee and holds bugs.fix only. */
    private function mayByLetters(string $actor, string $letters): bool
    {
        return match ($actor) {
            'client' => str_contains($letters, 'C'),
            'dev' => str_contains($letters, 'F'),
            'manager', 'admin' => str_contains($letters, 'S') || str_contains($letters, 'F') || str_contains($letters, 'K'),
            'support' => str_contains($letters, 'S') || str_contains($letters, 'K'),
            default => false,
        };
    }

    public function testSupportTransitionMatrix(): void
    {
        $statuses = array_keys(Config::get('workflow.support_statuses'));
        $t = $this->support(['project' => $this->alpha['public_id']]);
        foreach (['client' => $this->client, 'manager' => $this->manager, 'support' => $this->support, 'dev' => $this->dev, 'partner' => $this->partner, 'client2' => $this->client2] as $name => $uid) {
            foreach ($statuses as $from) {
                foreach ($statuses as $to) {
                    if ($to === $from) {
                        continue;
                    }
                    $cur = $this->setStatus($t, $from);
                    $letters = $this->allTransitions('support')[$from][$to] ?? null;
                    $role = TicketAccess::role($uid, $cur);
                    $expect = $letters !== null && match ($name) {
                        'client' => str_contains($letters, 'C'),
                        'manager', 'support' => str_contains($letters, 'S') || str_contains($letters, 'K'),
                        default => false,
                    };
                    try {
                        TicketService::transition($uid, $cur, $to, 'Explanation that is long enough to count.', '');
                        $this->assertTrue($expect, "{$name} must not move {$from}->{$to}");
                    } catch (ValidationException | HttpException $e) {
                        $this->assertFalse($expect, "{$name} should move {$from}->{$to} (role " . var_export($role, true) . '): ' . $e->getMessage());
                    }
                }
            }
        }
    }

    public function testClientCanNeverTriageConfirmOrMarkFixed(): void
    {
        $t = $this->bug();
        foreach (['triaged', 'confirmed', 'in_progress', 'fixed', 'client_verification', 'rejected', 'unable_to_reproduce', 'need_more_information', 'closed'] as $to) {
            $this->expectThrows(fn () => TicketService::transition($this->client, $this->fresh($t), $to, 'I want it so, honestly.'), \Throwable::class);
        }
        $this->assertSame('open', $this->fresh($t)['status']);
    }

    public function testClientConfirmsTheFixOrReopensWithAnExplanation(): void
    {
        $t = $this->bug();
        $t = $this->setStatus($t, 'client_verification');
        $this->expectThrows(fn () => TicketService::transition($this->client, $t, 'open', ''), ValidationException::class);
        $this->expectThrows(fn () => TicketService::transition($this->client, $t, 'open', 'no'), ValidationException::class);
        TicketService::transition($this->client, $t, 'open', 'It still fails on the mobile layout.');
        $r = $this->fresh($t);
        $this->assertSame('open', $r['status']);
        $this->assertSame(1, (int) $r['reopen_count']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.status_changed' AND actor_id = ?", [$this->client])['c']);
        $this->assertGreaterThanOrEqual(1, $this->notifs($this->admin, 'ticket.reopened'));
        // confirm and close
        $t2 = $this->setStatus($this->bug(), 'client_verification');
        TicketService::transition($this->client, $t2, 'closed', '');
        $c = $this->fresh($t2);
        $this->assertSame('closed', $c['status']);
        $this->assertNotNull($c['closed_at']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.closed'")['c']);
        $this->assertGreaterThanOrEqual(1, $this->notifs($this->admin, 'ticket.client_update'));
    }

    public function testReopeningAClosedTicketHonoursTheConfiguredWindow(): void
    {
        $t = $this->setStatus($this->bug(), 'closed');
        Database::run("UPDATE tickets SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE id = ?", [$t['id']]);
        TicketService::transition($this->client, $this->fresh($t), 'open', 'The problem has come back again.');
        $this->assertSame('open', $this->fresh($t)['status']);
        $t = $this->setStatus($t, 'closed');
        Database::run("UPDATE tickets SET closed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 45 DAY) WHERE id = ?", [$t['id']]);
        $this->expectThrows(fn () => TicketService::transition($this->client, $this->fresh($t), 'open', 'The problem has come back again.'), ValidationException::class);
        Config::set('support.client_reopen_days', 60);
        TicketService::transition($this->client, $this->fresh($t), 'open', 'The problem has come back again.');
        $this->assertSame('open', $this->fresh($t)['status']);
    }

    public function testNothingClosesAutomatically(): void
    {
        $t = $this->bug();
        foreach (['fixed', 'client_verification', 'resolved', 'need_more_information', 'rejected', 'unable_to_reproduce', 'in_progress'] as $s) {
            if ($s === 'resolved') {
                continue; // support status
            }
            $cur = $this->setStatus($t, $s);
            TicketService::reply($this->client, $cur, 'Just adding a screenshot.', [$this->png('more.png', $s)], '', false);
            $after = $this->fresh($t)['status'];
            $this->assertNotSame('closed', $after, "a reply while {$s} must not close the ticket");
            $this->assertNull($this->fresh($t)['closed_at']);
            $expected = $s === 'need_more_information' ? 'open' : $s;
            $this->assertSame($expected, $after);
        }
        $this->assertNull(Database::one("SELECT 1 x FROM tickets WHERE status = 'closed'"));
        $s = $this->setStatus($this->support(), 'resolved');
        TicketService::reply($this->client, $s, 'Thanks!');
        $this->assertSame('resolved', $this->fresh($s)['status'], 'a thank-you does not close it either');
        $a = $this->setStatus($this->support(), 'awaiting_client_response');
        TicketService::reply($this->client, $a, 'Here is the information.');
        $this->assertSame('open', $this->fresh($a)['status']);
    }

    public function testClosedTicketsRejectNewMessages(): void
    {
        $t = $this->setStatus($this->bug(), 'closed');
        $this->expectThrows(fn () => TicketService::reply($this->client, $t, 'late message'), ValidationException::class);
        $this->expectThrows(fn () => TicketService::reply($this->manager, $t, 'late reply'), ValidationException::class);
    }

    public function testStaleStatusIsRefusedUnderLock(): void
    {
        $t = $this->bug();
        TicketService::transition($this->manager, $t, 'triaged');
        $this->expectThrows(fn () => TicketService::transition($this->support, $t, 'confirmed'), ValidationException::class);
        $this->assertSame('triaged', $this->fresh($t)['status']);
    }

    public function testRequiredExplanationsAreEnforced(): void
    {
        $t = $this->bug();
        foreach (['rejected', 'unable_to_reproduce', 'need_more_information'] as $to) {
            $this->expectThrows(fn () => TicketService::transition($this->manager, $this->setStatus($t, 'triaged'), $to, ''), ValidationException::class);
        }
        TicketService::transition($this->manager, $this->setStatus($t, 'triaged'), 'rejected', 'This is a request for a new feature, not a defect.');
        $this->assertSame('rejected', $this->fresh($t)['status']);
        $m = Database::one("SELECT body, author_role FROM ticket_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 1", [$t['id']]);
        $this->assertSame('staff', $m['author_role']);
        $this->assertStringContainsString('new feature', $m['body'], 'the client sees the explanation');
    }

    public function testStaffMovesNeedTheMatchingPermission(): void
    {
        $t = $this->bug();
        $this->expectThrows(fn () => TicketService::transition($this->manager2, $t, 'triaged'), HttpException::class, 404);
        $custom = $this->makeUser('plain@x.test', ['website_developer']);
        \Vertex\Services\ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $custom, 'member');
        $this->assertSame('worker', TicketAccess::role($custom, $t));
        $this->expectThrows(fn () => TicketService::transition($custom, $t, 'triaged'), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::transition($custom, $t, 'closed', 'closing'), HttpException::class, 403);
        // a developer can only work a bug assigned to them
        $c = $this->setStatus($t, 'confirmed');
        $this->expectThrows(fn () => TicketService::transition($this->dev, $c, 'in_progress'), HttpException::class, 403);
        Database::run('UPDATE tickets SET assigned_to = ? WHERE id = ?', [$this->dev, $t['id']]);
        TicketService::transition($this->dev, $this->fresh($t), 'in_progress');
        $this->assertSame('in_progress', $this->fresh($t)['status']);
        TicketService::transition($this->dev, $this->fresh($t), 'fixed', 'Fixed in release 1.2');
        $this->assertSame('fixed', $this->fresh($t)['status']);
        $this->expectThrows(fn () => TicketService::transition($this->dev, $this->fresh($t), 'closed', 'closing it myself'), HttpException::class, 403);
    }

    public function testArchivedProjectsFreezeStatusChanges(): void
    {
        $t = $this->bug();
        Database::run('UPDATE projects SET archived_at = UTC_TIMESTAMP() WHERE id = ?', [$this->alpha['id']]);
        $this->assertSame([], TicketService::allowedMoves($this->manager, $this->fresh($t)));
    }

    // ------------------------------------------------------------------------------------------------------ severity / priority / assignment

    public function testSeverityAndPriorityAreSeparateAndChangesNeedAReason(): void
    {
        $t = $this->bug(['severity' => 'low']);
        $this->assertSame('low', $t['severity']);
        $this->expectThrows(fn () => TicketService::setSeverity($this->manager, $t, 'critical', ''), ValidationException::class);
        TicketService::setSeverity($this->manager, $t, 'critical', 'Checkout is down for every customer.');
        $r = $this->fresh($t);
        $this->assertSame('critical', $r['severity']);
        $this->assertSame('normal', $r['priority'], 'changing severity does not change priority');
        TicketService::setPriority($this->manager, $r, 'urgent', 'Release freeze tomorrow.');
        $r = $this->fresh($t);
        $this->assertSame(['critical', 'urgent'], [$r['severity'], $r['priority']]);
        $this->expectThrows(fn () => TicketService::setPriority($this->manager, $r, 'high', ''), ValidationException::class);
        $this->expectThrows(fn () => TicketService::setSeverity($this->client, $r, 'low', 'I decided'), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::setPriority($this->client, $r, 'low', 'I decided'), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::setSeverity($this->dev, $r, 'low', 'worker changing it'), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::setSeverity($this->manager, $this->support(), 'low', 'Not a bug'), ValidationException::class);
        $this->expectThrows(fn () => TicketService::setSeverity($this->manager, $r, 'catastrophic', 'bad value'), ValidationException::class);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.severity_changed'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.priority_changed'")['c']);
        $this->assertStringContainsString('Checkout is down', json_encode(TicketQuery::notes($this->manager, $r)), 'the reason is kept on the internal record');
        $client = json_encode(TicketQuery::activity($this->client, $r));
        $this->assertStringContainsString('critical', strtolower($client), 'the client is told the severity changed');
        $this->assertStringNotContainsString('Release freeze', $client);
    }

    public function testNoResponseTimeIsPromisedOrShownWhenNoTargetIsConfigured(): void
    {
        $t = $this->bug();
        $d = TicketQuery::dashboard($this->admin);
        $this->assertNull($d['overdue'], 'no SLA configured');
        $this->assertSame(0, TicketQuery::paginate($this->admin, ['group' => 'overdue'])['total']);
        Config::set('support.sla', ['first_response_hours' => 4]);
        Database::run('UPDATE tickets SET submitted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 HOUR) WHERE id = ?', [$t['id']]);
        $this->assertSame(1, TicketQuery::dashboard($this->admin)['overdue']);
        TicketService::reply($this->manager, $t, 'We have received this.');
        $this->assertSame(0, TicketQuery::dashboard($this->admin)['overdue'], 'answered tickets are no longer past the target');
    }

    public function testAssignmentIsLimitedToPeopleWhoCanWorkTheTicketAndNotifiesOnce(): void
    {
        $t = $this->support(['project' => $this->alpha['public_id']]);
        $mgr = Database::one('SELECT public_id FROM users WHERE id = ?', [$this->manager])['public_id'];
        TicketService::assign($this->admin, $t, $mgr);
        $r = $this->fresh($t);
        $this->assertSame($this->manager, (int) $r['assigned_to']);
        $this->assertSame('assigned', $r['status']);
        TicketService::assign($this->admin, $r, $mgr);
        $this->assertSame(1, $this->notifs($this->manager, 'ticket.assigned'), 'same assignee twice, one notification');
        foreach ([$this->client, $this->client2, $this->partner, $this->dev2] as $bad) {
            $pub = Database::one('SELECT public_id FROM users WHERE id = ?', [$bad])['public_id'];
            $this->expectThrows(fn () => TicketService::assign($this->admin, $this->fresh($t), $pub), ValidationException::class);
        }
        $this->expectThrows(fn () => TicketService::assign($this->support, $this->fresh($t), $mgr), HttpException::class, 403);
        $this->expectThrows(fn () => TicketService::assign($this->admin, $this->fresh($t), 'zzzz'), ValidationException::class);
        TicketService::assign($this->admin, $this->fresh($t), null);
        $this->assertSame('open', $this->fresh($t)['status']);
        $this->assertNull($this->fresh($t)['assigned_to']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action IN ('ticket.assigned','ticket.unassigned')")['c']);
    }

    public function testEscalationIsStaffOnlyOnceAndNotifiesAdmins(): void
    {
        $t = $this->bug();
        $this->expectThrows(fn () => TicketService::escalate($this->client, $t, 'Please hurry'), HttpException::class, 403);
        TicketService::escalate($this->manager, $t, 'Customer is losing orders.');
        $this->assertNotNull($this->fresh($t)['escalated_at']);
        $this->expectThrows(fn () => TicketService::escalate($this->manager, $this->fresh($t), 'Again please'), ValidationException::class);
        $this->assertSame(1, $this->notifs($this->admin, 'ticket.escalated'));
        $this->assertArrayNotHasKey('escalated_at', TicketAccess::forClient($this->fresh($t)));
    }

    // ------------------------------------------------------------------------------------------------------ warranty review

    private function decide(int $user, array $t, array $over = []): void
    {
        BugEligibilityService::decide($user, $t, $over + ['decision' => 'covered', 'reason' => 'This is a defect in work we delivered, so it is covered.', 'terms' => 'Warranty terms v1', 'considerations' => []]);
    }

    public function testReviewerRecordsDecisionReasonTermsAndTimestamp(): void
    {
        $this->activeWarranty();
        $t = $this->bug();
        $this->assertSame('awaiting_technical_review', $t['eligibility']);
        $this->decide($this->manager, $t, ['considerations' => ['third_party', 'hosting_domain_api'], 'internal_note' => 'Looks like our code.']);
        $r = $this->fresh($t);
        $this->assertSame('covered', $r['eligibility']);
        $this->assertSame($this->manager, (int) $r['eligibility_reviewer_id']);
        $this->assertNotNull($r['eligibility_reviewed_at']);
        $this->assertSame('Warranty terms v1', $r['eligibility_terms']);
        $this->assertSame(['third_party', 'hosting_domain_api'], json_decode($r['considerations_json'], true));
        $this->assertSame(0, (int) $r['quotation_required']);
        $this->assertSame(0, (int) $r['eligibility_overridden']);
        $this->assertSame('open', $r['status'], 'considerations exclude nothing and the status is untouched');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.eligibility_decided'")['c']);
        $this->assertSame(1, $this->notifs($this->client, 'ticket.eligibility'));
        $view = BugEligibilityService::clientView($r);
        $this->assertTrue($view['decided']);
        $this->assertStringContainsString('defect in work', $view['reason']);
        $this->assertStringNotContainsString('third', json_encode($view));
        $this->assertStringNotContainsString('Looks like our code', json_encode([$view, TicketQuery::activity($this->client, $r)]));
    }

    public function testConsiderationsAreNeverAutomaticExclusions(): void
    {
        $this->activeWarranty();
        $t = $this->bug();
        $all = array_keys(Config::get('workflow.bug_considerations'));
        $this->assertCount(6, $all);
        $this->decide($this->manager, $t, ['considerations' => $all]);
        $this->assertSame('covered', $this->fresh($t)['eligibility'], 'all considerations ticked, still covered because a person decided so');
        $this->expectThrows(fn () => $this->decide($this->manager, $this->bug(), ['considerations' => ['made_up']]), ValidationException::class);
    }

    public function testDecisionNeedsAReasonAndAKnownOutcome(): void
    {
        $t = $this->bug();
        $this->expectThrows(fn () => $this->decide($this->manager, $t, ['reason' => '']), ValidationException::class);
        $this->expectThrows(fn () => $this->decide($this->manager, $t, ['reason' => 'short']), ValidationException::class);
        $this->expectThrows(fn () => $this->decide($this->manager, $t, ['decision' => 'awaiting_technical_review']), ValidationException::class);
        $this->expectThrows(fn () => $this->decide($this->manager, $t, ['decision' => 'rejected']), ValidationException::class);
        $this->assertSame('submitted_for_assessment', $this->fresh($t)['eligibility']);
    }

    public function testCoveringAnIssueTheWarrantyDidNotCoverIsAnAdministratorOverride(): void
    {
        $t = $this->bug(); // no recorded warranty -> submitted for assessment
        $this->expectThrows(fn () => $this->decide($this->manager, $t), ValidationException::class);
        $this->assertSame('submitted_for_assessment', $this->fresh($t)['eligibility']);
        // not covered and chargeable are ordinary decisions for the reviewer
        $this->decide($this->manager, $t, ['decision' => 'not_covered', 'reason' => 'The warranty period had ended when this was reported.']);
        $this->assertSame('not_covered', $this->fresh($t)['eligibility']);
        // changing an earlier decision is an override: administrators only
        $this->expectThrows(fn () => $this->decide($this->manager, $this->fresh($t), ['decision' => 'potentially_chargeable', 'reason' => 'On reflection, quote it.']), ValidationException::class);
        $this->decide($this->admin, $this->fresh($t), ['decision' => 'covered', 'reason' => 'Goodwill: we will fix this as part of the warranty.']);
        $r = $this->fresh($t);
        $this->assertSame('covered', $r['eligibility']);
        $this->assertSame(1, (int) $r['eligibility_overridden']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.eligibility_overridden'")['c']);
        $a = Database::one("SELECT before_json, after_json FROM audit_logs WHERE action = 'ticket.eligibility_overridden'");
        $this->assertStringContainsString('not_covered', $a['before_json']);
        $this->assertStringContainsString('covered', $a['after_json']);
    }

    public function testDatesConfirmedLaterCanCoverAnEarlierReportWithoutAnOverride(): void
    {
        $t = $this->bug(); // reported while the start date was unconfirmed
        $this->assertSame('submitted_for_assessment', $t['eligibility']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-3), 'start_basis' => 'client_acceptance']);
        $this->decide($this->manager, $this->fresh($t));
        $r = $this->fresh($t);
        $this->assertSame(['covered', 0], [$r['eligibility'], (int) $r['eligibility_overridden']], 'the recorded period includes the day it was reported');
        // a report that pre-dates the warranty start is still outside the period: override needed
        $u = $this->bug(['title' => 'Reported before the start']);
        Database::run("UPDATE tickets SET submitted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE id = ?", [$u['id']]);
        Database::run("UPDATE bug_reports SET warranty_snapshot_json = '{\"status\":\"pending_confirmation\"}' WHERE ticket_id = ?", [$u['id']]);
        $this->expectThrows(fn () => $this->decide($this->manager, $this->fresh($u)), ValidationException::class);
        // a suspended warranty covers nothing without an override
        WarrantyService::setSuspended($this->admin, $this->alphaRow(), true, 'Unpaid balance per agreement');
        $v = $this->bug(['title' => 'Reported while suspended']);
        Database::run("UPDATE bug_reports SET warranty_snapshot_json = '{\"status\":\"suspended\"}' WHERE ticket_id = ?", [$v['id']]);
        $this->expectThrows(fn () => $this->decide($this->manager, $this->fresh($v)), ValidationException::class);
    }

    public function testOnlyAuthorisedStaffMayReviewEligibility(): void
    {
        $t = $this->bug();
        $this->expectThrows(fn () => $this->decide($this->client, $t, ['decision' => 'covered']), HttpException::class);
        $this->expectThrows(fn () => $this->decide($this->dev, $t, ['decision' => 'not_covered']), HttpException::class, 403);
        $this->expectThrows(fn () => $this->decide($this->support, $t, ['decision' => 'not_covered']), HttpException::class, 403);
        $this->expectThrows(fn () => $this->decide($this->manager2, $t, ['decision' => 'not_covered']), HttpException::class);
        $this->expectThrows(fn () => $this->decide($this->manager, $this->support(), ['decision' => 'not_covered']), HttpException::class);
        $this->assertSame('submitted_for_assessment', $this->fresh($t)['eligibility']);
    }

    // ------------------------------------------------------------------------------------------------------ chargeable

    /** @return array<string,mixed> */
    private function chargeableBug(): array
    {
        $t = $this->bug();
        $this->decide($this->manager, $t, ['decision' => 'potentially_chargeable', 'reason' => 'This looks like a change to the agreed scope, so it may be chargeable.']);

        return $this->fresh($t);
    }

    /** @return array<string,mixed> */
    private function proposal(array $t): array
    {
        $pub = ChangeRequestService::createDraftForTicket($this->manager, $t, ChangeRequestService::validateProposal(['scope_description' => 'Rebuild the contact form as a multi-step form', 'client_reason' => 'The agreed scope contains a single-step form', 'amount' => '4500', 'days' => '3']));

        return RevisionAccess::findChange($pub);
    }

    public function testPotentiallyChargeableCreatesAProposalButChargesNothing(): void
    {
        $price = Database::all('SELECT * FROM price_revisions');
        $project = Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]);
        $t = $this->chargeableBug();
        $this->assertSame(1, (int) $t['quotation_required']);
        $c = $this->proposal($t);
        $this->assertSame('draft', $c['status']);
        $this->assertSame($t['reference'], $c['ticket_reference']);
        $this->assertNull($c['revision_request_id']);
        $this->assertSame(450000, (int) $c['proposed_amount_minor']);
        $this->assertNull($c['quotation_ref'], 'the quotation module does not exist, so no reference is invented');
        $this->assertSame($price, Database::all('SELECT * FROM price_revisions'));
        $this->assertSame($project, Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), 'the approved project price and scope are untouched');
        $this->assertSame('staff', RevisionAccess::changeRole($this->manager, $c));
        $this->assertNull(RevisionAccess::changeRole($this->client, $c), 'clients never see a draft');
        // only one open proposal per issue
        $this->expectThrows(fn () => $this->proposal($t), ValidationException::class);
        // the proposal needs the potentially-chargeable decision
        $this->expectThrows(fn () => $this->proposal($this->bug()), ValidationException::class);
    }

    public function testClientApprovalGatesChargeableWork(): void
    {
        $t = $this->chargeableBug();
        $t = $this->setStatus($t, 'confirmed');
        $this->expectThrows(fn () => TicketService::transition($this->manager, $t, 'in_progress'), ValidationException::class);
        $c = $this->proposal($t);
        $this->expectThrows(fn () => TicketService::transition($this->manager, $this->fresh($t), 'in_progress'), ValidationException::class);
        ChangeRequestService::send($this->manager, $c);
        $c = RevisionAccess::findChange($c['public_id']);
        $this->assertSame('pending_client', $c['status']);
        $this->assertSame('client', RevisionAccess::changeRole($this->client, $c));
        $this->assertNull(RevisionAccess::changeRole($this->client2, $c));
        $this->assertSame(1, $this->notifs($this->client, 'change_request.awaiting_approval'));
        // staff cannot decide for the client; another client cannot either
        foreach ([$this->manager, $this->admin, $this->client2] as $u) {
            $this->expectThrows(fn () => ChangeRequestService::clientDecide($u, $c, 'approve', ''), HttpException::class);
        }
        $this->expectThrows(fn () => TicketService::transition($this->manager, $this->fresh($t), 'in_progress'), ValidationException::class);
        // a decline keeps the work blocked
        ChangeRequestService::clientDecide($this->client, $c, 'reject', 'Too expensive');
        $this->expectThrows(fn () => TicketService::transition($this->manager, $this->fresh($t), 'in_progress'), ValidationException::class);
        $this->expectThrows(fn () => ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($c['public_id']), 'approve', ''), ValidationException::class, null);
        // a new proposal, approved by the client, releases the work
        $c2 = $this->proposal($this->fresh($t));
        ChangeRequestService::send($this->manager, $c2);
        ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($c2['public_id']), 'approve', 'Go ahead');
        $this->assertSame('approved', Database::one('SELECT status FROM change_requests WHERE id = ?', [$c2['id']])['status']);
        TicketService::transition($this->manager, $this->fresh($t), 'in_progress');
        $this->assertSame('in_progress', $this->fresh($t)['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'change_request.client_approved'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'change_request.client_declined'")['c']);
        $this->assertGreaterThanOrEqual(1, $this->notifs($this->admin, 'change_request.client_decision') + $this->notifs($this->manager, 'change_request.client_decision'));
        $this->assertSame([], Database::all('SELECT * FROM price_revisions'), 'approval records consent only; no price or invoice is created');
        $this->assertTrue(Audit::verify()['ok']);
    }

    public function testOnlyThePermittedStaffMayProposeAndSendAndWithdraw(): void
    {
        $t = $this->chargeableBug();
        foreach ([$this->client, $this->dev, $this->support] as $u) {
            $this->expectThrows(fn () => ChangeRequestService::createDraftForTicket($u, $t, ChangeRequestService::validateProposal(['scope_description' => 'Some extra scope text', 'client_reason' => 'Some reason for the client', 'amount' => '100'])), HttpException::class);
        }
        $c = $this->proposal($t);
        foreach ([$this->client, $this->dev] as $u) {
            $this->expectThrows(fn () => ChangeRequestService::send($u, $c), HttpException::class);
            $this->expectThrows(fn () => ChangeRequestService::withdraw($u, $c), HttpException::class);
        }
        ChangeRequestService::send($this->manager, $c);
        ChangeRequestService::withdraw($this->manager, RevisionAccess::findChange($c['public_id']));
        $this->assertSame('withdrawn', Database::one('SELECT status FROM change_requests WHERE id = ?', [$c['id']])['status']);
        $client = json_encode(TicketQuery::changes($this->client, $this->fresh($t)));
        $this->assertStringContainsString($c['reference'], $client, 'a withdrawn proposal that was sent stays visible to the client');
        $this->assertSame([], TicketQuery::changes($this->dev, $this->fresh($t)), 'workers do not see financial detail');
    }

    public function testChangingTheDecisionIsBlockedWhileAProposalIsLive(): void
    {
        $t = $this->chargeableBug();
        $c = $this->proposal($t);
        ChangeRequestService::send($this->manager, $c);
        $this->expectThrows(fn () => $this->decide($this->admin, $this->fresh($t), ['decision' => 'covered', 'reason' => 'Changed our mind, cover it.']), ValidationException::class);
        ChangeRequestService::withdraw($this->manager, RevisionAccess::findChange($c['public_id']));
        $this->decide($this->admin, $this->fresh($t), ['decision' => 'covered', 'reason' => 'Changed our mind, cover it.']);
        $this->assertSame('covered', $this->fresh($t)['eligibility']);
    }

    // ------------------------------------------------------------------------------------------------------ support queue, filters, reads

    public function testQueueFiltersSearchDatesAndPagination(): void
    {
        $this->activeWarranty();
        $bug = $this->bug(['title' => 'Checkout button missing', 'severity' => 'critical']);
        $bill = $this->support(['category' => 'billing', 'subject' => 'Invoice query']);
        $dom = $this->support(['category' => 'domain_hosting', 'subject' => 'Domain renewal', 'project' => '']);
        Database::run("UPDATE tickets SET created_at = '2026-01-10 20:00:00' WHERE id = ?", [$bill['id']]);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['q' => 'Checkout'])['total']);
        $this->assertGreaterThanOrEqual(1, TicketQuery::paginate($this->admin, ['q' => 'ALPHA'])['total']);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['kind' => 'bug'])['total']);
        $this->assertSame(2, TicketQuery::paginate($this->admin, ['kind' => 'support'])['total']);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['category' => 'billing'])['total']);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['severity' => 'critical'])['total']);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['group' => 'high_critical'])['total']);
        $this->assertSame(3, TicketQuery::paginate($this->admin, ['group' => 'unassigned'])['total']);
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['status' => 'open', 'kind' => 'bug'])['total']);
        // 10 Jan 20:00 UTC is 11 Jan 01:30 in Kolkata
        $this->assertSame(1, TicketQuery::paginate($this->admin, ['from' => '2026-01-11', 'to' => '2026-01-11'])['total']);
        $this->assertSame(0, TicketQuery::paginate($this->admin, ['from' => '2026-01-10', 'to' => '2026-01-10'])['total']);
        // injection-shaped and junk filters are inert
        $this->assertSame(3, TicketQuery::paginate($this->admin, ['status' => "open' OR '1'='1", 'category' => 'x', 'kind' => 'z', 'group' => "'; DROP TABLE tickets;--", 'from' => 'garbage'])['total']);
        $this->assertSame(0, TicketQuery::paginate($this->admin, ['q' => "%' OR 1=1 --"])['total']);
        $this->assertSame(0, TicketQuery::paginate($this->admin, ['q' => '%'])['total'], 'LIKE wildcards are escaped');
        for ($i = 0; $i < 5; $i++) {
            $this->support(['subject' => "Bulk ticket {$i}"]);
        }
        $p1 = TicketQuery::paginate($this->admin, [], 1, 3);
        $p2 = TicketQuery::paginate($this->admin, [], 2, 3);
        $this->assertSame(8, $p1['total']);
        $this->assertCount(3, $p1['rows']);
        $this->assertCount(3, $p2['rows']);
        $this->assertEmpty(array_intersect(array_column($p1['rows'], 'public_id'), array_column($p2['rows'], 'public_id')));
        $this->assertNotNull($dom);
        $this->assertNotNull($bug);
    }

    public function testDashboardCountsAndAwaitingGroups(): void
    {
        $a = $this->bug();
        $b = $this->bug(['title' => 'Second bug here']);
        $c = $this->support();
        $d = $this->support(['subject' => 'Another one']);
        $this->setStatus($b, 'need_more_information');
        $this->setStatus($c, 'awaiting_internal_team');
        $this->setStatus($d, 'resolved');
        $e = $this->setStatus($this->bug(['title' => 'Third bug here']), 'client_verification');
        $dash = TicketQuery::dashboard($this->admin);
        $this->assertEquals(['open' => 4, 'unassigned' => 4, 'awaiting_client' => 1, 'awaiting_internal' => 1, 'fixed_verification' => 1, 'high_critical' => 3], array_intersect_key($dash, array_flip(['open', 'unassigned', 'awaiting_client', 'awaiting_internal', 'fixed_verification', 'high_critical'])));
        $this->assertSame(0, TicketQuery::dashboard($this->manager2)['open']);
        $this->assertNotNull($a);
        $this->assertNotNull($e);
    }

    public function testUnreadIndicatorsFollowTheOtherSide(): void
    {
        $t = $this->bug();
        TicketService::reply($this->manager, $t, 'Hello from the team');
        $this->assertSame(1, TicketQuery::unreadForClient($this->client));
        $this->assertSame(1, TicketQuery::paginate($this->client, [])['rows'][0]['unread']);
        TicketService::markRead($this->client, $t);
        $this->assertSame(0, TicketQuery::unreadForClient($this->client));
        TicketService::reply($this->client, $t, 'Client says hi');
        $this->assertSame(1, TicketQuery::paginate($this->admin, [])['rows'][0]['unread'], 'staff see that the client replied');
        $this->assertSame(0, TicketQuery::unreadForClient($this->client2));
    }

    public function testClientNeverReceivesStaffIdentitiesInTheThread(): void
    {
        $t = $this->bug();
        TicketService::reply($this->manager, $t, 'A reply from the manager');
        $c = TicketQuery::conversation($this->client, $t);
        $this->assertSame('Vertex support team', $c[0]['author_name']);
        $s = TicketQuery::conversation($this->admin, $t);
        $this->assertStringContainsString('mgr@x.test', $s[0]['author_name']);
        $act = json_encode(TicketQuery::activity($this->client, $this->fresh($t)));
        $this->assertStringNotContainsString('mgr@x.test', $act);
    }

    public function testEverythingWritesAnAuditEntryAndTheChainStaysValid(): void
    {
        $t = $this->bug();
        TicketService::reply($this->client, $t, 'x y z');
        TicketService::addNote($this->manager, $t, 'note body');
        TicketService::transition($this->manager, $t, 'triaged');
        TicketService::setSeverity($this->manager, $this->fresh($t), 'low', 'Cosmetic only really.');
        $actions = array_column(Database::all("SELECT action FROM audit_logs WHERE action LIKE 'ticket.%' ORDER BY id"), 'action');
        $this->assertSame(['ticket.created', 'ticket.client_reply', 'ticket.internal_note', 'ticket.status_changed', 'ticket.severity_changed'], $actions);
        $this->assertTrue(Audit::verify()['ok']);
        $this->assertStringNotContainsString('note body', json_encode(Database::all("SELECT before_json, after_json, meta_json FROM audit_logs WHERE action = 'ticket.internal_note'")), 'audit stores sizes and ids, not the note text');
    }

    public function testExistingGatesAreNotWeakened(): void
    {
        $this->assertFalse(Gate::can('tickets.view_own', $this->admin), 'administrators do not act as clients');
        $this->assertFalse(Gate::can('bugs.report', $this->admin));
        $this->assertFalse(Gate::can('tickets.view_all', $this->client));
        $this->assertFalse(Gate::can('tickets.view_all', $this->partner));
        $this->assertFalse(Gate::can('tickets.view_technical', $this->client));
        $this->assertFalse(Gate::can('warranty.manage', $this->dev));
        $this->assertFalse(Gate::can('warranty.override', $this->manager));
        $this->assertTrue(Gate::can('warranty.override', $this->admin));
    }
}
