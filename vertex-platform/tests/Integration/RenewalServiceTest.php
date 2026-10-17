<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Audit;
use Vertex\Services\Mailer;
use Vertex\Services\RenewalPricing;
use Vertex\Services\RenewalScheduler;
use Vertex\Services\RenewalService;

/** Renewal records, the approval/invoice/payment/provider-confirmation workflow, pricing snapshots, the scheduled task and reminders. */
final class RenewalServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    private int $owner;
    private string $clientId;
    private string $client2Id;
    private const TODAY = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->clientId = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $this->client2Id = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client2])['public_id'];
        Mailer::$sent = [];
        Config::set('mail.driver', 'none');
        Config::set('renewals.client_email', false);
        \Vertex\Services\AppTime::freeze(self::TODAY . ' 06:00:00');
    }

    protected function tearDown(): void
    {
        \Vertex\Services\AppTime::freeze(null);
        Mailer::$sent = [];
        $this->tearDownPhase3();
    }

    private function form(array $over = []): array
    {
        return $over + ['service_type' => 'domain', 'identifier' => 'Example-Shop.com', 'provider' => 'Fictional Registrar', 'activated_on' => '2025-06-20', 'expires_on' => '2026-07-20', 'period_months' => '12',
            'internal_notes' => 'Registered through the agency account.', 'client_notes' => 'We manage your domain.', 'client_reminders' => '1', 'project' => ''];
    }

    /** @return array<string,mixed> */
    private function make(array $over = [], ?string $client = null): array
    {
        $r = RenewalService::create($this->manager, $client ?? $this->clientId, $this->form($over));

        return RenewalService::find($r['public_id']);
    }

    private function fresh(array $s): array
    {
        return RenewalService::find($s['public_id']);
    }

    private function addPrice(string $type = 'domain', int $months = 12, string $base = '1000', string $tax = '18', string $from = '2026-01-01'): void
    {
        RenewalPricing::add($this->owner, ['service_type' => $type, 'period_months' => (string) $months, 'base' => $base, 'markup' => '200', 'discount' => '0', 'tax_percent' => $tax, 'effective_from' => $from, 'note' => 'Standard price']);
    }

    private function expectInvalid(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    /** Takes a service all the way to Awaiting Admin Review. @return array<string,mixed> */
    private function inReview(array $over = []): array
    {
        $s = $this->make($over + ['expires_on' => '2026-06-08']); // 7 days out: create() advances it
        $this->assertSame('awaiting_admin_review', $s['status']);

        return $s;
    }

    private function approveListPrice(array $s): void
    {
        RenewalService::approve($this->admin, $s, ['price_mode' => 'list', 'period_months' => '12']);
    }

    // ------------------------------------------------------------------------------------------------ records

    public function testCreateValidatesAndRecordsTheServiceWithoutAnyCredentialFields(): void
    {
        $s = $this->make();
        $this->assertSame('example-shop.com', $s['identifier'], 'domains are stored lower-case');
        $this->assertSame('active', $s['status']);
        $this->assertMatchesRegularExpression('/^RNW-2026-\d{5}$/', $s['reference']);
        $cols = array_column(Database::all('SHOW COLUMNS FROM renewal_services'), 'Field');
        foreach ($cols as $c) {
            $this->assertDoesNotMatchRegularExpression('/pass|secret|credential|epp|auth_code|token|api_key/i', $c, "no credential column ({$c})");
        }
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'renewal.created'")['c']);
        $this->expectInvalid(fn () => $this->make(['identifier' => 'not a domain']), 'host name');
        $this->expectInvalid(fn () => $this->make(['expires_on' => '2025-01-01']), 'after the activation');
        $this->expectInvalid(fn () => $this->make(['identifier' => 'other.com', 'period_months' => '7']), 'period');
        $this->expectInvalid(fn () => $this->make(), 'already has');
        $this->assertSame('hosting-plan', $this->make(['service_type' => 'hosting', 'identifier' => 'hosting-plan', 'expires_on' => '2027-01-01'])['identifier'], 'non-domain names are free text');
    }

    public function testProviderCredentialsCannotBeTypedIntoNotes(): void
    {
        foreach (['internal_notes' => 'login password: Hunter2!', 'client_notes' => 'EPP code = AB12CD34', ] as $field => $text) {
            $this->expectInvalid(fn () => $this->make([$field => $text, 'identifier' => 'creds-' . str_replace('_', '-', $field) . '.com']), 'password, key or code');
        }
        $this->expectInvalid(fn () => $this->make(['internal_notes' => 'api key: abc123', 'identifier' => 'creds3.com']), 'password, key or code');
        $this->make(['internal_notes' => 'The registrar renewed this last year after a phone call.', 'identifier' => 'fine.com']);
    }

    public function testOnlyPeopleWithThePermissionCanCreateEditOrActAndMissingRecordsAre404(): void
    {
        foreach ([$this->dev, $this->support, $this->client, $this->partner] as $u) {
            try {
                RenewalService::create($u, $this->clientId, $this->form());
                $this->fail('create must be refused');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $s = $this->make();
        $this->expectException(HttpException::class);
        RenewalService::requireStaff($this->dev, $s['public_id']);
    }

    public function testAProjectOfAnotherClientCannotBeLinked(): void
    {
        $this->expectInvalid(fn () => $this->make(['project' => $this->bravo['public_id']]), 'different client');
        $s = $this->make(['project' => $this->alpha['public_id'], 'identifier' => 'linked.com']);
        $this->assertSame((int) $this->alpha['id'], (int) $s['project_id']);
    }

    public function testTypeAndNameAreFixedAfterCreationButOtherFieldsAreEditable(): void
    {
        $s = $this->make();
        RenewalService::update($this->manager, $s, $this->form(['provider' => 'New Registrar', 'internal_notes' => 'moved', 'client_reminders' => '0']));
        $u = $this->fresh($s);
        $this->assertSame('New Registrar', $u['provider']);
        $this->assertSame(0, (int) $u['client_reminders']);
        $this->expectInvalid(fn () => RenewalService::update($this->manager, $u, $this->form(['identifier' => 'changed.com'])), 'cannot be changed');
    }

    // ------------------------------------------------------------------------------------------------ scheduler: stages

    public function testTheScheduledTaskMovesServicesThroughTheStagesByDate(): void
    {
        $far = $this->make(['expires_on' => '2027-06-01', 'identifier' => 'far.com']);
        $window = $this->make(['expires_on' => '2026-06-25', 'identifier' => 'window.com']);
        $soon = $this->make(['expires_on' => '2026-06-08', 'identifier' => 'soon.com']);
        $past = $this->make(['expires_on' => '2026-05-30', 'identifier' => 'past.com', 'activated_on' => '2025-05-30']);
        // create() already normalises, so build the "never ran" state by resetting
        Database::run("UPDATE renewal_services SET status = 'active'");
        $r = RenewalScheduler::run(self::TODAY);
        $this->assertTrue($r['ran']);
        $this->assertSame('active', $this->fresh($far)['status'], 'outside the window: untouched');
        $this->assertSame('renewal_approaching', $this->fresh($window)['status'], '24 days: inside the 30-day window, outside the 10-day review window');
        $this->assertSame('awaiting_admin_review', $this->fresh($soon)['status'], '7 days: Admin review is due');
        $this->assertSame('expired', $this->fresh($past)['status'], 'past the expiry date without confirmation');
        $again = RenewalScheduler::run(self::TODAY);
        $this->assertSame(0, $again['advanced'], 'a second run changes nothing');
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'renewal.review_needed'")['c'], 'approvers are told a review is needed');
    }

    public function testHeldAndCancelledServicesAreNeverAdvancedOrReminded(): void
    {
        $s = $this->make(['expires_on' => '2026-06-30']);
        RenewalService::hold($this->manager, $s, 'Client asked us to pause everything.');
        RenewalScheduler::run(self::TODAY);
        $this->assertSame('on_hold', $this->fresh($s)['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM renewal_reminders')['c']);
        RenewalService::release($this->manager, $this->fresh($s));
        $this->assertSame('renewal_approaching', $this->fresh($s)['status'], 'released back to where it was, then normalised for the date');
    }

    public function testPaidOrInProgressServicesPastTheirExpiryAreFlaggedOverdueNotExpired(): void
    {
        $s = $this->inReview();
        $this->addPrice();
        $this->approveListPrice($s);
        $s = $this->fresh($s);
        $cycle = RenewalService::openCycle($s);
        RenewalService::recordInvoice($this->manager, $s, ['invoice_ref' => 'INV-1', 'amount' => number_format($cycle['total_minor'] / 100, 2, '.', '')]);
        RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => number_format($cycle['total_minor'] / 100, 2, '.', ''), 'method' => 'upi', 'reference' => 'UPI-1', 'received_on' => self::TODAY]);
        RenewalScheduler::run('2026-06-20'); // twelve days after the expiry
        $x = $this->fresh($s);
        $this->assertSame('payment_received', $x['status'], 'paid services are not auto-expired');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_history WHERE to_status = 'expired'")['c']);
        \Vertex\Services\AppTime::freeze('2026-06-20 06:00:00');
        $this->assertTrue(RenewalService::isOverdue($x));
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function testTheFullWorkflowRenewsOnlyAfterProviderConfirmation(): void
    {
        $this->addPrice();
        $s = $this->inReview();
        $this->approveListPrice($s);
        $s = $this->fresh($s);
        $this->assertSame('approved_for_renewal', $s['status']);
        $cycle = RenewalService::openCycle($s);
        $this->assertSame((int) $cycle['net_minor'] + (int) $cycle['tax_minor'], (int) $cycle['total_minor']);
        $this->assertSame(120000, (int) $cycle['net_minor'], '1000 + 200 markup');
        $this->assertSame(21600, (int) $cycle['tax_minor'], '18% of 1,200');
        $this->assertSame(141600, (int) $cycle['total_minor']);
        $total = '1416.00';

        $this->expectInvalid(fn () => RenewalService::recordInvoice($this->manager, $s, ['invoice_ref' => 'INV-9', 'amount' => '1400.00']), 'must equal the approved total');
        RenewalService::recordInvoice($this->manager, $s, ['invoice_ref' => 'INV-9', 'quotation_ref' => 'QT-3', 'amount' => $total]);
        $s = $this->fresh($s);
        $this->assertSame('invoice_issued', $s['status']);
        RenewalService::markInvoiceSent($this->manager, $s);
        $s = $this->fresh($s);
        $this->assertSame('awaiting_payment', $s['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.invoice'", [$this->client])['c']);

        // part payment, a duplicate transaction, an overpayment, then the rest
        RenewalService::recordPayment($this->manager, $s, ['amount' => '400', 'method' => 'upi', 'reference' => 'UPI-A', 'received_on' => self::TODAY]);
        $this->assertSame('awaiting_payment', $this->fresh($s)['status']);
        $this->expectInvalid(fn () => RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '400', 'method' => 'upi', 'reference' => 'upi-a', 'received_on' => self::TODAY]), 'already been recorded');
        $this->expectInvalid(fn () => RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '2000', 'method' => 'upi', 'reference' => 'UPI-B', 'received_on' => self::TODAY]), 'still due');
        $this->expectInvalid(fn () => RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '100', 'method' => 'upi', 'reference' => 'UPI-F', 'received_on' => '2030-01-01']), 'future');
        RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '1016', 'method' => 'bank_transfer', 'reference' => 'UTR-77', 'received_on' => self::TODAY]);
        $s = $this->fresh($s);
        $this->assertSame('payment_received', $s['status']);
        $this->assertSame('2026-06-08', $s['expires_on'], 'PAYMENT DOES NOT RENEW: the expiry date is unchanged');
        $this->assertNull($s['last_renewed_at']);
        $c = RenewalService::openCycle($s);
        $this->assertSame(141600, (int) $c['paid_minor']);
        $this->assertNull($c['provider_confirmed_at']);

        // the provider confirmation cannot be recorded before the provider work started, nor by someone without the permission, nor with an old date
        $this->expectInvalid(fn () => RenewalService::recordProviderConfirmation($this->admin, $s, ['confirmation_ref' => 'REG-1', 'confirmed_on' => self::TODAY, 'new_expiry' => '2027-06-08']), 'In Progress');
        RenewalService::startProviderRenewal($this->manager, $s);
        $s = $this->fresh($s);
        $this->assertSame('renewal_in_progress', $s['status']);
        try {
            RenewalService::recordProviderConfirmation($this->manager, $s, ['confirmation_ref' => 'REG-1', 'confirmed_on' => self::TODAY, 'new_expiry' => '2027-06-08']);
            $this->fail('a manager does not hold renewals.confirm_provider');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectInvalid(fn () => RenewalService::recordProviderConfirmation($this->admin, $s, ['confirmation_ref' => 'REG-1', 'confirmed_on' => self::TODAY, 'new_expiry' => '2026-06-08']), 'later than the current');
        $this->expectInvalid(fn () => RenewalService::recordProviderConfirmation($this->admin, $s, ['confirmation_ref' => 'REG-1', 'confirmed_on' => '2030-01-01', 'new_expiry' => '2027-06-08']), 'future');
        RenewalService::recordProviderConfirmation($this->admin, $s, ['confirmation_ref' => 'REG-2026-77', 'confirmed_on' => self::TODAY, 'new_expiry' => '2027-06-08']);
        $s = $this->fresh($s);
        $this->assertSame('renewed', $s['status']);
        $this->assertSame('2027-06-08', $s['expires_on'], 'the next cycle runs from the date the PROVIDER confirmed');
        $this->assertSame(2, (int) $s['cycle_no']);
        $this->assertNotNull($s['last_renewed_at']);
        $this->assertNull($s['price_override_minor']);
        $closed = Database::one('SELECT * FROM renewal_cycles WHERE service_id = ? AND cycle_no = 1', [$s['id']]);
        $this->assertNotNull($closed['closed_at']);
        $this->assertSame('REG-2026-77', $closed['provider_confirmation_ref']);
        $this->assertSame(141600, (int) $closed['total_minor'], 'the completed cycle keeps its approved price');
        $this->assertNull(RenewalService::openCycle($s), 'no open cycle until the next window');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.completed'", [$this->client])['c']);
        $this->assertTrue(Audit::verify()['ok']);
        foreach (['renewal.approved', 'renewal.invoice_recorded', 'renewal.payment_recorded', 'renewal.provider_confirmed', 'renewal.status_changed'] as $a) {
            $this->assertGreaterThan(0, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = ?', [$a])['c'], $a);
        }
        // the next cycle opens by itself from the verified expiry
        RenewalScheduler::run('2027-05-20');
        $this->assertSame('renewal_approaching', $this->fresh($s)['status']);
        $this->assertSame(2, (int) RenewalService::openCycle($this->fresh($s))['cycle_no']);
    }

    public function testApprovalNeedsTheApprovePermissionAnAvailablePriceAndReasonsForExceptions(): void
    {
        $s = $this->inReview();
        try {
            RenewalService::approve($this->manager, $s, ['price_mode' => 'list']);
            $this->fail('managers cannot approve');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectInvalid(fn () => RenewalService::approve($this->admin, $s, ['price_mode' => 'list', 'period_months' => '12']), 'No renewal price is configured');
        $this->expectInvalid(fn () => RenewalService::approve($this->admin, $s, ['price_mode' => 'override', 'net' => '900', 'tax_percent' => '18', 'reason' => 'short']), 'Reason');
        RenewalService::approve($this->admin, $s, ['price_mode' => 'override', 'net' => '900', 'tax_percent' => '18', 'reason' => 'Loyalty price agreed with the client.']);
        $x = $this->fresh($s);
        $this->assertSame('approved_for_renewal', $x['status']);
        $this->assertSame(90000, (int) $x['price_override_minor']);
        $c = RenewalService::openCycle($x);
        $this->assertSame(106200, (int) $c['total_minor']);
        $this->assertSame('override', $c['price_source']);
        $this->expectInvalid(fn () => RenewalService::approve($this->admin, $x, ['price_mode' => 'override', 'net' => '1', 'reason' => 'Trying to approve again twice']), 'not waiting for approval');
    }

    public function testANoChargeRenewalSkipsTheInvoiceAndStillNeedsTheProviderConfirmation(): void
    {
        $s = $this->inReview();
        $this->expectInvalid(fn () => RenewalService::approve($this->admin, $s, ['price_mode' => 'waived', 'period_months' => '12']), 'Reason');
        RenewalService::approve($this->admin, $s, ['price_mode' => 'waived', 'reason' => 'Included in the website package.', 'period_months' => '12']);
        $x = $this->fresh($s);
        $this->assertSame('payment_received', $x['status']);
        $this->assertSame(0, (int) RenewalService::openCycle($x)['total_minor']);
        $this->assertSame('2026-06-08', $x['expires_on'], 'still not renewed');
        RenewalService::startProviderRenewal($this->manager, $x);
        RenewalService::recordProviderConfirmation($this->admin, $this->fresh($s), ['confirmation_ref' => 'REG-FREE-1', 'confirmed_on' => self::TODAY, 'new_expiry' => '2027-06-08']);
        $this->assertSame('renewed', $this->fresh($s)['status']);
    }

    public function testAnApprovedPriceIsASnapshotAndAnIssuedInvoiceIsOnlyChangedByVoidingIt(): void
    {
        $this->addPrice();
        $s = $this->inReview();
        $this->approveListPrice($s);
        $s = $this->fresh($s);
        $this->addPrice('domain', 12, '5000', '18', '2026-05-01'); // a newer, higher price
        $this->assertSame(141600, (int) RenewalService::openCycle($s)['total_minor'], 'changing the price list never changes an approved renewal');
        RenewalService::recordInvoice($this->manager, $s, ['invoice_ref' => 'INV-5', 'amount' => '1416.00']);
        $s = $this->fresh($s);
        try {
            RenewalService::reviseOrVoid($this->manager, $s, 'The price changed after approval.');
            $this->fail('only an approver can void an invoice');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        RenewalService::reviseOrVoid($this->admin, $s, 'The price changed after approval.');
        $x = $this->fresh($s);
        $this->assertSame('awaiting_admin_review', $x['status']);
        $c = RenewalService::openCycle($x);
        $this->assertNull($c['invoice_ref']);
        $this->assertNull($c['total_minor']);
        $this->assertStringContainsString('INV-5', (string) Database::one("SELECT note FROM renewal_history WHERE event = 'price_revision'")['note'], 'the voided invoice number stays in the history');
        $this->approveListPrice($x);
        $y = $this->fresh($s);
        $this->assertSame(613600, (int) RenewalService::openCycle($y)['total_minor'], 'the new approval uses the price in force now: (5000+200) x 1.18');
        // once money was recorded the invoice can no longer be voided
        RenewalService::recordInvoice($this->manager, $y, ['invoice_ref' => 'INV-6', 'amount' => '6136.00']);
        RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '100', 'method' => 'upi', 'reference' => 'UPI-X', 'received_on' => self::TODAY]);
        $this->expectInvalid(fn () => RenewalService::reviseOrVoid($this->admin, $this->fresh($s), 'Trying again after payment.'), 'Money has already been recorded');
    }

    public function testHoldRejectionAndCancellationNeedReasonsAndRespectMoneyInFlight(): void
    {
        $s = $this->inReview();
        $this->expectInvalid(fn () => RenewalService::hold($this->manager, $s, 'short'), 'Reason');
        RenewalService::reject($this->admin, $s, 'The client has not confirmed they want to keep it.');
        $x = $this->fresh($s);
        $this->assertSame('on_hold', $x['status']);
        $this->assertSame('awaiting_admin_review', $x['held_from']);
        RenewalService::release($this->manager, $x);
        $this->assertSame('awaiting_admin_review', $this->fresh($s)['status']);
        try {
            RenewalService::cancel($this->manager, $this->fresh($s), 'Client no longer needs this domain.');
            $this->fail('cancel needs the approve permission');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectInvalid(fn () => RenewalService::cancel($this->admin, $this->fresh($s), 'short'), 'Reason');
        $this->addPrice();
        $this->approveListPrice($this->fresh($s));
        $y = $this->fresh($s);
        RenewalService::recordInvoice($this->manager, $y, ['invoice_ref' => 'INV-C', 'amount' => '1416.00']);
        RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '500', 'method' => 'cash', 'reference' => 'CASH-1', 'received_on' => self::TODAY]);
        $this->expectInvalid(fn () => RenewalService::hold($this->manager, $this->fresh($s), 'Pausing while we talk to the client.'), 'cannot be put on hold');
        $this->expectInvalid(fn () => RenewalService::cancel($this->admin, $this->fresh($s), 'Client no longer needs this domain.'), 'Money has been recorded');
        $z = $this->make(['identifier' => 'cancel-me.com', 'expires_on' => '2027-09-01']);
        RenewalService::cancel($this->admin, $z, 'Client moved the domain to another agency.');
        $this->assertSame('cancelled', $this->fresh($z)['status']);
        $this->assertSame('Client moved the domain to another agency.', $this->fresh($z)['status_reason']);
    }

    public function testTheExpiryDateCorrectionNeedsAReasonAndIsRefusedWhileMoneyIsInFlight(): void
    {
        $s = $this->make(['expires_on' => '2027-03-01']);
        $this->expectInvalid(fn () => RenewalService::changeExpiry($this->manager, $s, '2027-03-05', 'short'), 'Reason');
        RenewalService::changeExpiry($this->manager, $s, '2027-03-05', 'The registrar shows the 5th, not the 1st.');
        $this->assertSame('2027-03-05', $this->fresh($s)['expires_on']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'renewal.expiry_corrected'")['c']);
        $this->addPrice();
        $r = $this->inReview(['identifier' => 'busy.com']);
        $this->approveListPrice($r);
        RenewalService::recordInvoice($this->manager, $this->fresh($r), ['invoice_ref' => 'INV-E', 'amount' => '1416.00']);
        $this->expectInvalid(fn () => RenewalService::changeExpiry($this->manager, $this->fresh($r), '2026-12-01', 'Trying to move the date mid-invoice.'), 'in progress');
    }

    // ------------------------------------------------------------------------------------------------ pricing

    public function testThePriceListPicksTheLatestPriceInForceAndIsAppendOnly(): void
    {
        $this->addPrice('domain', 12, '1000', '18', '2026-01-01');
        $this->addPrice('domain', 12, '1500', '18', '2026-09-01');
        $now = RenewalPricing::lookup('domain', 12, '2026-06-01');
        $this->assertSame(1000 * 100 + 20000, $now['net_minor']);
        $this->assertSame(21600, $now['tax_minor']);
        $this->assertSame(1700 * 100, RenewalPricing::lookup('domain', 12, '2026-09-01')['net_minor']);
        $this->assertNull(RenewalPricing::lookup('domain', 12, '2025-12-31'), 'before any effective date');
        $this->assertNull(RenewalPricing::lookup('hosting', 12, '2026-06-01'));
        $this->expectInvalid(fn () => $this->addPrice('domain', 12, '1200', '18', '2026-01-01'), 'later effective date');
        try {
            RenewalPricing::add($this->manager, ['service_type' => 'domain', 'period_months' => '12', 'base' => '1', 'effective_from' => '2026-01-01', 'note' => 'nope nope']);
            $this->fail('only renewals.prices holders edit the list');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectInvalid(fn () => RenewalPricing::add($this->owner, ['service_type' => 'domain', 'period_months' => '12', 'base' => '100', 'discount' => '500', 'tax_percent' => '18', 'effective_from' => '2027-01-01', 'note' => 'bad discount']), 'discount');
        $this->expectInvalid(fn () => RenewalPricing::add($this->owner, ['service_type' => 'domain', 'period_months' => '12', 'base' => '100', 'tax_percent' => '99', 'effective_from' => '2027-01-01', 'note' => 'bad tax']), 'Tax');
    }

    // ------------------------------------------------------------------------------------------------ reminders

    public function testRemindersFireOncePerOffsetAndNeverTwiceAcrossRuns(): void
    {
        $s = $this->make(['expires_on' => '2026-06-30']); // 29 days -> inside the 30-day offset
        RenewalScheduler::run(self::TODAY);
        RenewalScheduler::run(self::TODAY);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'client' AND offset_days = 30", [$s['id']])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.client_reminder'", [$this->client])['c'], 'the client is reminded once');
        $staff = (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.expiry'", [$this->manager])['c'];
        $this->assertSame(1, $staff, 'staff are reminded once');
        // eight days later (days = 21 -> still the 30 offset: nothing new), then 10 days out (offset 10), then 2 days out (offset 3)
        RenewalScheduler::run('2026-06-09');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.client_reminder'", [$this->client])['c']);
        RenewalScheduler::run('2026-06-20');
        RenewalScheduler::run('2026-06-20');
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.client_reminder'", [$this->client])['c']);
        RenewalScheduler::run('2026-06-28');
        $this->assertSame(3, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.client_reminder'", [$this->client])['c']);
    }

    public function testAMissedCronDoesNotCauseABurstOfOldReminders(): void
    {
        $s = $this->make(['expires_on' => '2026-06-30']);
        RenewalScheduler::run('2026-06-29'); // first ever run, one day before expiry: only the 1-day reminder, not 30 / 10 / 3 as well
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'client'", [$s['id']])['c']);
        $this->assertSame(1, (int) Database::one("SELECT offset_days AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'client'", [$s['id']])['c']);
    }

    public function testExpiredServicesAreReportedOnceAndAdminsAreToldToDecide(): void
    {
        $s = $this->make(['expires_on' => '2026-05-25', 'activated_on' => '2025-05-25']);
        RenewalScheduler::run(self::TODAY);
        RenewalScheduler::run('2026-06-02');
        $this->assertSame('expired', $this->fresh($s)['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'renewal.expired'", [$this->admin])['c'], 'admins are told once');
    }

    public function testClientRemindersRespectTheSwitchTheStatusAndAnApprovedWorkflow(): void
    {
        $off = $this->make(['expires_on' => '2026-06-05', 'client_reminders' => '0', 'identifier' => 'off.com']);
        RenewalScheduler::run(self::TODAY);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'client'", [$off['id']])['c']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'staff'", [$off['id']])['c'], 'staff are still reminded');
        // paid and in progress: the client is not nagged about an expiry they have already paid to avoid
        $this->addPrice();
        $p = $this->inReview(['identifier' => 'paid.com']);
        $this->approveListPrice($p);
        RenewalService::recordInvoice($this->manager, $this->fresh($p), ['invoice_ref' => 'INV-R', 'amount' => '1416.00']);
        RenewalService::recordPayment($this->manager, $this->fresh($p), ['amount' => '1416', 'method' => 'upi', 'reference' => 'UPI-R', 'received_on' => self::TODAY]);
        RenewalScheduler::run('2026-06-07');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND audience = 'client'", [$p['id']])['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE service_id = ? AND kind = 'confirmation_outstanding'", [$p['id']])['c'], 'staff are told the provider confirmation is outstanding');
    }

    public function testClientEmailIsAttemptedOnlyWhenEnabledAndConfiguredAndEveryAttemptIsRecorded(): void
    {
        $s = $this->make(['expires_on' => '2026-06-30']);
        RenewalScheduler::run(self::TODAY);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_reminders WHERE channel = 'email'")['c'], 'email is off by default: nothing attempted, nothing claimed');
        $this->assertSame([], Mailer::$sent);
        // enabled but no mail configured: recorded as not configured
        Database::run('DELETE FROM renewal_reminders');
        Config::set('renewals.client_email', true);
        RenewalScheduler::run(self::TODAY);
        $row = Database::one("SELECT result FROM renewal_reminders WHERE channel = 'email'");
        $this->assertSame('not_configured', $row['result']);
        $this->assertSame([], Mailer::$sent);
        // enabled and configured: sent once, and the wording carries no price
        Database::run('DELETE FROM renewal_reminders');
        Config::set('app.url', 'http://localhost');
        Config::set('mail.driver', 'array');
        RenewalScheduler::run(self::TODAY);
        RenewalScheduler::run(self::TODAY);
        $this->assertCount(1, Mailer::$sent);
        $this->assertSame('alpha@client.test', Mailer::$sent[0]['to']);
        $this->assertStringNotContainsString('₹', Mailer::$sent[0]['body']);
        $this->assertSame('sent', Database::one("SELECT result FROM renewal_reminders WHERE channel = 'email'")['result']);
        unset($s);
    }

    public function testTheSchedulerRecordsItsRunsSoTheProductNeverClaimsRemindersAreActiveWhenTheyAreNot(): void
    {
        $this->assertNull(RenewalScheduler::lastRun()['at']);
        $this->assertFalse(RenewalScheduler::healthy(), 'never ran: not healthy');
        RenewalScheduler::run(self::TODAY);
        $r = RenewalScheduler::lastRun();
        $this->assertNotNull($r['at']);
        $this->assertTrue($r['ok']);
        $this->assertTrue(RenewalScheduler::healthy());
        Database::run("UPDATE scheduler_runs SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 HOUR)");
        $this->assertFalse(RenewalScheduler::healthy(), 'a run older than 36 hours is not healthy');
    }

    // ------------------------------------------------------------------------------------------------ client view, lists, export

    public function testTheClientSeesOnlyTheirOwnServicesWithoutInternalDetail(): void
    {
        $mine = $this->make(['internal_notes' => 'INTERNAL-ONLY-NOTE', 'identifier' => 'mine.com']);
        $theirs = $this->make(['identifier' => 'theirs.com'], $this->client2Id);
        RenewalService::requireOwn($this->client, $mine['public_id']);
        foreach ([[$this->client, $theirs['public_id']], [$this->client2, $mine['public_id']]] as [$u, $id]) {
            try {
                RenewalService::requireOwn($u, $id);
                $this->fail('another client\'s service must be a 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
        }
        try {
            RenewalService::requireOwn($this->partner, $mine['public_id']);
            $this->fail('a partner has no portal services');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertCount(1, RenewalService::forClient($this->client));
        $this->addPrice();
        $r = $this->inReview(['identifier' => 'view.com']);
        $before = RenewalService::clientView($this->fresh($r));
        $this->assertNull($before['price'], 'no price is shown before the Admin approved it');
        $this->approveListPrice($r);
        RenewalService::recordInvoice($this->manager, $this->fresh($r), ['invoice_ref' => 'INV-V', 'amount' => '1416.00']);
        $v = RenewalService::clientView($this->fresh($r));
        $this->assertSame(141600, $v['price']['total_minor']);
        $this->assertSame('INV-V', $v['invoice_ref']);
        $this->assertStringNotContainsString('INTERNAL', json_encode(RenewalService::clientView($this->fresh($mine))));
        $this->assertArrayNotHasKey('internal_notes', $v);
        $this->assertArrayNotHasKey('provider_confirmation_ref', $v);
    }

    public function testDashboardFiltersPaginationAndAFormulaSafeExport(): void
    {
        $this->addPrice();
        $a = $this->make(['expires_on' => '2026-06-20', 'identifier' => 'a.com']);
        $b = $this->make(['expires_on' => '2027-02-01', 'identifier' => '=cmd|b.com', 'service_type' => 'hosting']);
        $c = $this->inReview(['identifier' => 'c.com']);
        $d = RenewalService::paginate($this->manager, [], 1, 20);
        $this->assertSame(3, $d['total']);
        $this->assertSame('c.com', $d['rows'][0]['identifier'], 'soonest expiry first');
        $this->assertSame(1, RenewalService::paginate($this->manager, ['type' => 'hosting'], 1, 20)['total']);
        $this->assertSame(1, RenewalService::paginate($this->manager, ['bucket' => 'awaiting_review'], 1, 20)['total']);
        $this->assertSame(2, RenewalService::paginate($this->manager, ['from' => '2026-06-01', 'to' => '2026-06-30'], 1, 20)['total']);
        $this->assertSame(1, RenewalService::paginate($this->manager, ['q' => 'a.com'], 1, 20)['total']);
        $this->assertSame(0, RenewalService::paginate($this->manager, ['q' => "' OR 1=1 --"], 1, 20)['total']);
        $this->assertSame([], RenewalService::paginate($this->manager, [], 999999999, 20)['rows'], 'a huge page number is harmless');
        $dash = RenewalService::dashboard($this->manager);
        $this->assertSame(3, $dash['total']);
        $this->assertSame(1, $dash['awaiting_review']);
        $csv = RenewalService::export($this->manager, []);
        $this->assertSame('Reference', $csv[0][0]);
        $cells = array_column($csv, 3);
        $this->assertContains("'=cmd|b.com", $cells, 'a cell that starts with = is neutralised');
        $this->assertNotContains('=cmd|b.com', $cells);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'renewal.exported'")['c']);
        try {
            RenewalService::paginate($this->dev, [], 1, 20);
            $this->fail('workers have no access');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        unset($a, $b, $c);
    }

    public function testRenewalActivityNeverTouchesProjectPriceOrPaymentRecords(): void
    {
        $this->addPrice();
        $before = [Database::all('SELECT * FROM projects ORDER BY id'), Database::all('SELECT * FROM clients ORDER BY id'), (int) Database::one('SELECT COUNT(*) AS c FROM client_payments')['c']];
        $s = $this->inReview(['project' => $this->alpha['public_id'], 'identifier' => 'untouched.com']);
        $this->approveListPrice($s);
        RenewalService::recordInvoice($this->manager, $this->fresh($s), ['invoice_ref' => 'INV-U', 'amount' => '1416.00']);
        RenewalService::recordPayment($this->manager, $this->fresh($s), ['amount' => '1416', 'method' => 'upi', 'reference' => 'UPI-U', 'received_on' => self::TODAY]);
        $this->assertSame($before[0], Database::all('SELECT * FROM projects ORDER BY id'));
        $this->assertSame($before[1], Database::all('SELECT * FROM clients ORDER BY id'));
        $this->assertSame($before[2], (int) Database::one('SELECT COUNT(*) AS c FROM client_payments')['c'], 'renewal payments are separate from project payments and commissions');
    }
}
