<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\CrmStageService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryService;
use Vertex\Services\PermissionSync;

/** CRM rules at the service level: sources, validation, pipeline, duplicates, ownership, follow-ups, conversion. */
final class CrmServiceTest extends DatabaseTestCase
{
    private int $owner;
    private int $admin;
    private int $manager;
    private int $manager2;
    private int $partner;
    private int $partner2;
    private int $support;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->manager2 = $this->makeUser('mgr2@x.test', ['manager']);
        $this->partner = $this->makeUser('p1@x.test', ['referral_partner']);
        $this->partner2 = $this->makeUser('p2@x.test', ['referral_partner']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
    }

    protected function tearDown(): void
    {
        AppTime::freeze(null);
    }

    /** @return array<string,string> */
    private function form(array $over = []): array
    {
        return $over + [
            'contact_name' => 'Ravi Kumar', 'email' => 'ravi@acme.test', 'phone' => '+91 98765 43210', 'company_name' => 'Acme Traders Pvt Ltd',
            'service' => 'website_development', 'description' => 'We need a catalogue website with about ten pages and an enquiry form.',
            'budget_range' => '25k_100k', 'expected_deadline' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function enquiry(array $over = [], string $source = 'manual', ?int $actor = null): array
    {
        $r = EnquiryService::create($actor ?? $this->admin, $source, $this->form($over));

        return EnquiryPolicy::find($r['public_id']);
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
            $this->fail("Expected a validation error containing '{$needle}'");
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' | ', $e->errors));
        }
    }

    // ---- creation from every source ------------------------------------------------------------------------------------

    public function testManualEnquiryGetsUniqueReferenceInitialStageAndTimeline(): void
    {
        $a = EnquiryService::create($this->admin, 'manual', $this->form());
        $b = EnquiryService::create($this->admin, 'manual', $this->form(['email' => 'other@acme.test', 'phone' => '+91 90000 11111', 'company_name' => 'Other Co']));
        $year = date('Y');
        $this->assertMatchesRegularExpression("/^ENQ-{$year}-\\d{6}$/", $a['reference']);
        $this->assertNotSame($a['reference'], $b['reference']);
        $this->assertSame('ENQ-' . $year . '-000001', $a['reference']);
        $this->assertSame('ENQ-' . $year . '-000002', $b['reference']);

        $e = EnquiryPolicy::find($a['public_id']);
        $this->assertSame('new_enquiry', $e['stage_code']);
        $this->assertSame('manual', $e['source']);
        $this->assertSame('ravi@acme.test', $e['email']);
        $this->assertSame('9876543210', $e['phone_digits']);
        $this->assertSame('acme traders pvt ltd', $e['company_norm']);
        $tl = EnquiryService::timeline((int) $e['id'])['rows'];
        $this->assertSame('created', $tl[0]['type']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'enquiry.created'")['c'] - 1, 'two created, one for each call');
    }

    public function testPublicSourcesAreAnonymousNeedConsentAndNeverAcceptStaffFields(): void
    {
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form()), 'contact you');
        $r = EnquiryService::create(null, 'website_requirements', $this->form(['priority' => 'urgent', 'owner_id' => (string) $this->admin, 'stage' => 'won']), ['consent' => true, 'ip' => '203.0.113.7']);
        $e = EnquiryPolicy::find($r['public_id']);
        $this->assertSame('normal', $e['priority'], 'public submitters cannot choose priority');
        $this->assertNull($e['owner_id']);
        $this->assertSame('new_enquiry', $e['stage_code']);
        $this->assertNotNull($e['consent_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $e['submitted_ip_hash']);
        $this->assertStringNotContainsString('203.0.113.7', (string) json_encode($e), 'raw IP is never stored');

        $this->expectException(\InvalidArgumentException::class);
        EnquiryService::create($this->admin, 'contact_form', $this->form(), ['consent' => true]);
    }

    public function testEachPublicSourceHasItsOwnRequiredFields(): void
    {
        $meta = ['consent' => true];
        $c = EnquiryService::create(null, 'contact_form', ['contact_name' => 'Asha', 'email' => 'asha@x.test', 'description' => 'Hello, can you help me with a website?'], $meta);
        $this->assertSame('other', EnquiryPolicy::find($c['public_id'])['service'], 'contact form needs no service');

        $this->expectInvalid(fn () => EnquiryService::create(null, 'appointment_request', ['contact_name' => 'Asha', 'email' => 'a@x.test', 'description' => 'Discuss a video'], $meta), 'phone');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'appointment_request', ['contact_name' => 'Asha', 'email' => 'a@x.test', 'phone' => '+91 90000 22222', 'description' => 'Discuss a video'], $meta), 'Preferred date');
        $a = EnquiryService::create(null, 'appointment_request', ['contact_name' => 'Asha', 'email' => 'a@x.test', 'phone' => '+91 90000 22222', 'preferred_slot' => 'Tuesday 4pm IST', 'description' => 'Discuss a video'], $meta);
        $this->assertSame('Tuesday 4pm IST', EnquiryPolicy::find($a['public_id'])['preferred_slot']);

        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['description' => 'too short']), $meta), 'Project description');
    }

    public function testValidationRejectsBadInputWithClearMessages(): void
    {
        $m = ['consent' => true];
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['email' => 'nope']), $m), 'valid email');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['contact_name' => '']), $m), 'Name is required');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['contact_name' => '<script>alert(1)</script>']), $m), 'must not contain');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['phone' => 'call me']), $m), 'valid phone');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['service' => 'hacking']), $m), 'valid service');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['budget_range' => 'unlimited']), $m), 'budget range');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['expected_deadline' => '2020-01-01']), $m), 'past');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['expected_deadline' => '2026-02-30']), $m), 'valid date');
        $this->expectInvalid(fn () => EnquiryService::create(null, 'website_requirements', $this->form(['description' => str_repeat('x', 5001)]), $m), 'Project description');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'nothing is stored for invalid submissions');
    }

    public function testControlCharactersAreStrippedAndHtmlIsStoredInertForEscapingOnOutput(): void
    {
        $e = $this->enquiry(['contact_name' => "Ravi\x00 Kumar\x07", 'description' => "Line1\r\nLine2 & <b>bold</b> \x1B[31m"]);
        $this->assertSame('Ravi Kumar', $e['contact_name']);
        $this->assertStringNotContainsString("\x1B", $e['description']);
        $this->assertStringContainsString("Line1\nLine2", $e['description']);
        $this->assertStringContainsString('<b>bold</b>', $e['description'], 'text bodies keep markup characters; templates escape on output');
    }

    public function testManualAndReferralCreationNeedTheirPermissions(): void
    {
        $this->expect403(fn () => EnquiryService::create($this->support, 'manual', $this->form()), 'support cannot create enquiries');
        $this->expect403(fn () => EnquiryService::create($this->partner, 'manual', $this->form()), 'partner cannot create manual enquiries');
        $this->expect403(fn () => EnquiryService::create($this->manager, 'referral_partner', $this->form()), 'only partners submit referrals');
        $r = EnquiryService::create($this->partner, 'referral_partner', $this->form(['priority' => 'urgent']));
        $e = EnquiryPolicy::find($r['public_id']);
        $this->assertSame($this->partner, (int) $e['referral_partner_id']);
        $this->assertSame('normal', $e['priority'], 'partners cannot set priority');
    }

    // ---- scoping --------------------------------------------------------------------------------------------------------

    public function testVisibilityByOwnerAdminAndReferralPartner(): void
    {
        $mine = $this->enquiry(['email' => 'a@x.test', 'phone' => '+91 90000 00001']);
        $other = $this->enquiry(['email' => 'b@x.test', 'phone' => '+91 90000 00002', 'company_name' => 'Zed']);
        $viaPartner = EnquiryPolicy::find(EnquiryService::create($this->partner, 'referral_partner', $this->form(['email' => 'c@x.test', 'phone' => '+91 90000 00003', 'company_name' => 'Yak']))['public_id']);
        EnquiryService::assign($this->admin, $mine, $this->manager);

        $this->assertSame('assigned', EnquiryPolicy::level($this->manager, EnquiryPolicy::find($mine['public_id'])));
        $this->assertNull(EnquiryPolicy::level($this->manager, $other), 'manager sees only owned enquiries');
        $this->assertNull(EnquiryPolicy::level($this->manager2, EnquiryPolicy::find($mine['public_id'])));
        $this->assertSame('all', EnquiryPolicy::level($this->admin, $other));
        $this->assertSame('own', EnquiryPolicy::level($this->partner, $viaPartner));
        $this->assertNull(EnquiryPolicy::level($this->partner2, $viaPartner), 'other partners cannot see it');
        $this->assertNull(EnquiryPolicy::level($this->partner, $other), 'partner cannot see unrelated enquiries');
        $this->assertNull(EnquiryPolicy::level($this->support, $mine));
    }

    public function testPartnerCannotUseStaffAbilitiesOnTheirOwnSubmission(): void
    {
        $e = EnquiryPolicy::find(EnquiryService::create($this->partner, 'referral_partner', $this->form())['public_id']);
        $this->expect403(fn () => EnquiryService::addNote($this->partner, $e, 'sneaky note'), 'notes');
        $this->expect403(fn () => EnquiryService::moveStage($this->partner, $e, 'contacted'), 'stage');
        $this->expect403(fn () => EnquiryService::update($this->partner, $e, $this->form()), 'edit');
        $this->expect403(fn () => EnquiryService::assign($this->partner, $e, $this->partner), 'assign');
    }

    public function testArchivedEnquiriesAreHiddenFromNonAdmins(): void
    {
        $e = $this->enquiry();
        EnquiryService::assign($this->admin, $e, $this->manager);
        $e = EnquiryPolicy::find($e['public_id']);
        $this->assertTrue(EnquiryPolicy::canView($this->manager, $e));
        EnquiryService::setArchived($this->admin, $e, true);
        $e = EnquiryPolicy::find($e['public_id']);
        $this->assertFalse(EnquiryPolicy::canView($this->manager, $e));
        $this->assertTrue(EnquiryPolicy::canView($this->admin, $e));
        $this->expect403(fn () => EnquiryService::setArchived($this->manager, $e, false), 'manager cannot restore');
    }

    // ---- assignment -----------------------------------------------------------------------------------------------------

    public function testOnlyAuthorizedAdminsAssignAndOnlyToEligibleStaff(): void
    {
        $e = $this->enquiry();
        $this->expect403(fn () => EnquiryService::assign($this->manager, $e, $this->manager), 'managers cannot assign');
        $this->expectInvalid(fn () => EnquiryService::assign($this->admin, $e, $this->partner), 'cannot own');
        $this->expectInvalid(fn () => EnquiryService::assign($this->admin, $e, $this->support), 'cannot own');
        EnquiryService::assign($this->admin, $e, $this->manager);
        $this->assertSame($this->manager, (int) EnquiryPolicy::find($e['public_id'])['owner_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'enquiry.assigned'")['c']);
        EnquiryService::assign($this->admin, EnquiryPolicy::find($e['public_id']), null);
        $this->assertNull(EnquiryPolicy::find($e['public_id'])['owner_id']);
    }

    // ---- pipeline -------------------------------------------------------------------------------------------------------

    public function testStageMovesAreRecordedInTheActivityHistory(): void
    {
        $e = $this->enquiry();
        EnquiryService::moveStage($this->admin, $e, 'contacted');
        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'requirement_review');
        $tl = EnquiryService::timeline((int) $e['id'])['rows'];
        $this->assertSame('stage_changed', $tl[0]['type']);
        $this->assertStringContainsString('Contacted → Requirement Review', $tl[0]['summary']);
        $this->assertSame($this->admin, (int) $tl[0]['actor_id']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'enquiry.stage_changed'")['c']);
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'requirement_review'), 'already');
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'nonsense'), 'valid stage');
    }

    public function testLostRequiresAReasonWonRequiresExplicitConfirmationAndPermission(): void
    {
        $e = $this->enquiry();
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, $e, 'lost'), 'Reason');
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, $e, 'lost', 'x'), 'Reason');
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, $e, 'won'), 'confirmation');
        $this->assertSame('new_enquiry', EnquiryPolicy::find($e['public_id'])['stage_code'], 'nothing changed');

        EnquiryService::moveStage($this->admin, $e, 'lost', 'Budget too small');
        $lost = EnquiryPolicy::find($e['public_id']);
        $this->assertSame('lost', $lost['stage_code']);
        $this->assertSame('Budget too small', $lost['lost_reason']);
        $this->assertNotNull($lost['lost_at']);
    }

    public function testNoAutomaticWinAndNoSkippingTheReopenStep(): void
    {
        $e = $this->enquiry();
        EnquiryService::moveStage($this->admin, $e, 'quote_in_preparation');
        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'quote_sent');
        // an external "quotation sent/approved" event may add a note but never changes the stage
        EnquiryService::recordExternalEvent((int) $e['id'], 'Quotation Q-1 sent to client');
        EnquiryService::recordExternalEvent((int) $e['id'], 'Quotation Q-1 accepted by client');
        $this->assertSame('quote_sent', EnquiryPolicy::find($e['public_id'])['stage_code'], 'a quotation event must not mark the enquiry Won');
        $this->assertNull(EnquiryPolicy::find($e['public_id'])['won_at']);

        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'won', null, true);
        $won = EnquiryPolicy::find($e['public_id']);
        $this->assertNotNull($won['won_at']);
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, $won, 'lost', 'changed mind'), 'Reopen');
        EnquiryService::moveStage($this->admin, $won, 'negotiation'); // admin holds enquiries.reopen
        $this->assertNull(EnquiryPolicy::find($e['public_id'])['won_at'], 'won marker cleared on reopen');
    }

    public function testManagerCanMoveOwnedEnquiriesButCannotReopenAndOnlyMarksWonWithConfirmation(): void
    {
        $e = $this->enquiry();
        EnquiryService::assign($this->admin, $e, $this->manager);
        $e = EnquiryPolicy::find($e['public_id']);
        EnquiryService::moveStage($this->manager, $e, 'contacted');
        EnquiryService::moveStage($this->manager, EnquiryPolicy::find($e['public_id']), 'won', null, true);
        $won = EnquiryPolicy::find($e['public_id']);
        $this->expect403(fn () => EnquiryService::moveStage($this->manager, $won, 'contacted'), 'reopening needs enquiries.reopen');

        $mine = $this->enquiry(['email' => 'm2@x.test', 'phone' => '+91 90000 00009', 'company_name' => 'Quill Co']);
        $this->expect403(fn () => EnquiryService::moveStage($this->manager, $mine, 'contacted'), 'not their enquiry');
        $this->expect403(fn () => EnquiryService::moveStage($this->manager2, $e, 'negotiation'), 'another manager');
    }

    public function testCrmStagesAreConfigurableWithGuardrails(): void
    {
        $stages = CrmStageService::all();
        $this->assertCount(8, $stages);
        $this->assertSame(['New Enquiry', 'Contacted', 'Requirement Review', 'Quote in Preparation', 'Quote Sent', 'Negotiation', 'Won', 'Lost'], array_column($stages, 'name'));

        $rows = [];
        foreach ($stages as $s) {
            $rows[(int) $s['id']] = ['name' => $s['name'], 'position' => $s['position'], 'active' => $s['is_active']];
        }
        $contacted = (int) CrmStageService::byCode('contacted')['id'];
        $rows[$contacted]['name'] = 'Initial Contact Made';
        CrmStageService::update($this->owner, $rows);
        $this->assertSame('Initial Contact Made', CrmStageService::byCode('contacted')['name']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'crm.stages_changed'")['c']);

        $this->expect403(fn () => CrmStageService::update($this->manager, $rows), 'manager cannot configure');
        $won = (int) CrmStageService::byCode('won')['id'];
        $bad = $rows;
        $bad[$won]['active'] = 0;
        $this->expectInvalid(fn () => CrmStageService::update($this->owner, $bad), 'cannot be deactivated');
        $dup = $rows;
        $dup[$contacted]['position'] = $rows[(int) CrmStageService::byCode('negotiation')['id']]['position'];
        $this->expectInvalid(fn () => CrmStageService::update($this->owner, $dup), 'same position');

        $e = $this->enquiry();
        $inUse = $rows;
        $inUse[(int) CrmStageService::byCode('new_enquiry')['id']]['active'] = 0;
        $this->expectInvalid(fn () => CrmStageService::update($this->owner, $inUse), 'still has');
    }

    // ---- duplicates -----------------------------------------------------------------------------------------------------

    public function testDuplicatesAreFlaggedByEmailOrPhoneNeverMergedAndNotByNameAlone(): void
    {
        $first = $this->enquiry(['contact_name' => 'Ravi Kumar', 'email' => 'ravi@acme.test', 'phone' => '+91 98765 43210', 'company_name' => 'Acme Traders']);
        $sameEmail = $this->enquiry(['contact_name' => 'R. Kumar', 'email' => 'RAVI@acme.test', 'phone' => '+91 90000 99999', 'company_name' => 'Different']);
        $samePhone = $this->enquiry(['contact_name' => 'Someone', 'email' => 'x@y.test', 'phone' => '098765-43210', 'company_name' => 'Other']);
        $sameNameOnly = $this->enquiry(['contact_name' => 'Ravi Kumar', 'email' => 'ravi2@else.test', 'phone' => '+91 80000 11111', 'company_name' => 'Unrelated']);

        $this->assertSame('suspected', $sameEmail['duplicate_status']);
        $this->assertSame((int) $first['id'], (int) $sameEmail['possible_duplicate_of']);
        $this->assertSame('suspected', $samePhone['duplicate_status']);
        $this->assertSame('none', $sameNameOnly['duplicate_status'], 'the same name alone is not a duplicate signal');
        $this->assertSame(4, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'flagged enquiries are kept, never merged or dropped');

        $weak = $this->enquiry(['email' => 'new@person.test', 'phone' => '+91 70000 11111', 'company_name' => 'ACME  traders!!']);
        $this->assertSame('none', $weak['duplicate_status']);
        $d = EnquiryService::duplicatesFor($this->admin, $weak);
        $this->assertSame([], $d['strong']);
        $this->assertContains((int) $first['id'], array_map('intval', array_column($d['weak'], 'id')), 'same normalised company is a weak hint');
    }

    public function testDuplicateReviewNeverLeaksEnquiriesOutsideTheViewersScope(): void
    {
        $a = $this->enquiry(['email' => 'dup@x.test', 'phone' => '+91 91111 11111']);
        $b = $this->enquiry(['email' => 'dup@x.test', 'phone' => '+91 92222 22222', 'company_name' => 'Zed Co']);
        EnquiryService::assign($this->admin, $b, $this->manager);
        $d = EnquiryService::duplicatesFor($this->manager, EnquiryPolicy::find($b['public_id']));
        $this->assertSame([], $d['strong'], 'the matching enquiry is not the manager\'s to see');
        $this->assertSame(1, $d['hidden'], 'only a count is disclosed');
        $d2 = EnquiryService::duplicatesFor($this->admin, EnquiryPolicy::find($b['public_id']));
        $this->assertCount(1, $d2['strong']);
    }

    public function testConfirmingADuplicateClosesItAsLostWithoutDeletingAnything(): void
    {
        $a = $this->enquiry(['email' => 'dup@x.test', 'phone' => '+91 91111 11111']);
        $b = $this->enquiry(['email' => 'dup@x.test', 'phone' => '+91 92222 22222', 'company_name' => 'Zed Co']);
        EnquiryService::markDuplicate($this->admin, $b, $a['reference']);
        $b2 = EnquiryPolicy::find($b['public_id']);
        $this->assertSame('confirmed', $b2['duplicate_status']);
        $this->assertSame('lost', $b2['stage_code']);
        $this->assertSame('Duplicate of ' . $a['reference'], $b2['lost_reason']);
        $noted = array_values(array_filter(EnquiryService::timeline((int) $a['id'])['rows'], static fn ($r) => $r['type'] === 'duplicate'));
        $this->assertCount(1, $noted, 'the original is told that another enquiry was marked as its duplicate');
        $this->assertStringContainsString($b['reference'], $noted[0]['summary']);
        $this->expectInvalid(fn () => EnquiryService::markDuplicate($this->admin, EnquiryPolicy::find($a['public_id']), $a['reference']), 'another enquiry');
        EnquiryService::dismissDuplicate($this->admin, $a);
    }

    // ---- notes, contact history, follow-ups ----------------------------------------------------------------------------

    public function testNotesContactHistoryAndFollowupsFeedTheTimelineAndNextFollowupDate(): void
    {
        $e = $this->enquiry();
        EnquiryService::addNote($this->admin, $e, 'Prefers WhatsApp in the evening.');
        EnquiryService::addContact($this->admin, $e, 'call', 'Spoke for 10 minutes; wants a quote by Friday.');
        AppTime::freeze('2026-03-10 05:00:00');
        EnquiryService::addFollowup($this->admin, $e, '2026-03-14', 'Send quote');
        EnquiryService::addFollowup($this->admin, $e, '2026-03-12', 'Check requirements');
        $this->assertSame('2026-03-12', EnquiryPolicy::find($e['public_id'])['next_followup_date']);

        $open = EnquiryService::followups((int) $e['id']);
        EnquiryService::completeFollowup($this->admin, $e, (int) $open[0]['id']);
        $this->assertSame('2026-03-14', EnquiryPolicy::find($e['public_id'])['next_followup_date']);
        EnquiryService::completeFollowup($this->admin, $e, (int) $open[1]['id']);
        $this->assertNull(EnquiryPolicy::find($e['public_id'])['next_followup_date']);

        $types = array_column(EnquiryService::timeline((int) $e['id'])['rows'], 'type');
        foreach (['note', 'contact', 'followup'] as $t) {
            $this->assertContains($t, $types);
        }
        $this->expectInvalid(fn () => EnquiryService::addFollowup($this->admin, $e, '2026-03-01', 'in the past'), 'past');
        $this->expectInvalid(fn () => EnquiryService::addContact($this->admin, $e, 'carrier-pigeon', 'x'), 'contact channel');
        $this->expectInvalid(fn () => EnquiryService::addNote($this->admin, $e, ' '), 'Note');
        $this->expect403(fn () => EnquiryService::addNote($this->manager2, $e, 'not mine'), 'notes need access to the enquiry');
        $this->expect403(fn () => EnquiryService::addNote($this->support, $e, 'support has no CRM'), 'no CRM permission');
    }

    // ---- edit -----------------------------------------------------------------------------------------------------------

    public function testEditRecordsChangedFieldsInTimelineButOnlyFieldNamesInAudit(): void
    {
        $e = $this->enquiry();
        EnquiryService::update($this->admin, $e, $this->form(['phone' => '+91 90000 77777', 'priority' => 'high']));
        $row = Database::one("SELECT meta_json, before_json, after_json FROM audit_logs WHERE action = 'enquiry.updated'");
        $this->assertStringContainsString('phone', $row['meta_json']);
        $this->assertStringNotContainsString('90000 77777', $row['meta_json'] . $row['before_json'] . $row['after_json'], 'personal data stays out of the audit log');
        $this->assertSame('high', EnquiryPolicy::find($e['public_id'])['priority']);
        EnquiryService::update($this->admin, EnquiryPolicy::find($e['public_id']), $this->form(['phone' => '+91 90000 77777', 'priority' => 'high']));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'enquiry.updated'")['c'], 'no-op edits are not logged');
    }

    // ---- conversion -----------------------------------------------------------------------------------------------------

    private function wonEnquiry(array $over = []): array
    {
        $e = $this->enquiry($over);
        EnquiryService::moveStage($this->admin, $e, 'won', null, true);

        return EnquiryPolicy::find($e['public_id']);
    }

    public function testOnlyAWonEnquiryConvertsAndOnlyOnceKeepingItsHistory(): void
    {
        $open = $this->enquiry();
        $this->expectInvalid(fn () => EnquiryService::convert($this->admin, $open, []), 'marked Won');

        $e = $this->wonEnquiry(['email' => 'win@x.test', 'phone' => '+91 93333 33333', 'company_name' => 'Win Co', 'expected_deadline' => '2027-01-31']);
        $historyBefore = EnquiryService::timeline((int) $e['id'])['total'];
        $r = EnquiryService::convert($this->admin, $e, ['client' => 'new', 'project_name' => 'Win Co website']);

        $c = Database::one('SELECT * FROM clients WHERE id = ?', [$r['client_id']]);
        $this->assertSame('business', $c['client_type']);
        $this->assertSame('win@x.test', $c['email']);
        $this->assertNull($c['user_id'], 'no portal login is created implicitly');
        $p = Database::one('SELECT * FROM projects WHERE id = ?', [$r['project_id']]);
        $this->assertSame('draft', $p['status'], 'a converted project starts as a Draft');
        $this->assertSame('Win Co website', $p['title']);
        $this->assertSame((int) $e['id'], (int) $p['enquiry_id']);
        $this->assertSame('2027-01-31', $p['target_date']);
        $this->assertMatchesRegularExpression('/^KIS-\d{4}-\d{4}$/', $p['code']);

        $after = EnquiryPolicy::find($e['public_id']);
        $this->assertSame((int) $r['project_id'], (int) $after['converted_project_id']);
        $this->assertSame('won', $after['stage_code'], 'the enquiry keeps its Won stage');
        $this->assertGreaterThan($historyBefore, EnquiryService::timeline((int) $e['id'])['total'], 'history preserved and extended');
        $this->assertSame('created', array_values(array_slice(EnquiryService::timeline((int) $e['id'])['rows'], -1))[0]['type']);

        $this->expectInvalid(fn () => EnquiryService::convert($this->admin, $after, ['client' => 'new']), 'already been converted');
        $this->expectInvalid(fn () => EnquiryService::update($this->admin, $after, $this->form()), 'locked');
        $this->expectInvalid(fn () => EnquiryService::moveStage($this->admin, $after, 'contacted'), 'locked');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM projects')['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients')['c']);
    }

    public function testConversionNeverCreatesDuplicateClientsWithoutExplicitConfirmation(): void
    {
        $existing = $this->makeLooseClient('Ravi Kumar', 'ravi@acme.test', 'Acme Traders', '+91 98765 43210');
        $e = $this->wonEnquiry();
        $this->expectInvalid(fn () => EnquiryService::convert($this->admin, $e, ['client' => 'new']), 'already exists');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM projects')['c'], 'the failed conversion rolled back completely');
        $this->assertNull(EnquiryPolicy::find($e['public_id'])['converted_project_id']);

        // explicit link to the existing record: no new client
        $r = EnquiryService::convert($this->admin, EnquiryPolicy::find($e['public_id']), ['client' => $existing['public_id']]);
        $this->assertSame($existing['id'], $r['client_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients')['c']);
        $this->assertSame($existing['id'], (int) Database::one('SELECT client_id FROM projects WHERE id = ?', [$r['project_id']])['client_id']);
    }

    public function testConfirmedDifferentClientIsAllowedAndConversionNeedsTheRightPermissions(): void
    {
        $this->makeLooseClient('Ravi Kumar', 'ravi@acme.test', 'Acme Traders', '+91 98765 43210');
        $e = $this->wonEnquiry();
        $this->expect403(fn () => EnquiryService::convert($this->manager, $e, ['client' => 'new']), 'managers cannot convert');
        $r = EnquiryService::convert($this->admin, $e, ['client' => 'new', 'confirm_new' => '1']);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM clients')['c']);
        $this->assertGreaterThan(0, $r['project_id']);
    }

    public function testAuditEntriesExistForEveryCrmMutation(): void
    {
        $e = $this->enquiry();
        EnquiryService::update($this->admin, $e, $this->form(['priority' => 'high']));
        EnquiryService::assign($this->admin, EnquiryPolicy::find($e['public_id']), $this->manager);
        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'contacted');
        EnquiryService::addNote($this->admin, EnquiryPolicy::find($e['public_id']), 'note one');
        EnquiryService::addContact($this->admin, EnquiryPolicy::find($e['public_id']), 'email', 'sent intro');
        AppTime::freeze(null);
        EnquiryService::addFollowup($this->admin, EnquiryPolicy::find($e['public_id']), AppTime::todayPlus(2), 'call back');
        EnquiryService::setArchived($this->admin, EnquiryPolicy::find($e['public_id']), true);
        $actions = array_column(Database::all("SELECT DISTINCT action FROM audit_logs WHERE action LIKE 'enquiry.%'"), 'action');
        foreach (['enquiry.created', 'enquiry.updated', 'enquiry.assigned', 'enquiry.stage_changed', 'enquiry.note_added', 'enquiry.contact_logged', 'enquiry.followup_added', 'enquiry.archived'] as $a) {
            $this->assertContains($a, $actions, $a);
        }
        $this->assertTrue(\Vertex\Services\Audit::verify()['ok']);
    }
}
