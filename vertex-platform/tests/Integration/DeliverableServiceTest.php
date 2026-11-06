<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Auth;
use Vertex\Services\ClientApprovalService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableQuery;
use Vertex\Services\DeliverableService;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\TaskService;

/** Deliverables: permissions, draft/published visibility, lifecycle, versions, audit. */
final class DeliverableServiceTest extends DatabaseTestCase
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

    private function audit(string $action): int
    {
        return (int) Database::one('SELECT COUNT(*) c FROM audit_logs WHERE action = ?', [$action])['c'];
    }

    // ---- who may upload ---------------------------------------------------------------------------------------------------

    public function testOnlyAuthorisedPeopleOnTheProjectMayUpload(): void
    {
        $in = ['title' => 'Logo pack', 'category' => 'graphic_design'];
        foreach ([[$this->client, 'clients cannot upload deliverables'], [$this->partner, 'referral partners have no access'], [$this->dev2, 'a worker on another project'], [$this->manager2, 'an unassigned manager'], [$this->support, 'support has no deliverables.upload']] as [$u, $why]) {
            $this->expect403(fn () => DeliverableService::create($u, $this->alphaRow(), $in, $this->png(), false), $why);
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM deliverables')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c'], 'no file was stored for refused uploads');
        $byWorker = $this->makeDeliverable(['title' => 'Worker upload'], $this->dev);
        $this->assertSame('draft', $byWorker['status'], 'a worker upload is a draft');
        $byAdmin = $this->makeDeliverable(['title' => 'Admin upload'], $this->admin);
        $this->assertMatchesRegularExpression('/^DLV-\d{4}-\d{4}$/', $byAdmin['code']);
        $this->assertNotSame($byWorker['code'], $byAdmin['code']);
    }

    public function testUploadNeverPublishesAndWorkersCannotPublishReviewOrArchive(): void
    {
        $d = $this->makeDeliverable([], $this->dev);
        $v = $this->ver($d, 1);
        $this->assertSame('draft', $v['status']);
        $this->assertNull($v['published_at']);
        $this->assertNull($d['published_version_id']);
        $this->assertNull($d['first_published_at']);
        DeliverableService::submitForReview($this->dev, $d, $v);
        $v = $this->ver($d, 1);
        $this->expect403(fn () => DeliverableService::review($this->dev, $this->freshD($d), $v, 'approved'), 'workers cannot approve internally');
        $this->expect403(fn () => DeliverableService::publish($this->dev, $this->freshD($d), $v), 'workers cannot publish');
        $this->expect403(fn () => DeliverableService::setArchived($this->dev, $this->freshD($d), true), 'workers cannot archive');
        $this->expect403(fn () => DeliverableService::publish($this->support, $this->freshD($d), $v), 'support cannot publish');
        $this->expect403(fn () => DeliverableService::publish($this->manager2, $this->freshD($d), $v), 'an unassigned manager cannot publish');
        $this->assertSame('pending_review', $this->ver($d, 1)['status']);
        // publishing needs an approved version, not merely the permission
        $this->expectInvalid(fn () => DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1)), 'cannot take that action');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM deliverable_versions WHERE published_at IS NOT NULL')['c']);
    }

    // ---- lifecycle ----------------------------------------------------------------------------------------------------------

    public function testFullLifecycleAndStatuses(): void
    {
        $d = $this->makeDeliverable();
        $this->assertSame('draft', $d['status']);
        DeliverableService::submitForReview($this->manager, $d, $this->ver($d, 1));
        $this->assertSame('pending_review', $this->freshD($d)['status']);
        $this->expectInvalid(fn () => DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'changes_requested', ''), 'required');
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'changes_requested', 'The logo is cropped');
        $this->assertSame('changes_requested', $this->ver($d, 1)['status']);
        $this->assertSame('draft', $this->freshD($d)['status'], 'back to draft for the team');
        DeliverableService::submitForReview($this->manager, $this->freshD($d), $this->ver($d, 1));
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved', 'Good now');
        $this->assertSame('approved_internal', $this->freshD($d)['status']);
        $this->assertNull($this->freshD($d)['first_published_at'], 'internal approval does not publish');
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        $f = $this->freshD($d);
        $this->assertSame('published', $f['status']);
        $this->assertSame((int) $this->ver($d, 1)['id'], (int) $f['published_version_id']);
        $this->assertNotNull($f['first_published_at']);
        DeliverableService::setArchived($this->admin, $f, true);
        $this->assertSame('archived', $this->freshD($d)['status']);
        $this->assertNotNull($this->freshD($d)['archived_at']);
        DeliverableService::setArchived($this->admin, $this->freshD($d), false);
        $this->assertSame('published', $this->freshD($d)['status'], 'restoring recomputes from the live version');
        $reviews = Database::all('SELECT decision FROM deliverable_reviews ORDER BY id');
        $this->assertSame(['changes_requested', 'approved'], array_column($reviews, 'decision'));
    }

    public function testNobodyApprovesTheirOwnUploadUnlessEnabled(): void
    {
        $d = $this->makeDeliverable([], $this->admin);
        DeliverableService::submitForReview($this->admin, $d, $this->ver($d, 1));
        $this->expectInvalid(fn () => DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved'), 'someone else');
        \Vertex\Core\Config::set('uploads.allow_self_approval', true);
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved');
        \Vertex\Core\Config::set('uploads.allow_self_approval', false);
        $this->assertSame('approved_internal', $this->ver($d, 1)['status']);
    }

    // ---- visibility -----------------------------------------------------------------------------------------------------------

    public function testClientsNeverSeeDraftsPendingOrApprovedButUnpublishedWork(): void
    {
        $d = $this->makeDeliverable(['title' => 'SECRET-DRAFT-TITLE', 'description' => 'SECRET-DRAFT-DESC']);
        foreach (['draft', 'pending_review', 'approved_internal'] as $step) {
            $row = $this->freshD($d);
            $this->assertFalse(DeliverablePolicy::canView($this->client, $row), "client cannot view a {$step} deliverable");
            $this->assertSame([], DeliverablePolicy::versionsFor($this->client, $row));
            $this->assertSame(0, DeliverableQuery::paginate($this->client, [])['total']);
            $this->assertSame([], array_merge(...array_column(DeliverableQuery::clientDashboard($this->client), 'deliverables') ?: [[]]));
            try {
                DeliverablePolicy::requireView($this->client, $d['public_id']);
                $this->fail('expected 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
            if ($step === 'draft') {
                DeliverableService::submitForReview($this->manager, $row, $this->ver($d, 1));
            } elseif ($step === 'pending_review') {
                DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved');
            }
        }
        $this->assertSame(1, DeliverableQuery::paginate($this->manager, [])['total'], 'staff with draft access see it');
        $this->assertSame(0, DeliverableQuery::paginate($this->support, [])['total'], 'support (no draft permission) does not');
        $this->assertFalse(DeliverablePolicy::canView($this->support, $this->freshD($d)));
        $this->assertSame(1, DeliverableQuery::paginate($this->dev, [])['total'], 'assigned workers see drafts on their project');
        $this->assertSame(0, DeliverableQuery::paginate($this->dev2, [])['total'], 'workers of other projects do not');
        $this->assertSame(0, DeliverableQuery::paginate($this->client2, [])['total']);
        $this->assertSame(0, DeliverableQuery::paginate($this->partner, [])['total']);
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        $this->assertTrue(DeliverablePolicy::canView($this->client, $this->freshD($d)));
        $this->assertTrue(DeliverablePolicy::canView($this->support, $this->freshD($d)), 'support sees published work');
        $this->assertFalse(DeliverablePolicy::canView($this->client2, $this->freshD($d)), 'another client');
        $this->assertFalse(DeliverablePolicy::canView($this->partner, $this->freshD($d)), 'referral partners never');
        $this->assertFalse(DeliverablePolicy::canView($this->dev2, $this->freshD($d)));
        $this->assertFalse(DeliverablePolicy::canView($this->manager2, $this->freshD($d)), 'unassigned managers');
    }

    public function testClientSeesOnlyPublishedVersionsAndNoInternalFields(): void
    {
        $d = $this->makeDeliverable(['change_notes' => 'INTERNAL-NOTE-V1', 'label' => 'First cut']);
        $this->publishVersion($d, 1);
        DeliverableService::addVersion($this->manager, $this->freshD($d), ['change_notes' => 'INTERNAL-NOTE-V2'], $this->png('v2.png', 'x'), false);
        DeliverableService::addVersion($this->manager, $this->freshD($d), ['change_notes' => 'INTERNAL-NOTE-V3'], $this->png('v3.png', 'y'), false);
        $clientVersions = DeliverablePolicy::versionsFor($this->client, $this->freshD($d));
        $this->assertSame([1], array_map(static fn (array $v): int => (int) $v['version_no'], $clientVersions), 'unpublished versions are invisible');
        $json = json_encode($clientVersions);
        foreach (['INTERNAL-NOTE', 'change_notes', 'approver', 'uploader_name', 'sha256', 'scan_status'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $json, "{$leak} must not reach the client");
        }
        $staff = DeliverablePolicy::versionsFor($this->manager, $this->freshD($d));
        $this->assertSame([3, 2, 1], array_map(static fn (array $v): int => (int) $v['version_no'], $staff));
        $this->assertSame([1], array_map(static fn (array $v): int => (int) $v['version_no'], DeliverablePolicy::versionsFor($this->support, $this->freshD($d))));
        // a client cannot even resolve an unpublished version by its public id
        $v3 = $this->ver($d, 3);
        $this->assertFalse(DeliverablePolicy::canViewVersion($this->client, $this->freshD($d), $v3));
        $this->assertFalse(DeliverablePolicy::canDownload($this->client, $this->freshD($d), $v3));
        $this->assertTrue(DeliverablePolicy::canDownload($this->client, $this->freshD($d), $this->ver($d, 1)));
        $this->assertFalse(DeliverablePolicy::canDownload($this->client2, $this->freshD($d), $this->ver($d, 1)), 'cross-client');
        $this->assertFalse(DeliverablePolicy::canDownload($this->dev2, $this->freshD($d), $this->ver($d, 1)), 'worker on another project');
        // a version id belonging to a DIFFERENT deliverable is refused
        $other = $this->makeDeliverable(['title' => 'Other']);
        $this->assertFalse(DeliverablePolicy::canViewVersion($this->manager, $this->freshD($d), $this->ver($other, 1)));
        $this->assertNull(DeliverablePolicy::findVersion($this->freshD($d), $this->ver($other, 1)['public_id']));
    }

    // ---- versions -----------------------------------------------------------------------------------------------------------

    public function testNewVersionsNeverOverwriteAndKeepFullHistory(): void
    {
        $d = $this->makeDeliverable();
        $v1 = $this->publishVersion($d, 1);
        $v1File = Database::one('SELECT * FROM stored_files WHERE id = ?', [$v1['file_id']]);
        $r = DeliverableService::addVersion($this->dev, $this->freshD($d), ['label' => 'Revised', 'change_notes' => 'Fixed the crop'], $this->png('design-v2.png', 'v2'), false);
        $this->assertSame(2, $r['version_no']);
        $v2 = $this->ver($d, 2);
        $this->assertSame(['draft', $this->dev, 'Revised', 'design-v2.png'], [$v2['status'], (int) $v2['uploaded_by'], $v2['label'], $v2['original_name']]);
        $v1After = $this->ver($d, 1);
        $this->assertSame('published', $v1After['status'], 'V1 is untouched and still the client version');
        $this->assertSame((int) $v1['file_id'], (int) $v1After['file_id']);
        $this->assertSame($v1File, Database::one('SELECT * FROM stored_files WHERE id = ?', [$v1['file_id']]), 'V1 file metadata unchanged');
        $this->assertFileExists(\Vertex\Services\FileStore::path($v1File));
        $this->assertSame('published', $this->freshD($d)['status'], 'the deliverable stays published while V2 is a draft');
        $this->assertSame((int) $v1['id'], (int) $this->freshD($d)['published_version_id']);
        $this->assertSame([$v1['version_no'], 2], [1, (int) $v2['version_no']]);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM deliverable_versions WHERE deliverable_id = ?', [$d['id']])['c']);
        $this->assertSame(2, $this->audit('deliverable.version_created') ?: 0, 'V1 and V2 creation are audited');
        $this->expect403(fn () => DeliverableService::addVersion($this->client, $this->freshD($d), [], $this->png('c.png', 'c'), false), 'clients cannot add versions');
        $this->expect403(fn () => DeliverableService::addVersion($this->dev2, $this->freshD($d), [], $this->png('c.png', 'c'), false), 'workers of another project cannot');
    }

    public function testPublishingANewVersionSupersedesButNeverDeletesAndCanBeRolledBack(): void
    {
        $d = $this->makeDeliverable();
        $this->publishVersion($d, 1);
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        $this->publishVersion($d, 2);
        $this->assertSame(['superseded', 'published'], [$this->ver($d, 1)['status'], $this->ver($d, 2)['status']]);
        $this->assertSame((int) $this->ver($d, 2)['id'], (int) $this->freshD($d)['published_version_id']);
        $this->assertNotNull($this->ver($d, 1)['published_at'], 'V1 keeps its publication timestamp');
        // the client still sees V1 as an earlier version and V2 as current
        $this->assertSame([2, 1], array_map(static fn (array $v): int => (int) $v['version_no'], DeliverablePolicy::versionsFor($this->client, $this->freshD($d))));
        // designate V1 as the client-facing version again
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        $this->assertSame(['published', 'superseded'], [$this->ver($d, 1)['status'], $this->ver($d, 2)['status']]);
        $this->assertSame((int) $this->ver($d, 1)['id'], (int) $this->freshD($d)['published_version_id']);
        $this->expectInvalid(fn () => DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1)), 'cannot take that action');
        $this->assertSame(3, $this->audit('deliverable.published'));
    }

    public function testWithdrawRemovesAVersionFromTheClientButKeepsIt(): void
    {
        $d = $this->makeDeliverable();
        $v = $this->publishVersion($d, 1);
        $this->expectInvalid(fn () => DeliverableService::withdraw($this->admin, $this->freshD($d), $v, 'no'), 'characters');
        $this->expect403(fn () => DeliverableService::withdraw($this->dev, $this->freshD($d), $v, 'Wrong file attached'), 'workers cannot withdraw');
        DeliverableService::withdraw($this->admin, $this->freshD($d), $v, 'Wrong file attached');
        $this->assertSame('withdrawn', $this->ver($d, 1)['status']);
        $this->assertNull($this->freshD($d)['published_version_id']);
        $this->assertSame([], DeliverablePolicy::versionsFor($this->client, $this->freshD($d)));
        $this->assertFalse(DeliverablePolicy::canDownload($this->client, $this->freshD($d), $this->ver($d, 1)));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM deliverable_versions')['c'], 'nothing deleted');
        $this->assertSame(1, $this->audit('deliverable.withdrawn'));
        $this->assertNotSame('published', $this->freshD($d)['status']);
    }

    public function testArchiveHidesFromActiveListsButKeepsHistory(): void
    {
        $d = $this->makeDeliverable();
        $this->publishVersion($d, 1);
        $this->expect403(fn () => DeliverableService::setArchived($this->manager2, $this->freshD($d), true), 'unassigned manager');
        $this->expect403(fn () => DeliverableService::setArchived($this->client, $this->freshD($d), true), 'client');
        DeliverableService::setArchived($this->manager, $this->freshD($d), true);
        $this->assertSame(0, DeliverableQuery::paginate($this->manager, [])['total'], 'hidden from the normal list');
        $this->assertSame(1, DeliverableQuery::paginate($this->manager, ['archived' => true])['total']);
        $this->assertSame('archived', ClientApprovalService::stateFor($this->freshD($d))['key']);
        $this->expectInvalid(fn () => DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('x.png', 'x'), false), 'archived');
        $this->expectInvalid(fn () => DeliverableService::updateMeta($this->manager, $this->freshD($d), ['title' => 'New title', 'category' => 'other']), 'archived');
        $this->assertSame(1, $this->audit('deliverable.archived'));
        DeliverableService::setArchived($this->manager, $this->freshD($d), false);
        $this->assertSame(1, DeliverableQuery::paginate($this->manager, [])['total']);
        $this->assertSame(1, $this->audit('deliverable.restored'));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM deliverables')['c']);
    }

    // ---- content and metadata ---------------------------------------------------------------------------------------------

    public function testFileOrHttpsLinkExactlyOneAndLinkValidation(): void
    {
        $base = ['title' => 'Prototype', 'category' => 'website_files'];
        $res = DeliverableService::create($this->manager, $this->alphaRow(), $base + ['external_url' => 'https://www.figma.com/file/abc123/Prototype'], null, false);
        $v = Database::one('SELECT * FROM deliverable_versions WHERE deliverable_id = ?', [$res['id']]);
        $this->assertNull($v['file_id']);
        $this->assertSame('https://www.figma.com/file/abc123/Prototype', $v['external_url']);
        $this->expectInvalid(fn () => DeliverableService::create($this->manager, $this->alphaRow(), $base, null, false), 'file to upload');
        $this->expectInvalid(fn () => DeliverableService::create($this->manager, $this->alphaRow(), $base + ['external_url' => 'https://a.example.com/x'], $this->png(), false), 'not both');
        foreach (['http://example.com/x', 'javascript:alert(1)', 'ftp://example.com/f', 'https://user:pw@example.com/', 'https://localhost/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://intranet/x', 'https://x.internal/a', 'https://exa mple.com', 'https://' . str_repeat('a', 500) . '.com', '//example.com/x', 'data:text/html,hi'] as $bad) {
            $this->expectInvalid(fn () => DeliverableService::create($this->manager, $this->alphaRow(), $base + ['external_url' => $bad], null, false), 'link must');
        }
        \Vertex\Core\Config::set('uploads.external_hosts', ['figma.com']);
        $this->expectInvalid(fn () => DeliverableService::create($this->manager, $this->alphaRow(), $base + ['external_url' => 'https://evil.example.org/x'], null, false), 'only accepted from');
        $ok = DeliverableService::create($this->manager, $this->alphaRow(), $base + ['external_url' => 'https://www.figma.com/file/zzz'], null, false);
        \Vertex\Core\Config::set('uploads.external_hosts', []);
        $this->assertNotEmpty($ok['code']);
    }

    public function testValidationAndTaskMilestoneMustBelongToTheProject(): void
    {
        $this->expectInvalid(fn () => $this->makeDeliverable(['title' => 'ab']), 'Title');
        $this->expectInvalid(fn () => $this->makeDeliverable(['category' => 'nonsense']), 'category');
        $this->expectInvalid(fn () => $this->makeDeliverable(['title' => '<b>x</b> bold']), 'Title');
        $bravoTask = TaskService::create($this->admin, ProjectPolicy::find($this->bravo['public_id']), ['title' => 'Bravo task']);
        $this->expectInvalid(fn () => $this->makeDeliverable(['task' => $bravoTask['public_id']]), 'task from this project');
        $bm = MilestoneService::create($this->admin, ProjectPolicy::find($this->bravo['public_id']), ['title' => 'Bravo milestone']);
        $this->expectInvalid(fn () => $this->makeDeliverable(['milestone' => $bm['public_id']]), 'milestone from this project');
        $task = TaskService::create($this->admin, $this->alphaRow(), ['title' => 'Alpha task']);
        $ms = MilestoneService::create($this->admin, $this->alphaRow(), ['title' => 'Alpha milestone']);
        $d = $this->makeDeliverable(['task' => $task['public_id'], 'milestone' => $ms['public_id'], 'description' => 'With links']);
        $this->assertSame([$task['public_id'], $ms['public_id']], [$d['task_public_id'], $d['milestone_public_id']]);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM deliverables WHERE title IN ('ab','<b>x</b> bold')")['c']);
        DeliverableService::updateMeta($this->manager, $d, ['title' => 'Renamed', 'category' => 'document']);
        $this->assertSame(['Renamed', 'document', null], [$this->freshD($d)['title'], $this->freshD($d)['category'], $this->freshD($d)['task_id']]);
        $this->expect403(fn () => DeliverableService::updateMeta($this->dev2, $this->freshD($d), ['title' => 'Hijack', 'category' => 'other']), 'other-project worker');
    }

    public function testClosedProjectsRefuseUploads(): void
    {
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->alpha['id']]);
        $this->expectInvalid(fn () => $this->makeDeliverable(), 'closed');
        Database::run("UPDATE projects SET status = 'in_progress', archived_at = UTC_TIMESTAMP() WHERE id = ?", [$this->alpha['id']]);
        $this->expect403(fn () => DeliverableService::create($this->manager, ProjectPolicy::find($this->alpha['public_id']), ['title' => 'Late upload'], $this->png(), false), 'archived projects are invisible to non-admins');
    }

    public function testRevokedWorkerLosesAccessImmediately(): void
    {
        $d = $this->makeDeliverable([], $this->dev);
        $this->assertTrue(DeliverablePolicy::canView($this->dev, $d));
        ProjectAssignmentService::revoke($this->admin, $this->alpha['id'], $this->dev);
        $this->assertFalse(DeliverablePolicy::canView($this->dev, $this->freshD($d)));
        $this->assertSame(0, DeliverableQuery::paginate($this->dev, [])['total']);
        $this->expect403(fn () => DeliverableService::addVersion($this->dev, $this->freshD($d), [], $this->png('z.png', 'z'), false), 'no access after revocation');
    }

    public function testQaQueueShowsOnlyWhatTheUserMayAct(): void
    {
        $a = $this->makeDeliverable(['title' => 'Awaiting review']);
        DeliverableService::submitForReview($this->manager, $a, $this->ver($a, 1));
        $b = $this->makeDeliverable(['title' => 'Awaiting publish']);
        DeliverableService::submitForReview($this->manager, $b, $this->ver($b, 1));
        DeliverableService::review($this->admin, $this->freshD($b), $this->ver($b, 1), 'approved');
        $q = DeliverableQuery::qaQueue($this->admin);
        $this->assertSame(['Awaiting review'], array_column($q['review'], 'title'));
        $this->assertSame(['Awaiting publish'], array_column($q['publish'], 'title'));
        $this->assertSame(['Awaiting review'], array_column(DeliverableQuery::qaQueue($this->manager)['review'], 'title'));
        foreach ([$this->dev, $this->dev2, $this->client, $this->support, $this->manager2, $this->partner] as $u) {
            $this->assertSame(['review' => [], 'publish' => []], DeliverableQuery::qaQueue($u), 'no queue for user ' . $u);
        }
    }

    // ---- audit and notifications ------------------------------------------------------------------------------------------

    public function testEveryCriticalActionIsAuditedWithoutSecrets(): void
    {
        $d = $this->makeDeliverable();
        $this->publishVersion($d, 1);
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        DeliverableService::setArchived($this->admin, $this->freshD($d), true);
        foreach (['deliverable.created', 'deliverable.version_created', 'deliverable.submitted_for_review', 'deliverable.approved_internal', 'deliverable.published', 'deliverable.archived'] as $action) {
            $this->assertGreaterThanOrEqual(1, $this->audit($action), "{$action} is audited");
        }
        $a = Database::one("SELECT * FROM audit_logs WHERE action = 'deliverable.published'");
        $this->assertSame($this->admin, (int) $a['actor_id']);
        $blob = json_encode(Database::all("SELECT before_json, after_json, meta_json FROM audit_logs WHERE action LIKE 'deliverable.%'"));
        $this->assertStringNotContainsString('stored_path', (string) $blob, 'storage paths are not written to the audit log');
        $this->assertStringNotContainsString('storage/uploads', (string) $blob);
        $this->assertTrue(\Vertex\Services\Audit::verify()['ok'], 'the hash chain still verifies');
        $this->assertGreaterThanOrEqual(5, (int) Database::one("SELECT COUNT(*) c FROM project_activity WHERE subject_type = 'deliverable'")['c']);
        // the shared client timeline shows publication, never uploads or reviews
        $clientTimeline = \Vertex\Services\ProjectActivity::timeline((int) $this->alpha['id'], true)['rows'];
        $this->assertStringContainsString('Deliverable published', json_encode($clientTimeline));
        $this->assertStringNotContainsString('submitted for internal review', json_encode($clientTimeline));
        $this->assertStringNotContainsString('approved internally', json_encode($clientTimeline));
    }

    public function testNotificationsAreTargetedAndNotDuplicated(): void
    {
        $d = $this->makeDeliverable();
        DeliverableService::submitForReview($this->manager, $d, $this->ver($d, 1));
        $reviewers = array_map('intval', array_column(Database::all("SELECT user_id FROM notifications WHERE event_type = 'deliverable.review_required'"), 'user_id'));
        $this->assertContains($this->admin, $reviewers, 'administrators with review rights are told');
        $this->assertNotContains($this->manager, $reviewers, 'not the person who submitted it');
        $this->assertNotContains($this->dev, $reviewers, 'workers do not review');
        $this->assertNotContains($this->client, $reviewers);
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'deliverable.published'", [$this->client])['c'], 'nothing for the client before publication');
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        $n = Database::all("SELECT * FROM notifications WHERE user_id = ?", [$this->client]);
        $this->assertCount(1, $n);
        $this->assertSame(['deliverable.published', 'not_sent'], [$n[0]['event_type'], $n[0]['email_status']], 'in-app only: never claims an email was sent');
        $this->assertSame('/deliverables/' . $d['public_id'], $n[0]['link']);
        $this->assertStringNotContainsString('internal', strtolower($n[0]['title'] . $n[0]['body']));
        // a revised version notifies with a different event
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        $this->publishVersion($d, 2);
        $this->assertSame(['deliverable.published', 'deliverable.revised_version_published'], array_column(Database::all('SELECT event_type FROM notifications WHERE user_id = ? ORDER BY id', [$this->client]), 'event_type'));
        // the unique (user, dedupe key) index stops a retried event
        $before = (int) Database::one('SELECT COUNT(*) c FROM notifications')['c'];
        \Vertex\Services\Notifier::send([$this->client], 'deliverable.published', 'dup', null, null, null, null, null, "version:{$this->ver($d, 2)['id']}:published:2");
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM notifications')['c']);
    }

    public function testExistingProjectAndFinancialDataIsUntouched(): void
    {
        Database::run("UPDATE projects SET approved_quotation_ref = 'QT-1', approved_amount_minor = 5000000, approved_at = UTC_TIMESTAMP() WHERE id = ?", [$this->alpha['id']]);
        Database::run("INSERT INTO price_revisions (project_id, revision_no, kind, base_minor, surcharge_minor, amount_minor, quotation_ref, created_at) VALUES (?, 1, 'original', 5000000, 0, 5000000, 'QT-1', UTC_TIMESTAMP())", [$this->alpha['id']]);
        $before = [Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), Database::all('SELECT * FROM price_revisions'), Database::all('SELECT * FROM price_change_requests'), Database::all('SELECT * FROM tasks')];
        $d = $this->makeDeliverable();
        $this->publishVersion($d, 1);
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2.png', '2'), false);
        DeliverableService::setArchived($this->admin, $this->freshD($d), true);
        $after = [Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), Database::all('SELECT * FROM price_revisions'), Database::all('SELECT * FROM price_change_requests'), Database::all('SELECT * FROM tasks')];
        $this->assertSame($before[1], $after[1]);
        $this->assertSame($before[2], $after[2]);
        $this->assertSame($before[3], $after[3]);
        foreach (['approved_quotation_ref', 'approved_amount_minor', 'status', 'priority', 'title', 'budget_minor'] as $col) {
            $this->assertSame($before[0][$col], $after[0][$col], "projects.{$col}");
        }
    }

    public function testDeliverableTablesProtectHistoryWithRestrictForeignKeys(): void
    {
        $rows = Database::all(
            "SELECT TABLE_NAME t, CONSTRAINT_NAME c, DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('deliverables','deliverable_versions','deliverable_reviews','client_approvals','revision_requests','revision_files','revision_status_history','revision_notes','change_requests')
                AND REFERENCED_TABLE_NAME IN ('projects','deliverables','deliverable_versions','revision_requests','stored_files')",
        );
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('RESTRICT', $r['r'], "{$r['t']}.{$r['c']} must not cascade");
        }
        $this->expectException(\PDOException::class);
        $d = $this->makeDeliverable();
        Database::run('DELETE FROM projects WHERE id = ?', [$d['project_id']]);
    }
}
