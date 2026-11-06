<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CouponService;
use Vertex\Services\CreditNoteService;
use Vertex\Services\InvoiceService;
use Vertex\Services\QuotationService;

/** Phase 10 over real HTTP: coupons, credit notes, PDFs and receipts (isolation), reports, approvals. */
final class Phase10HttpTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private string $clientPublic;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** @return array<string,mixed> */
    private function issued(string $total = '1000', ?string $client = null): array
    {
        $d = InvoiceService::create($this->finance, $client ?? $this->clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => $total]], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return InvoiceService::find($d['public_id']);
    }

    public function testCouponScreensAreGatedAndTheFormCreatesACoupon(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $this->assertSame(200, $o->get('/coupons')['status']);
        $this->assertSame(200, $o->get('/coupons/new')['status']);
        $o->post('/coupons', ['code' => 'welcome5', 'description' => 'Welcome', 'discount_type' => 'percent', 'percent' => '5', 'campaign' => '', 'min_spend' => '', 'max_uses' => '', 'max_uses_per_client' => ''], '/coupons/new');
        $c = Database::one("SELECT * FROM coupons WHERE code = 'WELCOME5'");
        $this->assertNotNull($c);
        $this->assertSame(500, (int) $c['percent_bps']);
        $this->assertStringContainsString('WELCOME5', $o->get('/coupons/' . $c['public_id'])['body']);
        $o->post('/campaigns', ['name' => 'Launch', 'description' => '', 'starts_on' => '', 'ends_on' => '', 'status' => 'active'], '/coupons');
        $this->assertStringContainsString('Launch', $o->get('/coupons')['body']);
        // finance may look but not change; clients and managers see nothing
        $f = $this->loggedIn('fin@x.test');
        $this->assertSame(200, $f->get('/coupons')['status']);
        $this->assertSame(403, $f->get('/coupons/new')['status']);
        $this->assertSame(403, $f->post('/coupons', ['code' => 'X1', 'discount_type' => 'percent', 'percent' => '5'], '/coupons')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/coupons')['status']);
    }

    public function testInvoiceFormAppliesACouponAndTheClientSeesIt(): void
    {
        CouponService::create($this->owner, ['code' => 'SAVE10', 'discount_type' => 'percent', 'percent' => '10']);
        $f = $this->loggedIn('fin@x.test');
        $f->post('/invoices', ['client' => $this->clientPublic, 'title' => 'Coupon invoice', 'project' => '', 'due_date' => '', 'discount' => '0', 'coupon_code' => 'save10', 'client_notes' => '', 'internal_notes' => '', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '1000']]], '/invoices/new');
        $i = Database::one("SELECT * FROM invoices WHERE title = 'Coupon invoice'");
        $this->assertSame([10000, 90000], [(int) $i['coupon_discount_minor'], (int) $i['total_minor']]);
        InvoiceService::issue($this->admin, InvoiceService::find($i['public_id']));
        $c = $this->loggedIn('alpha@client.test');
        $body = $c->get('/my/invoices/' . $i['public_id'])['body'];
        $this->assertStringContainsString('Coupon SAVE10', $body);
        $this->assertStringContainsString('/my/invoices/' . $i['public_id'] . '/pdf', $body);
        $f->post('/invoices', ['client' => $this->clientPublic, 'title' => 'Bad coupon', 'project' => '', 'due_date' => '', 'discount' => '0', 'coupon_code' => 'NOPE', 'client_notes' => '', 'internal_notes' => '', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '1000']]], '/invoices/new');
        $this->assertNull(Database::one("SELECT id FROM invoices WHERE title = 'Bad coupon'"), 'an invalid coupon stops the draft being saved');
    }

    public function testCreditNoteThroughTheFormNeedsThePasswordAndShowsToTheClient(): void
    {
        $i = $this->issued('1000');
        $o = $this->loggedIn('owner@x.test');
        $o->post('/invoices/' . $i['public_id'], ['action' => 'credit_note', 'amount' => '200', 'reason' => 'Scope reduced by agreement', 'confirm_password' => 'wrong'], '/invoices/' . $i['public_id']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM credit_notes')['c']);
        $o->post('/invoices/' . $i['public_id'], ['action' => 'credit_note', 'amount' => '200', 'reason' => 'Scope reduced by agreement', 'confirm_password' => self::PASSWORD], '/invoices/' . $i['public_id']);
        $n = Database::one('SELECT * FROM credit_notes');
        $this->assertNotNull($n);
        $this->assertSame(80000, InvoiceService::payable(InvoiceService::find($i['public_id'])));
        $this->assertStringContainsString('CRN-', $o->get('/invoices/' . $i['public_id'])['body']);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertSame(200, $c->get('/my/credit-notes/' . $n['public_id'])['status']);
        $pdf = $c->get('/my/credit-notes/' . $n['public_id'] . '/pdf');
        $this->assertSame(200, $pdf['status']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf['body']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/credit-notes/' . $n['public_id'])['status']);
    }

    public function testPdfsAndReceiptsAreDeliveredOnlyToTheRightPeople(): void
    {
        $i = $this->issued('1000');
        $r = ClientPaymentService::record($this->finance, ['invoice' => $i['public_id'], 'role' => 'other', 'gross' => '1000', 'method' => 'bank_transfer', 'external_ref' => 'UTR123456', 'received_on' => AppTime::today()]);
        $p = ClientPaymentService::find($r['public_id']);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertSame(404, $c->get('/my/receipts/' . $p['public_id'])['status'], 'not verified yet, so no receipt');
        ClientPaymentService::verify($this->admin, $p);
        $this->assertSame(200, $c->get('/my/receipts/' . $p['public_id'])['status']);
        $pdf = $c->get('/my/receipts/' . $p['public_id'] . '/pdf');
        $this->assertSame(200, $pdf['status']);
        $this->assertStringStartsWith('%PDF-', $pdf['body']);
        $this->assertSame(200, $this->loggedIn('fin@x.test')->get('/client-payments/' . $p['public_id'] . '/receipt')['status']);
        // another client gets nothing, for any of the documents
        $c2 = $this->loggedIn('bravo@client.test');
        foreach (['/my/receipts/' . $p['public_id'], '/my/receipts/' . $p['public_id'] . '/pdf', '/my/invoices/' . $i['public_id'] . '/pdf'] as $path) {
            $this->assertSame(404, $c2->get($path)['status'], $path);
        }
        $this->assertSame(403, $c->get('/invoices/' . $i['public_id'] . '/pdf')['status'], 'the staff PDF route is staff only');
        $f = $this->loggedIn('fin@x.test');
        $head = $f->headersOf('/invoices/' . $i['public_id'] . '/pdf');
        $this->assertStringContainsString('application/pdf', implode("\n", $head));
        // quotation PDF
        $q = QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Quote', 'items' => [['description' => 'Logo', 'quantity' => '1', 'unit' => '500']], 'project' => '', 'valid_until' => '', 'client_notes' => '', 'internal_notes' => '']);
        $this->assertStringStartsWith('%PDF-', $this->loggedIn('mgr@x.test')->get('/quotations/' . $q['public_id'] . '/pdf')['body']);
    }

    public function testReportsAndApprovalCentrePages(): void
    {
        $this->issued('1000');
        $o = $this->loggedIn('owner@x.test');
        $this->assertStringContainsString('Revenue and collections', $o->get('/reports')['body']);
        $rev = $o->get('/reports/revenue?from=2026-01-01&to=2026-12-31');
        $this->assertSame(200, $rev['status']);
        $this->assertStringContainsString('<svg', $rev['body']);
        $this->assertStringContainsString('chart', $rev['body']);
        $csv = $o->get('/reports/revenue/csv?table=0');
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('Month', $csv['body']);
        $this->assertSame(404, $o->get('/reports/nonsense')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/reports')['status']);
        $this->assertSame(403, $this->loggedIn('fin@x.test')->get('/reports/workload')['status']);
        $this->assertSame(200, $this->loggedIn('fin@x.test')->get('/reports/aging')['status']);
        $dash = $o->get('/dashboard')['body'];
        $this->assertStringContainsString('<svg', $dash, 'the owner dashboard shows charts');
        $this->assertDoesNotMatchRegularExpression('/ style="/', $dash);
        $this->assertStringNotContainsString('<svg', $this->loggedIn('alpha@client.test')->get('/dashboard')['body'], 'a client sees no business charts');
        $a = $o->get('/approvals');
        $this->assertSame(200, $a['status']);
        $this->assertStringContainsString('Approval centre', $a['body']);
        $this->assertSame(200, $this->loggedIn('alpha@client.test')->get('/approvals')['status']);
        $this->assertStringContainsString('Nothing is waiting for you', $this->loggedIn('alpha@client.test')->get('/approvals')['body']);
    }
}
