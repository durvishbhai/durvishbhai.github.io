<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Audit;
use Vertex\Services\BillingItems;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\InvoiceService;
use Vertex\Services\QuotationService;

/** Quotations, invoices without tax, payments against invoices (staff and client-submitted), the business profile. */
final class BillingServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private string $clientPublic;
    private string $client2Public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $this->client2Public = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client2])['public_id'];
        Database::run("INSERT INTO manual_upi_settings (id, enabled, upi_id, require_proof, updated_at) VALUES (1, 1, 'kis@testbank', 0, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled = 1, upi_id = 'kis@testbank', require_proof = 0"); // clients' "I have paid" details need manual UPI to be on
        Config::set('mail.driver', 'none');
        $this->profile();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function profile(array $over = []): void
    {
        BusinessProfile::save($this->admin, $over + ['trade_name' => 'Vertex Integrated Solutions', 'legal_name' => '', 'address' => "12 Test Street\nMumbai", 'email' => 'billing@vertex.test', 'phone' => '+91 90000 00000',
            'website' => 'https://vertex.test', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'UPI: pay@bank', 'invoice_footer' => 'Thank you.']);
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

    private function expectStatus(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("HTTP {$status} was expected.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    private function lines(string $total = '1000'): array
    {
        return [['description' => 'Website design', 'quantity' => '1', 'unit' => $total], ['description' => '', 'quantity' => '', 'unit' => '']];
    }

    /** @return array<string,mixed> a draft invoice made by finance */
    private function draft(string $total = '1000', ?array $project = null, ?string $client = null, array $over = []): array
    {
        $r = InvoiceService::create($this->finance, $client ?? $this->clientPublic, $over + ['title' => 'Website work', 'project' => $project['public_id'] ?? '', 'items' => $this->lines($total), 'discount' => '0', 'due_date' => '', 'client_notes' => '', 'internal_notes' => 'INTERNAL']);

        return InvoiceService::find($r['public_id']);
    }

    /** @return array<string,mixed> an issued invoice */
    private function issued(string $total = '1000', ?array $project = null, ?string $client = null): array
    {
        $d = $this->draft($total, $project, $client);
        InvoiceService::issue($this->admin, $d);

        return InvoiceService::find($d['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ line items and money

    public function testLineItemsAreExactIntegerPaiseAndNeverContainTax(): void
    {
        $p = BillingItems::parse([['description' => 'Logo', 'quantity' => '2.5', 'unit' => '1000.00'], ['description' => 'Hosting', 'quantity' => '', 'unit' => '499.50']]);
        $this->assertSame([250000 * 1 + 0, 49950], array_column($p['items'], 'total_minor') === [250000, 49950] ? [250000, 49950] : []);
        $this->assertSame(299950, $p['subtotal_minor']);
        $this->assertSame(250, $p['items'][0]['quantity_x100']);
        $this->expectInvalid(fn () => BillingItems::parse([['description' => 'Item', 'quantity' => '0.33', 'unit' => '10.01']]), 'whole number of paise');
        $this->expectInvalid(fn () => BillingItems::parse([['description' => 'Item', 'quantity' => '-1', 'unit' => '10']]), 'quantity');
        $this->expectInvalid(fn () => BillingItems::parse([['description' => 'Item', 'quantity' => '1', 'unit' => '0']]), 'price');
        $this->expectInvalid(fn () => BillingItems::parse([['description' => 'Item', 'quantity' => '1', 'unit' => 'abc']]));
        $this->expectInvalid(fn () => BillingItems::parse(array_fill(0, 51, ['description' => 'Row', 'quantity' => '1', 'unit' => '1'])), 'at most');
        $this->assertSame(['items' => [], 'subtotal_minor' => 0], BillingItems::parse([['description' => '', 'quantity' => '', 'unit' => '']]));
        foreach (['invoices', 'invoice_items', 'quotations', 'quotation_items'] as $t) {
            foreach (array_column(Database::all("SHOW COLUMNS FROM {$t}"), 'Field') as $c) {
                $this->assertDoesNotMatchRegularExpression('/tax|gst|cgst|sgst|igst/i', $c, "no tax column ({$t}.{$c})");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ business profile

    public function testOnlyBillingAdministratorsEditTheBusinessProfileAndIssuedInvoicesKeepTheirSnapshot(): void
    {
        foreach ([$this->manager, $this->finance, $this->client, $this->partner, $this->dev] as $u) {
            $this->expectStatus(403, fn () => BusinessProfile::save($u, ['trade_name' => 'Hacked', 'tax_statement' => 'x tax']));
        }
        $this->expectInvalid(fn () => BusinessProfile::save($this->admin, ['trade_name' => 'A', 'tax_statement' => 'Tax is not applicable.']), 'Business name');
        $this->expectInvalid(fn () => $this->profile(['website' => 'javascript:alert(1)']), 'http');
        $inv = $this->issued();
        $snap = json_decode((string) $inv['business_snapshot'], true);
        $this->assertSame('Vertex Integrated Solutions', $snap['trade_name']);
        $this->assertSame('Tax is not applicable.', $snap['tax_statement']);
        $this->profile(['trade_name' => 'Renamed Co', 'tax_statement' => 'Different statement.']);
        $this->assertSame('Vertex Integrated Solutions', json_decode((string) InvoiceService::find($inv['public_id'])['business_snapshot'], true)['trade_name'], 'an issued invoice is frozen');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'billing.profile_changed'")['c']);
    }

    public function testAnInvoiceCannotBeIssuedUntilTheBusinessNameIsSet(): void
    {
        Database::run("UPDATE business_profile SET trade_name = '' WHERE id = 1");
        $d = $this->draft();
        $this->expectInvalid(fn () => InvoiceService::issue($this->admin, $d), 'business details');
    }

    // ------------------------------------------------------------------------------------------------ quotations

    public function testQuotationLifecycleAcceptanceAndOwnership(): void
    {
        $r = QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Website package', 'valid_until' => date('Y-m-d', strtotime('+10 days')), 'client_notes' => 'Terms', 'items' => $this->lines('50000'), 'internal_notes' => 'SECRET-INTERNAL']);
        $q = QuotationService::find($r['public_id']);
        $this->assertSame('draft', $q['status']);
        $this->assertSame(5000000, (int) $q['total_minor']);
        $this->assertMatchesRegularExpression('/^QUO-\d{4}-\d{5}$/', $q['reference']);
        $this->expectStatus(403, fn () => QuotationService::send($this->manager, $q));
        $this->expectStatus(404, fn () => QuotationService::requireOwn($this->client, $q['public_id']));
        QuotationService::send($this->admin, $q);
        $q = QuotationService::find($q['public_id']);
        $this->assertSame('sent', $q['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'quote.sent'", [$this->client])['c']);
        $own = QuotationService::requireOwn($this->client, $q['public_id']);
        $this->expectStatus(404, fn () => QuotationService::requireOwn($this->client2, $q['public_id']));
        $this->expectStatus(404, fn () => QuotationService::requireOwn($this->partner, $q['public_id']));
        $this->expectStatus(403, fn () => QuotationService::clientDecision($this->partner, $own, 'accept'));
        $this->expectStatus(404, fn () => QuotationService::clientDecision($this->client2, $own, 'accept'));
        $this->expectInvalid(fn () => QuotationService::update($this->manager, $own, ['title' => 'Changed', 'items' => $this->lines('1')]), 'draft');
        QuotationService::clientDecision($this->client, $own, 'accept', 'Looks good');
        $q = QuotationService::find($q['public_id']);
        $this->assertSame('accepted', $q['status']);
        $this->assertSame('portal', $q['decision_channel']);
        $this->expectInvalid(fn () => QuotationService::clientDecision($this->client, $q, 'reject'), 'no longer');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'quote.decided'")['c']);
        // acceptance is an agreement only: no payment, no commission entry
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM client_payments')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM commission_entries')['c']);
    }

    public function testExpiredWithdrawnAndOfflineDecisions(): void
    {
        $mk = fn (string $t): array => QuotationService::find(QuotationService::create($this->manager, $this->clientPublic, ['title' => $t, 'items' => $this->lines('100'), 'valid_until' => date('Y-m-d', strtotime('+5 days'))])['public_id']);
        $a = $mk('Will expire');
        QuotationService::send($this->admin, $a);
        Database::run('UPDATE quotations SET valid_until = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), $a['id']]);
        $this->expectInvalid(fn () => QuotationService::clientDecision($this->client, QuotationService::find($a['public_id']), 'accept'), 'expired');
        $this->assertSame('expired', QuotationService::find($a['public_id'])['status']);
        $b = $mk('Will be withdrawn');
        QuotationService::send($this->admin, $b);
        $this->expectInvalid(fn () => QuotationService::withdraw($this->admin, QuotationService::find($b['public_id']), 'x'), 'Reason');
        QuotationService::withdraw($this->admin, QuotationService::find($b['public_id']), 'Scope changed completely.');
        $this->assertSame('withdrawn', QuotationService::find($b['public_id'])['status']);
        $c = $mk('Offline');
        QuotationService::send($this->admin, $c);
        $this->expectStatus(403, fn () => QuotationService::recordOfflineDecision($this->manager, QuotationService::find($c['public_id']), 'accept', 'Phone call on Monday'));
        QuotationService::recordOfflineDecision($this->admin, QuotationService::find($c['public_id']), 'accept', 'Client confirmed by phone on Monday.');
        $x = QuotationService::find($c['public_id']);
        $this->assertSame('accepted', $x['status']);
        $this->assertSame('offline', $x['decision_channel']);
        $this->assertSame(0, QuotationService::expireDue(), 'expireDue is idempotent');
        $this->expectInvalid(fn () => QuotationService::send($this->admin, QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Empty', 'items' => []]) ? QuotationService::find(Database::one('SELECT public_id FROM quotations ORDER BY id DESC LIMIT 1')['public_id']) : []), 'Add at least one line');
    }

    public function testAnAcceptedQuotationBecomesOneDraftInvoice(): void
    {
        $q = QuotationService::find(QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Package', 'items' => [['description' => 'Item A', 'quantity' => '2', 'unit' => '500'], ['description' => 'Item B', 'quantity' => '1', 'unit' => '250.50']], 'valid_until' => ''])['public_id']);
        $this->expectInvalid(fn () => InvoiceService::createFromQuotation($this->finance, $q), 'accepted');
        QuotationService::send($this->admin, $q);
        QuotationService::recordOfflineDecision($this->admin, QuotationService::find($q['public_id']), 'accept', 'Client accepted by email.');
        $q = QuotationService::find($q['public_id']);
        $inv = InvoiceService::createFromQuotation($this->finance, $q);
        $i = InvoiceService::find($inv['public_id']);
        $this->assertSame('draft', $i['status']);
        $this->assertSame(125050, (int) $i['total_minor']);
        $this->assertSame((int) $q['id'], (int) $i['quotation_id']);
        $this->assertCount(2, InvoiceService::items($i));
        $this->expectInvalid(fn () => InvoiceService::createFromQuotation($this->finance, $q), 'already exists');
        $this->expectStatus(403, fn () => InvoiceService::createFromQuotation($this->client, $q));
    }

    // ------------------------------------------------------------------------------------------------ invoices

    public function testInvoiceNumbersAreAssignedAtIssueAreSequentialAndNeverReused(): void
    {
        $d = $this->draft();
        $this->assertNull($d['reference'], 'a draft has no number');
        $this->expectStatus(403, fn () => InvoiceService::issue($this->finance, $d));
        InvoiceService::issue($this->admin, $d);
        $one = InvoiceService::find($d['public_id']);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-00001$/', $one['reference']);
        $this->assertSame('issued', $one['status']);
        $this->assertSame(date('Y-m-d', strtotime('+14 days')), $one['due_date'], 'default due date');
        $this->expectInvalid(fn () => InvoiceService::issue($this->admin, $one), 'draft');
        $this->expectInvalid(fn () => InvoiceService::update($this->finance, $one, ['title' => 'Edit', 'items' => $this->lines('1')]), 'never changed');
        $two = $this->issued('200');
        $this->assertMatchesRegularExpression('/-00002$/', $two['reference']);
        InvoiceService::void($this->admin, $one, 'Issued by mistake, replacing it.');
        $three = $this->issued('300');
        $this->assertMatchesRegularExpression('/-00003$/', $three['reference'], 'a voided number is not reused');
        $this->assertSame('void', InvoiceService::find($d['public_id'])['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM invoices WHERE reference = ?", [$one['reference']])['c'], 'the voided invoice stays on record');
        $this->expectStatus(403, fn () => InvoiceService::void($this->finance, $two, 'Finance cannot void invoices.'));
        $this->expectInvalid(fn () => InvoiceService::void($this->admin, $two, 'short'), 'Reason');
        $this->expectInvalid(fn () => InvoiceService::void($this->admin, InvoiceService::find($d['public_id']), 'Voiding it a second time.'), 'already void');
    }

    public function testDraftEditsDiscountAndTotals(): void
    {
        $d = $this->draft('1000', null, null, ['discount' => '100']);
        $this->assertSame(100000, (int) $d['subtotal_minor']);
        $this->assertSame(10000, (int) $d['discount_minor']);
        $this->assertSame(90000, (int) $d['total_minor']);
        InvoiceService::update($this->finance, $d, ['title' => 'Edited', 'items' => [['description' => 'Item A', 'quantity' => '3', 'unit' => '100']], 'discount' => '50', 'due_date' => '', 'project' => '']);
        $e = InvoiceService::find($d['public_id']);
        $this->assertSame(25000, (int) $e['total_minor']);
        $this->expectInvalid(fn () => InvoiceService::update($this->finance, $e, ['title' => 'Bad', 'items' => [['description' => 'Item A', 'quantity' => '1', 'unit' => '10']], 'discount' => '20']), 'discount');
    }

    public function testAZeroInvoiceCannotBeIssuedAndAProjectOfAnotherClientCannotBeLinked(): void
    {
        $r = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Nothing yet', 'items' => [], 'discount' => '0']);
        $this->expectInvalid(fn () => InvoiceService::issue($this->admin, InvoiceService::find($r['public_id'])), 'at least one line');
        $this->expectInvalid(fn () => $this->draft('100', $this->bravo), 'client\'s projects');
        $this->expectStatus(403, fn () => InvoiceService::create($this->client, $this->clientPublic, ['title' => 'Self invoice', 'items' => $this->lines('1')]));
        $this->expectStatus(403, fn () => InvoiceService::create($this->partner, $this->clientPublic, ['title' => 'x', 'items' => $this->lines('1')]));
    }

    public function testClientsSeeOnlyTheirOwnIssuedInvoices(): void
    {
        $d = $this->draft();
        $this->expectStatus(404, fn () => InvoiceService::requireOwn($this->client, $d['public_id']));
        InvoiceService::issue($this->admin, $d);
        $i = InvoiceService::requireOwn($this->client, $d['public_id']);
        $this->assertSame($d['public_id'], $i['public_id']);
        $this->expectStatus(404, fn () => InvoiceService::requireOwn($this->client2, $d['public_id']));
        $this->expectStatus(404, fn () => InvoiceService::requireOwn($this->partner, $d['public_id']));
        $this->expectStatus(404, fn () => InvoiceService::requireStaff($this->client, $d['public_id']));
        $this->assertCount(1, InvoiceService::forClient($this->client));
        $this->assertSame([], InvoiceService::forClient($this->client2));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'invoice.issued'", [$this->client])['c']);
        $this->expectStatus(403, fn () => InvoiceService::paginate($this->client, [], 1, 20));
    }

    // ------------------------------------------------------------------------------------------------ payments against invoices

    private function recordPay(array $inv, string $amount, ?string $ref = null, string $role = 'other'): array
    {
        $r = ClientPaymentService::record($this->finance, ['invoice' => $inv['public_id'], 'role' => $role, 'gross' => $amount, 'method' => 'bank_transfer', 'external_ref' => $ref ?? 'UTR' . bin2hex(random_bytes(4)), 'received_on' => date('Y-m-d')]);

        return ClientPaymentService::find($r['public_id']);
    }

    public function testStaffPaymentsAreVerifiedByASecondPersonAndDriveTheInvoiceStatus(): void
    {
        $inv = $this->issued('1000');
        $p1 = $this->recordPay($inv, '400');
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status'], 'a recorded but unverified payment changes nothing');
        $this->assertSame(40000, InvoiceService::balance(InvoiceService::find($inv['public_id']))['pending']);
        $this->expectInvalid(fn () => $this->recordPay($inv, '700'), 'still due');
        $adminRecorded = ClientPaymentService::find(ClientPaymentService::record($this->admin, ['invoice' => $inv['public_id'], 'role' => 'other', 'gross' => '10', 'method' => 'cash', 'external_ref' => 'CASH-SOD-1', 'received_on' => date('Y-m-d')])['public_id']);
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, $adminRecorded), 'cannot verify');
        $this->expectStatus(403, fn () => ClientPaymentService::verify($this->finance, $p1));
        ClientPaymentService::reject($this->owner, $adminRecorded, 'Test entry removed again.');
        ClientPaymentService::verify($this->admin, $p1);
        $x = InvoiceService::find($inv['public_id']);
        $this->assertSame('partially_paid', $x['status']);
        $this->assertSame(40000, InvoiceService::paidMinor((int) $x['id']));
        $p2 = $this->recordPay($inv, '600');
        ClientPaymentService::verify($this->admin, $p2);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'invoice.paid'", [$this->client])['c']);
        $this->expectInvalid(fn () => $this->recordPay($inv, '1'), 'issued invoice');
        // a refund of part of the second payment reopens the invoice
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p2['public_id']), 10000, 'Partial refund agreed.', 'refund');
        $y = InvoiceService::find($inv['public_id']);
        $this->assertSame('partially_paid', $y['status']);
        $this->assertSame(90000, InvoiceService::paidMinor((int) $y['id']));
        $this->assertSame(10000, InvoiceService::balance($y)['outstanding']);
        // a duplicate transaction reference is refused
        $inv2 = $this->issued('500');
        $this->recordPay($inv2, '100', 'DUP-REF-1');
        $this->expectInvalid(fn () => $this->recordPay($inv2, '100', 'dup-ref-1'), 'already recorded');
    }

    public function testAnInvoiceWithMoneyOnItCannotBeVoidedAndRejectedPaymentsNeverCount(): void
    {
        $inv = $this->issued('1000');
        $p = $this->recordPay($inv, '300');
        $this->expectInvalid(fn () => InvoiceService::void($this->admin, $inv, 'Trying to void with a pending payment.'), 'Payments are recorded');
        ClientPaymentService::reject($this->admin, $p, 'The bank shows nothing for this reference.');
        $this->assertSame('rejected', ClientPaymentService::find($p['public_id'])['status']);
        $this->assertSame(0, InvoiceService::paidMinor((int) $inv['id']));
        $this->assertSame(0, InvoiceService::pendingMinor((int) $inv['id']));
        $this->expectInvalid(fn () => ClientPaymentService::reject($this->admin, ClientPaymentService::find($p['public_id']), 'Rejecting it twice.'), 'waiting for verification');
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, ClientPaymentService::find($p['public_id'])), 'not waiting');
        $this->expectStatus(403, fn () => ClientPaymentService::reject($this->finance, $this->recordPay($inv, '50'), 'Finance cannot reject.'));
        $p2 = $this->recordPay($inv, '200');
        ClientPaymentService::verify($this->admin, $p2);
        $this->expectInvalid(fn () => InvoiceService::void($this->admin, InvoiceService::find($inv['public_id']), 'Trying to void after a payment.'), 'Payments are recorded');
    }

    public function testAnInvoiceWithoutAProjectTakesPaymentsAndNeverTouchesCommission(): void
    {
        $inv = $this->issued('1000');
        $this->assertNull($inv['project_id']);
        $p = $this->recordPay($inv, '1000');
        $this->assertNull($p['project_id']);
        $this->assertSame('other', $p['payment_role']);
        ClientPaymentService::verify($this->admin, $p);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM commission_entries')['c']);
    }

    // ------------------------------------------------------------------------------------------------ client claims

    public function testAClientSubmitsPaymentDetailsAndStaffVerifyAgainstTheBank(): void
    {
        $inv = $this->issued('1000');
        $own = InvoiceService::requireOwn($this->client, $inv['public_id']);
        $in = ['amount' => '1000', 'method' => 'upi', 'reference' => '412345678901', 'paid_on' => date('Y-m-d')];
        $this->expectStatus(403, fn () => ClientPaymentService::claim($this->partner, $own, $in));
        $this->expectStatus(404, fn () => ClientPaymentService::claim($this->client2, $own, $in));
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['amount' => '1500'] + $in), 'still due');
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['reference' => 'ab'] + $in));
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['reference' => "12345<script>"] + $in));
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['reference' => 'ab*cd?ef12'] + $in), 'letters, digits');
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['paid_on' => '2099-01-01'] + $in), 'future');
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['method' => 'cheque'] + $in), 'payment method');
        $res = ClientPaymentService::claim($this->client, $own, $in);
        $p = ClientPaymentService::find($res['public_id']);
        $this->assertSame('client_claim', $p['source']);
        $this->assertSame('pending_verification', $p['status']);
        $this->assertSame((int) $this->client, (int) $p['recorded_by']);
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status'], 'a claim never changes the invoice');
        $this->assertSame(100000, InvoiceService::balance(InvoiceService::find($inv['public_id']))['pending']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'client_payment.needs_verification' AND user_id = ?", [$this->admin])['c']);
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own, ['amount' => '10', 'reference' => 'ANOTHER-REF-77'] + $in), 'still due');
        $own2 = InvoiceService::requireOwn($this->client, $inv['public_id']);
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, $own2, ['amount' => '1'] + $in), 'already been submitted');
        // verification needs the amount that reached the bank; a wrong claim is corrected and recorded
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, $p), 'actually reached');
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, $p, ['received' => '0', 'role' => 'other']), 'reject');
        ClientPaymentService::verify($this->admin, $p, ['received' => '900', 'role' => 'other']);
        $v = ClientPaymentService::find($p['public_id']);
        $this->assertSame('verified', $v['status']);
        $this->assertSame(90000, (int) $v['gross_minor'], 'the bank amount replaces the claimed amount');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM client_payment_events WHERE payment_id = ? AND event = 'amount_corrected'", [$p['id']])['c']);
        $this->assertSame('partially_paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND event_type = 'payment.confirmed'", [$this->client])['c']);
        // the client sees only verified money and their own submissions, never staff references
        $this->assertCount(1, InvoiceService::clientPayments(InvoiceService::find($inv['public_id'])));
    }

    public function testARejectedClaimTellsTheClientAndLeavesTheInvoiceUnpaid(): void
    {
        $inv = $this->issued('500');
        $res = ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv['public_id']), ['amount' => '500', 'method' => 'upi', 'reference' => 'UTRFAKE000111', 'paid_on' => date('Y-m-d')]);
        ClientPaymentService::reject($this->admin, ClientPaymentService::find($res['public_id']), 'No such transaction in the bank statement.');
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status']);
        $n = Database::one("SELECT body FROM notifications WHERE user_id = ? AND event_type = 'payment.not_confirmed'", [$this->client]);
        $this->assertNotNull($n);
        $this->assertStringContainsString('No such transaction', (string) $n['body']);
        // the same reference cannot be resubmitted: it stays unique even after a rejection
        $this->expectInvalid(fn () => ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv['public_id']), ['amount' => '500', 'method' => 'upi', 'reference' => 'UTRFAKE000111', 'paid_on' => date('Y-m-d')]), 'already been submitted');
    }

    public function testClaimsAreRateLimited(): void
    {
        $inv = $this->issued('100000');
        $own = InvoiceService::requireOwn($this->client, $inv['public_id']);
        $codes = [];
        for ($k = 0; $k < 12; $k++) {
            try {
                ClientPaymentService::claim($this->client, $own, ['amount' => '1', 'method' => 'upi', 'reference' => 'RATE' . str_pad((string) $k, 8, '0', STR_PAD_LEFT), 'paid_on' => date('Y-m-d')]);
                $codes[] = 200;
            } catch (HttpException $e) {
                $codes[] = $e->status;
            }
        }
        $this->assertContains(429, $codes, 'more than ten submissions an hour is refused');
        $this->assertSame(10, count(array_filter($codes, static fn (int $c): bool => $c === 200)));
    }

    // ------------------------------------------------------------------------------------------------ the whole story with commission

    public function testQuotationToInvoiceToClaimedPaymentsEarnsTheTenAndFivePercentCommissionOnlyAfterVerification(): void
    {
        $ref = $this->creditedReferral();
        $q = QuotationService::find(QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Alpha website', 'project' => $this->alpha['public_id'], 'items' => $this->lines('100000'), 'valid_until' => ''])['public_id']);
        QuotationService::send($this->admin, $q);
        QuotationService::clientDecision($this->client, QuotationService::requireOwn($this->client, $q['public_id']), 'accept');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], 'accepting a quotation earns nothing');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status']);
        $inv = InvoiceService::find(InvoiceService::createFromQuotation($this->finance, QuotationService::find($q['public_id']))['public_id']);
        $this->assertSame((int) $this->alpha['id'], (int) $inv['project_id']);
        InvoiceService::issue($this->admin, $inv);
        $own = InvoiceService::requireOwn($this->client, $inv['public_id']);
        $c1 = ClientPaymentService::claim($this->client, $own, ['amount' => '50000', 'method' => 'upi', 'reference' => 'UPI-ADVANCE-0001', 'paid_on' => date('Y-m-d')]);
        $this->assertSame('pending_eligibility', $this->entry($ref, 'advance')['status'], 'a claim earns nothing');
        $this->expectInvalid(fn () => ClientPaymentService::verify($this->admin, ClientPaymentService::find($c1['public_id']), ['received' => '50000', 'role' => 'sideways']), 'payment type');
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($c1['public_id']), ['received' => '50000', 'role' => 'advance']);
        $adv = $this->entry($ref, 'advance');
        $this->assertSame('eligible_for_review', $adv['status']);
        $this->assertSame(500000, (int) $adv['amount_minor'], '10% of INR 50,000');
        $this->assertSame('partially_paid', InvoiceService::find($inv['public_id'])['status']);
        $c2 = ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv['public_id']), ['amount' => '50000', 'method' => 'bank_transfer', 'reference' => 'NEFT-BALANCE-0002', 'paid_on' => date('Y-m-d')]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($c2['public_id']), ['received' => '50000', 'role' => 'balance']);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $full = $this->entry($ref, 'full');
        $this->assertSame('eligible_for_review', $full['status']);
        $this->assertSame(1000000, (int) $full['amount_minor'], '15% of INR 1,00,000 minus the INR 5,000 already earned');
        $this->assertTrue(Audit::verify()['ok']);
    }
}
