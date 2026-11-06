<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\DeliverableService;
use Vertex\Services\Gate;
use Vertex\Services\TaskPolicy;
use Vertex\Services\TaskService;

/**
 * The "second person" rules protect the business from its staff. The Owner (Super Admin) is exempt: they may approve or verify what they recorded,
 * uploaded or did themselves, and the audit trail still names them. Everyone else stays bound.
 */
final class OwnerApprovalTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

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

    public function testOnlyTheOwnerIsExempt(): void
    {
        $this->assertTrue(Gate::canSelfApprove($this->owner));
        foreach ([$this->admin, $this->manager, $this->finance, $this->support, $this->dev, $this->client, $this->partner] as $u) {
            $this->assertFalse(Gate::canSelfApprove($u));
        }
    }

    public function testTheOwnerCanVerifyAPaymentTheyRecordedButAnAdministratorStillCannot(): void
    {
        $in = static fn (string $ref): array => ['project' => '', 'role' => 'advance', 'gross' => '1000', 'tax' => '0', 'method' => 'upi', 'external_ref' => $ref, 'received_on' => AppTime::today()];
        $mine = ClientPaymentService::record($this->owner, ['project' => $this->alpha['public_id']] + $in('OWNER-SELF-1'));
        ClientPaymentService::verify($this->owner, ClientPaymentService::find($mine['public_id']));
        $row = ClientPaymentService::find($mine['public_id']);
        $this->assertSame('verified', $row['status']);
        $this->assertSame([$this->owner, $this->owner], [(int) $row['recorded_by'], (int) $row['verified_by']], 'the record shows the Owner did both');
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'client_payment.verified' AND actor_id = ?", [$this->owner]));

        $theirs = ClientPaymentService::record($this->admin, ['project' => $this->alpha['public_id']] + $in('ADMIN-SELF-1'));
        try {
            ClientPaymentService::verify($this->admin, ClientPaymentService::find($theirs['public_id']));
            $this->fail('An administrator must not verify their own payment.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot verify', implode(' ', $e->errors));
        }
    }

    public function testTheOwnerCanApproveTheirOwnDeliverableUpload(): void
    {
        $d = $this->makeDeliverable([], $this->owner);
        DeliverableService::submitForReview($this->owner, $d, $this->ver($d));
        DeliverableService::review($this->owner, $this->freshD($d), $this->ver($d), 'approved', 'Looks right to me.');
        $this->assertNotSame('pending_review', $this->ver($d)['status'], 'the Owner approved their own upload');
        // an administrator who uploaded still cannot
        $e = $this->makeDeliverable(['title' => 'Second'], $this->admin);
        DeliverableService::submitForReview($this->admin, $e, $this->ver($e));
        $this->expectException(ValidationException::class);
        DeliverableService::review($this->admin, $this->freshD($e), $this->ver($e), 'approved');
    }

    public function testTheOwnerCanCompleteATaskTheyWereAssignedToReview(): void
    {
        \Vertex\Services\ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $this->owner, 'member');
        $t = TaskService::create($this->owner, $this->alphaRow(), ['title' => 'Owner task', 'assignee' => (string) Database::one('SELECT public_id FROM users WHERE id = ?', [$this->owner])['public_id']]);
        $task = TaskPolicy::find($t['public_id']);
        Database::run("UPDATE tasks SET status = 'in_review' WHERE id = ?", [$task['id']]);
        TaskService::transition($this->owner, TaskPolicy::find($t['public_id']), 'completed');
        $this->assertSame('completed', TaskPolicy::find($t['public_id'])['status']);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function ver(array $d, int $n = 1): array
    {
        return Database::one('SELECT * FROM deliverable_versions WHERE deliverable_id = ? AND version_no = ?', [$d['id'], $n]);
    }
}
