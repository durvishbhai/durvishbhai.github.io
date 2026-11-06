<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/** Row-level isolation between clients, workers, partners and staff, at the policy/service level. */
final class ProjectAccessTest extends DatabaseTestCase
{
    private int $admin;
    private int $clientA;
    private int $clientB;
    private int $manager;
    private int $dev;
    private int $dev2;
    private int $finance;
    private int $partner;
    private array $projA;
    private array $projB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->clientA = $this->makeUser('ca@x.test', ['client']);
        $this->clientB = $this->makeUser('cb@x.test', ['client']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->dev2 = $this->makeUser('dev2@x.test', ['website_developer']);
        $this->finance = $this->makeUser('fin@x.test', ['finance_staff']);
        $this->partner = $this->makeUser('ref@x.test', ['referral_partner']);
        $this->projA = $this->makeProject($this->clientA, 'Project A');
        $this->projB = $this->makeProject($this->clientB, 'Project B');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->manager, 'lead');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->dev);
    }

    private function canView(int $user, array $proj): bool
    {
        return ProjectPolicy::canView($user, ProjectPolicy::find($proj['public_id']));
    }

    /** @return list<string> titles */
    private function visible(int $user): array
    {
        return array_column(ProjectPolicy::visibleTo($user)['rows'], 'title');
    }

    public function testClientsSeeOnlyTheirOwnProjects(): void
    {
        $this->assertTrue($this->canView($this->clientA, $this->projA));
        $this->assertFalse($this->canView($this->clientA, $this->projB), 'cross-client access');
        $this->assertFalse($this->canView($this->clientB, $this->projA), 'cross-client access (reverse)');
        $this->assertSame(['Project A'], $this->visible($this->clientA));
        $this->assertSame(['Project B'], $this->visible($this->clientB));
    }

    public function testWorkersAndManagersSeeOnlyAssignedProjects(): void
    {
        $this->assertTrue($this->canView($this->dev, $this->projA));
        $this->assertFalse($this->canView($this->dev, $this->projB));
        $this->assertFalse($this->canView($this->dev2, $this->projA), 'cross-worker isolation');
        $this->assertTrue($this->canView($this->manager, $this->projA));
        $this->assertFalse($this->canView($this->manager, $this->projB), 'managers do not see everything');
        $this->assertSame(['Project A'], $this->visible($this->manager));
        $this->assertSame([], $this->visible($this->dev2));
    }

    public function testAdministratorsSeeEverythingFinanceAndPartnersSeeNothing(): void
    {
        $this->assertEqualsCanonicalizing(['Project A', 'Project B'], $this->visible($this->admin));
        $this->assertSame([], $this->visible($this->finance));
        $this->assertSame([], $this->visible($this->partner));
        $this->assertFalse($this->canView($this->finance, $this->projA));
        $this->assertFalse($this->canView($this->partner, $this->projA));
    }

    public function testRevokedAssignmentLosesAccessImmediately(): void
    {
        ProjectAssignmentService::revoke($this->admin, $this->projA['id'], $this->dev);
        $this->assertFalse($this->canView($this->dev, $this->projA));
        $this->assertSame([], $this->visible($this->dev));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.member_unassigned'")['c']);

        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->dev); // re-assign works and clears revoked_at
        $this->assertTrue($this->canView($this->dev, $this->projA));
    }

    public function testDeactivatedUsersLoseProjectAccessEvenIfStillAssigned(): void
    {
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$this->dev]);
        $this->assertFalse($this->canView($this->dev, $this->projA));
        $this->assertSame([], $this->visible($this->dev));
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$this->clientA]);
        $this->assertFalse($this->canView($this->clientA, $this->projA));
    }

    public function testRequireViewReturns404ForMissingMalformedAndForbiddenAlike(): void
    {
        foreach ([$this->projB['public_id'], str_repeat('a', 32), '1', '1 OR 1=1', '../etc/passwd', ''] as $id) {
            try {
                ProjectPolicy::requireView($this->clientA, $id);
                $this->fail("expected 404 for '{$id}'");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status, "'{$id}' must be indistinguishable from not-found");
            }
        }
        $this->assertSame('Project A', ProjectPolicy::requireView($this->clientA, $this->projA['public_id'])['title']);
    }

    public function testOnlyAuthorizedAdminsCanAssign(): void
    {
        foreach ([$this->manager, $this->dev, $this->clientA, $this->finance, $this->partner] as $actor) {
            try {
                ProjectAssignmentService::assign($actor, $this->projB['id'], $this->dev2);
                $this->fail('non-admin assigned a project');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertFalse($this->canView($this->dev2, $this->projB));
        $this->expectException(HttpException::class);
        ProjectAssignmentService::revoke($this->manager, $this->projA['id'], $this->dev);
    }

    public function testOnlyEligibleActiveTeamMembersCanBeAssigned(): void
    {
        foreach ([$this->clientB, $this->partner, $this->finance] as $notEligible) {
            try {
                ProjectAssignmentService::assign($this->admin, $this->projB['id'], $notEligible);
                $this->fail('ineligible user assigned');
            } catch (ValidationException) {
                $this->assertFalse($this->canView($notEligible, $this->projB) && $notEligible !== $this->clientB);
            }
        }
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$this->dev2]);
        $this->expectException(ValidationException::class);
        ProjectAssignmentService::assign($this->admin, $this->projB['id'], $this->dev2);
    }

    public function testAssignmentAuditAndIdempotence(): void
    {
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->dev); // already assigned: no duplicate row/audit
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM project_assignments WHERE project_id = ? AND user_id = ?', [$this->projA['id'], $this->dev])['c']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.member_assigned'")['c']);
        $a = Database::one("SELECT actor_id FROM audit_logs WHERE action = 'project.member_assigned' ORDER BY id LIMIT 1");
        $this->assertSame($this->admin, (int) $a['actor_id']);
    }

    public function testDatabaseRejectsDuplicateAssignmentRows(): void
    {
        $this->expectException(\PDOException::class);
        Database::run(
            'INSERT INTO project_assignments (project_id, user_id, project_role, assigned_by, assigned_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$this->projA['id'], $this->dev, 'member', $this->admin],
        );
    }

    public function testReferralPartnersOnlyOwnReferralRecordsAtDatabaseRelationLevel(): void
    {
        $other = $this->makeUser('ref2@x.test', ['referral_partner']);
        Database::run("INSERT INTO referrals (public_id, partner_user_id, referred_name, created_at, updated_at) VALUES (?, ?, 'Lead 1', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $this->partner]);
        Database::run("INSERT INTO referrals (public_id, partner_user_id, referred_name, created_at, updated_at) VALUES (?, ?, 'Lead 2', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $other]);
        $mine = Database::all('SELECT referred_name FROM referrals WHERE partner_user_id = ?', [$this->partner]);
        $this->assertSame([['referred_name' => 'Lead 1']], $mine);
    }
}
