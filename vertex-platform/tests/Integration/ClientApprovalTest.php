<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ClientApprovalService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableService;

/** The client's own, per-version approval record. */
final class ClientApprovalTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
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

    private function approvals(): int
    {
        return (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c'];
    }

    public function testClientApprovesTheExactPublishedVersionAndItIsRecorded(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        $this->assertSame('awaiting', ClientApprovalService::stateFor($this->freshD($d))['key'], 'Ready for client review');
        $this->assertSame(0, $this->approvals(), 'internal approval and publication are not client acceptance');
        $this->assertTrue(ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', 'Love it', str_repeat('a', 32)));
        $row = Database::one('SELECT * FROM client_approvals');
        $this->assertSame([$this->client, (int) $v1['id'], (int) $d['id'], (int) $this->alpha['id'], 'approved', 'Love it'], [(int) $row['user_id'], (int) $row['version_id'], (int) $row['deliverable_id'], (int) $row['project_id'], $row['action'], $row['feedback']]);
        $state = ClientApprovalService::stateFor($this->freshD($d));
        $this->assertSame(['approved', 'Approved'], [$state['key'], $state['label']]);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'deliverable.client_approved' AND actor_id = " . $this->client)['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM project_activity WHERE event_type = 'deliverable.client_approved' AND visibility = 'client'")['c']);
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', '', ''), 'already approved');
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'rejected', 'changed my mind', ''), 'already approved');
        $this->assertSame(1, $this->approvals());
    }

    public function testApprovalOfOneVersionNeverCarriesToANewerOrOlderOne(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', '', '');
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        $v2 = $this->publishVersion($d, 2);
        $s = ClientApprovalService::stateFor($this->freshD($d));
        $this->assertSame('awaiting', $s['key'], 'V1 approval does not approve V2');
        $this->assertSame((int) $v2['id'], (int) $s['version']['id']);
        // the client is still looking at the V1 page: the stale version id is refused
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', '', ''), 'no longer the current');
        $this->assertSame(1, $this->approvals(), 'nothing recorded for the stale version');
        ClientApprovalService::respond($this->client, $this->freshD($d), $v2['public_id'], 'approved', 'V2 is good', '');
        $this->assertSame([(int) $v1['id'], (int) $v2['id']], array_map('intval', array_column(Database::all('SELECT version_id FROM client_approvals ORDER BY id'), 'version_id')));
        // V3 exists as a draft: unpublished work cannot be answered
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v3.png', '3'), false);
        $v3 = $this->ver($d, 3);
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v3['public_id'], 'approved', '', ''), 'no longer the current');
        // rolling the live version back to V1 shows V1's own (earlier) approval, V2 is not inherited
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        $this->assertSame('approved', ClientApprovalService::stateFor($this->freshD($d))['key']);
        $this->assertSame((int) $this->ver($d, 1)['id'], (int) ClientApprovalService::stateFor($this->freshD($d))['latest']['version_id']);
    }

    public function testOnlyTheOwningClientCanRespond(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        foreach ([[$this->admin, 'administrators cannot accept on the client\'s behalf'], [$this->manager, 'managers'], [$this->support, 'support'], [$this->dev, 'workers'],
            [$this->client2, 'another client'], [$this->partner, 'referral partners']] as [$u, $why]) {
            $this->expect403(fn () => ClientApprovalService::respond($u, $this->freshD($d), $v1['public_id'], 'approved', '', ''), $why);
        }
        $this->assertSame(0, $this->approvals());
    }

    public function testRejectionNeedsAReasonAndRevisionThinkingIsSeparate(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'rejected', '', ''), 'Reason is required');
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'rejected', 'no', ''), 'Reason');
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'maybe', '', ''), 'approve or reject');
        ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'rejected', 'The colours are wrong for our brand', '');
        $s = ClientApprovalService::stateFor($this->freshD($d));
        $this->assertSame('rejected', $s['key']);
        $this->assertSame('The colours are wrong for our brand', $s['latest']['feedback']);
        // after a rejection they may still approve the same version later
        ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', '', '');
        $this->assertSame('approved', ClientApprovalService::stateFor($this->freshD($d))['key']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'deliverable.client_rejected'")['c']);
    }

    public function testRetriedSubmissionsAreIdempotent(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        $token = bin2hex(random_bytes(16));
        $this->assertTrue(ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', 'ok', $token));
        $this->assertFalse(ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'approved', 'ok', $token), 'the same form sent twice is a no-op');
        $this->assertSame(1, $this->approvals());
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'deliverable.client_approved'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'deliverable.client_response' AND user_id = " . $this->admin)['c'], 'one notification per staff member, not two');
    }

    public function testUnpublishedArchivedAndClosedDeliverablesCannotBeAnswered(): void
    {
        $draft = $this->makeDeliverable(['title' => 'Draft one']);
        $this->expect403(fn () => ClientApprovalService::respond($this->client, $this->freshD($draft), $this->ver($draft, 1)['public_id'], 'approved', '', ''), 'a client cannot even see a draft');
        $d = $this->makeDeliverable(['title' => 'Live one']);
        $v = $this->publishVersion($d, 1);
        DeliverableService::withdraw($this->admin, $this->freshD($d), $v, 'Published by mistake');
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($d), $v['public_id'], 'approved', '', ''), 'no longer the current');
        $e = $this->makeDeliverable(['title' => 'To archive']);
        $ev = $this->publishVersion($e, 1);
        DeliverableService::setArchived($this->admin, $this->freshD($e), true);
        $this->expectInvalid(fn () => ClientApprovalService::respond($this->client, $this->freshD($e), $ev['public_id'], 'approved', '', ''), 'no longer open');
        $this->assertSame(0, $this->approvals());
    }

    public function testHistoryIsPerClientAndPreservedAcrossVersions(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        ClientApprovalService::respond($this->client, $this->freshD($d), $v1['public_id'], 'rejected', 'First reaction', '');
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        $v2 = $this->publishVersion($d, 2);
        ClientApprovalService::respond($this->client, $this->freshD($d), $v2['public_id'], 'approved', 'Better', '');
        $own = ClientApprovalService::history($this->client, $this->freshD($d));
        $this->assertSame([[2, 'approved'], [1, 'rejected']], array_map(static fn (array $h): array => [(int) $h['version_no'], $h['action']], $own));
        $this->assertSame(2, count(ClientApprovalService::history($this->manager, $this->freshD($d))));
        $this->assertSame([], ClientApprovalService::history($this->client2, $this->freshD($d)), 'another client sees nothing');
        $this->assertSame([], ClientApprovalService::history($this->partner, $this->freshD($d)));
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c'], 'append-only: earlier answers are never overwritten');
        $this->assertFalse(DeliverablePolicy::canView($this->client2, $this->freshD($d)));
    }

    public function testDownloadingOrViewingRecordsNoApproval(): void
    {
        $d = $this->makeDeliverable();
        $v = $this->publishVersion($d, 1);
        $this->assertTrue(DeliverablePolicy::canDownload($this->client, $this->freshD($d), $v));
        $this->assertTrue(DeliverablePolicy::canView($this->client, $this->freshD($d)));
        $this->assertSame(0, $this->approvals());
        $this->assertSame('awaiting', ClientApprovalService::stateFor($this->freshD($d))['key']);
    }
}
