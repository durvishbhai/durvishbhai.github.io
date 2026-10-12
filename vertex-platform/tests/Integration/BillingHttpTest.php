<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\GatewayConfig;
use Vertex\Services\InvoiceService;
use Vertex\Services\QuotationService;

/** Quotations, invoices, client payment submission and the Google Pay settings over real HTTP: isolation, role gates, step-up password, CSRF, escaping, secrets. */
final class BillingHttpTest extends HttpTestCase
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
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 123', 'email' => '', 'phone' => '', 'website' => '', 'legal_name' => '', 'invoice_footer' => '']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function issued(string $title = 'Website work', string $total = '1000', string $client = ''): array
    {
        $d = InvoiceService::create($this->finance, $client ?: $this->clientPublic, ['title' => $title, 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => $total]], 'discount' => '0', 'internal_notes' => 'STAFF-ONLY-NOTE', 'client_notes' => 'Visible note']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return InvoiceService::find($d['public_id']);
    }

    public function testStaffCreateAQuotationAndInvoiceThroughTheFormsAndRolesAreGated(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/quotations/new')['status']);
        $m->post('/quotations', ['client' => $this->clientPublic, 'title' => 'Form quote', 'project' => '', 'valid_until' => '', 'client_notes' => '', 'internal_notes' => '', 'items' => [['description' => 'Logo design', 'quantity' => '2', 'unit' => '1500'], ['description' => '', 'quantity' => '', 'unit' => '']]], '/quotations/new');
        $q = Database::one('SELECT * FROM quotations');
        $this->assertSame('draft', $q['status']);
        $this->assertSame(300000, (int) $q['total_minor']);
        $this->assertStringContainsString('Logo design', $m->get('/quotations/' . $q['public_id'])['body']);
        $f = $this->loggedIn('fin@x.test');
        $this->assertSame(200, $f->get('/invoices/new')['status']);
        $f->post('/invoices', ['client' => $this->clientPublic, 'title' => 'Form invoice', 'project' => '', 'due_date' => '', 'discount' => '0', 'client_notes' => '', 'internal_notes' => '', 'items' => [['description' => 'Hosting setup', 'quantity' => '1', 'unit' => '2500']]], '/invoices/new');
        $this->assertSame(250000, (int) Database::one('SELECT total_minor FROM invoices')['total_minor']);
        // an error keeps what was typed
        $bad = $f->post('/invoices', ['client' => $this->clientPublic, 'title' => '', 'items' => [['description' => 'Keep this line', 'quantity' => '1', 'unit' => '10']]], '/invoices/new');
        $this->assertSame(303, $bad['status']);
        $this->assertStringContainsString('Keep this line', $f->get('/invoices/new')['body']);
        foreach (['dev@x.test', 'sup@x.test', 'partner@x.test', 'alpha@client.test'] as $who) {
            $c = $this->loggedIn($who);
            foreach (['/invoices', '/invoices/new', '/quotations', '/quotations/new', '/admin/payments', '/admin/business'] as $p) {
                $this->assertSame(403, $c->get($p)['status'], "{$who} must not open {$p}");
            }
        }
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/invoices')['status'], 'managers do not see invoices');
        $this->assertSame(403, $this->loggedIn('fin@x.test')->get('/quotations')['status']);
    }

    public function testIssuingAndVoidingNeedTheAdministratorPassword(): void
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'To issue', 'items' => [['description' => 'Some work', 'quantity' => '1', 'unit' => '500']], 'discount' => '0']);
        $url = '/invoices/' . $d['public_id'];
        $f = $this->loggedIn('fin@x.test');
        $this->assertSame(403, $f->post($url, ['action' => 'issue', 'confirm_password' => self::PASSWORD], $url)['status'], 'finance cannot issue');
        $a = $this->loggedIn('admin@x.test');
        $a->post($url, ['action' => 'issue', 'confirm_password' => 'wrong'], $url);
        $this->assertSame('draft', InvoiceService::find($d['public_id'])['status']);
        $a->post($url, ['action' => 'issue', 'confirm_password' => self::PASSWORD], $url);
        $i = InvoiceService::find($d['public_id']);
        $this->assertSame('issued', $i['status']);
        $this->assertStringContainsString($i['reference'], $a->get($url)['body']);
        $a->post($url, ['action' => 'void', 'reason' => 'Created by mistake.', 'confirm_password' => 'wrong'], $url);
        $this->assertSame('issued', InvoiceService::find($d['public_id'])['status']);
        $a->post($url, ['action' => 'void', 'reason' => 'Created by mistake.', 'confirm_password' => self::PASSWORD], $url);
        $this->assertSame('void', InvoiceService::find($d['public_id'])['status']);
    }

    public function testAClientSeesOnlyTheirOwnInvoicesPaysAndSubmitsDetailsWithoutDoubleSubmit(): void
    {
        $mine = $this->issued('Mine only');
        $theirs = $this->issued('Theirs only', '700', (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client2])['public_id']);
        GatewayConfig::saveUpi($this->admin, ['upi_id' => 'vertex.pay@okhdfcbank', 'payee_name' => 'Vertex Pay', 'enabled' => '1']);
        $c = $this->loggedIn('alpha@client.test');
        $list = $c->get('/my/invoices');
        $this->assertStringContainsString('Mine only', $list['body']);
        $this->assertStringNotContainsString('Theirs only', $list['body']);
        $page = $c->get('/my/invoices/' . $mine['public_id']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Tax is not applicable.', $page['body']);
        $this->assertStringContainsString('upi://pay?pa=vertex.pay%40okhdfcbank', html_entity_decode($page['body']));
        $this->assertStringContainsString('Pay ₹1,000.00 with Google Pay', $page['body']);
        $this->assertStringNotContainsString('STAFF-ONLY-NOTE', $page['body']);
        $this->assertSame(404, $c->get('/my/invoices/' . $theirs['public_id'])['status'], 'IDOR across clients');
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/invoices/' . $mine['public_id'])['status']);
        $this->assertSame(404, $c->post('/my/invoices/' . $theirs['public_id'] . '/pay', ['amount' => '700', 'method' => 'upi', 'reference' => 'STEAL1234567', 'paid_on' => date('Y-m-d')], '/my/invoices')['status']);
        $this->assertSame(403, $c->get('/invoices/' . $mine['public_id'])['status'], 'a client cannot open the staff invoice page');
        $this->assertSame(403, $this->loggedIn('partner@x.test')->get('/my/invoices')['status']);
        $form = ['amount' => '1000', 'method' => 'upi', 'reference' => 'UTR-HTTP-0001', 'paid_on' => date('Y-m-d')];
        $this->assertSame(419, $c->postNoToken('/my/invoices/' . $mine['public_id'] . '/pay', $form)['status']);
        $c->post('/my/invoices/' . $mine['public_id'] . '/pay', $form, '/my/invoices/' . $mine['public_id']);
        $c->post('/my/invoices/' . $mine['public_id'] . '/pay', $form, '/my/invoices/' . $mine['public_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM client_payments WHERE source = 'client_claim'")['c'], 'a double submit records one claim');
        $this->assertSame('issued', InvoiceService::find($mine['public_id'])['status'], 'still unpaid until staff verify');
        $after = $c->get('/my/invoices/' . $mine['public_id'])['body'];
        $this->assertStringContainsString('Your submitted payments', $after);
        $this->assertStringNotContainsString('Pay ₹1,000.00 with Google Pay', $after, 'nothing left to pay while the full amount is awaiting confirmation');
    }

    public function testStaffVerifyAClientSubmittedPaymentWithTheBankAmountAndRejectNeedsAReason(): void
    {
        $inv = $this->issued('Verify me');
        $res = ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv['public_id']), ['amount' => '1000', 'method' => 'upi', 'reference' => 'UTR-VERIFY-0001', 'paid_on' => date('Y-m-d')]);
        $pay = ClientPaymentService::find($res['public_id']);
        $url = '/client-payments/' . $pay['public_id'];
        $a = $this->loggedIn('admin@x.test');
        $show = $a->get($url);
        $this->assertSame(200, $show['status']);
        $this->assertStringContainsString('Check your bank statement', $show['body']);
        $this->assertSame(403, $this->loggedIn('fin@x.test')->post($url . '/verify', ['received' => '1000', 'confirm_password' => self::PASSWORD], $url)['status'], 'finance cannot verify');
        $a->post($url . '/verify', ['received' => '1000', 'role' => 'other', 'confirm_password' => 'wrong'], $url);
        $this->assertSame('pending_verification', ClientPaymentService::find($pay['public_id'])['status']);
        $a->post($url . '/reject', ['reason' => 'x'], $url);
        $this->assertSame('pending_verification', ClientPaymentService::find($pay['public_id'])['status'], 'a rejection needs a real reason');
        $a->post($url . '/verify', ['received' => '800', 'role' => 'other', 'confirm_password' => self::PASSWORD], $url);
        $v = ClientPaymentService::find($pay['public_id']);
        $this->assertSame('verified', $v['status']);
        $this->assertSame(80000, (int) $v['gross_minor']);
        $this->assertSame('partially_paid', InvoiceService::find($inv['public_id'])['status']);
        $client = $this->loggedIn('alpha@client.test')->get('/my/invoices/' . $inv['public_id'])['body'];
        $this->assertStringContainsString('₹800.00', $client);
        $this->assertStringNotContainsString('UTR-VERIFY-0001', explode('Your submitted payments', $client)[0], 'staff references are not shown in the verified payments list');
    }

    public function testPrintViewsEscapeTextAndShowTheTaxStatementNotInternalNotes(): void
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Print test', 'items' => [['description' => 'Line with text', 'quantity' => '1', 'unit' => '100']], 'discount' => '0', 'internal_notes' => 'STAFF-ONLY-NOTE', 'client_notes' => '"><img src=x onerror=alert(2)> <script>alert(1)</script>']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        foreach ([['admin@x.test', '/invoices/' . $d['public_id'] . '/print'], ['alpha@client.test', '/my/invoices/' . $d['public_id'] . '/print']] as [$who, $path]) {
            $r = $this->loggedIn($who)->get($path);
            $this->assertSame(200, $r['status'], $who);
            $this->assertStringNotContainsString('<script>alert(1)', $r['body']);
            $this->assertStringNotContainsString('<img src=x', $r['body']);
            $this->assertStringContainsString('&lt;script&gt;', $r['body']);
            $this->assertStringContainsString('Tax is not applicable.', $r['body']);
            $this->assertStringContainsString('Vertex Integrated Solutions', $r['body']);
            $this->assertStringNotContainsString('STAFF-ONLY-NOTE', $r['body']);
            $this->assertStringNotContainsString('GST', $r['body']);
        }
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/invoices/' . $d['public_id'] . '/print')['status']);
    }

    public function testAClientAcceptsAQuotationInThePortalAndOthersCannot(): void
    {
        $q = QuotationService::find(QuotationService::create($this->manager, $this->clientPublic, ['title' => 'Portal quote', 'items' => [['description' => 'Consulting', 'quantity' => '1', 'unit' => '2000']], 'valid_until' => '', 'internal_notes' => 'STAFF-ONLY-NOTE'])['public_id']);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertSame(404, $c->get('/my/quotes/' . $q['public_id'])['status'], 'a draft is invisible to the client');
        QuotationService::send($this->admin, $q);
        $page = $c->get('/my/quotes/' . $q['public_id']);
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('STAFF-ONLY-NOTE', $page['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/quotes/' . $q['public_id'])['status']);
        $url = '/my/quotes/' . $q['public_id'];
        $c->post($url, ['decision' => 'accept'], $url);
        $this->assertSame('sent', QuotationService::find($q['public_id'])['status'], 'the confirmation box is required');
        $c->post($url, ['decision' => 'accept', 'confirm' => '1', 'note' => 'Go ahead'], $url);
        $this->assertSame('accepted', QuotationService::find($q['public_id'])['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) AS c FROM client_payments')['c']);
        $this->assertSame(403, $this->loggedIn('partner@x.test')->post($url, ['decision' => 'accept', 'confirm' => '1'], '/account')['status']);
    }

    public function testGooglePaySettingsPageSavesWithThePasswordAndNeverShowsSecretsAgain(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $page = $a->get('/admin/payments');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Google Pay is not a payment gateway on its own', $page['body']);
        $this->assertStringContainsString('Not configured', $page['body']);
        $upi = ['action' => 'save_upi', 'upi_id' => 'vertex.pay@okhdfcbank', 'payee_name' => 'Vertex Pay', 'merchant_code' => '', 'enabled' => '1'];
        $a->post('/admin/payments', $upi + ['confirm_password' => 'wrong'], '/admin/payments');
        $this->assertFalse(GatewayConfig::upiActive(), 'wrong password: nothing saved');
        $a->post('/admin/payments', array_replace($upi, ['upi_id' => 'bad upi', 'confirm_password' => self::PASSWORD]), '/admin/payments');
        $this->assertFalse(GatewayConfig::upiActive(), 'invalid UPI ID: nothing saved');
        $a->post('/admin/payments', $upi + ['confirm_password' => self::PASSWORD], '/admin/payments');
        $this->assertTrue(GatewayConfig::upiActive());
        $this->assertStringContainsString('Payments are NOT confirmed automatically', $a->get('/admin/payments')['body']);
        // the Google Pay API fields: secrets go in once and never come back
        $api = ['action' => 'save_api', 'environment' => 'TEST', 'merchant_id' => '', 'merchant_name' => 'Vertex', 'merchant_origin' => 'https://pay.vertex.test', 'processor' => 'razorpay', 'processor_merchant_id' => 'acct_Abc123',
            'processor_key_id' => 'rzp_test_AbCd1234', 'processor_key_secret' => 'sUp3rS3cretKeyValue99', 'webhook_secret' => 'whsec_ZyXwVuTs1234', 'networks' => ['VISA'], 'auth_methods' => ['PAN_ONLY']];
        $a->post('/admin/payments', $api + ['confirm_password' => 'wrong'], '/admin/payments');
        $this->assertSame([], GatewayConfig::get(GatewayConfig::API)['config']);
        $a->post('/admin/payments', $api + ['confirm_password' => self::PASSWORD], '/admin/payments');
        $this->assertSame('razorpay', GatewayConfig::get(GatewayConfig::API)['config']['processor']);
        $body = $a->get('/admin/payments')['body'];
        foreach (['sUp3rS3cretKeyValue99', 'whsec_ZyXwVuTs1234'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, 'a saved secret is never rendered');
        }
        $this->assertStringContainsString('••••ue99', $body);
        $this->assertStringContainsString('not installed in this release', $body);
        $this->assertStringContainsString('does <em>not</em> switch card payments on', $body);
        $this->assertSame('sUp3rS3cretKeyValue99', GatewayConfig::secret(GatewayConfig::API, 'processor_key_secret'));
        // the check button shows the example link without any secret
        $a->post('/admin/payments', ['action' => 'check_upi'], '/admin/payments');
        $this->assertStringContainsString('Example link', $a->get('/admin/payments')['body']);
        // nobody else can open or post to it
        foreach (['mgr@x.test', 'fin@x.test', 'alpha@client.test', 'partner@x.test'] as $who) {
            $c = $this->loggedIn($who);
            $this->assertSame(403, $c->post('/admin/payments', array_replace($upi, ['upi_id' => 'attacker@bank', 'confirm_password' => self::PASSWORD]), '/account')['status'], $who);
        }
        $this->assertSame('vertex.pay@okhdfcbank', GatewayConfig::get(GatewayConfig::UPI)['config']['upi_id']);
        $audit = (string) Database::one("SELECT GROUP_CONCAT(COALESCE(before_json, ''), COALESCE(after_json, ''), COALESCE(meta_json, '')) AS x FROM audit_logs")['x'];
        $this->assertStringNotContainsString('sUp3rS3cretKeyValue99', $audit);
    }

    public function testBusinessDetailsNeedThePasswordAndFeedTheInvoice(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $this->assertSame(200, $a->get('/admin/business')['status']);
        $form = ['trade_name' => 'New Trade Name', 'legal_name' => '', 'address' => '1 New Road', 'email' => '', 'phone' => '', 'website' => '', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Pay by UPI', 'invoice_footer' => ''];
        $a->post('/admin/business', $form + ['confirm_password' => 'wrong'], '/admin/business');
        $this->assertSame('Vertex Integrated Solutions', BusinessProfile::get()['trade_name']);
        $a->post('/admin/business', $form + ['confirm_password' => self::PASSWORD], '/admin/business');
        $this->assertSame('New Trade Name', BusinessProfile::get()['trade_name']);
        $inv = $this->issued('After rename');
        $this->assertStringContainsString('New Trade Name', $this->loggedIn('admin@x.test')->get('/invoices/' . $inv['public_id'] . '/print')['body']);
    }
}
