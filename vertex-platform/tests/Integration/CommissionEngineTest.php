<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Audit;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CommissionRules;
use Vertex\Services\CommissionService;
use Vertex\Services\ReferralService;

/** The 10 % advance milestone, the 5 % full-payment milestone, the base, duplicate payments, refunds, cancellations and the preservation of existing records. */
final class CommissionEngineTest extends DatabaseTestCase
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

    // ------------------------------------------------------------------------------------------------ milestone 1

    public function testTenPercentAdvanceMilestoneIsCalculatedOnlyAfterTheAdvanceIsVerified(): void
    {
        $ref = $this->creditedReferral();
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], 'both milestones exist as Pending Eligibility from the start');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status']);
        // recorded but NOT verified: nothing is eligible
        $r = ClientPaymentService::record($this->finance, ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '50000', 'tax' => '0', 'method' => 'upi', 'external_ref' => 'UPI-1', 'received_on' => date('Y-m-d')]);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], 'an unverified payment never makes a commission eligible');
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));
        $e = $this->entry($ref, 'advance');
        $this->assertSame('eligible_for_review', $e['status']);
        $this->assertSame(5000000, (int) $e['base_minor']);
        $this->assertSame(1000, (int) $e['rate_bps']);
        $this->assertSame(500000, (int) $e['amount_minor'], '10 % of INR 50,000 = INR 5,000');
        $this->assertNotNull($e['calculated_at']);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'the 5 % milestone is not triggered by the advance');
        // inputs and formula are stored with the entry
        $f = json_decode($e['formula_json'], true);
        $this->assertSame('cumulative', $f['mode']);
        $this->assertSame('floor(base x 1000 / 10000)', $f['formula']);
        $this->assertSame(10000000, $f['inputs']['contract_value_minor']);
        $this->assertSame(5000000, $f['inputs']['advance_eligible_minor']);
        $this->assertArrayHasKey('rules', $f);
        $this->assertSame(0, (int) $f['rules_version']);
        $pay = Database::all('SELECT payment_id, eligible_minor FROM commission_entry_payments WHERE entry_id = ?', [$e['id']]);
        $this->assertCount(1, $pay);
        $this->assertSame(5000000, (int) $pay[0]['eligible_minor']);
        $this->assertSame(1, (int) Database::one('SELECT attribution_locked FROM partner_referrals WHERE id = ?', [$ref['id']])['attribution_locked'], 'eligibility locks the attribution');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'commission.calculated'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'commission.milestone'", [$this->partner])['c']);
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'commission.review_needed'")['c']);
        $this->assertTrue(Audit::verify()['ok']);
    }

    public function testAnUnverifiedPaymentBesideAVerifiedOneNeverCountsTowardTheBase(): void
    {
        $ref = $this->creditedReferral();
        // an unpaid / unverified INR 30,000 payment exists on the project while another payment is verified: the evaluation runs and must ignore it
        ClientPaymentService::record($this->finance, ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '30000', 'tax' => '0', 'method' => 'upi', 'external_ref' => 'UPI-PENDING', 'received_on' => \Vertex\Services\AppTime::today()]);
        $this->pay('advance', '20000');
        $e = $this->entry($ref, 'advance');
        $this->assertSame(2000000, (int) $e['base_minor'], 'only the verified INR 20,000 counts');
        $this->assertSame(200000, (int) $e['amount_minor']);
        $this->assertCount(1, Database::all('SELECT 1 FROM commission_entry_payments WHERE entry_id = ?', [$e['id']]));
        $f = json_decode($e['formula_json'], true);
        $this->assertSame(2000000, $f['inputs']['advance_eligible_minor']);
        $this->assertSame(2000000, $f['inputs']['received_net_of_refunds_minor']);
    }

    public function testTheBaseExcludesTaxAndOnlyCountsWhatWasReceived(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '59000', '9000');
        $e = $this->entry($ref, 'advance');
        $this->assertSame(5000000, (int) $e['base_minor'], 'INR 59,000 received including INR 9,000 tax: the base is INR 50,000');
        $this->assertSame(500000, (int) $e['amount_minor']);
    }

    public function testTaxIsIncludedWhenTheRuleSaysSo(): void
    {
        $this->useRules(['exclude_tax' => false]);
        $ref = $this->creditedReferral();
        $this->pay('advance', '59000', '9000');
        $this->assertSame(5900000, (int) $this->entry($ref, 'advance')['base_minor']);
        $this->assertSame(1, (int) CommissionRules::current()['version']);
        $this->assertSame(1, (int) json_decode($this->entry($ref, 'advance')['formula_json'], true)['rules_version']);
    }

    public function testAMinimumAdvanceAndPartialPayments(): void
    {
        $this->useRules(['advance_min_bps' => 4000]); // the advance must reach 40 % of the contract value
        $ref = $this->creditedReferral();
        $this->pay('advance', '20000');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], '20 % is below the 40 % minimum advance');
        $this->pay('advance', '10000');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], '30 % is still below the minimum');
        $this->pay('advance', '15000');
        $e = $this->entry($ref, 'advance');
        $this->assertSame('eligible_for_review', $e['status'], '45 % reached: the three partial advances together qualify');
        $this->assertSame(4500000, (int) $e['base_minor']);
        $this->assertSame(450000, (int) $e['amount_minor']);
        $this->assertCount(3, Database::all('SELECT 1 FROM commission_entry_payments WHERE entry_id = ?', [$e['id']]));
    }

    public function testAPaymentNotClassifiedAsAdvanceDoesNotTriggerTheAdvanceMilestoneUnlessPaidInFull(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('other', '30000');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status']);
        $this->pay('balance', '70000');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'advance')['status'], 'paid in full also satisfies the advance condition');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'full')['status']);
    }

    // ------------------------------------------------------------------------------------------------ milestone 2

    public function testFivePercentFullPaymentMilestoneCumulativeTotalsFifteenPercentOfWhatWasReceived(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $m1 = $this->approveEntry($this->entry($ref, 'advance'));
        $this->assertSame('payable', $m1['status']);
        $this->pay('balance', '30000');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'INR 80,000 of INR 1,00,000 received: not yet paid in full');
        $this->pay('balance', '20000');
        $m2 = $this->entry($ref, 'full');
        $this->assertSame('eligible_for_review', $m2['status']);
        $this->assertSame(10000000, (int) $m2['base_minor'], 'cumulative: the whole eligible amount received');
        $this->assertSame(1000000, (int) $m2['amount_minor'], '15 % x 1,00,000 = 15,000 minus the 5,000 already earned = 10,000');
        $this->assertSame(1500000, (int) $m1['amount_minor'] + (int) $m2['amount_minor'], 'total earned is 15 % of the money received');
        $this->assertSame(1000000, (int) $m2['amount_minor']);
        $f = json_decode($m2['formula_json'], true);
        $this->assertSame(1500, $f['cumulative_rate_bps']);
        $this->assertSame(500000, $f['advance_milestone_net_minor']);
        $this->assertCount(3, Database::all('SELECT 1 FROM commission_entry_payments WHERE entry_id = ?', [$m2['id']]), 'every payment counted once');
    }

    public function testPerMilestoneModeCountsEachPaymentInExactlyOneMilestone(): void
    {
        $this->useRules(['formula_mode' => 'per_milestone']);
        $ref = $this->creditedReferral();
        $a = $this->pay('advance', '50000');
        $b = $this->pay('balance', '50000');
        $m1 = $this->entry($ref, 'advance');
        $m2 = $this->entry($ref, 'full');
        $this->assertSame([500000, 250000], [(int) $m1['amount_minor'], (int) $m2['amount_minor']], '10 % of the advance, 5 % of the later payment');
        $in1 = array_column(Database::all('SELECT payment_id FROM commission_entry_payments WHERE entry_id = ?', [$m1['id']]), 'payment_id');
        $in2 = array_column(Database::all('SELECT payment_id FROM commission_entry_payments WHERE entry_id = ?', [$m2['id']]), 'payment_id');
        $this->assertSame([(int) $a['id']], array_map('intval', $in1));
        $this->assertSame([(int) $b['id']], array_map('intval', $in2));
        $this->assertSame([], array_intersect($in1, $in2), 'no payment is counted twice');
    }

    public function testASingleFullPaymentEarnsBothMilestones(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('balance', '100000');
        $this->assertSame(1000000, (int) $this->entry($ref, 'advance')['amount_minor'], '10 % of the whole payment');
        $this->assertSame(500000, (int) $this->entry($ref, 'full')['amount_minor'], 'cumulative: 15 % in total');
    }

    public function testAcceptingAQuotationOrApprovingExtraWorkNeverTriggersTheSecondMilestone(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        Database::run("UPDATE projects SET approved_amount_minor = 12000000, approved_quotation_ref = 'QT-NEW', approved_at = UTC_TIMESTAMP() WHERE id = ?", [$this->alpha['id']]);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status']);
        Database::run("INSERT INTO change_requests (public_id, reference, project_id, title, scope_description, reason, proposed_amount_minor, status, created_at, decided_at) VALUES (?, 'CHG-T-1', ?, 'Extra', 'x', 'y', 2000000, 'approved', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $this->alpha['id']]);
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'nothing was paid for the extra work');
        $this->pay('balance', '50000'); // 1,00,000 received but the contract (incl. approved extra work) is 1,40,000
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'the full-payment condition counts the approved additional work');
        $this->pay('balance', '40000');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'full')['status']);
    }

    public function testTheContractValueExcludesApprovedExtraWorkWhenConfigured(): void
    {
        $this->useRules(['include_change_requests' => false]);
        $ref = $this->creditedReferral();
        Database::run("INSERT INTO change_requests (public_id, reference, project_id, title, scope_description, reason, proposed_amount_minor, status, created_at, decided_at) VALUES (?, 'CHG-T-2', ?, 'Extra', 'x', 'y', 2000000, 'approved', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $this->alpha['id']]);
        $this->pay('advance', '50000');
        $this->pay('balance', '50000');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'full')['status']);
    }

    public function testNoPriceRecordedMeansTheFullPaymentCannotBeDetermined(): void
    {
        $ref = $this->creditedReferral();
        Database::run('UPDATE projects SET approved_amount_minor = NULL WHERE id = ?', [$this->alpha['id']]);
        $this->pay('advance', '50000');
        $this->pay('balance', '500000');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'advance')['status']);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'without an approved price nothing proves the project is paid in full');
    }

    public function testTheToleranceCountsAnAlmostCompletePaymentAsFull(): void
    {
        $this->useRules(['full_payment_tolerance_minor' => 50000]); // INR 500 short is still "paid in full"
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $this->pay('balance', '49600');
        $this->assertSame('eligible_for_review', $this->entry($ref, 'full')['status']);
    }

    // ------------------------------------------------------------------------------------------------ who earns

    public function testOnlyACreditedConvertedReferralEarnsAndOnlyOnItsProject(): void
    {
        // not credited
        $res = ReferralService::submit($this->partner, ['prospect_name' => 'Nina', 'email' => 'nina@x.test', 'service' => 'other', 'description' => 'Wants a small site built.', 'token' => bin2hex(random_bytes(16))]);
        $r = $this->refRow($res['public_id']);
        $this->pay('advance', '50000');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM commission_entries')['c'], 'no referral is attached to the project: nothing is earned');
        ReferralService::setStatus($this->admin, $r, 'under_review');
        ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), 'approved');
        ReferralService::markConverted($this->admin, $this->refRow($r['public_id']), $this->alpha['public_id']); // converted but attribution still pending
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM commission_entries')['c'], 'converted but not credited: no commission');
        ReferralService::decideAttribution($this->admin, $this->refRow($r['public_id']), 'credited', 'Introduced by the partner before any contact from us.');
        $this->assertSame('eligible_for_review', $this->entry($this->refRow($r['public_id']), 'advance')['status'], 'crediting evaluates the payments already received');
        // a payment on ANOTHER project does nothing for this referral
        $before = (int) Database::one('SELECT SUM(amount_minor) s FROM commission_entries')['s'];
        $this->pay('advance', '25000', '0', $this->bravo);
        $this->assertSame($before, (int) Database::one('SELECT SUM(amount_minor) s FROM commission_entries')['s']);
    }

    public function testApprovalAndQuoteSentNeverConvertAReferral(): void
    {
        $res = ReferralService::submit($this->partner, ['prospect_name' => 'Omar', 'email' => 'omar@x.test', 'service' => 'other', 'description' => 'Wants a small site built.', 'token' => bin2hex(random_bytes(16))]);
        $r = $this->refRow($res['public_id']);
        foreach (['under_review', 'approved', 'contacted', 'qualified', 'quote_sent'] as $to) {
            ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), $to);
            $this->assertNotSame('converted', $this->refRow($r['public_id'])['status']);
        }
        $this->expectInvalid(fn () => ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), 'converted'), 'never converts');
        $this->expectInvalid(fn () => ReferralService::markConverted($this->admin, $this->refRow($r['public_id']), 'nope'), 'project');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM commission_entries')['c']);
    }

    // ------------------------------------------------------------------------------------------------ duplicates / idempotence

    public function testTheSamePaymentCannotBeRecordedOrCountedTwice(): void
    {
        $ref = $this->creditedReferral();
        $in = ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '50000', 'tax' => '0', 'method' => 'gateway', 'external_ref' => 'pay_ABC123', 'received_on' => date('Y-m-d')];
        $a = ClientPaymentService::record($this->finance, $in);
        $this->expectInvalid(fn () => ClientPaymentService::record($this->finance, $in), 'already recorded');
        $this->expectInvalid(fn () => ClientPaymentService::record($this->finance2, ['external_ref' => 'PAY_abc123'] + $in), 'already recorded');
        // a retried webhook handler uses the idempotent form and gets the same row back
        $b = ClientPaymentService::record($this->finance, $in, true);
        $this->assertFalse($b['created']);
        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        // ...but never silently for a different amount or project
        $this->expectInvalid(fn () => ClientPaymentService::record($this->finance, ['gross' => '60000'] + $in, true), 'already recorded');
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($a['public_id']));
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, ClientPaymentService::find($a['public_id'])), 'not waiting');
        CommissionService::evaluateProject((int) $this->alpha['id']);
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM commission_entries')['c'], 'running the engine again creates nothing new');
        $this->assertSame(500000, (int) $this->entry($ref, 'advance')['amount_minor']);
        $this->assertCount(1, Database::all('SELECT 1 FROM commission_entry_payments'));
    }

    public function testTheRecorderCannotVerifyTheirOwnPayment(): void
    {
        $r = ClientPaymentService::record($this->admin, ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '1000', 'tax' => '0', 'method' => 'upi', 'external_ref' => 'SELF-1', 'received_on' => date('Y-m-d')]);
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id'])), 'cannot verify');
        foreach ([$this->finance, $this->manager, $this->client, $this->partner, $this->dev] as $u) {
            try {
                ClientPaymentService::verify($u, ClientPaymentService::find($r['public_id']));
                $this->fail('only payments.reconcile holders may verify');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertSame('pending_verification', ClientPaymentService::find($r['public_id'])['status']);
        $this->useRules(['separation_of_duties' => false]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));
        $this->assertSame('verified', ClientPaymentService::find($r['public_id'])['status']);
    }

    public function testPaymentInputsAreValidated(): void
    {
        $base = ['project' => $this->alpha['public_id'], 'role' => 'advance', 'gross' => '1000', 'tax' => '0', 'method' => 'upi', 'external_ref' => 'V-1', 'received_on' => date('Y-m-d')];
        foreach ([['gross' => '0'], ['gross' => '-5'], ['gross' => 'abc'], ['tax' => '2000'], ['role' => 'free'], ['method' => 'magic'], ['external_ref' => 'ab'], ['received_on' => '2999-01-01'], ['received_on' => 'x'], ['project' => 'nope']] as $bad) {
            $this->expectInvalid(fn () => ClientPaymentService::record($this->finance, $bad + $base));
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
    }

    // ------------------------------------------------------------------------------------------------ refunds / cancellations

    public function testARefundBeforeApprovalRecalculatesInPlaceKeepingTheHistory(): void
    {
        $ref = $this->creditedReferral();
        $p = $this->pay('advance', '50000');
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 1000000, 'Client received a partial refund');
        $e = $this->entry($ref, 'advance');
        $this->assertSame('eligible_for_review', $e['status']);
        $this->assertSame(4000000, (int) $e['base_minor']);
        $this->assertSame(400000, (int) $e['amount_minor']);
        $h = Database::all("SELECT event, before_json, after_json FROM commission_history WHERE entry_id = ? ORDER BY id", [$e['id']]);
        $this->assertSame(['created', 'calculated', 'recalculated'], array_column($h, 'event'));
        $this->assertSame(500000, json_decode($h[2]['before_json'], true)['amount_minor']);
        $this->assertSame(400000, json_decode($h[2]['after_json'], true)['amount_minor']);
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 4000000, 'Rest refunded');
        $e = $this->entry($ref, 'advance');
        $this->assertSame('pending_eligibility', $e['status'], 'nothing left: back to pending');
        $this->assertSame(0, (int) $e['amount_minor']);
        $this->assertSame('reversed', ClientPaymentService::find($p['public_id'])['status']);
    }

    public function testARefundAfterApprovalCreatesANegativeAdjustmentLineAndNeverEditsTheOriginal(): void
    {
        $ref = $this->creditedReferral();
        $p = $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($ref, 'advance'));
        $this->assertSame(500000, $this->partnerBalance()['available']);
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 2500000, 'Half of the advance refunded');
        $orig = Database::one('SELECT * FROM commission_entries WHERE id = ?', [$e['id']]);
        $this->assertSame(500000, (int) $orig['amount_minor'], 'the original calculation is preserved');
        $adj = Database::one("SELECT * FROM commission_entries WHERE parent_entry_id = ?", [$e['id']]);
        $this->assertSame(['adjustment', 'payable', -250000], [$adj['milestone'], $adj['status'], (int) $adj['amount_minor']]);
        $this->assertSame(250000, $this->partnerBalance()['available'], 'the partner can only withdraw what remains');
        $this->assertSame('payable', $orig['status']);
        $this->assertStringContainsString('Adjusted', $adj['partner_note']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'commission.adjusted'")['c']);
        // repeating the engine does not adjust again
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM commission_entries WHERE parent_entry_id = ?', [$e['id']])['c']);
        // a full refund of the rest reverses the (now adjusted) milestone
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 2500000, 'The rest refunded');
        $this->assertSame(0, $this->partnerBalance()['available']);
    }

    public function testAFullRefundOfAnApprovedUnpaidCommissionReversesItWithoutDeletingIt(): void
    {
        $ref = $this->creditedReferral();
        $p = $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($ref, 'advance'));
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 5000000, 'Order cancelled and fully refunded', 'chargeback');
        $r = Database::one('SELECT * FROM commission_entries WHERE id = ?', [$e['id']]);
        $this->assertSame('reversed', $r['status']);
        $this->assertSame(500000, (int) $r['amount_minor'], 'the original calculation stays visible');
        $this->assertSame(0, $this->partnerBalance()['available']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'commission.reversed'")['c']);
        $this->assertStringContainsString('payment', $r['partner_note']);
        // paying again re-qualifies with a NEW generation of the milestone; the old row is kept
        $this->pay('advance', '50000');
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM commission_entries WHERE milestone = 'advance'")['c']);
        $this->assertSame(2, (int) $this->entry($ref, 'advance')['seq']);
    }

    public function testARefundAfterPaymentOfTheCommissionCarriesANegativeBalance(): void
    {
        $ref = $this->creditedReferral();
        $p = $this->pay('advance', '50000');
        $this->approveEntry($this->entry($ref, 'advance'));
        $this->saveDetails($this->partner, $this->partnerRow);
        $po = $this->requestPayout('5000');
        $payout = \Vertex\Services\PayoutService::find($po['public_id']);
        \Vertex\Services\PayoutService::approve($this->admin, $payout);
        \Vertex\Services\PayoutService::recordPayment($this->finance, \Vertex\Services\PayoutService::find($po['public_id']), ['method' => 'upi', 'reference' => 'PAYOUT-1', 'date' => date('Y-m-d'), 'amount' => '5000']);
        $this->assertSame('paid', $this->entry($ref, 'advance')['status']);
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 5000000, 'Full refund after the commission was paid');
        $this->assertSame(-500000, $this->partnerBalance()['available'], 'the clawback is carried against future commissions');
        $this->assertSame('paid', $this->entry($ref, 'advance')['status'], 'a paid entry is never rewritten');
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'at most');
        // a new qualifying payment first repays the carried debt
        $this->pay('advance', '50000');
        $e2 = $this->entry($ref, 'advance');
        $this->assertSame(500000, (int) $e2['amount_minor']);
        $this->approveEntry($e2);
        $this->assertSame(0, $this->partnerBalance()['available']);
    }

    public function testCancellationKeepsTheAdvanceByDefaultAndNeverEarnsTheSecondMilestone(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $this->approveEntry($this->entry($ref, 'advance'));
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->alpha['id']]);
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame('payable', $this->entry($ref, 'advance')['status'], 'advance without completion: the earned milestone is kept (default policy)');
        $this->pay('balance', '50000'); // a late payment on a cancelled project
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'a cancelled project never earns the full-payment milestone');
    }

    public function testCancellationCanReverseTheAdvanceWhenTheRuleSaysSo(): void
    {
        $this->useRules(['advance_without_completion' => 'reverse']);
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($ref, 'advance'));
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->alpha['id']]);
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $this->assertSame('reversed', Database::one('SELECT status FROM commission_entries WHERE id = ?', [$e['id']])['status']);
        $this->assertSame(0, $this->partnerBalance()['available']);
    }

    // ------------------------------------------------------------------------------------------------ rules

    public function testChangingTheRulesNeverRewritesAnExistingEntry(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $before = $this->entry($ref, 'advance');
        $this->useRules(['advance_rate_bps' => 2000]);
        CommissionService::evaluateProject((int) $this->alpha['id']);
        $after = $this->entry($ref, 'advance');
        $this->assertSame(500000, (int) $after['amount_minor'], 'calculated under rules v0 and left alone');
        $this->assertSame((string) $before['formula_json'], (string) $after['formula_json']);
        $this->assertSame(1, CommissionRules::current()['version']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'commission.rules_changed'")['c']);
        // a NEW entry (another referral, another project) uses the new rate
        $ref2 = $this->creditedReferral($this->partner2, 'second@prospect.test', $this->bravo);
        $this->pay('advance', '25000', '0', $this->bravo);
        $this->assertSame(500000, (int) $this->entry($ref2, 'advance')['amount_minor'], '20 % x 25,000');
        $this->assertSame(1, (int) json_decode($this->entry($ref2, 'advance')['formula_json'], true)['rules_version']);
    }

    public function testOnlyAuthorisedPeopleChangeTheRulesAndInputsAreValidated(): void
    {
        $rules = CommissionRules::current()['rules'];
        $in = $rules + ['full_payment_tolerance_minor' => '0', 'min_payout_minor' => '0'];
        foreach ([$this->manager, $this->finance, $this->partner, $this->client, $this->dev] as $u) {
            try {
                CommissionRules::save($u, $in, 'because');
                $this->fail('must be refused');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        foreach ([['advance_rate_bps' => '-1'], ['advance_rate_bps' => '9999'], ['full_rate_bps' => 'x'], ['formula_mode' => 'magic'], ['advance_without_completion' => 'x'], ['payable_hold_days' => '999']] as $bad) {
            $this->expectInvalid(fn () => CommissionRules::save($this->admin, $bad + $in, 'because'));
        }
        $this->assertSame(0, CommissionRules::current()['version']);
        $this->assertSame(0, CommissionRules::save($this->admin, $in, 'no change'), 'saving identical rules creates no version');
    }

    // ------------------------------------------------------------------------------------------------ approval, hold, adjustments

    public function testApprovalNeedsADifferentPersonFromTheOneWhoRecordedOrVerifiedThePayment(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000'); // recorded by finance, verified by admin
        $e = $this->entry($ref, 'advance');
        $this->expectInvalid(fn () => CommissionService::approve($this->admin, $e), 'someone else must approve');
        foreach ([$this->finance, $this->manager, $this->partner, $this->client, $this->dev] as $u) {
            try {
                CommissionService::approve($u, $e);
                $this->fail('must be refused');
            } catch (HttpException $x) {
                $this->assertSame(403, $x->status);
            }
        }
        $this->assertSame('eligible_for_review', $this->entry($ref, 'advance')['status']);
        $a = $this->approveEntry($e);
        $this->assertSame(['payable', $this->owner], [$a['status'], (int) $a['approved_by']]);
        $this->expectInvalid(fn () => CommissionService::approve($this->owner, $a), 'Eligible for Review');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'commission.approved' AND actor_id = ?", [$this->owner])['c']);
    }

    public function testHoldReleaseAndTheHoldPeriod(): void
    {
        $this->useRules(['payable_hold_days' => 7]);
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $a = $this->approveEntry($this->entry($ref, 'advance'));
        $this->assertSame('approved', $a['status']);
        $this->assertSame(\Vertex\Services\AppTime::todayPlus(7), $a['payable_after']);
        $this->assertSame(0, $this->partnerBalance()['available']);
        $this->assertSame(500000, $this->partnerBalance()['approved']);
        CommissionService::hold($this->owner, $a, 'Checking the client contract for a clause on referral fees.', 'Under review by our team.');
        $h = Database::one('SELECT * FROM commission_entries WHERE id = ?', [$a['id']]);
        $this->assertSame(['on_hold', 'approved'], [$h['status'], $h['held_from']]);
        $this->assertSame(500000, $this->partnerBalance()['on_hold']);
        CommissionService::release($this->owner, $h);
        $this->assertSame('approved', Database::one('SELECT status FROM commission_entries WHERE id = ?', [$a['id']])['status']);
        \Vertex\Services\AppTime::freeze(gmdate('Y-m-d H:i:s', time() + 8 * 86400));
        try {
            CommissionService::releaseDue((int) $this->partnerRow['id']);
            $this->assertSame('payable', Database::one('SELECT status FROM commission_entries WHERE id = ?', [$a['id']])['status']);
            $this->assertSame(500000, $this->partnerBalance()['available']);
        } finally {
            \Vertex\Services\AppTime::freeze(null);
        }
    }

    public function testManualAdjustmentsAndReversalsAreControlledAndPreserveTheOriginal(): void
    {
        $ref = $this->creditedReferral();
        $this->pay('advance', '50000');
        $e = $this->approveEntry($this->entry($ref, 'advance'));
        foreach ([$this->manager, $this->finance, $this->partner] as $u) {
            try {
                CommissionService::adjust($u, $e, -1000, 'trying to reduce the commission');
                $this->fail('must be refused');
            } catch (HttpException $x) {
                $this->assertSame(403, $x->status);
            }
        }
        $this->expectInvalid(fn () => CommissionService::adjust($this->owner, $e, 0, 'A reason that is long enough.'), 'non-zero');
        $this->expectInvalid(fn () => CommissionService::adjust($this->owner, $e, -999999999, 'A reason that is long enough.'), 'reversal');
        $id = CommissionService::adjust($this->owner, $e, -20000, 'Correcting a miskeyed tax amount on the payment.');
        $this->assertSame(480000, $this->partnerBalance()['available']);
        $this->assertSame(500000, (int) Database::one('SELECT amount_minor FROM commission_entries WHERE id = ?', [$e['id']])['amount_minor']);
        // a positive correction is an earning that needs approval
        $up = CommissionService::adjust($this->owner, Database::one('SELECT * FROM commission_entries WHERE id = ?', [$e['id']]), 10000, 'Restoring part of the correction after review.');
        $line = Database::one('SELECT * FROM commission_entries WHERE id = ?', [$up]);
        $this->assertSame('eligible_for_review', $line['status']);
        $this->assertSame(480000, $this->partnerBalance()['available'], 'not payable until approved');
        $this->expectInvalid(fn () => CommissionService::approve($this->owner, $line), 'created an adjustment');
        CommissionService::approve($this->admin, $line);
        $this->assertSame(490000, $this->partnerBalance()['available']);
        // reversal: the original and its lines are closed, nothing is deleted
        CommissionService::reverse($this->owner, Database::one('SELECT * FROM commission_entries WHERE id = ?', [$e['id']]), 'The referral agreement was breached by the partner.');
        $this->assertSame(0, $this->partnerBalance()['available']);
        $this->assertSame(['reversed', 500000], [Database::one('SELECT status FROM commission_entries WHERE id = ?', [$e['id']])['status'], (int) Database::one('SELECT amount_minor FROM commission_entries WHERE id = ?', [$e['id']])['amount_minor']]);
        $this->assertSame(3, (int) Database::one('SELECT COUNT(*) c FROM commission_entries WHERE referral_id = ? AND milestone IN (\'advance\', \'adjustment\')', [$ref['id']])['c']);
        $this->assertGreaterThanOrEqual(5, (int) Database::one('SELECT COUNT(*) c FROM commission_history')['c']);
        $this->assertTrue(Audit::verify()['ok']);
    }

    public function testAnExistingPaymentOrProjectRecordIsNeverModifiedByCommissionActivity(): void
    {
        $ref = $this->creditedReferral();
        $p1 = $this->pay('advance', '50000');
        $project = Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$project['client_id']]);
        $before = Database::one('SELECT id, public_id, reference, project_id, client_id, invoice_ref, payment_role, gross_minor, tax_minor, currency, method, external_ref, external_key, received_on, recorded_by FROM client_payments WHERE id = ?', [$p1['id']]);
        $e = $this->approveEntry($this->entry($ref, 'advance'));
        $this->pay('balance', '50000');
        CommissionService::hold($this->owner, $e, 'A long enough reason for the hold.');
        CommissionService::release($this->owner, Database::one('SELECT * FROM commission_entries WHERE id = ?', [$e['id']]));
        $this->assertSame($before, Database::one('SELECT id, public_id, reference, project_id, client_id, invoice_ref, payment_role, gross_minor, tax_minor, currency, method, external_ref, external_key, received_on, recorded_by FROM client_payments WHERE id = ?', [$p1['id']]));
        $this->assertSame($project, Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]), 'the project, its approved price and status are untouched');
        $this->assertSame($client, Database::one('SELECT * FROM clients WHERE id = ?', [$project['client_id']]));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
    }

    public function testMoneyArithmeticIsExactForLargeAmounts(): void
    {
        $m = \Vertex\Services\Money::class;
        $this->assertSame(9000000000000, $m::mulDiv(9000000000000, 9000000000000, 9000000000000));
        $this->assertSame(10, $m::mulDiv(7, 3, 2));
        $this->assertSame(0, $m::mulDiv(0, 5, 3));
        $this->assertSame(333333333333, $m::bps(3333333333333, 1000));
        $this->assertSame(1, $m::mulDiv(1, 1, 1));
        $this->assertSame(499999999999999, $m::mulDiv(999999999999999, 1, 2));
        $this->expectException(\InvalidArgumentException::class);
        $m::mulDiv(1, 1, 0);
    }
}
