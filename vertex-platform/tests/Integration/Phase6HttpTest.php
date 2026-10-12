<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\CommissionService;
use Vertex\Services\PartnerApplicationService;
use Vertex\Services\PayoutService;
use Vertex\Services\ReferralService;

/** Referral partner portal, commissions and payouts over real HTTP: isolation, role gates, step-up, CSRF, duplicate submission, leaks, escaping. */
final class Phase6HttpTest extends HttpTestCase
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
        PartnerApplicationService::recordTerms($this->admin, ['title' => 'Referral Partner Terms', 'terms' => 'Test terms: commission is paid only on verified client payments.']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function applyForm(array $over = []): array
    {
        return $over + ['full_name' => 'Asha Applicant', 'email' => 'asha@apply.test', 'phone' => '+91 98765 11111', 'organization' => 'Asha Consulting',
            'professional_info' => 'Ten years of introducing small businesses to digital agencies.', 'referral_source' => 'Introduced by a mutual contact.', 'agree' => '1', 'website_url' => ''];
    }

    private function applicationCount(): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM partner_applications')['c'];
    }

    // ---- public application ---------------------------------------------------------------------------------------------

    public function testPublicApplicationFormStoresAnApplicationWithoutCreatingAnAccount(): void
    {
        $c = $this->client();
        $page = $c->get('/partners/apply');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Referral Partner Terms', $page['body']);
        $users = (int) Database::one('SELECT COUNT(*) AS c FROM users')['c'];
        $r = $c->post('/partners/apply', $this->applyForm(), '/partners/apply');
        $this->assertSame(303, $r['status']);
        $this->assertSame('/partners/apply/received', $r['location']);
        $a = Database::one('SELECT * FROM partner_applications ORDER BY id DESC LIMIT 1');
        $this->assertSame('received', $a['status']);
        $this->assertNull($a['user_id']);
        $this->assertSame($users, (int) Database::one('SELECT COUNT(*) AS c FROM users')['c'], 'applying must never create or activate an account');
        $this->assertNotNull($a['terms_accepted_at']);
        $this->assertMatchesRegularExpression('/^PAP-/', $a['reference']);
        $this->assertStringContainsString($a['reference'], $c->get('/partners/apply/received')['body']);
    }

    public function testDuplicateOpenApplicationIsIgnoredWithoutRevealingIt(): void
    {
        $c = $this->client();
        $c->post('/partners/apply', $this->applyForm(), '/partners/apply');
        $this->assertSame(1, $this->applicationCount());
        $second = $this->client()->post('/partners/apply', $this->applyForm(['full_name' => 'Asha A']), '/partners/apply');
        $this->assertSame(303, $second['status']);
        $this->assertSame('/partners/apply/received', $second['location'], 'the same page whether or not an application already exists');
        $this->assertSame(1, $this->applicationCount());
    }

    public function testHoneypotCsrfAndMissingAgreementStoreNothing(): void
    {
        $c = $this->client();
        $c->post('/partners/apply', $this->applyForm(['website_url' => 'http://spam.test']), '/partners/apply');
        $this->assertSame(0, $this->applicationCount());
        $this->assertSame(419, $this->client()->postNoToken('/partners/apply', $this->applyForm())['status']);
        $bad = $this->client()->post('/partners/apply', $this->applyForm(['agree' => '']), '/partners/apply');
        $this->assertSame('/partners/apply', $bad['location']);
        $this->assertSame(0, $this->applicationCount(), 'terms must be agreed to apply');
    }

    public function testApplicationsAreClosedUntilTheBusinessRecordsTerms(): void
    {
        Database::run('DELETE FROM partner_terms');
        $c = $this->client();
        $this->assertStringContainsString('not open yet', $c->get('/partners/apply')['body']);
        $c->post('/partners/apply', $this->applyForm(), '/login');
        $this->assertSame(0, $this->applicationCount());
    }

    public function testApplicationDecisionNeedsAnAuthorisedAdminPasswordAndAReason(): void
    {
        $c = $this->client();
        $c->post('/partners/apply', $this->applyForm(), '/partners/apply');
        $id = Database::one('SELECT public_id FROM partner_applications')['public_id'];
        foreach (['mgr@x.test', 'fin@x.test', 'partner@x.test', 'alpha@client.test'] as $who) {
            $this->assertContains($this->loggedIn($who)->get('/referrals/applications/' . $id)['status'], [403, 404], "{$who} must not open an application");
        }
        $admin = $this->loggedIn('admin@x.test');
        $this->assertSame(200, $admin->get('/referrals/applications/' . $id)['status']);
        $bad = $admin->post('/referrals/applications/' . $id, ['action' => 'approve', 'confirm_password' => 'wrong-password'], '/referrals/applications/' . $id);
        $this->assertSame('received', Database::one('SELECT status FROM partner_applications')['status'], 'wrong password must not approve');
        $this->assertNotSame(404, $bad['status']);
        $admin->post('/referrals/applications/' . $id, ['action' => 'reject', 'reason' => 'short'], '/referrals/applications/' . $id);
        $this->assertNotSame('rejected', Database::one('SELECT status FROM partner_applications')['status'], 'a rejection needs a real reason');
        $admin->post('/referrals/applications/' . $id, ['action' => 'reject', 'reason' => 'We could not verify the details provided.'], '/referrals/applications/' . $id);
        $a = Database::one('SELECT * FROM partner_applications');
        $this->assertSame('rejected', $a['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM referral_partners WHERE user_id IN (SELECT id FROM users WHERE email = ?)', ['asha@apply.test'])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'partner.application_rejected'")['c']);
    }

    public function testApprovalCreatesAPendingAccountNeverAnActiveOne(): void
    {
        $this->client()->post('/partners/apply', $this->applyForm(), '/partners/apply');
        $id = Database::one('SELECT public_id FROM partner_applications')['public_id'];
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/referrals/applications/' . $id, ['action' => 'approve', 'confirm_password' => self::PASSWORD], '/referrals/applications/' . $id);
        $this->assertSame('approved', Database::one('SELECT status FROM partner_applications')['status']);
        $u = Database::one("SELECT status, password_hash FROM users WHERE email = 'asha@apply.test'");
        $this->assertSame('pending', $u['status'], 'the applicant must activate through the secure invitation');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) AS c FROM referral_partners WHERE user_id = (SELECT id FROM users WHERE email = ?)', ['asha@apply.test'])['c']);
        $this->assertNotNull($this->mailLink('/invite/', 'asha@apply.test'), 'an invitation email goes to the applicant');
    }

    // ---- partner portal isolation -----------------------------------------------------------------------------------------

    public function testPartnerSeesOnlyOwnReferralsAndOtherPartnersGet404(): void
    {
        $mine = $this->creditedReferral($this->partner, 'one@prospect.test');
        $theirs = $this->creditedReferral($this->partner2, 'two@prospect.test', $this->bravo);
        ReferralService::addNote($this->admin, $this->refRow($mine['public_id']), 'INTERNAL-NOTE-MINE-SECRET');
        $p1 = $this->loggedIn('partner@x.test');
        $dash = $p1->get('/partner');
        $this->assertSame(200, $dash['status']);
        $this->assertStringContainsString($mine['reference'], $dash['body']);
        $this->assertStringNotContainsString($theirs['reference'], $dash['body']);
        $this->assertStringNotContainsString('Pat Prospect two', $dash['body']);
        $this->assertSame(200, $p1->get('/partner/r/' . $mine['public_id'])['status']);
        $this->assertSame(404, $p1->get('/partner/r/' . $theirs['public_id'])['status'], 'IDOR on another partner\'s referral');
        $detail = $p1->get('/partner/r/' . $mine['public_id'])['body'];
        foreach (['INTERNAL-NOTE-MINE-SECRET', 'attribution', 'duplicate_status', 'Alpha Website', 'VIS-2026'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $detail, "{$leak} must not reach the partner");
        }
        $this->assertSame(404, $this->loggedIn('partner2@x.test')->get('/partner/r/' . $mine['public_id'])['status']);
    }

    public function testNonPartnersCannotUseThePartnerPortalOrStaffScreens(): void
    {
        $partnerPaths = ['/partner', '/partner/submit', '/partner/commissions', '/partner/payouts', '/partner/payouts/new', '/partner/payout-details'];
        foreach (['alpha@client.test' => 'client', 'dev@x.test' => 'worker', 'sup@x.test' => 'support', 'fin@x.test' => 'finance'] as $email => $label) {
            $c = $this->loggedIn($email);
            foreach ($partnerPaths as $p) {
                $this->assertContains($c->get($p)['status'], [403, 404], "{$label} must not open {$p}");
            }
        }
        $staffPaths = ['/referrals', '/referrals/applications', '/referrals/partners', '/referrals/terms', '/commissions', '/commissions/rules', '/payouts', '/client-payments', '/referrals/dashboard'];
        foreach (['partner@x.test' => 'partner', 'alpha@client.test' => 'client', 'dev@x.test' => 'worker', 'sup@x.test' => 'support', 'mgr@x.test' => 'manager'] as $email => $label) {
            $c = $this->loggedIn($email);
            foreach ($staffPaths as $p) {
                $this->assertSame(403, $c->get($p)['status'], "{$label} must not open {$p}");
            }
        }
        $this->assertSame(403, $this->loggedIn('fin@x.test')->get('/referrals/applications')['status'], 'finance does not review applications');
    }

    public function testPartnerSubmitsAReferralThroughTheFormAndDuplicateSubmitIsIdempotent(): void
    {
        $p = $this->loggedIn('partner@x.test');
        $form = $p->get('/partner/submit');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $t);
        $in = ['prospect_name' => 'Hello Prospect', 'email' => 'hello@prospect.test', 'phone' => '+91 97000 00001', 'business_name' => 'Hello Co', 'service' => 'website_development', 'description' => 'Wants a small business website soon.',
            'estimated_budget' => '50000', 'notes' => '', 'token' => $t[1], 'partner_id' => (string) $this->partnerRow['id'], 'status' => 'converted', 'attribution' => 'credited'];
        $p->post('/partner/submit', $in, '/partner/submit');
        $p->post('/partner/submit', $in, '/partner/submit');
        $rows = Database::all("SELECT * FROM partner_referrals WHERE email_norm = 'hello@prospect.test'");
        $this->assertCount(1, $rows, 'a double submit creates one referral');
        $this->assertSame((int) $this->partnerRow['id'], (int) $rows[0]['partner_id']);
        $this->assertSame('received', $rows[0]['status'], 'a partner cannot set the status');
        $this->assertSame('pending', $rows[0]['attribution'], 'a partner cannot set the credit decision');
        // the same partner repeating the same prospect with a fresh token is blocked as a duplicate
        $p->post('/partner/submit', ['token' => bin2hex(random_bytes(16))] + $in, '/partner/submit');
        $this->assertCount(1, Database::all("SELECT id FROM partner_referrals WHERE email_norm = 'hello@prospect.test'"));
    }

    public function testTwoPartnersClaimingTheSameProspectCreateAnAdminReviewNeverAMerge(): void
    {
        $a = $this->creditedReferral($this->partner, 'same@prospect.test');
        $res = ReferralService::submit($this->partner2, ['prospect_name' => 'Pat Prospect', 'email' => 'same@prospect.test', 'phone' => '', 'business_name' => '', 'service' => 'website_development',
            'description' => 'Competing claim for the same prospect.', 'estimated_budget' => '', 'notes' => '', 'token' => bin2hex(random_bytes(16))]);
        $b = $this->refRow($res['public_id']);
        $this->assertNotSame('credited', $b['attribution']);
        $admin = $this->loggedIn('admin@x.test');
        $show = $admin->get('/referrals/' . $b['public_id']);
        $this->assertSame(200, $show['status']);
        $this->assertStringContainsString($a['reference'], $show['body'], 'the competing claim is listed for the reviewer');
        $dup = $admin->get('/referrals?duplicates=1');
        $this->assertStringContainsString($b['reference'], $dup['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM partner_referrals WHERE attribution = 'credited' AND email_norm = 'same@prospect.test'")['c']);
        $partner2View = $this->loggedIn('partner2@x.test')->get('/partner/r/' . $b['public_id'])['body'];
        $this->assertStringNotContainsString($a['reference'], $partner2View, 'one partner never learns about the other\'s claim');
        $this->assertStringNotContainsString('partner@x.test', $partner2View);
    }

    public function testReferralTextIsEscapedForStaff(): void
    {
        ReferralService::submit($this->partner, ['prospect_name' => 'Evil Prospect', 'email' => 'x@prospect.test', 'phone' => '', 'business_name' => 'Evil Co', 'service' => 'website_development',
            'description' => 'Wants a site. <script>alert(1)</script> <b>bold</b>', 'estimated_budget' => '', 'notes' => '"><img src=x onerror=alert(2)>', 'token' => bin2hex(random_bytes(16))]);
        $admin = $this->loggedIn('admin@x.test');
        $id = Database::one('SELECT public_id FROM partner_referrals ORDER BY id DESC LIMIT 1')['public_id'];
        $this->assertStringNotContainsString('<script>alert(1)', $admin->get('/referrals')['body']);
        $body = $admin->get('/referrals/' . $id)['body'];
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('&lt;img src=x', $body);
    }

    // ---- commissions & payouts over HTTP ---------------------------------------------------------------------------------

    public function testCommissionApprovalNeedsThePasswordAndSeparationOfDuties(): void
    {
        $r = $this->creditedReferral();
        $this->pay('advance', '50000'); // recorded by finance, verified by admin
        $e = $this->entry($r, 'advance');
        $this->assertSame('eligible_for_review', $e['status']);
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/commissions/' . $e['public_id'], ['action' => 'approve', 'confirm_password' => self::PASSWORD], '/commissions/' . $e['public_id']);
        $this->assertSame('eligible_for_review', Database::one('SELECT status FROM commission_entries WHERE id = ?', [$e['id']])['status'], 'the verifier of the payment cannot approve the commission');
        $owner = $this->loggedIn('owner@x.test');
        $owner->post('/commissions/' . $e['public_id'], ['action' => 'approve', 'confirm_password' => 'nope'], '/commissions/' . $e['public_id']);
        $this->assertSame('eligible_for_review', Database::one('SELECT status FROM commission_entries WHERE id = ?', [$e['id']])['status'], 'a wrong password does not approve');
        $owner->post('/commissions/' . $e['public_id'], ['action' => 'approve', 'confirm_password' => self::PASSWORD], '/commissions/' . $e['public_id']);
        $this->assertContains(Database::one('SELECT status FROM commission_entries WHERE id = ?', [$e['id']])['status'], ['approved', 'payable']);
        $this->assertSame(403, $this->loggedIn('fin@x.test')->post('/commissions/' . $e['public_id'], ['action' => 'hold', 'reason' => 'finance trying'], '/client-payments')['status']);
    }

    public function testPartnerLedgerPagesNeverShowPaymentsInvoicesOrInternalReasons(): void
    {
        $r = $this->creditedReferral();
        $pay = $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($r, 'advance'));
        CommissionService::hold($this->owner, $e, 'INTERNAL-HOLD-REASON-XYZ', 'We are confirming a detail with you.');
        $p = $this->loggedIn('partner@x.test');
        $body = $p->get('/partner/commissions')['body'];
        $this->assertStringContainsString('We are confirming a detail with you.', $body);
        foreach (['INTERNAL-HOLD-REASON-XYZ', $pay['reference'], 'INV-TEST', 'UTR', 'Alpha Website', '1,00,000', '100,000', 'bank_transfer'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "{$leak} must not appear in the partner's commission view");
        }
        $this->assertSame(403, $p->get('/client-payments/' . $pay['public_id'])['status']);
        $this->assertSame(403, $p->get('/commissions/' . $e['public_id'])['status']);
    }

    public function testPayoutWorkflowOverHttpApprovalIsNotPaymentAndDetailsStayEncrypted(): void
    {
        $r = $this->creditedReferral();
        $this->pay('advance', '50000');
        $this->approveEntry($this->entry($r, 'advance'));
        $p = $this->loggedIn('partner@x.test');
        $secretAcct = '9876543210123456';
        $p->post('/partner/payout-details', ['method' => 'bank_transfer', 'account_name' => 'Partner Name', 'identifier' => $secretAcct, 'bank_name' => 'Test Bank', 'confirm_password' => 'wrong'], '/partner/payout-details');
        $this->assertNull(Database::one('SELECT payout_details_enc FROM referral_partners WHERE id = ?', [$this->partnerRow['id']])['payout_details_enc'], 'step-up password required');
        $p->post('/partner/payout-details', ['method' => 'bank_transfer', 'account_name' => 'Partner Name', 'identifier' => $secretAcct, 'bank_name' => 'Test Bank', 'confirm_password' => self::PASSWORD], '/partner/payout-details');
        $stored = (string) Database::one('SELECT payout_details_enc FROM referral_partners WHERE id = ?', [$this->partnerRow['id']])['payout_details_enc'];
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($secretAcct, $stored, 'stored encrypted');
        $this->assertStringNotContainsString($secretAcct, $p->get('/partner/payouts')['body'], 'only a masked hint is shown back');
        $this->assertStringNotContainsString($secretAcct, $p->get('/partner/payout-details')['body']);

        // the partner asks for more than the balance, then for a valid amount (twice, with the same token)
        $form = $p->get('/partner/payouts/new');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $t);
        $p->post('/partner/payouts', ['amount' => '999999', 'notes' => '', 'token' => bin2hex(random_bytes(16))], '/partner/payouts/new');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM payout_requests')['c'], 'cannot exceed the available balance');
        $p->post('/partner/payouts', ['amount' => '3000', 'notes' => 'please', 'token' => $t[1]], '/partner/payouts/new');
        $p->post('/partner/payouts', ['amount' => '3000', 'notes' => 'please', 'token' => $t[1]], '/partner/payouts/new');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) AS c FROM payout_requests')['c'], 'double submit = one request');
        $po = Database::one('SELECT * FROM payout_requests');
        $this->assertSame(300000, (int) $po['amount_minor']);

        // another partner can neither open nor cancel it
        $p2 = $this->loggedIn('partner2@x.test');
        $this->assertSame(404, $p2->get('/partner/payouts/' . $po['public_id'])['status']);
        $this->assertSame(404, $p2->post('/partner/payouts/' . $po['public_id'] . '/cancel', [], '/partner/payouts')['status']);
        $this->assertSame('requested', Database::one('SELECT status FROM payout_requests')['status']);

        // staff: finance can see but not approve; admin approves (needs password); approval is not payment
        $fin = $this->loggedIn('fin@x.test');
        $this->assertSame(200, $fin->get('/payouts/' . $po['public_id'])['status']);
        $this->assertSame(403, $fin->post('/payouts/' . $po['public_id'], ['action' => 'approve', 'confirm_password' => self::PASSWORD], '/payouts')['status']);
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/payouts/' . $po['public_id'], ['action' => 'approve', 'confirm_password' => 'wrong'], '/payouts/' . $po['public_id']);
        $this->assertSame('requested', Database::one('SELECT status FROM payout_requests')['status']);
        $admin->post('/payouts/' . $po['public_id'], ['action' => 'approve', 'confirm_password' => self::PASSWORD], '/payouts/' . $po['public_id']);
        $row = Database::one('SELECT * FROM payout_requests');
        $this->assertSame('approved', $row['status']);
        $this->assertNull($row['paid_at'], 'approval is not payment');
        // separation of duties: the approver cannot record the payment
        $admin->post('/payouts/' . $po['public_id'], ['action' => 'pay', 'method' => 'bank_transfer', 'reference' => 'UTR-ADMIN', 'date' => date('Y-m-d'), 'amount' => '3000', 'confirm_password' => self::PASSWORD], '/payouts/' . $po['public_id']);
        $this->assertSame('approved', Database::one('SELECT status FROM payout_requests')['status']);
        // details are revealed only on request, to the payer, and audited; the page without ?reveal=1 has no details
        $this->assertStringNotContainsString($secretAcct, $fin->get('/payouts/' . $po['public_id'])['body']);
        $this->assertStringContainsString($secretAcct, $fin->get('/payouts/' . $po['public_id'] . '?reveal=1')['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'payout.details_viewed'")['c']);
        $this->assertStringNotContainsString($secretAcct, (string) Database::one("SELECT GROUP_CONCAT(COALESCE(before_json, ''), COALESCE(after_json, ''), COALESCE(meta_json, '')) AS x FROM audit_logs")['x'], 'no payout detail ever reaches the audit log');
        $fin->post('/payouts/' . $po['public_id'], ['action' => 'pay', 'method' => 'bank_transfer', 'reference' => 'UTR-FIN-1', 'date' => date('Y-m-d'), 'amount' => '2999', 'confirm_password' => self::PASSWORD], '/payouts/' . $po['public_id']);
        $this->assertSame('approved', Database::one('SELECT status FROM payout_requests')['status'], 'the paid amount must equal the approved amount');
        $fin->post('/payouts/' . $po['public_id'], ['action' => 'pay', 'method' => 'bank_transfer', 'reference' => 'UTR-FIN-1', 'date' => date('Y-m-d'), 'amount' => '3000', 'confirm_password' => self::PASSWORD], '/payouts/' . $po['public_id']);
        $paid = Database::one('SELECT * FROM payout_requests');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame((int) $this->finance, (int) $paid['paid_by']);
        $this->assertNotSame((int) $paid['approved_by'], (int) $paid['paid_by']);
        $this->assertStringContainsString('UTR-FIN-1', $p->get('/partner/payouts/' . $po['public_id'])['body'], 'the partner sees the payment reference');
        $this->assertSame(0, $this->partnerBalance()['available'] + $this->partnerBalance()['reserved'] - 200000, 'the remaining balance is 2,000');
    }

    public function testClientPaymentsNeedTwoPeopleAndRefundsAreStepUpProtected(): void
    {
        $fin = $this->loggedIn('fin@x.test');
        $fin->post('/client-payments', ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '20000', 'tax' => '0', 'method' => 'bank_transfer', 'external_ref' => 'UTR-HTTP-1', 'invoice_ref' => '', 'received_on' => date('Y-m-d'), 'notes' => ''], '/client-payments/new');
        $pay = Database::one('SELECT * FROM client_payments');
        $this->assertSame('pending_verification', $pay['status']);
        $this->assertSame(403, $fin->post('/client-payments/' . $pay['public_id'] . '/verify', ['confirm_password' => self::PASSWORD], '/client-payments')['status'], 'finance cannot reconcile');
        // duplicate transaction reference is refused, not double counted
        $fin->post('/client-payments', ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '20000', 'tax' => '0', 'method' => 'bank_transfer', 'external_ref' => 'UTR-HTTP-1', 'invoice_ref' => '', 'received_on' => date('Y-m-d'), 'notes' => ''], '/client-payments/new');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) AS c FROM client_payments')['c']);
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/client-payments/' . $pay['public_id'] . '/verify', ['confirm_password' => 'bad'], '/client-payments/' . $pay['public_id']);
        $this->assertSame('pending_verification', Database::one('SELECT status FROM client_payments')['status']);
        $admin->post('/client-payments/' . $pay['public_id'] . '/verify', ['confirm_password' => self::PASSWORD], '/client-payments/' . $pay['public_id']);
        $this->assertSame('verified', Database::one('SELECT status FROM client_payments')['status']);
        $admin->post('/client-payments/' . $pay['public_id'] . '/verify', ['confirm_password' => self::PASSWORD], '/client-payments/' . $pay['public_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM client_payment_events WHERE event = 'verified'")['c'], 'verifying twice records one verification');
        $this->assertSame(403, $this->loggedIn('partner@x.test')->get('/client-payments/' . $pay['public_id'])['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/client-payments/' . $pay['public_id'])['status']);
    }

    public function testReferralEmailIsNeverSentWithoutAPreviewAndConfirmation(): void
    {
        $r = $this->creditedReferral();
        $admin = $this->loggedIn('admin@x.test');
        $before = $this->mailCount();
        $show = $admin->get('/referrals/' . $r['public_id']);
        preg_match('#/referrals/' . $r['public_id'] . '/email/(\d+)#', $show['body'], $m);
        $this->assertNotEmpty($m, 'a status change offers a preview, not a send');
        $this->assertSame($before, $this->mailCount(), 'changing a status sends no email');
        $preview = $admin->get($m[0]);
        $this->assertSame(200, $preview['status']);
        $this->assertSame($before, $this->mailCount(), 'previewing sends no email');
        preg_match('/name="hash" value="([a-f0-9]{64})"/', $preview['body'], $h);
        $admin->post($m[0], ['hash' => $h[1]], $m[0]);
        $this->assertSame($before, $this->mailCount(), 'no confirmation box ticked: nothing sent');
        $admin->post($m[0], ['hash' => str_repeat('0', 64), 'confirm' => '1'], $m[0]);
        $this->assertSame($before, $this->mailCount(), 'a tampered preview hash sends nothing');
        $admin->post($m[0], ['hash' => $h[1], 'confirm' => '1'], $m[0]);
        $this->assertSame($before + 1, $this->mailCount());
        $admin->post($m[0], ['hash' => $h[1], 'confirm' => '1'], $m[0]);
        $this->assertSame($before + 1, $this->mailCount(), 'the same status email is never sent twice');
        $this->assertSame(403, $this->loggedIn('fin@x.test')->get($m[0])['status']);
        $this->assertContains($this->loggedIn('partner@x.test')->get($m[0])['status'], [403, 404]);
    }

    public function testApplicationFileIsDownloadableOnlyByReviewers(): void
    {
        $c = $this->client();
        $f = $this->png('badge.png', 'apply');
        $c->upload('/partners/apply', $this->applyForm(), ['document[0]' => [$f['tmp_name'], 'badge.png', 'image/png']], '/partners/apply');
        $a = Database::one('SELECT a.public_id, f.public_id AS fid FROM partner_applications a JOIN stored_files f ON f.id = a.doc_file_id');
        $this->assertNotNull($a, 'the optional supporting file was stored');
        $url = '/referrals/applications/' . $a['public_id'] . '/files/' . $a['fid'];
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get($url)['status']);
        foreach (['partner@x.test', 'alpha@client.test', 'dev@x.test', 'fin@x.test'] as $who) {
            $this->assertContains($this->loggedIn($who)->get($url)['status'], [403, 404], "{$who}");
        }
        $this->assertSame(303, $this->client()->get($url)['status'], 'anonymous visitors are sent to sign in');
    }

    public function testAuditTrailCoversTheWholePartnerLifecycle(): void
    {
        $r = $this->creditedReferral();
        $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($r, 'advance'));
        $po = $this->requestPayoutHelper();
        foreach (['partner.terms_recorded', 'referral.created', 'referral.status_changed', 'referral.attribution_changed', 'referral.converted', 'client_payment.recorded', 'client_payment.verified', 'commission.calculated', 'commission.approved', 'payout.requested'] as $action) {
            $this->assertGreaterThan(0, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = ?', [$action])['c'], "audit action {$action} is recorded");
        }
        $this->assertTrue(\Vertex\Services\Audit::verify()['ok'] ?? false, 'the audit hash chain is intact');
        unset($e, $po);
    }

    private function requestPayoutHelper(): array
    {
        $this->saveDetails($this->partner, $this->partnerRow);

        return $this->requestPayout('1000');
    }
}
