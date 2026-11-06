<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\Audit;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CommissionService;
use Vertex\Services\PartnerService;
use Vertex\Services\PayoutService;
use Vertex\Services\SecretBox;

/** Balance validation, concurrency, approval versus payment, separation of duties, protected payout details. */
final class PayoutServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    /** @var array<string,mixed> */
    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        $this->ref = $this->creditedReferral();
        $this->saveDetails($this->partner, $this->partnerRow);
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

    private function expectStatus(callable $fn, int $status): void
    {
        try {
            $fn();
            $this->fail("Expected HTTP {$status}");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    /** Earns INR 15,000: advance 5,000 + full 10,000, both approved and payable. */
    private function earn(): void
    {
        $this->pay('advance', '50000');
        $this->approveEntry($this->entry($this->ref, 'advance'));
        $this->pay('balance', '50000');
        $this->approveEntry($this->entry($this->ref, 'full'));
        $this->assertSame(1500000, $this->partnerBalance()['available']);
    }

    private function payout(string $publicId): array
    {
        return PayoutService::find($publicId);
    }

    private function bankPay(string $publicId, int $actor, array $over = []): void
    {
        PayoutService::recordPayment($actor, $this->payout($publicId), $over + ['method' => 'bank_transfer', 'reference' => 'NEFT' . random_int(100000, 999999), 'date' => AppTime::today(), 'amount' => '5000']);
    }

    // ------------------------------------------------------------------------------------------------ requests and balance

    public function testAPartnerCanRequestUpToTheirAvailableBalanceAndNeverMore(): void
    {
        $this->earn();
        $this->expectInvalid(fn () => $this->requestPayout('15000.01'), 'at most');
        $this->expectInvalid(fn () => $this->requestPayout('0'), 'greater than zero');
        $this->expectInvalid(fn () => $this->requestPayout('-5'));
        $this->expectInvalid(fn () => $this->requestPayout('abc'));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM payout_requests')['c']);
        $r = $this->requestPayout('6000');
        $p = $this->payout($r['public_id']);
        $this->assertMatchesRegularExpression('/^PYO-\d{4}-\d{5}$/', $p['reference']);
        $this->assertSame(['requested', 600000, 1500000], [$p['status'], (int) $p['amount_minor'], (int) $p['available_minor']]);
        $b = $this->partnerBalance();
        $this->assertSame([900000, 600000], [$b['available'], $b['reserved']]);
        $this->expectInvalid(fn () => $this->requestPayout('9000.01'), 'at most');
        $this->requestPayout('9000');
        $this->assertSame(0, $this->partnerBalance()['available']);
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'at most');
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'payout.requested'")['c']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'payout.requested'", [$this->admin])['c']);
    }

    public function testNothingIsRequestableBeforeCommissionsArePayableAndDetailsExist(): void
    {
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'at most');
        $this->pay('advance', '50000'); // eligible for review, not approved
        $this->assertSame(0, $this->partnerBalance()['available']);
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'at most');
        $this->approveEntry($this->entry($this->ref, 'advance'));
        Database::run('UPDATE referral_partners SET payout_details_enc = NULL, payout_details_hint = NULL WHERE id = ?', [$this->partnerRow['id']]);
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'payout details');
    }

    public function testTheMinimumPayoutRuleAndIdempotentSubmissions(): void
    {
        $this->earn();
        $this->useRules(['min_payout_minor' => 100000]);
        $this->expectInvalid(fn () => $this->requestPayout('999'), 'smallest payout');
        $tok = bin2hex(random_bytes(16));
        $a = PayoutService::request($this->partner, ['amount' => '2000', 'notes' => 'Please pay by NEFT.', 'token' => $tok]);
        $b = PayoutService::request($this->partner, ['amount' => '2000', 'notes' => 'Please pay by NEFT.', 'token' => $tok]);
        $this->assertTrue($a['created']);
        $this->assertFalse($b['created']);
        $this->assertSame($a['public_id'], $b['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM payout_requests')['c']);
        $this->assertSame(1300000, $this->partnerBalance()['available'], 'the retry reserved nothing more');
    }

    public function testOnlyActivePartnersWithThePermissionCanRequestAndTheyOnlySpendTheirOwnBalance(): void
    {
        $this->earn();
        foreach ([$this->client, $this->dev, $this->manager, $this->finance, $this->admin] as $u) {
            $this->expectStatus(fn () => $this->requestPayout('1', $u), 404);
        }
        $this->expectInvalid(fn () => $this->requestPayout('1', $this->partner2), 'payout details');
        $this->saveDetails($this->partner2, $this->partner2Row);
        $this->expectInvalid(fn () => $this->requestPayout('1', $this->partner2), 'at most');
        PartnerService::setSuspended($this->admin, $this->partnerRow, true, 'Programme breach under review.');
        $this->expectStatus(fn () => $this->requestPayout('1'), 403);
    }

    public function testReservationsAreOldestFirstAndEntriesFollowTheirPayoutState(): void
    {
        $this->earn();
        $r = $this->requestPayout('5000'); // exactly the advance entry
        $adv = $this->entry($this->ref, 'advance');
        $full = $this->entry($this->ref, 'full');
        $this->assertSame(['payout_requested', 'payable'], [$adv['status'], $full['status']]);
        $r2 = $this->requestPayout('4000'); // part of the full entry
        $this->assertSame('payable', $this->entry($this->ref, 'full')['status'], 'partly reserved entries stay payable for the rest');
        $this->assertSame(600000, $this->partnerBalance()['available']);
        $alloc = PayoutService::allocations($this->payout($r2['public_id']));
        $this->assertSame([400000], array_map('intval', array_column($alloc, 'amount_minor')));
        $this->assertSame($full['reference'], $alloc[0]['reference']);
        $this->requestPayout('6000');
        $this->assertSame('payout_requested', $this->entry($this->ref, 'full')['status']);
        $this->assertNotNull($r);
    }

    // ------------------------------------------------------------------------------------------------ rejection, cancellation

    public function testARejectedRequestReleasesTheReservationAndTheReasonIsRecorded(): void
    {
        $this->earn();
        $r = $this->requestPayout('15000');
        $this->assertSame(0, $this->partnerBalance()['available']);
        $p = $this->payout($r['public_id']);
        $this->expectInvalid(fn () => PayoutService::reject($this->admin, $p, ''), 'required');
        $this->expectInvalid(fn () => PayoutService::reject($this->admin, $p, 'no'), 'must be');
        foreach ([$this->finance, $this->manager, $this->partner, $this->client, $this->dev] as $u) {
            $this->expectStatus(fn () => PayoutService::reject($u, $p, 'Not authorised to reject this request.'), 403);
        }
        PayoutService::reject($this->admin, $p, 'Please add your tax details before we can pay you.');
        $row = $this->payout($r['public_id']);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame(1500000, $this->partnerBalance()['available'], 'the reserved amount is released');
        $this->assertSame(0, $this->partnerBalance()['reserved']);
        $this->assertSame(['payable', 'payable'], [$this->entry($this->ref, 'advance')['status'], $this->entry($this->ref, 'full')['status']]);
        $this->assertSame('Please add your tax details before we can pay you.', PayoutService::partnerView($row)['reason']);
        $this->assertStringContainsString('tax details', json_encode(PayoutService::history_for($row, false)));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'payout.rejected'", [$this->partner])['c']);
        $this->expectInvalid(fn () => PayoutService::reject($this->admin, $row, 'Rejecting a second time should fail.'), 'cannot be rejected');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'payout.rejected'")['c']);
        // the released money can be requested again
        $this->requestPayout('15000');
    }

    public function testAPartnerCanCancelAnOpenRequestButNotAnApprovedOne(): void
    {
        $this->earn();
        $r = $this->requestPayout('3000');
        $this->expectStatus(fn () => PayoutService::cancelByPartner($this->partner2, $this->payout($r['public_id'])), 404);
        PayoutService::cancelByPartner($this->partner, $this->payout($r['public_id']));
        $this->assertSame(['cancelled', 1500000], [$this->payout($r['public_id'])['status'], $this->partnerBalance()['available']]);
        $r2 = $this->requestPayout('3000');
        PayoutService::approve($this->admin, $this->payout($r2['public_id']));
        $this->expectInvalid(fn () => PayoutService::cancelByPartner($this->partner, $this->payout($r2['public_id'])), 'no longer be cancelled');
        $this->assertSame('approved', $this->payout($r2['public_id'])['status']);
    }

    // ------------------------------------------------------------------------------------------------ approval is not payment

    public function testApprovingNeverMarksAPayoutPaidAndPaymentIsASeparateRecordedAct(): void
    {
        $this->earn();
        $r = $this->requestPayout('5000');
        $id = $r['public_id'];
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance), 'Approval is a separate step');
        PayoutService::startReview($this->admin, $this->payout($id));
        $this->assertSame('under_review', $this->payout($id)['status']);
        PayoutService::approve($this->admin, $this->payout($id), 'Details checked.');
        $p = $this->payout($id);
        $this->assertSame(['approved', $this->admin], [$p['status'], (int) $p['approved_by']]);
        $this->assertNull($p['paid_by']);
        $this->assertNull($p['paid_at']);
        $this->assertNull($p['payment_reference']);
        $this->assertSame('payout_requested', $this->entry($this->ref, 'advance')['status'], 'approval does not mark the commission paid');
        $this->assertSame(0, $this->partnerBalance()['paid']);
        $this->assertSame(500000, $this->partnerBalance()['reserved']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'payout.approved'", [$this->partner])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'payout.ready_to_pay'", [$this->finance])['c']);
        // only now the payment is recorded, by someone else
        $this->expectInvalid(fn () => $this->bankPay($id, $this->admin), 'cannot also record');
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['amount' => '4999']), 'must equal the approved amount');
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['reference' => '']), 'required');
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['date' => '2999-01-01']), 'Payment date');
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['date' => date('Y-m-d', strtotime('+3 days'))]), 'future');
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['method' => 'magic']));
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance, ['date' => '2020-01-01']), 'before the payout was requested');
        $this->assertSame('approved', $this->payout($id)['status']);
        PayoutService::markProcessing($this->finance, $this->payout($id));
        $this->assertSame('processing', $this->payout($id)['status']);
        $this->bankPay($id, $this->finance, ['reference' => 'NEFT-998877']);
        $p = $this->payout($id);
        $this->assertSame(['paid', $this->finance, $this->admin, 'NEFT-998877', 500000], [$p['status'], (int) $p['paid_by'], (int) $p['approved_by'], $p['payment_reference'], (int) $p['paid_amount_minor']]);
        $this->assertSame('paid', $this->entry($this->ref, 'advance')['status']);
        $b = $this->partnerBalance();
        $this->assertSame([1000000, 0, 500000], [$b['available'], $b['reserved'], $b['paid']]);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'payout.paid'")['c']);
        $meta = json_decode(Database::one("SELECT meta_json FROM audit_logs WHERE action = 'payout.paid'")['meta_json'], true);
        $this->assertSame([$this->admin, $this->finance], [$meta['approved_by'], $meta['paid_by']], 'the approver and the payer are both on record');
        // it can never be paid twice
        $this->expectInvalid(fn () => $this->bankPay($id, $this->finance), 'Approval is a separate step');
        $this->expectInvalid(fn () => PayoutService::reject($this->admin, $this->payout($id), 'Trying to reject after the payment.'), 'cannot be rejected');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'payout.paid'", [$this->partner])['c']);
    }

    public function testASeparateFinanceUserIsNotNeededWhenSeparationOfDutiesIsOffButSamePersonIsOtherwiseRefused(): void
    {
        $this->earn();
        $a = $this->requestPayout('1000');
        PayoutService::approve($this->admin, $this->payout($a['public_id']));
        $this->expectInvalid(fn () => $this->bankPay($a['public_id'], $this->admin, ['amount' => '1000']), 'cannot also record');
        $this->useRules(['separation_of_duties' => false]);
        $this->bankPay($a['public_id'], $this->admin, ['amount' => '1000']);
        $this->assertSame('paid', $this->payout($a['public_id'])['status']);
    }

    public function testTheOwnerMayApproveAndPayTheSamePayout(): void
    {
        $this->earn();
        $a = $this->requestPayout('1000');
        PayoutService::approve($this->owner, $this->payout($a['public_id']));
        $this->bankPay($a['public_id'], $this->owner, ['amount' => '1000']);
        $this->assertSame('paid', $this->payout($a['public_id'])['status'], 'the Owner is exempt from the second-person rule; the audit log still names them');
    }

    public function testPaymentReferencesAreUniqueAcrossPayouts(): void
    {
        $this->earn();
        $a = $this->requestPayout('1000');
        $b = $this->requestPayout('1000');
        foreach ([$a, $b] as $x) {
            PayoutService::approve($this->admin, $this->payout($x['public_id']));
        }
        $this->bankPay($a['public_id'], $this->finance, ['reference' => 'SAME-REF-1', 'amount' => '1000']);
        $this->expectInvalid(fn () => $this->bankPay($b['public_id'], $this->finance, ['reference' => 'SAME-REF-1', 'amount' => '1000']), 'already been used');
        $this->assertSame('approved', $this->payout($b['public_id'])['status']);
    }

    public function testRolePermissionsForPayoutActions(): void
    {
        $this->earn();
        $r = $this->requestPayout('2000');
        $p = $this->payout($r['public_id']);
        foreach ([$this->finance, $this->manager, $this->support, $this->partner, $this->client, $this->dev] as $u) {
            $this->expectStatus(fn () => PayoutService::approve($u, $p), 403);
            $this->expectStatus(fn () => PayoutService::startReview($u, $p), 403);
        }
        PayoutService::approve($this->admin, $p);
        foreach ([$this->manager, $this->support, $this->partner, $this->client, $this->dev] as $u) {
            $this->expectStatus(fn () => $this->bankPay($r['public_id'], $u, ['amount' => '2000']), 403);
            $this->expectStatus(fn () => PayoutService::markProcessing($u, $this->payout($r['public_id'])), 403);
            $this->expectStatus(fn () => PartnerService::revealForPayout($u, $this->payout($r['public_id'])), 403);
        }
        // read access
        foreach ([$this->partner, $this->client, $this->dev, $this->manager] as $u) {
            $this->expectStatus(fn () => PayoutService::requireStaff($u, $r['public_id']), 404);
            $this->assertSame(0, PayoutService::paginate($u, [])['total']);
        }
        $this->assertSame(1, PayoutService::paginate($this->finance, [])['total'], 'finance staff can see payout requests');
        $this->expectStatus(fn () => PayoutService::requireOwn($this->partner2, $r['public_id']), 404);
        $this->assertSame($r['public_id'], PayoutService::requireOwn($this->partner, $r['public_id'])['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ ledger changes during a request

    public function testApprovalIsRefusedWhenARefundMadeTheBalanceNegativeAfterTheRequest(): void
    {
        $this->earn();
        $p1 = Database::one("SELECT * FROM client_payments WHERE payment_role = 'advance'");
        $r = $this->requestPayout('15000');
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p1['public_id']), 5000000, 'The advance was refunded in full after a dispute.');
        $this->assertLessThan(0, $this->partnerBalance()['available']);
        $this->expectInvalid(fn () => PayoutService::approve($this->admin, $this->payout($r['public_id'])), 'ledger changed');
        PayoutService::reject($this->admin, $this->payout($r['public_id']), 'The commission changed after a refund; please request again.');
        $this->assertSame(0, $this->partnerBalance()['available'], 'the refund removed both milestones: nothing is owed');
        $this->expectInvalid(fn () => $this->requestPayout('1'), 'at most');
    }

    public function testAReservedEntryCannotBeHeldOrReversedUntilThePayoutIsResolved(): void
    {
        $this->earn();
        $r = $this->requestPayout('5000');
        $adv = $this->entry($this->ref, 'advance');
        $this->expectInvalid(fn () => CommissionService::hold($this->owner, $adv, 'A reason that is long enough here.'), 'reserved');
        $this->expectInvalid(fn () => CommissionService::reverse($this->owner, $adv, 'A reason that is long enough here.'), 'reserved');
        PayoutService::cancelByPartner($this->partner, $this->payout($r['public_id']));
        CommissionService::hold($this->owner, $this->entry($this->ref, 'advance'), 'A reason that is long enough here.');
        $this->assertSame(1000000, $this->partnerBalance()['available'], 'a held entry is not available');
    }

    // ------------------------------------------------------------------------------------------------ concurrency

    /** @return list<array<string,mixed>> */
    private function race(array $partnerUsers, string $amount): array
    {
        $start = microtime(true) + 1.5;
        $procs = [];
        foreach ($partnerUsers as $u) {
            $proc = proc_open([PHP_BINARY, VERTEX_ROOT . '/tests/Support/payout_race.php', (string) $u, $amount, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, VERTEX_ROOT,
                ['PATH' => (string) getenv('PATH'), 'UPLOAD_STORAGE_PATH' => (string) getenv('UPLOAD_STORAGE_PATH')]);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $line = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            proc_close($proc);
            $out[] = json_decode($line, true) ?? ['ok' => false, 'message' => 'no output: ' . $err];
        }

        return $out;
    }

    public function testConcurrentRequestsCannotReserveTheSameBalanceTwice(): void
    {
        $this->earn(); // INR 15,000 available
        $results = $this->race(array_fill(0, 6, $this->partner), '6000');
        $ok = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
        $this->assertCount(2, $ok, 'two requests of INR 6,000 fit into INR 15,000; the other four must be refused: ' . json_encode($results));
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM payout_requests')['c']);
        $this->assertSame(1200000, (int) Database::one('SELECT SUM(amount_minor) s FROM payout_requests')['s']);
        $alloc = (int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) s FROM payout_allocations WHERE released_at IS NULL')['s'];
        $this->assertSame(1200000, $alloc, 'allocations equal the requests: nothing reserved twice');
        $this->assertSame(300000, $this->partnerBalance()['available']);
        foreach (Database::all('SELECT e.amount_minor, COALESCE(SUM(a.amount_minor), 0) alloc FROM commission_entries e LEFT JOIN payout_allocations a ON a.entry_id = e.id AND a.released_at IS NULL GROUP BY e.id, e.amount_minor') as $e) {
            $this->assertLessThanOrEqual((int) $e['amount_minor'], (int) $e['alloc'], 'no entry is over-allocated');
        }
        $refused = array_filter($results, static fn (array $r): bool => !$r['ok']);
        foreach ($refused as $r) {
            $this->assertStringContainsString('at most', (string) $r['message']);
        }
    }

    public function testConcurrentSmallRequestsNeverExceedTheBalanceInTotal(): void
    {
        $this->earn();
        $results = $this->race(array_fill(0, 10, $this->partner), '1700');
        $ok = count(array_filter($results, static fn (array $r): bool => $r['ok']));
        $this->assertSame(8, $ok, 'floor(15000 / 1700) = 8 requests fit: ' . json_encode($results));
        $this->assertSame(8 * 170000, (int) Database::one('SELECT SUM(amount_minor) s FROM payout_requests')['s']);
        $this->assertSame(1500000 - 8 * 170000, $this->partnerBalance()['available']);
        $this->assertGreaterThanOrEqual(0, $this->partnerBalance()['available']);
    }

    // ------------------------------------------------------------------------------------------------ protected payout details

    public function testPayoutDetailsAreEncryptedMaskedAndOnlyShownToThePayerOnAnApprovedRequestWithAnAuditEntry(): void
    {
        $row = Database::one('SELECT * FROM referral_partners WHERE id = ?', [$this->partnerRow['id']]);
        $this->assertStringNotContainsString('1234567890123456', (string) $row['payout_details_enc']);
        $this->assertStringNotContainsString('Priya Partner', (string) $row['payout_details_enc']);
        $this->assertSame('Bank transfer •••• 3456', $row['payout_details_hint']);
        $this->assertSame('1234567890123456', json_decode((string) SecretBox::open((string) $row['payout_details_enc']), true)['identifier']);
        $this->earn();
        $r = $this->requestPayout('5000');
        $p = $this->payout($r['public_id']);
        $this->assertSame('Bank transfer •••• 3456', $p['details_hint']);
        // nothing can be revealed before approval
        $this->assertNull(PartnerService::revealForPayout($this->admin, $p));
        PayoutService::approve($this->admin, $p);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'payout.details_viewed'")['c']);
        $d = PartnerService::revealForPayout($this->finance, $this->payout($r['public_id']));
        $this->assertSame(['bank_transfer', 'Priya Partner', '1234567890123456'], [$d['method'], $d['name'], $d['identifier']]);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'payout.details_viewed' AND actor_id = ?", [$this->finance])['c'], 'every view is audited');
        // the partner changing details later does not alter the request's snapshot
        PartnerService::saveDetails($this->partner, PartnerService::forUser($this->partner), ['method' => 'upi', 'account_name' => 'Priya P', 'identifier' => 'priya@okbank', 'bank_name' => '']);
        $d2 = PartnerService::revealForPayout($this->finance, $this->payout($r['public_id']));
        $this->assertSame('1234567890123456', $d2['identifier']);
        $this->assertSame('UPI •••• bank', PartnerService::forUser($this->partner)['payout_details_hint']);
        // never in the logs, the partner's own view or any list
        $everything = json_encode([Database::all('SELECT before_json, after_json, meta_json FROM audit_logs'), PayoutService::partnerView($this->payout($r['public_id'])), PayoutService::paginateOwn($this->partner), PayoutService::paginate($this->admin, [])]);
        foreach (['1234567890123456', 'priya@okbank', 'Priya Partner'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertStringNotContainsString('details_enc', json_encode(PayoutService::partnerView($this->payout($r['public_id']))));
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'partner.payout_details_changed'")['c']);
    }

    public function testSecretBoxRejectsTamperingAndOtherKeys(): void
    {
        $s = SecretBox::seal('top secret');
        $this->assertSame('top secret', SecretBox::open($s));
        $this->assertNotSame($s, SecretBox::seal('top secret'), 'a fresh nonce every time');
        $raw = base64_decode($s);
        $raw[30] = $raw[30] ^ "\x01";
        $this->assertNull(SecretBox::open(base64_encode($raw)));
        $this->assertNull(SecretBox::open('garbage'));
        $this->assertNull(SecretBox::open(''));
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('z', 32)));
        $this->assertNull(SecretBox::open($s), 'another APP_KEY cannot open it');
        Config::set('app.key', '');
        $this->expectException(\RuntimeException::class);
        SecretBox::seal('x');
    }

    public function testPayoutDetailsInputIsValidatedAndOnlyThePartnerCanSetTheirOwn(): void
    {
        $p = PartnerService::forUser($this->partner);
        foreach ([['method' => 'x'], ['account_name' => ''], ['identifier' => 'ab'], ['bank_name' => '']] as $bad) {
            $this->expectInvalid(fn () => PartnerService::saveDetails($this->partner, $p, $bad + ['method' => 'bank_transfer', 'account_name' => 'A Person', 'identifier' => '123456789', 'bank_name' => 'Bank']));
        }
        $this->expectStatus(fn () => PartnerService::saveDetails($this->partner2, $p, ['method' => 'upi', 'account_name' => 'Mallory', 'identifier' => 'mallory@bank', 'bank_name' => '']), 404);
        $this->expectStatus(fn () => PartnerService::saveDetails($this->client, $p, ['method' => 'upi', 'account_name' => 'Mallory', 'identifier' => 'mallory@bank', 'bank_name' => '']), 404);
        $this->assertSame('Bank transfer •••• 3456', PartnerService::forUser($this->partner)['payout_details_hint']);
    }

    public function testTheAuditChainAndTheLedgerStayConsistentThroughAWholePayoutCycle(): void
    {
        $this->earn();
        $r = $this->requestPayout('15000');
        PayoutService::approve($this->admin, $this->payout($r['public_id']));
        $this->bankPay($r['public_id'], $this->finance, ['amount' => '15000']);
        $b = $this->partnerBalance();
        $this->assertSame([0, 0, 1500000], [$b['available'], $b['reserved'], $b['paid']]);
        $this->assertSame(['paid', 'paid'], [$this->entry($this->ref, 'advance')['status'], $this->entry($this->ref, 'full')['status']]);
        $this->assertTrue(Audit::verify()['ok']);
        $this->assertGreaterThanOrEqual(4, (int) Database::one('SELECT COUNT(*) c FROM payout_history')['c']);
        $dash = PayoutService::dashboard($this->admin);
        $this->assertSame([0, 0, 1, 1500000], [$dash['awaiting_review'], $dash['awaiting_payment'], $dash['paid_count'], $dash['paid_minor']]);
    }
}
