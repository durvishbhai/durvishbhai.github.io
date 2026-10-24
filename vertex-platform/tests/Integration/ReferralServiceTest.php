<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Audit;
use Vertex\Services\CommissionService;
use Vertex\Services\EnquiryService;
use Vertex\Services\Gate;
use Vertex\Services\Mailer;
use Vertex\Services\PartnerApplicationService;
use Vertex\Services\PartnerService;
use Vertex\Services\ReferralMailService;
use Vertex\Services\ReferralService;

/** Partner applications, referral submission, duplicates, attribution, isolation and the previewed referral emails. */
final class ReferralServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        Mailer::$sent = [];
        Config::set('mail.driver', 'none');
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
        Mailer::$sent = [];
    }

    private function expectInvalid(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    private function expectStatus(callable $fn, int $status): void
    {
        try {
            $fn();
            $this->fail("Expected HTTP {$status}");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    /** @return array<string,mixed> */
    private function appForm(array $over = []): array
    {
        return $over + ['full_name' => 'Anita Applicant', 'email' => 'anita@applicant.test', 'phone' => '+91 98000 12345', 'organization' => 'Anita Media', 'professional_info' => 'I run a marketing community with 5,000 small business owners.',
            'referral_source' => 'Introduced by an existing client at a meetup.', 'agree' => '1'];
    }

    private function openApplications(): void
    {
        PartnerApplicationService::recordTerms($this->admin, ['title' => 'Referral Partner Terms', 'terms' => 'These are the business\'s own signed terms for referral partners.']);
    }

    /** @return array<string,mixed> */
    private function apply(array $over = [], ?int $userId = null, ?array $file = null): array
    {
        $r = PartnerApplicationService::submit($userId, $this->appForm($over), $file, '203.0.113.9', false);

        return $r;
    }

    private function app(string $reference): array
    {
        return PartnerApplicationService::find(Database::one('SELECT public_id FROM partner_applications WHERE reference = ?', [$reference])['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ applications

    public function testApplicationsAreClosedUntilTheBusinessRecordsItsTerms(): void
    {
        $this->expectInvalid(fn () => $this->apply(), 'not open');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
        foreach ([$this->manager, $this->finance, $this->partner, $this->client] as $u) {
            $this->expectStatus(fn () => PartnerApplicationService::recordTerms($u, ['title' => 'Terms', 'terms' => 'Some terms of the programme, long enough.']), 403);
        }
        $v = PartnerApplicationService::recordTerms($this->admin, ['title' => 'Referral Partner Terms', 'terms' => 'These are the business\'s own signed terms for referral partners.']);
        $this->assertSame(1, $v);
        $this->assertSame(2, PartnerApplicationService::recordTerms($this->admin, ['title' => 'Revised', 'terms' => 'Revised wording for the referral programme terms.']));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM partner_terms WHERE current_flag = 1')['c']);
    }

    public function testAnApplicationIsReceivedWithAReferenceAndNeverCreatesOrActivatesAnAccount(): void
    {
        $this->openApplications();
        $users = (int) Database::one('SELECT COUNT(*) c FROM users')['c'];
        $res = $this->apply();
        $this->assertFalse($res['duplicate']);
        $this->assertMatchesRegularExpression('/^PAP-\d{4}-\d{5}$/', $res['reference']);
        $a = $this->app($res['reference']);
        $this->assertSame(['received', 'Anita Applicant', 'anita@applicant.test', 1], [$a['status'], $a['full_name'], $a['email'], (int) $a['terms_version']]);
        $this->assertNotNull($a['terms_accepted_at']);
        $this->assertNull($a['user_id']);
        $this->assertSame($users, (int) Database::one('SELECT COUNT(*) c FROM users')['c'], 'no login is created by an application');
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM referral_partners')['c'], 'only the two fixture partners exist: no partner record was created');
        $this->assertNull((new UserRepository())->findByEmail('anita@applicant.test'));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'partner.application_received'", [$this->admin])['c']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type LIKE 'partner.%' AND user_id IN (?, ?, ?)", [$this->client, $this->partner, $this->dev])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'partner.application_received'")['c']);
        $this->assertStringNotContainsString('anita@applicant.test', (string) Database::one("SELECT after_json FROM audit_logs WHERE action = 'partner.application_received'")['after_json'], 'no personal data in the audit log');
        $this->assertSame([], Mailer::$sent, 'no automatic email');
    }

    public function testApplicationInputIsValidated(): void
    {
        $this->openApplications();
        foreach ([['full_name' => ''], ['email' => 'not-an-email'], ['phone' => 'abc'], ['phone' => ''], ['professional_info' => 'short'], ['referral_source' => ''], ['agree' => ''], ['agree' => '0'], ['full_name' => 'A<b>'], ['organization' => str_repeat('x', 200)]] as $bad) {
            $this->expectInvalid(fn () => $this->apply($bad));
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
    }

    public function testDuplicateApplicationsAreSilentNoOpsAndARejectedApplicantMayReapply(): void
    {
        $this->openApplications();
        $first = $this->apply();
        $again = $this->apply(['full_name' => 'Anita A.']);
        $this->assertTrue($again['duplicate']);
        $this->assertNull($again['reference'], 'the form does not reveal anything about the earlier application');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
        $this->assertTrue($this->apply(['email' => 'ANITA@Applicant.TEST'])['duplicate'], 'e-mail matching ignores case');
        PartnerApplicationService::reject($this->admin, $this->app($first['reference']), 'We are not recruiting partners in that sector at the moment.');
        $second = $this->apply();
        $this->assertFalse($second['duplicate'], 'after a rejection the person may apply again');
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
        // an existing partner's address is never accepted as a new application
        $this->assertTrue($this->apply(['email' => 'partner@x.test'])['duplicate']);
    }

    public function testASignedInApplicantIsLinkedAndCannotApplyForSomeoneElsesAddress(): void
    {
        $this->openApplications();
        $res = $this->apply(['email' => 'someone-else@x.test', 'full_name' => 'Carla Client'], $this->client);
        $a = $this->app($res['reference']);
        $this->assertSame($this->client, (int) $a['user_id']);
        $this->assertSame('alpha@client.test', $a['email'], 'the address comes from the signed-in account, not from the form');
    }

    public function testAnApplicationMayCarryOneSafeSupportingFile(): void
    {
        $this->openApplications();
        $this->expectInvalid(fn () => $this->apply([], null, $this->tmp('cv.php', '<?php echo 1;')));
        $this->expectInvalid(fn () => $this->apply([], null, $this->tmp('cv.pdf', 'this is not a pdf at all')));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c'], 'a refused file leaves nothing behind');
        $res = $this->apply([], null, $this->png('profile.png'));
        $a = $this->app($res['reference']);
        $this->assertSame('profile.png', $a['file_name']);
    }

    public function testOnlyAdministratorsDecideAndTheDecisionRules(): void
    {
        $this->openApplications();
        $res = $this->apply();
        $a = $this->app($res['reference']);
        foreach ([$this->manager, $this->finance, $this->support, $this->dev, $this->partner, $this->client] as $u) {
            $this->expectStatus(fn () => PartnerApplicationService::approve($u, $a), 403);
            $this->expectStatus(fn () => PartnerApplicationService::reject($u, $a, 'A reason that is long enough.'), 403);
            $this->expectStatus(fn () => PartnerApplicationService::requireView($u, $a['public_id']), 404);
            $this->assertSame(0, PartnerApplicationService::paginate($u, [])['total'], 'only reviewers list applications');
        }
        $this->expectInvalid(fn () => PartnerApplicationService::reject($this->admin, $a, ''), 'required');
        $this->expectInvalid(fn () => PartnerApplicationService::reject($this->admin, $a, 'too short'), 'must be');
        $this->assertSame('received', $this->app($res['reference'])['status']);
        PartnerApplicationService::markUnderReview($this->admin, $a);
        $this->assertSame('under_review', $this->app($res['reference'])['status']);
        $this->expectInvalid(fn () => PartnerApplicationService::markUnderReview($this->admin, $a), 'newly received');
        PartnerApplicationService::reject($this->admin, $this->app($res['reference']), 'We are not recruiting partners in that sector at the moment.');
        $r = $this->app($res['reference']);
        $this->assertSame(['rejected', $this->admin], [$r['status'], (int) $r['decided_by']]);
        $this->assertStringContainsString('not recruiting', $r['decision_reason']);
        $this->expectInvalid(fn () => PartnerApplicationService::approve($this->admin, $r), 'already been decided');
        $this->expectInvalid(fn () => PartnerApplicationService::reject($this->admin, $r, 'A second decision should not be possible.'), 'already been decided');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM referral_partners WHERE user_id NOT IN (?, ?)', [$this->partner, $this->partner2])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'partner.application_rejected' AND actor_id = ?", [$this->admin])['c']);
    }

    public function testApprovalCreatesAPendingAccountThatTheApplicantActivatesThemselves(): void
    {
        $this->openApplications();
        Config::set('mail.driver', 'none');
        $res = $this->apply();
        $out = PartnerApplicationService::approve($this->admin, $this->app($res['reference']), 'Looks like a good fit.');
        $this->assertFalse($out['linked_existing']);
        $u = (new UserRepository())->findByEmail('anita@applicant.test');
        $this->assertSame('pending', $u['status'], 'the account stays pending until the applicant uses their invitation');
        $this->assertSame(['referral_partner'], array_column((new UserRepository())->rolesOf((int) $u['id']), 'slug'));
        $p = Database::one('SELECT * FROM referral_partners WHERE user_id = ?', [$u['id']]);
        $this->assertMatchesRegularExpression('/^PRT-\d{4}-\d{4}$/', $p['reference']);
        $this->assertSame('active', $p['status']);
        $a = $this->app($res['reference']);
        $this->assertSame(['approved', (int) $p['id']], [$a['status'], (int) $a['partner_id']]);
        $this->assertFalse($out['invite_sent'], 'no mail driver: the invitation email was NOT sent and nobody is told it was');
        $this->assertNull(PartnerService::forUser((int) $u['id']), 'a pending account is not an active partner');
        // an invitation can be sent later through the existing admin screens
        Config::set('app.url', 'https://vis.test');
        Config::set('app.env', 'testing');
        Config::set('mail.driver', 'array');
        $this->assertTrue(\Vertex\Services\UserService::adminResendInvite($this->owner, (int) $u['id']));
        $this->assertStringContainsString('/invite/', Mailer::$sent[0]['body']);
    }

    public function testApprovalNeverLinksAnExistingAccountJustBecauseTheEmailMatches(): void
    {
        $this->openApplications();
        $res = $this->apply(['email' => 'alpha@client.test']);
        $this->expectInvalid(fn () => PartnerApplicationService::approve($this->admin, $this->app($res['reference'])), 'already exists');
        $this->assertNotContains('referral_partner', array_column((new UserRepository())->rolesOf($this->client), 'slug'));
        // the account owner applies while signed in: approval adds the role to THAT account
        PartnerApplicationService::reject($this->admin, $this->app($res['reference']), 'Please apply from your own signed-in account.');
        $res2 = $this->apply(['email' => 'ignored@x.test'], $this->client);
        $out = PartnerApplicationService::approve($this->admin, $this->app($res2['reference']));
        $this->assertTrue($out['linked_existing']);
        $this->assertContains('referral_partner', array_column((new UserRepository())->rolesOf($this->client), 'slug'));
        $this->assertContains('client', array_column((new UserRepository())->rolesOf($this->client), 'slug'), 'the client role is kept');
        Gate::flush();
        $this->assertNotNull(PartnerService::forUser($this->client));
        $this->assertTrue(Audit::verify()['ok']);
    }

    public function testAnAdministratorCannotPromoteAnAccountAtOrAboveTheirOwnLevelThroughAnApplication(): void
    {
        $this->openApplications();
        $res = $this->apply(['email' => 'x@x.test'], $this->manager2);
        $this->assertSame('received', $this->app($res['reference'])['status']);
        // manager level 60 < administrator 90, so it works; the owner (100) can never be touched by an administrator
        $ownerRes = PartnerApplicationService::submit($this->owner, $this->appForm(), null, '1.2.3.4', false);
        $this->expectStatus(fn () => PartnerApplicationService::approve($this->admin, $this->app($ownerRes['reference'])), 403);
    }

    // ------------------------------------------------------------------------------------------------ referral submission and isolation

    /** @return array<string,mixed> */
    private function refForm(array $over = []): array
    {
        return $over + ['prospect_name' => 'Rhea Prospect', 'email' => 'rhea@prospect.test', 'phone' => '+91 90000 11122', 'business_name' => 'Rhea Studio', 'service' => 'website_development',
            'description' => 'Needs a website for her design studio with a portfolio.', 'estimated_budget' => '75000', 'notes' => 'Met at an event.', 'token' => bin2hex(random_bytes(16))];
    }

    private function submitRef(int $userId, array $over = []): array
    {
        $res = ReferralService::submit($userId, $this->refForm($over));

        return $this->refRow($res['public_id']);
    }

    public function testAnApprovedPartnerSubmitsAReferralWithAllTheFields(): void
    {
        $r = $this->submitRef($this->partner);
        $this->assertMatchesRegularExpression('/^REF-\d{4}-\d{5}$/', $r['reference']);
        $this->assertSame(['received', 'pending', 'none'], [$r['status'], $r['attribution'], $r['duplicate_status']]);
        $this->assertSame(7500000, (int) $r['estimated_budget_minor']);
        $this->assertSame((int) $this->partnerRow['id'], (int) $r['partner_id']);
        $this->assertSame('Rhea Studio', $r['business_name']);
        $this->assertNotNull($r['created_at']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'referral.received'", [$this->admin])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'referral.created'")['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM clients WHERE contact_name = ?', ['Rhea Prospect'])['c'], 'a referral never creates a client record');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
    }

    public function testOnlyActivePartnersCanSubmitAndInputIsValidated(): void
    {
        foreach ([$this->client, $this->admin, $this->manager, $this->dev, $this->finance] as $u) {
            $this->expectStatus(fn () => ReferralService::submit($u, $this->refForm()), 404);
        }
        $this->expectInvalid(fn () => ReferralService::submit($this->partner, $this->refForm(['email' => '', 'phone' => ''])), 'at least');
        foreach ([['prospect_name' => ''], ['email' => 'nope'], ['phone' => 'abc'], ['service' => 'magic'], ['description' => 'short'], ['estimated_budget' => 'lots'], ['business_name' => 'A<b>'], ['notes' => str_repeat('x', 3000)]] as $bad) {
            $this->expectInvalid(fn () => ReferralService::submit($this->partner, $bad + $this->refForm()));
        }
        PartnerService::setSuspended($this->admin, $this->partnerRow, true, 'Breach of the programme terms.');
        $this->expectStatus(fn () => ReferralService::submit($this->partner, $this->refForm()), 403);
        PartnerService::setSuspended($this->admin, $this->partnerRow, false, 'Resolved after review.');
        $this->assertTrue(ReferralService::submit($this->partner, $this->refForm())['created']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM partner_referrals')['c']);
    }

    public function testResubmittingTheSameFormOrTheSameProspectDoesNotCreateASecondReferral(): void
    {
        $in = $this->refForm();
        $a = ReferralService::submit($this->partner, $in);
        $b = ReferralService::submit($this->partner, $in);
        $this->assertFalse($b['created']);
        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM notifications WHERE event_type = ? AND user_id = ?', ['referral.received', $this->admin])['c']);
        // a new form for the same prospect while the first is open
        $this->expectInvalid(fn () => ReferralService::submit($this->partner, $this->refForm()), 'already referred');
        $this->expectInvalid(fn () => ReferralService::submit($this->partner, $this->refForm(['email' => 'other@x.test'])), 'already referred'); // same phone
        // once the first is rejected the partner may refer them again
        $r = $this->refRow($a['public_id']);
        ReferralService::setStatus($this->admin, $r, 'rejected', 'This contact asked us not to be approached by partners.');
        $this->assertTrue(ReferralService::submit($this->partner, $this->refForm())['created']);
    }

    public function testASecondPartnerReferringTheSameContactIsFlaggedForAdminReviewNotMergedOrCredited(): void
    {
        $clients = (int) Database::one('SELECT COUNT(*) c FROM clients')['c'];
        $a = $this->submitRef($this->partner);
        $b = $this->submitRef($this->partner2, ['prospect_name' => 'R. Prospect', 'business_name' => '']);
        $a = $this->refRow($a['public_id']);
        $this->assertSame(['conflict', 'suspected'], [$b['attribution'], $b['duplicate_status']]);
        $this->assertSame((int) $a['id'], (int) $b['duplicate_of_id']);
        $this->assertSame('conflict', $a['attribution'], 'the earlier claim is flagged too so an admin sees both');
        $this->assertSame($clients, (int) Database::one('SELECT COUNT(*) c FROM clients')['c'], 'nothing is merged or created');
        $this->assertSame(2, ReferralService::paginate($this->admin, ['duplicates' => true])['total']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type IN ('referral.received', 'referral.received_duplicate') AND user_id = ?", [$this->admin])['c']);
        // neither partner can tell the other exists
        foreach ([[$this->partner, $a], [$this->partner2, $b]] as [$u, $row]) {
            $json = json_encode([ReferralService::partnerView($row), ReferralService::historyFor($u, $row, false), ReferralService::paginateOwn($u, [])]);
            foreach (['duplicate', 'conflict', 'Possible', 'another partner', 'partner2', 'R. Prospect'] as $needle) {
                if ($row === $b && $needle === 'R. Prospect') {
                    continue;
                }
                $this->assertStringNotContainsStringIgnoringCase($needle, $json, "partner view must not contain {$needle}");
            }
        }
        // only one can be credited
        ReferralService::decideAttribution($this->admin, $a, 'credited', 'The first partner introduced the contact two weeks earlier.');
        $this->expectInvalid(fn () => ReferralService::decideAttribution($this->admin, $this->refRow($b['public_id']), 'credited', 'Also trying to credit the second claim.'), 'already credited');
        ReferralService::decideAttribution($this->admin, $this->refRow($b['public_id']), 'duplicate', 'Duplicate of the earlier claim from another partner.');
        $b2 = $this->refRow($b['public_id']);
        $this->assertSame(['duplicate', 'confirmed'], [$b2['attribution'], $b2['duplicate_status']]);
        $this->assertSame(0, ReferralService::paginate($this->admin, ['duplicates' => true, 'attribution' => 'conflict'])['total']);
        $h = array_column(Database::all("SELECT event FROM referral_history WHERE referral_id = ? ORDER BY id", [$b['id']]), 'event');
        $this->assertSame(['created', 'duplicate', 'attribution'], $h);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'referral.attribution_changed'")['c']);
    }

    public function testAProspectMatchingACrmRecordIsFlaggedNotMerged(): void
    {
        $e = EnquiryService::create($this->admin, 'manual', ['contact_name' => 'Rhea P', 'email' => 'rhea@prospect.test', 'phone' => '+91 90000 11122', 'company_name' => '', 'service' => 'other', 'description' => 'An earlier direct enquiry from the same person.', 'budget_range' => '', 'expected_deadline' => '']);
        $r = $this->submitRef($this->partner);
        $this->assertSame(['pending', 'suspected'], [$r['attribution'], $r['duplicate_status']], 'known to the CRM: an admin decides whether the partner is credited');
        $this->assertStringContainsString('CRM enquiry ' . $e['reference'], (string) Database::one("SELECT note FROM referral_history WHERE referral_id = ? AND event = 'duplicate'", [$r['id']])['note']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
        $this->assertNull($r['enquiry_id']);
        ReferralService::decideAttribution($this->admin, $r, 'denied', 'The contact had already enquired directly before the referral.');
        $this->assertSame('denied', $this->refRow($r['public_id'])['attribution']);
        $this->assertSame('dismissed', $this->refRow($r['public_id'])['duplicate_status']);
    }

    public function testPartnersSeeOnlyTheirOwnReferralsAndNothingInternal(): void
    {
        $a = $this->submitRef($this->partner, ['prospect_name' => 'Alpha Prospect', 'email' => 'a@p.test', 'phone' => '']);
        $b = $this->submitRef($this->partner2, ['prospect_name' => 'Bravo Secret Prospect', 'email' => 'b@p.test', 'phone' => '+91 91111 22233']);
        ReferralService::addNote($this->admin, $a, 'INTERNAL-NOTE-MARKER the prospect is difficult');
        ReferralService::setStatus($this->admin, $a, 'under_review', '', 'INTERNAL-STATUS-NOTE');
        $a = $this->refRow($a['public_id']);
        ReferralService::decideAttribution($this->admin, $a, 'credited', 'INTERNAL-ATTRIBUTION-REASON is confidential');
        $a = $this->refRow($a['public_id']);
        $this->assertSame(1, ReferralService::paginateOwn($this->partner, [])['total']);
        $this->assertSame(1, ReferralService::paginateOwn($this->partner2, [])['total']);
        $this->assertSame($a['public_id'], ReferralService::requireOwn($this->partner, $a['public_id'])['public_id']);
        $this->expectStatus(fn () => ReferralService::requireOwn($this->partner, $b['public_id']), 404);
        $this->expectStatus(fn () => ReferralService::requireOwn($this->partner2, $a['public_id']), 404);
        $this->expectStatus(fn () => ReferralService::requireOwn($this->client, $a['public_id']), 404);
        $this->expectStatus(fn () => ReferralService::requireOwn($this->dev, $a['public_id']), 404);
        $this->expectStatus(fn () => ReferralService::requireOwn($this->manager, $a['public_id']), 404);
        foreach ([$this->partner, $this->partner2, $this->client, $this->dev, $this->manager, $this->finance] as $u) {
            $this->expectStatus(fn () => ReferralService::requireStaff($u, $a['public_id']), 404);
            $this->assertSame(0, ReferralService::paginate($u, [])['total']);
        }
        $json = json_encode([ReferralService::partnerView($a), ReferralService::historyFor($this->partner, $a, false), ReferralService::paginateOwn($this->partner, []), ReferralService::countsOwn($this->partner)]);
        foreach (['INTERNAL-', 'Bravo Secret', 'b@p.test', 'attribution_reason', 'duplicate', 'partner_id', 'enquiry_id', 'project_id', 'admin@x.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "partner data must not contain {$needle}");
        }
        $this->assertSame('in_review' === ReferralService::partnerView($this->refRow($b['public_id']))['credit'] ? 1 : 0, 1);
        $this->assertSame('credited', ReferralService::partnerView($a)['credit']);
        // staff see everything, notes included
        $this->assertStringContainsString('INTERNAL-NOTE-MARKER', json_encode(ReferralService::notes($this->admin, $a)));
        $this->assertSame([], ReferralService::notes($this->partner, $a));
    }

    public function testReferralStatusWorkflowRulesAndPartnerVisibleReasons(): void
    {
        $r = $this->submitRef($this->partner);
        $id = $r['public_id'];
        foreach ([$this->partner, $this->client, $this->dev, $this->manager, $this->finance] as $u) {
            $this->expectStatus(fn () => ReferralService::setStatus($u, $this->refRow($id), 'under_review'), 403);
        }
        foreach (['contacted', 'qualified', 'quote_sent', 'approved', 'not_converted', 'converted'] as $bad) {
            $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $this->refRow($id), $bad));
        }
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $this->refRow($id), 'rejected', ''), 'required');
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $this->refRow($id), 'rejected', 'short'), 'must be');
        foreach (['under_review', 'approved', 'contacted', 'qualified', 'quote_sent'] as $to) {
            ReferralService::setStatus($this->admin, $this->refRow($id), $to);
            $this->assertSame($to, $this->refRow($id)['status']);
        }
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $this->refRow($id), 'not_converted'), 'required');
        ReferralService::setStatus($this->admin, $this->refRow($id), 'not_converted', 'The prospect decided to postpone the project to next year.', 'Follow up in January.');
        $row = $this->refRow($id);
        $this->assertSame('not_converted', $row['status']);
        $this->assertSame('The prospect decided to postpone the project to next year.', ReferralService::partnerView($row)['reason']);
        $hist = ReferralService::historyFor($this->partner, $row, false);
        $this->assertSame(['received', 'under_review', 'approved', 'contacted', 'qualified', 'quote_sent', 'not_converted'], array_column($hist, 'to_value'));
        $this->assertSame(6, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'referral.status_changed'", [$this->partner])['c'], 'one in-app notification per change, none duplicated');
        $this->assertSame(6, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'referral.status_changed'")['c']);
        // stale page
        $stale = $this->refRow($id);
        ReferralService::setStatus($this->admin, $stale, 'contacted');
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $stale, 'contacted'), 'changed by someone else');
    }

    public function testConversionNeedsAnExistingActiveProjectAndOnlyOneCreditedReferralPerProject(): void
    {
        $a = $this->creditedReferral();
        $this->assertSame('converted', $a['status']);
        $this->assertSame((int) $this->alpha['id'], (int) $a['credit_project_id']);
        $this->assertSame((int) $this->alpha['id'], (int) $a['project_id']);
        $b = $this->submitRef($this->partner2, ['email' => 'other@prospect.test', 'phone' => '+91 92222 33344']);
        foreach (['under_review', 'approved'] as $to) {
            ReferralService::setStatus($this->admin, $this->refRow($b['public_id']), $to);
        }
        ReferralService::decideAttribution($this->admin, $this->refRow($b['public_id']), 'credited', 'A different prospect introduced by the second partner.');
        $this->expectInvalid(fn () => ReferralService::markConverted($this->admin, $this->refRow($b['public_id']), $this->alpha['public_id']), 'already credited');
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->bravo['id']]);
        $this->expectInvalid(fn () => ReferralService::markConverted($this->admin, $this->refRow($b['public_id']), $this->bravo['public_id']), 'active project');
        Database::run("UPDATE projects SET status = 'in_progress' WHERE id = ?", [$this->bravo['id']]);
        ReferralService::markConverted($this->admin, $this->refRow($b['public_id']), $this->bravo['public_id']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM partner_referrals WHERE status = 'converted'")['c']);
    }

    public function testAttributionIsLockedOnceCommissionEligibilityIsEstablished(): void
    {
        $a = $this->creditedReferral();
        $this->pay('advance', '50000');
        $a = $this->refRow($a['public_id']);
        $this->assertSame(1, (int) $a['attribution_locked']);
        $this->expectInvalid(fn () => ReferralService::decideAttribution($this->admin, $a, 'denied', 'Trying to take the credit away after the fact.'), 'locked');
        $this->expectInvalid(fn () => ReferralService::decideAttribution($this->owner, $a, 'duplicate', 'Even the owner cannot re-attribute it.'), 'locked');
        $this->expectInvalid(fn () => ReferralService::linkRecords($this->admin, $a, '', $this->bravo['public_id']), 'can no longer be changed');
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $a, 'rejected', 'Rejecting it after the commission became eligible.'), 'cannot move');
        $this->assertSame('credited', $this->refRow($a['public_id'])['attribution']);
        $this->assertSame((int) $this->partnerRow['id'], (int) $this->refRow($a['public_id'])['partner_id']);
        foreach ([$this->manager, $this->finance, $this->partner, $this->client] as $u) {
            $this->expectStatus(fn () => ReferralService::decideAttribution($u, $a, 'denied', 'Not authorised to decide this.'), 403);
        }
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'referral.attribution_changed'")['c']);
    }

    public function testStaffCreateACrmEnquiryFromAnApprovedReferralAndConversionLinksTheProject(): void
    {
        $r = $this->submitRef($this->partner);
        $this->expectInvalid(fn () => ReferralService::createEnquiry($this->admin, $r), 'approved');
        foreach (['under_review', 'approved'] as $to) {
            ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), $to);
        }
        ReferralService::createEnquiry($this->admin, $this->refRow($r['public_id']));
        $r = $this->refRow($r['public_id']);
        $enq = Database::one('SELECT * FROM enquiries WHERE id = ?', [$r['enquiry_id']]);
        $this->assertSame(['referral_partner', $this->partner], [$enq['source'], (int) $enq['referral_partner_id']]);
        $this->expectInvalid(fn () => ReferralService::createEnquiry($this->admin, $r), 'without a CRM enquiry');
        foreach ([$this->manager, $this->partner, $this->client] as $u) {
            $this->expectStatus(fn () => ReferralService::createEnquiry($u, $this->refRow($r['public_id'])), 403);
        }
        // converting the enquiry links the client and project but does NOT convert the referral
        $e = \Vertex\Services\EnquiryPolicy::find($enq['public_id']);
        EnquiryService::moveStage($this->admin, $e, 'won', null, true);
        $conv = EnquiryService::convert($this->admin, \Vertex\Services\EnquiryPolicy::find($enq['public_id']), ['client' => 'new', 'confirm_new' => '1', 'project_name' => 'Rhea Studio website']);
        $r = $this->refRow($r['public_id']);
        $this->assertSame((int) $conv['project_id'], (int) $r['project_id']);
        $this->assertSame('approved', $r['status'], 'a won enquiry or a created project never converts the referral by itself');
        $this->assertNull($r['credit_project_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'referral.enquiry_converted'", [$this->admin])['c']);
    }

    // ------------------------------------------------------------------------------------------------ previewed referral e-mails

    private function lastStatusHistory(array $r): int
    {
        return (int) Database::one("SELECT id FROM referral_history WHERE referral_id = ? AND event = 'status' ORDER BY id DESC LIMIT 1", [$r['id']])['id'];
    }

    public function testReferralStatusEmailsAreNeverSentAutomaticallyAndNeedAPreviewAndConfirmation(): void
    {
        Config::set('app.url', 'https://vis.test');
        Config::set('app.env', 'testing');
        Config::set('mail.driver', 'array');
        $r = $this->submitRef($this->partner);
        ReferralService::setStatus($this->admin, $r, 'under_review');
        ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), 'rejected', 'We cannot take on this kind of project at the moment.');
        $this->assertSame([], Mailer::$sent, 'changing a status sends nothing');
        $r = $this->refRow($r['public_id']);
        $hid = $this->lastStatusHistory($r);
        foreach ([$this->partner, $this->manager, $this->finance, $this->client] as $u) {
            $this->expectStatus(fn () => ReferralMailService::preview($u, $r, $hid), 403);
        }
        $p = ReferralMailService::preview($this->admin, $r, $hid);
        $this->assertSame('partner@x.test', $p['to']);
        $this->assertStringContainsString('Rejected', $p['subject']);
        $this->assertStringContainsString('We cannot take on this kind of project', $p['body']);
        $this->assertStringNotContainsString('INTERNAL', $p['body']);
        $this->assertTrue($p['mail_enabled']);
        $this->assertSame([], Mailer::$sent, 'a preview sends nothing');
        $this->expectInvalid(fn () => ReferralMailService::send($this->admin, $r, $hid, str_repeat('0', 64)), 'changed since you previewed');
        $this->assertSame([], Mailer::$sent);
        $this->assertSame('sent', ReferralMailService::send($this->admin, $r, $hid, $p['hash']));
        $this->assertCount(1, Mailer::$sent);
        $this->assertSame($p['body'], Mailer::$sent[0]['body'], 'the message sent is exactly the message previewed');
        $this->expectInvalid(fn () => ReferralMailService::send($this->admin, $r, $hid, $p['hash']), 'already sent');
        $this->assertCount(1, Mailer::$sent, 'a retry or double click cannot send twice');
        $this->assertTrue(ReferralMailService::preview($this->admin, $r, $hid)['already_sent']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'referral.email_sent'")['c']);
        // a history row that is not a partner-visible status change cannot be mailed
        $internal = (int) Database::one("SELECT id FROM referral_history WHERE referral_id = ? AND event = 'created'", [$r['id']])['id'];
        $this->expectStatus(fn () => ReferralMailService::preview($this->admin, $r, $internal), 404);
    }

    public function testWithoutAMailDriverNothingIsSentAndNothingClaimsItWas(): void
    {
        Config::set('mail.driver', 'none');
        $r = $this->submitRef($this->partner);
        ReferralService::setStatus($this->admin, $r, 'under_review');
        $r = $this->refRow($r['public_id']);
        $hid = $this->lastStatusHistory($r);
        $p = ReferralMailService::preview($this->admin, $r, $hid);
        $this->assertFalse($p['mail_enabled']);
        $this->assertSame('not_configured', ReferralMailService::send($this->admin, $r, $hid, $p['hash']));
        $this->assertSame([], Mailer::$sent);
        $log = ReferralMailService::log($this->admin, $r);
        $this->assertSame('not_configured', $log[0]['result']);
        $this->assertFalse(ReferralMailService::preview($this->admin, $r, $hid)['already_sent'], 'a not-configured attempt does not count as sent');
        $this->assertSame([], ReferralMailService::log($this->partner, $r));
    }

    public function testEveryStepIsAuditedAndTheAuditChainStaysValid(): void
    {
        $this->openApplications();
        $res = $this->apply();
        PartnerApplicationService::approve($this->admin, $this->app($res['reference']));
        $r = $this->creditedReferral();
        $this->pay('advance', '50000');
        $actions = array_column(Database::all('SELECT DISTINCT action FROM audit_logs'), 'action');
        foreach (['partner.terms_recorded', 'partner.application_received', 'partner.application_approved', 'referral.created', 'referral.status_changed', 'referral.attribution_changed', 'referral.converted',
            'client_payment.recorded', 'client_payment.verified', 'commission.calculated'] as $a) {
            $this->assertContains($a, $actions, $a);
        }
        $this->assertTrue(Audit::verify()['ok']);
        $this->assertNotNull($r);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE (action LIKE 'partner.%' OR action LIKE 'referral.%' OR action LIKE 'commission.%' OR action LIKE 'client_payment.%') AND (after_json LIKE '%anita@applicant.test%' OR meta_json LIKE '%anita@applicant.test%' OR after_json LIKE '%rhea@prospect%')")['c'], 'no personal data in the Phase 6 audit entries');
    }
}
