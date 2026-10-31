<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableService;
use Vertex\Services\Gate;
use Vertex\Services\PriceChangeService;
use Vertex\Services\RevisionAccess;
use Vertex\Services\RevisionQuery;
use Vertex\Services\RevisionService;
use Vertex\Services\RevisionTerms;

/** Revision requests, the state machine, included vs chargeable, change requests and the revision policy foundation. */
final class RevisionServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    /** @var array<string,mixed> */
    private array $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->d = $this->makeDeliverable(['title' => 'Brand video']);
        $this->publishVersion($this->d, 1);
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

    /** @return array<string,mixed> */
    private function submit(string $text = 'Please make the intro shorter and the logo bigger.', ?int $actor = null): array
    {
        $res = RevisionService::submit($actor ?? $this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => $text], [], false);

        return RevisionAccess::find($res['public_id']);
    }

    /** @return array<string,mixed> */
    private function fresh(array $r): array
    {
        return RevisionAccess::find($r['public_id']);
    }

    private function toReview(array $r): array
    {
        RevisionService::transition($this->support, $r, 'under_review');

        return $this->fresh($r);
    }

    private function setStatus(array $r, string $status, string $decision = 'unclassified'): array
    {
        Database::run('UPDATE revision_requests SET status = ?, scope_decision = ? WHERE id = ?', [$status, $decision, $r['id']]);

        return $this->fresh($r);
    }

    private function terms(?int $limit, string $scope = 'default', string $ref = 'QT-2026-014'): void
    {
        RevisionTerms::set($this->admin, ['scope' => $scope, 'service' => 'website_development', 'project' => $this->alpha['public_id'], 'included' => $limit === null ? '' : (string) $limit, 'terms' => 'Two rounds of revisions per the quotation.', 'source_reference' => $ref, 'reason' => 'From the signed quotation']);
    }

    private function chargeableInput(array $over = []): array
    {
        return $over + ['reason' => 'This asks for a new scene that was not in the storyboard.', 'scope_description' => 'Shoot and edit one additional 20 second scene.', 'client_reason' => 'The storyboard we agreed has no such scene.', 'amount' => '7500', 'days' => '3', 'due_date' => '2026-12-20'];
    }

    // ---- submission ---------------------------------------------------------------------------------------------------------

    public function testOnlyTheOwningClientSubmitsAgainstTheirOwnPublishedDeliverable(): void
    {
        $d = DeliverablePolicy::find($this->d['public_id']);
        foreach ([[$this->client2, 'another client'], [$this->admin, 'staff cannot request on the client\'s behalf'], [$this->manager, 'manager'], [$this->dev, 'worker'], [$this->partner, 'referral partner']] as [$u, $why]) {
            $this->expect403(fn () => RevisionService::submit($u, $d, ['description' => 'A long enough request text.'], [], false), $why);
        }
        $draft = $this->makeDeliverable(['title' => 'Unpublished']);
        $this->expect403(fn () => RevisionService::submit($this->client, DeliverablePolicy::find($draft['public_id']), ['description' => 'Trying a draft deliverable'], [], false), 'unpublished work');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c']);
        $r = $this->submit();
        $this->assertMatchesRegularExpression('/^REV-\d{4}-\d{5}$/', $r['reference']);
        $this->assertSame(['submitted', $this->client, 'unclassified'], [$r['status'], (int) $r['requested_by'], $r['scope_decision']]);
        $this->assertSame((int) $this->ver($this->d, 1)['id'], (int) $r['version_id'], 'tied to the exact version reviewed');
        $this->assertSame('revision_requested', Database::one('SELECT action FROM client_approvals')['action']);
        $this->assertSame(['submitted'], array_column(Database::all('SELECT to_status FROM revision_status_history'), 'to_status'));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'revision.submitted'")['c']);
    }

    public function testValidationClosedProjectsAndStaleVersions(): void
    {
        $d = DeliverablePolicy::find($this->d['public_id']);
        $this->expectInvalid(fn () => RevisionService::submit($this->client, $d, ['description' => 'short'], [], false), 'Describe the change');
        $this->expectInvalid(fn () => RevisionService::submit($this->client, $d, ['description' => 'A long enough request', 'version' => str_repeat('a', 32)], [], false), 'current published version');
        DeliverableService::addVersion($this->manager, $d, [], $this->png('v2.png', '2'), false);
        $this->publishVersion($this->d, 2);
        $this->expectInvalid(fn () => RevisionService::submit($this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => 'About the old one', 'version' => $this->ver($this->d, 1)['public_id']], [], false), 'current published version');
        $ok = RevisionService::submit($this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => 'About the new one', 'version' => $this->ver($this->d, 2)['public_id']], [], false);
        $this->assertTrue($ok['created']);
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->alpha['id']]);
        $this->expectInvalid(fn () => RevisionService::submit($this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => 'After cancellation'], [], false), 'no longer open');
    }

    public function testDuplicateSubmissionsAreIdempotentAndNotifiedOnce(): void
    {
        $d = DeliverablePolicy::find($this->d['public_id']);
        $token = bin2hex(random_bytes(16));
        $a = RevisionService::submit($this->client, $d, ['description' => 'Same request text here', 'token' => $token], [], false);
        $b = RevisionService::submit($this->client, $d, ['description' => 'Same request text here', 'token' => $token], [], false);
        $this->assertTrue($a['created']);
        $this->assertFalse($b['created']);
        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->admin} AND event_type = 'revision.received'")['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c']);
        $recipients = array_map('intval', array_column(Database::all("SELECT user_id FROM notifications WHERE event_type = 'revision.received'"), 'user_id'));
        $this->assertEqualsCanonicalizing([$this->admin, $this->manager, $this->support], $recipients, 'staff on the project who handle revisions');
        $this->assertNotContains($this->client, $recipients);
    }

    public function testAttachmentsAreValidatedAndStoredPrivately(): void
    {
        $d = DeliverablePolicy::find($this->d['public_id']);
        $this->expectInvalid(fn () => RevisionService::submit($this->client, $d, ['description' => 'With a bad attachment'], [$this->tmp('evil.php', '<?php echo 1;')], false), 'not allowed');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c'], 'nothing is created when an attachment is refused');
        $storedBefore = (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c'];
        $this->assertSame(1, $storedBefore, 'only the deliverable file exists');
        $this->expectInvalid(fn () => RevisionService::submit($this->client, $d, ['description' => 'Too many files here'], [$this->png('1.png'), $this->png('2.png', 'b'), $this->png('3.png', 'c'), $this->png('4.png', 'd')], false), 'up to 3');
        $res = RevisionService::submit($this->client, $d, ['description' => 'See the attached mock-up'], [$this->png('mockup.png', 'm')], false);
        $r = RevisionAccess::find($res['public_id']);
        $this->assertSame($storedBefore + 1, (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c']);
        $files = RevisionQuery::files($r);
        $this->assertSame(['mockup.png'], array_column($files, 'original_name'));
        $this->assertArrayNotHasKey('stored_path', $files[0], 'storage paths never leave the data layer');
    }

    // ---- state machine -------------------------------------------------------------------------------------------------------

    public function testEveryStatusPairMatchesTheConfiguredMachineForAnAdministrator(): void
    {
        $statuses = array_keys((array) Config::get('workflow.revision_statuses'));
        $this->assertCount(11, $statuses);
        $r = $this->submit();
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                $row = $this->setStatus($r, $from, 'included');
                $letters = Config::get("workflow.revision_transitions.{$from}.{$to}");
                $mustSucceed = $letters !== null && $letters !== 'X' && !(in_array($to, ['included_revision', 'potentially_chargeable'], true) && $from !== 'potentially_chargeable');
                try {
                    RevisionService::transition($this->admin, $row, $to, 'A client facing explanation.', 'Internal reasoning for the move.');
                    $this->assertTrue($mustSucceed, "{$from} -> {$to} succeeded but must not");
                    $this->assertSame($to, $this->fresh($r)['status']);
                } catch (ValidationException $e) {
                    $this->assertFalse($mustSucceed, "{$from} -> {$to} was refused: " . implode(' ', $e->errors));
                    $this->assertSame($from, $this->fresh($r)['status'], 'a refused move changes nothing');
                }
            }
        }
    }

    public function testTerminalStatusesAreFinalAndCompletionIsTimestamped(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'included', ['reason' => 'It is a small tweak covered by the scope.']);
        RevisionService::transition($this->manager, $this->fresh($r), 'in_progress');
        RevisionService::transition($this->manager, $this->fresh($r), 'ready_for_client_review');
        $this->assertNull($this->fresh($r)['completed_at']);
        $this->expectInvalid(fn () => RevisionService::transition($this->manager, $this->fresh($r), 'resolved'), 'Explanation');
        RevisionService::transition($this->client, $this->fresh($r), 'resolved');
        $f = $this->fresh($r);
        $this->assertSame('resolved', $f['status']);
        $this->assertNotNull($f['completed_at']);
        foreach (['under_review', 'in_progress', 'cancelled'] as $to) {
            $this->expectInvalid(fn () => RevisionService::transition($this->admin, $this->fresh($r), $to), 'cannot move');
        }
        $this->assertSame([], RevisionService::allowedMoves($this->admin, $this->fresh($r)));
        $this->assertGreaterThanOrEqual(6, (int) Database::one('SELECT COUNT(*) c FROM revision_status_history WHERE revision_id = ?', [$r['id']])['c']);
        $this->assertGreaterThanOrEqual(4, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'revision.status_changed'")['c']);
    }

    public function testRejectionNeedsAnExplanationTheClientCanReadAndCancelledRequestsStayClosed(): void
    {
        $r = $this->submit();
        $this->expectInvalid(fn () => RevisionService::transition($this->support, $r, 'rejected'), 'Explanation');
        RevisionService::transition($this->support, $r, 'rejected', 'This is outside what we can change after sign-off.');
        $f = $this->fresh($r);
        $this->assertSame(['rejected', 'This is outside what we can change after sign-off.'], [$f['status'], $f['client_response']]);
        $this->assertSame('Not accepted', Config::get('workflow.revision_client_labels.rejected'));
        $c = $this->submit('Another request that the client cancels');
        RevisionService::transition($this->client, $c, 'cancelled');
        $this->assertSame('cancelled', $this->fresh($c)['status']);
        $this->expect403(fn () => RevisionService::transition($this->client, $this->fresh($this->submit('Third request text')), 'under_review'), 'clients cannot start reviews');
    }

    public function testRoleLettersAreEnforcedPerPermission(): void
    {
        $r = $this->toReview($this->submit());
        $this->expect403(fn () => RevisionService::classify($this->support, $r, 'included', ['reason' => 'support may not classify anything']), 'support lacks revisions.classify');
        $this->expect403(fn () => RevisionService::classify($this->dev, $r, 'included', ['reason' => 'a worker may not classify it']), 'workers lack revisions.manage');
        try {
            RevisionService::transition($this->manager2, $r, 'rejected', 'x y z');
            $this->fail('an unassigned manager has no project access');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status, 'it does not even look like it exists');
        }
        RevisionService::classify($this->manager, $r, 'included', ['reason' => 'This is a normal tweak within the scope.']);
        $this->assertSame('included_revision', $this->fresh($r)['status']);
        $this->expectInvalid(fn () => RevisionService::transition($this->manager, $this->fresh($r), 'potentially_chargeable'), 'cannot move');
        $this->expectInvalid(fn () => RevisionService::transition($this->manager, $this->setStatus($r, 'under_review'), 'included_revision'), 'classification form');
        $this->expectInvalid(fn () => RevisionService::transition($this->admin, $this->setStatus($r, 'potentially_chargeable', 'chargeable'), 'awaiting_cost_approval'), 'cost proposal');
        $this->assertSame([], RevisionService::allowedMoves($this->dev, $this->fresh($r)));
        $this->assertSame([], RevisionService::allowedMoves($this->client2, $this->fresh($r)));
    }

    // ---- included vs chargeable ----------------------------------------------------------------------------------------------

    public function testChargeableClassificationCreatesADraftProposalAndChargesNothing(): void
    {
        Database::run("UPDATE projects SET approved_quotation_ref = 'QT-9', approved_amount_minor = 10000000, approved_at = UTC_TIMESTAMP() WHERE id = ?", [$this->alpha['id']]);
        Database::run("INSERT INTO price_revisions (project_id, revision_no, kind, base_minor, surcharge_minor, amount_minor, quotation_ref, created_at) VALUES (?, 1, 'original', 10000000, 0, 10000000, 'QT-9', UTC_TIMESTAMP())", [$this->alpha['id']]);
        $priceBefore = [Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), Database::all('SELECT * FROM price_revisions'), Database::all('SELECT * FROM price_change_requests')];
        $r = $this->toReview($this->submit());
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $r, 'chargeable', ['reason' => 'It adds a scene']), 'Proposed additional scope');
        $this->assertSame('under_review', $this->fresh($r)['status'], 'a failed classification changes nothing');
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput(['amount' => '0'])), 'greater than zero');
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput(['amount' => 'abc'])), 'amount');
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput(['reason' => 'short'])), '10-3000');
        RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput());
        $f = $this->fresh($r);
        $this->assertSame(['potentially_chargeable', 'chargeable'], [$f['status'], $f['scope_decision']]);
        $c = Database::one('SELECT * FROM change_requests');
        $this->assertSame(['draft', 750000, 3, '2026-12-20', $this->manager], [$c['status'], (int) $c['proposed_amount_minor'], (int) $c['proposed_days'], $c['proposed_due_date'], (int) $c['proposed_by']]);
        $this->assertMatchesRegularExpression('/^CHG-\d{4}-\d{5}$/', $c['reference']);
        $base = json_decode($c['baseline_json'], true);
        $this->assertSame(['QT-9', 10000000], [$base['quotation_ref'], $base['approved_total_minor']], 'the original approved price is recorded alongside, not changed');
        $this->assertSame($priceBefore[0], Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), 'project price fields untouched');
        $this->assertSame($priceBefore[1], Database::all('SELECT * FROM price_revisions'));
        $this->assertSame($priceBefore[2], Database::all('SELECT * FROM price_change_requests'));
        // nothing reached the client
        $this->assertSame([], RevisionQuery::changes($this->client, $f));
        $this->assertNull(RevisionAccess::changeRole($this->client, RevisionAccess::findChange($c['public_id'])), 'clients cannot open a draft proposal');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->client} AND event_type LIKE 'change_request%'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->admin} AND event_type = 'revision.potentially_chargeable'")['c'], 'admins are told about the chargeable classification');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->manager} AND event_type = 'revision.potentially_chargeable'")['c']);
        // work cannot start while the client has not approved
        $this->expectInvalid(fn () => RevisionService::transition($this->admin, $this->setStatus($f, 'approved_for_work', 'chargeable'), 'in_progress'), 'until the client has approved');
    }

    public function testClientApprovalOfTheAdditionalCostOpensTheWork(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput());
        $c = RevisionAccess::findChange(Database::one('SELECT public_id FROM change_requests')['public_id']);
        $this->expect403(fn () => ChangeRequestService::send($this->support, $c), 'support cannot send proposals');
        $this->expect403(fn () => ChangeRequestService::send($this->client, $c), 'clients cannot send proposals');
        ChangeRequestService::updateDraft($this->manager, $c, $this->chargeableInput(['amount' => '9000']));
        $this->assertSame(900000, (int) Database::one('SELECT proposed_amount_minor FROM change_requests')['proposed_amount_minor']);
        ChangeRequestService::send($this->manager, RevisionAccess::findChange($c['public_id']));
        $this->assertSame('awaiting_cost_approval', $this->fresh($r)['status']);
        $this->assertSame('pending_client', Database::one('SELECT status FROM change_requests')['status']);
        $n = Database::one("SELECT * FROM notifications WHERE user_id = ? AND event_type = 'change_request.awaiting_approval'", [$this->client]);
        $this->assertNotNull($n);
        $this->assertSame('/changes/' . $c['public_id'], $n['link']);
        $this->expectInvalid(fn () => ChangeRequestService::updateDraft($this->manager, RevisionAccess::findChange($c['public_id']), $this->chargeableInput()), 'draft');

        $pending = RevisionAccess::findChange($c['public_id']);
        $this->assertSame('client', RevisionAccess::changeRole($this->client, $pending));
        foreach ([[$this->admin, 'administrators cannot approve for the client'], [$this->manager, 'managers'], [$this->support, 'support'], [$this->client2, 'another client'], [$this->partner, 'partners'], [$this->dev, 'workers']] as [$u, $why]) {
            $this->expect403(fn () => ChangeRequestService::clientDecide($u, $pending, 'approve', ''), $why);
        }
        $this->assertSame('pending_client', Database::one('SELECT status FROM change_requests')['status']);
        $this->assertNull(RevisionAccess::changeRole($this->client2, $pending), 'another client cannot open it');
        $this->expectInvalid(fn () => ChangeRequestService::clientDecide($this->client, $pending, 'perhaps', ''), 'approve or decline');

        ChangeRequestService::clientDecide($this->client, $pending, 'approve', 'Go ahead');
        $f = $this->fresh($r);
        $this->assertSame('approved_for_work', $f['status']);
        $cr = Database::one('SELECT * FROM change_requests');
        $this->assertSame(['approved', $this->client, 'Go ahead'], [$cr['status'], (int) $cr['decided_by'], $cr['decision_note']]);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'change_request.client_approved' AND actor_id = {$this->client}")['c']);
        $this->expectInvalid(fn () => ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($c['public_id']), 'approve', ''), 'already been decided');
        RevisionService::transition($this->manager, $f, 'in_progress');
        $this->assertSame('in_progress', $this->fresh($r)['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'change_request.client_decision' AND user_id = {$this->admin}")['c']);
    }

    public function testDecliningReturnsToPotentiallyChargeableAndNeverTouchesPrice(): void
    {
        Database::run("INSERT INTO price_revisions (project_id, revision_no, kind, base_minor, surcharge_minor, amount_minor, created_at) VALUES (?, 1, 'original', 100, 0, 100, UTC_TIMESTAMP())", [$this->alpha['id']]);
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput());
        $c = RevisionAccess::findChange(Database::one('SELECT public_id FROM change_requests')['public_id']);
        ChangeRequestService::send($this->manager, $c);
        ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($c['public_id']), 'reject', 'Too expensive for us');
        $this->assertSame('potentially_chargeable', $this->fresh($r)['status']);
        $this->assertSame('rejected', Database::one('SELECT status FROM change_requests')['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'change_request.client_approved'")['c']);
        // staff can follow up: reject with an explanation instead of doing the work
        RevisionService::transition($this->manager, $this->fresh($r), 'rejected', 'We understand; we will leave the scene out.');
        $this->assertSame('rejected', $this->fresh($r)['status']);
    }

    public function testWithdrawingOrClosingARequestKillsAnyLiveProposal(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput());
        $c = RevisionAccess::findChange(Database::one('SELECT public_id FROM change_requests')['public_id']);
        ChangeRequestService::send($this->manager, $c);
        ChangeRequestService::withdraw($this->manager, RevisionAccess::findChange($c['public_id']));
        $this->assertSame(['withdrawn', 'potentially_chargeable'], [Database::one('SELECT status FROM change_requests')['status'], $this->fresh($r)['status']]);
        $this->expectInvalid(fn () => ChangeRequestService::clientDecide($this->client, RevisionAccess::findChange($c['public_id']), 'approve', ''), 'already been decided');
        $this->expectInvalid(fn () => ChangeRequestService::withdraw($this->manager, RevisionAccess::findChange($c['public_id'])), 'already been decided');
        // a second proposal, then the client cancels the whole request
        $r2 = $this->toReview($this->submit('A second request that will be cancelled'));
        RevisionService::classify($this->manager, $r2, 'chargeable', $this->chargeableInput());
        RevisionService::transition($this->client, $this->fresh($r2), 'cancelled');
        $this->assertSame(['withdrawn', 'withdrawn'], array_column(Database::all('SELECT status FROM change_requests ORDER BY id'), 'status'));
    }

    public function testOverrideToIncludedIsAdministratorOnlyAndAudited(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'chargeable', $this->chargeableInput());
        $this->expect403(fn () => RevisionService::overrideToIncluded($this->manager, $this->fresh($r), 'The manager cannot override themselves'), 'managers lack revisions.override');
        $this->expect403(fn () => RevisionService::overrideToIncluded($this->support, $this->fresh($r), 'The support cannot override either'), 'support');
        $this->expectInvalid(fn () => RevisionService::overrideToIncluded($this->admin, $this->fresh($r), 'short'), '10-3000');
        RevisionService::overrideToIncluded($this->admin, $this->fresh($r), 'Goodwill: the client has been with us for years.');
        $f = $this->fresh($r);
        $this->assertSame(['included_revision', 'included'], [$f['status'], $f['scope_decision']]);
        $this->assertSame('withdrawn', Database::one('SELECT status FROM change_requests')['status']);
        $a = Database::one("SELECT * FROM audit_logs WHERE action = 'revision.classification_overridden'");
        $this->assertSame($this->admin, (int) $a['actor_id']);
        $this->assertStringContainsString('chargeable', $a['before_json']);
        $this->assertStringContainsString('included', $a['after_json']);
        $this->assertStringContainsString('Goodwill', $f['scope_reason']);
        $this->expectInvalid(fn () => RevisionService::overrideToIncluded($this->admin, $f, 'Trying to override again here'), 'potentially chargeable');
    }

    // ---- policy foundation ---------------------------------------------------------------------------------------------------

    public function testNoAllowanceIsInventedWhenNothingIsConfigured(): void
    {
        $t = RevisionTerms::forProject($this->alphaRow());
        $this->assertSame([null, null, 0, null, 'none'], [$t['policy'], $t['limit'], $t['used'], $t['remaining'], $t['source']]);
        // so classification is free (manual, reasoned) for classifiers
        for ($i = 1; $i <= 4; $i++) {
            $r = $this->toReview($this->submit("Request number {$i} with enough text"));
            RevisionService::classify($this->manager, $r, 'included', ['reason' => "Judged included by the manager, case {$i}."]);
        }
        $this->assertSame(4, RevisionTerms::forProject($this->alphaRow())['used']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM revision_policies')['c'], 'the system never writes a default allowance');
    }

    public function testNumericAllowanceIsAdvisoryButContradictingItNeedsAnAdministrator(): void
    {
        $this->terms(2);
        $mk = fn (string $t) => $this->toReview($this->submit($t));
        RevisionService::classify($this->manager, $mk('First revision request text'), 'included', ['reason' => 'Within the two included rounds.']);
        RevisionService::classify($this->manager, $mk('Second revision request text'), 'included', ['reason' => 'Within the two included rounds.']);
        $this->assertSame(['limit' => 2, 'used' => 2, 'remaining' => 0, 'source' => 'default'], array_intersect_key(RevisionTerms::forProject($this->alphaRow()), array_flip(['limit', 'used', 'remaining', 'source'])));
        $third = $mk('Third revision request text');
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $third, 'included', ['reason' => 'Let us just include it again.']), 'allowance (2) is already used up');
        RevisionService::classify($this->manager, $third, 'chargeable', $this->chargeableInput());
        $this->assertSame('potentially_chargeable', $this->fresh($third)['status']);
        $snap = json_decode($this->fresh($third)['policy_snapshot_json'], true);
        $this->assertSame([2, 2, 'QT-2026-014', false], [$snap['limit'], $snap['used_before'], $snap['reference'], $snap['overridden']]);
        // an administrator may include a request beyond the allowance: recorded as an override
        $fourth = $mk('Fourth revision request text');
        RevisionService::classify($this->admin, $fourth, 'included', ['reason' => 'Goodwill concession agreed with the owner.']);
        $this->assertTrue(json_decode($this->fresh($fourth)['policy_snapshot_json'], true)['overridden']);
        // a request classified chargeable while allowance remains also needs an administrator
        Database::run("UPDATE revision_requests SET status = 'cancelled' WHERE scope_decision = 'included'");
        $fifth = $mk('Fifth revision request text');
        $this->expectInvalid(fn () => RevisionService::classify($this->manager, $fifth, 'chargeable', $this->chargeableInput()), 'not used up yet');
        RevisionService::classify($this->admin, $fifth, 'chargeable', $this->chargeableInput());
        $this->assertSame('potentially_chargeable', $this->fresh($fifth)['status']);
    }

    public function testCancelledAndRejectedRequestsDoNotConsumeTheAllowance(): void
    {
        $this->terms(1);
        $a = $this->toReview($this->submit('A request that gets cancelled'));
        RevisionService::classify($this->manager, $a, 'included', ['reason' => 'Included within the allowance.']);
        $this->assertSame(1, RevisionTerms::forProject($this->alphaRow())['used']);
        RevisionService::transition($this->client, $this->fresh($a), 'cancelled');
        $this->assertSame(0, RevisionTerms::forProject($this->alphaRow())['used']);
        $b = $this->toReview($this->submit('A request after the cancellation'));
        RevisionService::classify($this->manager, $b, 'included', ['reason' => 'Now within the allowance again.']);
        $this->assertSame('included_revision', $this->fresh($b)['status']);
    }

    public function testPolicyResolutionOrderVersioningPermissionsAndAudit(): void
    {
        $this->terms(1, 'default', 'DEFAULT-REF');
        $this->terms(3, 'service', 'SERVICE-REF');
        $t = RevisionTerms::forProject($this->alphaRow());
        $this->assertSame([3, 'service', 'SERVICE-REF'], [$t['limit'], $t['source'], $t['policy']['source_reference']], 'the service terms beat the default');
        $this->terms(5, 'project', 'CONTRACT-77');
        $t = RevisionTerms::forProject($this->alphaRow());
        $this->assertSame([5, 'project'], [$t['limit'], $t['source']], 'project terms beat both');
        $bravoRow = \Vertex\Services\ProjectPolicy::find($this->bravo['public_id']);
        $this->assertSame('default', RevisionTerms::forProject($bravoRow)['source'], 'other projects are unaffected');
        $this->assertSame(1, RevisionTerms::forProject($bravoRow)['limit']);
        // versioning: a change supersedes, history is kept; identical values write nothing
        $this->assertFalse(RevisionTerms::set($this->admin, ['scope' => 'project', 'project' => $this->alpha['public_id'], 'included' => '5', 'terms' => 'Two rounds of revisions per the quotation.', 'source_reference' => 'CONTRACT-77', 'reason' => 'No change at all']));
        $this->terms(6, 'project', 'CONTRACT-77-A1');
        $rows = Database::all("SELECT * FROM revision_policies WHERE scope_key = ? ORDER BY version", ['project:' . $this->alpha['id']]);
        $this->assertCount(2, $rows);
        $this->assertSame([[1, null], [2, 1]], [[(int) $rows[0]['version'], $rows[0]['current_flag'] === null ? null : (int) $rows[0]['current_flag']], [(int) $rows[1]['version'], (int) $rows[1]['current_flag']]], 'one current version; v1 superseded');
        $this->assertNotNull($rows[0]['superseded_at']);
        $this->assertSame(['5', '6'], array_map(static fn (array $x): string => (string) $x['included_revisions'], $rows));
        $this->assertSame(4, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'revision_policy.changed'")['c'], 'default, service, project v1, project v2');
        // permissions and validation
        foreach ([$this->manager, $this->support, $this->dev, $this->client] as $u) {
            $this->expect403(fn () => RevisionTerms::set($u, ['scope' => 'default', 'included' => '9', 'reason' => 'Not allowed to do this']), 'only revisions.policy holders');
        }
        $this->expectInvalid(fn () => RevisionTerms::set($this->admin, ['scope' => 'default', 'included' => '-1', 'reason' => 'Negative allowance is wrong']), 'whole number');
        $this->expectInvalid(fn () => RevisionTerms::set($this->admin, ['scope' => 'default', 'included' => '', 'reason' => 'Neither number nor terms']), 'allowance, written terms');
        $this->expectInvalid(fn () => RevisionTerms::set($this->admin, ['scope' => 'project', 'project' => 'nope', 'included' => '2', 'reason' => 'Unknown project here']), 'Choose a project');
        $this->expectInvalid(fn () => RevisionTerms::set($this->admin, ['scope' => 'service', 'service' => 'plumbing', 'included' => '2', 'reason' => 'Unknown service here']), 'service');
        // an allowance can also be "not specified", with only written terms
        RevisionTerms::set($this->admin, ['scope' => 'service', 'service' => 'video_editing', 'included' => '', 'terms' => 'As agreed per project.', 'reason' => 'Terms only, no number']);
        $video = Database::one("SELECT * FROM revision_policies WHERE scope_key = 'service:video_editing'");
        $this->assertNull($video['included_revisions']);
    }

    // ---- visibility, assignment, notes -----------------------------------------------------------------------------------------

    public function testInternalNotesAndClassificationNeverReachTheClient(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::addNote($this->support, $r, 'INTERNAL-NOTE: the client is difficult');
        RevisionService::classify($this->manager, $this->fresh($r), 'chargeable', $this->chargeableInput(['reason' => 'INTERNAL-REASON this is clearly extra work']));
        $f = $this->fresh($r);
        $this->assertCount(2, RevisionQuery::notes($this->manager, $f));
        $this->assertSame([], RevisionQuery::notes($this->client, $f), 'the client gets no internal notes');
        $clientHistory = RevisionQuery::history($this->client, $f);
        $this->assertSame(['submitted', 'under_review'], array_column($clientHistory, 'to_status'), 'the chargeable assessment is hidden');
        $staffHistory = array_column(RevisionQuery::history($this->manager, $f), 'to_status');
        $this->assertSame(['submitted', 'under_review', 'potentially_chargeable'], $staffHistory);
        $blob = json_encode([$clientHistory, RevisionQuery::paginate($this->client, [])['rows'], RevisionQuery::changes($this->client, $f)]);
        foreach (['INTERNAL-NOTE', 'INTERNAL-REASON', 'chargeable', 'scope_reason', 'policy_snapshot'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $blob, "{$leak} must not reach the client");
        }
        $this->assertSame('Under review', Config::get('workflow.revision_client_labels.potentially_chargeable'), 'the client label hides the internal assessment');
        $this->expect403(fn () => RevisionService::addNote($this->client, $f, 'clients cannot add internal notes'), 'client note');
        $this->expect403(fn () => RevisionService::addNote($this->dev, $f, 'workers cannot'), 'worker note');
    }

    public function testRevisionVisibilityFollowsOwnershipAndProjectAccess(): void
    {
        $r = $this->submit();
        $this->assertTrue(RevisionAccess::canView($this->client, $r));
        $this->assertTrue(RevisionAccess::canView($this->support, $r));
        $this->assertTrue(RevisionAccess::canView($this->manager, $r));
        $this->assertTrue(RevisionAccess::canView($this->admin, $r));
        foreach ([$this->client2, $this->dev, $this->dev2, $this->manager2, $this->partner] as $u) {
            $this->assertFalse(RevisionAccess::canView($u, $r), "user {$u}");
            $this->assertSame(0, RevisionQuery::paginate($u, [])['total']);
            try {
                RevisionAccess::requireView($u, $r['public_id']);
                $this->fail('expected 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
        }
        $this->assertSame(1, RevisionQuery::paginate($this->client, [])['total']);
        $this->assertSame(1, RevisionQuery::paginate($this->support, ['q' => $r['reference']])['total']);
        $this->assertSame(0, RevisionQuery::paginate($this->support, ['q' => 'no-such-thing'])['total']);
        $this->assertSame(1, RevisionQuery::paginate($this->admin, ['status' => 'submitted'])['total']);
    }

    public function testAssignmentNeedsAProjectMemberWhoHandlesRevisions(): void
    {
        $r = $this->submit();
        $this->expect403(fn () => RevisionService::assign($this->dev, $r, $this->publicId($this->support)), 'workers cannot assign');
        $this->expect403(fn () => RevisionService::assign($this->client, $r, $this->publicId($this->support)), 'clients cannot');
        $this->expectInvalid(fn () => RevisionService::assign($this->manager, $r, $this->publicId($this->dev)), 'works on this project and handles revisions');
        $this->expectInvalid(fn () => RevisionService::assign($this->manager, $r, $this->publicId($this->manager2)), 'works on this project');
        $this->expectInvalid(fn () => RevisionService::assign($this->manager, $r, 'garbage'), 'works on this project');
        RevisionService::assign($this->manager, $r, $this->publicId($this->support));
        $this->assertSame($this->support, (int) $this->fresh($r)['assigned_to']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->support} AND event_type = 'revision.assigned'")['c']);
        $this->assertSame(1, RevisionQuery::paginate($this->support, ['mine' => true])['total']);
        $this->assertSame(0, RevisionQuery::paginate($this->manager, ['mine' => true])['total']);
        RevisionService::assign($this->manager, $this->fresh($r), null);
        $this->assertNull($this->fresh($r)['assigned_to']);
    }

    public function testStaleStatusesAreRefusedAndEverythingIsAudited(): void
    {
        $r = $this->submit();
        $stale = $r;
        RevisionService::transition($this->support, $r, 'under_review');
        $this->expectInvalid(fn () => RevisionService::transition($this->support, $stale, 'rejected', 'Late click on a stale page'), 'changed while');
        $this->assertSame('under_review', $this->fresh($r)['status']);
        $this->assertSame($this->support, (int) $this->fresh($r)['assigned_to'], 'starting a review assigns the reviewer');
        RevisionService::setClientResponse($this->support, $this->fresh($r), 'Thanks, we are looking at it.');
        $this->assertSame('Thanks, we are looking at it.', $this->fresh($r)['client_response']);
        $this->expect403(fn () => RevisionService::setClientResponse($this->client, $this->fresh($r), 'a client writing the staff response'), 'client');
        $this->assertTrue(\Vertex\Services\Audit::verify()['ok']);
        $blob = json_encode(Database::all("SELECT before_json, after_json, meta_json FROM audit_logs WHERE action LIKE 'revision.%' OR action LIKE 'change_request.%'"));
        $this->assertStringNotContainsString('Thanks, we are looking', (string) $blob, 'free-text content is not copied into the audit log');
    }

    public function testReadyForReviewCanPointAtTheNewVersion(): void
    {
        $r = $this->toReview($this->submit());
        RevisionService::classify($this->manager, $r, 'included', ['reason' => 'A simple change within scope.']);
        RevisionService::transition($this->manager, $this->fresh($r), 'in_progress');
        DeliverableService::addVersion($this->manager, DeliverablePolicy::find($this->d['public_id']), [], $this->png('v2.png', '2'), false);
        $v2 = $this->ver($this->d, 2);
        $this->expectInvalid(fn () => RevisionService::transition($this->manager, $this->fresh($r), 'ready_for_client_review', '', '', $this->ver($this->d, 1)['public_id']), 'different version');
        $this->expectInvalid(fn () => RevisionService::transition($this->manager, $this->fresh($r), 'ready_for_client_review', '', '', str_repeat('b', 32)), 'different version');
        RevisionService::transition($this->manager, $this->fresh($r), 'ready_for_client_review', 'The updated cut is ready.', '', $v2['public_id']);
        $f = $this->fresh($r);
        $this->assertSame([(int) $v2['id'], 2], [(int) $f['resolved_version_id'], (int) $f['resolved_version_no']]);
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = {$this->client} AND event_type = 'revision.update'")['c']);
    }

    public function testNoNewCodeWritesPriceTablesOrApprovedBaselineColumns(): void
    {
        foreach (['ChangeRequestService', 'RevisionService', 'RevisionTerms', 'DeliverableService', 'ClientApprovalService', 'Notifier', 'RevisionAccess'] as $cls) {
            $src = (string) file_get_contents(VERTEX_ROOT . "/app/Services/{$cls}.php");
            $this->assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+(price_revisions|price_change_requests|priority_pricing_rules|invoices?|quotations?)\b/i', $src, "{$cls} must not touch price/invoice tables");
            $this->assertDoesNotMatchRegularExpression('/approved_amount_minor|approved_quotation_ref/i', $src, "{$cls} must not write the approved baseline");
        }
        $this->assertTrue(true);
    }
}
