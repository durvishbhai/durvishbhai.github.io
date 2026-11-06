<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\DocumentPdf;
use Vertex\Services\InvoiceService;
use Vertex\Services\ReceiptService;

/** Payment receipts: numbering, automatic issue, manual issue, sealing, voiding, the amount in words, the PDF and the public verification page. */
final class ReceiptTest extends HttpTestCase
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
        BusinessProfile::save($this->admin, ['trade_name' => 'Krazy Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** @return array<string,mixed> */
    private function verifiedPayment(string $ref = 'UTR100200300', string $gross = '1000'): array
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => $gross, 'method' => 'bank_transfer', 'external_ref' => $ref, 'received_on' => AppTime::today()]);
        $p = ClientPaymentService::find($r['public_id']);
        ClientPaymentService::verify($this->admin, $p);

        return ClientPaymentService::find($r['public_id']);
    }

    /** @return array<string,string> */
    private function manualInput(array $over = []): array
    {
        return $over + ['client' => '', 'client_name' => 'Walk-in Customer', 'client_email' => 'walkin@example.test', 'client_phone' => '+91 90000 11111', 'client_address' => 'Pune',
            'lines' => [['description' => 'Logo design (advance)', 'amount' => '5000'], ['description' => '', 'amount' => '']], 'discount' => '500', 'tax' => '0', 'method' => 'cash', 'txn_id' => 'BOOK-0042',
            'paid_on' => AppTime::today(), 'paid_time' => '10:30', 'against' => 'Quote Q-12', 'note' => ''];
    }

    public function testTheAmountInWordsUsesIndianNumbering(): void
    {
        $this->assertSame('Rupees Five Thousand Only', ReceiptService::words(500000));
        $this->assertSame('Rupees One Only', ReceiptService::words(100));
        $this->assertSame('Rupees Zero and Fifty Paise Only', ReceiptService::words(50));
        $this->assertSame('Rupees One Lakh Twenty Thousand Three Hundred Forty and Fifty Paise Only', ReceiptService::words(12034050));
        $this->assertSame('Rupees Two Crore Five Lakh Only', ReceiptService::words(2050000000));
    }

    public function testAVerifiedPaymentGetsExactlyOneNumberedReceiptAutomatically(): void
    {
        $p = $this->verifiedPayment();
        $r = ReceiptService::forPayment((int) $p['id']);
        $this->assertNotNull($r, 'made the moment the payment was verified');
        $this->assertMatchesRegularExpression('/^KIS-PR-' . date('Y') . '-0001$/', $r['number']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', $r['verify_code']);
        $this->assertSame(100000, (int) $r['paid_minor']);
        $this->assertTrue(ReceiptService::sealMatches($r));
        $this->assertSame($r['id'], ReceiptService::issueForPayment((int) $p['id'])['id'], 'asking again never makes a second receipt');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'receipt.issued'", [$this->client])['c']);
        $v = ReceiptService::present($r);
        $this->assertSame('valid', $v['effective']);
        $this->assertSame('Rupees One Thousand Only', $v['words']);
        $this->assertStringContainsString('Payment towards invoice', $v['lines'][0]['description']);
        $second = $this->verifiedPayment('UTR999888777');
        $this->assertMatchesRegularExpression('/-0002$/', ReceiptService::forPayment((int) $second['id'])['number']);
    }

    public function testAPaymentThatIsNotVerifiedHasNoReceipt(): void
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Work', 'items' => [['description' => 'Small job', 'quantity' => '1', 'unit' => '500']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => '500', 'method' => 'upi', 'external_ref' => 'UPI123456', 'received_on' => AppTime::today()]);
        $this->assertNull(ReceiptService::issueForPayment((int) ClientPaymentService::find($r['public_id'])['id']));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c']);
    }

    public function testTheTeamCanIssueAReceiptByHandAndOnlyWithThePermission(): void
    {
        foreach ([$this->manager, $this->dev, $this->client] as $u) {
            try {
                ReceiptService::issueManual($u, $this->manualInput());
                $this->fail('must be refused');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $r = ReceiptService::issueManual($this->finance, $this->manualInput());
        $v = ReceiptService::present($r);
        $this->assertSame(450000, $v['paidMinor'], '5000 - 500 discount');
        $this->assertSame('manual', $r['source']);
        $this->assertSame('Walk-in Customer', $v['client']['name']);
        $this->assertSame('10:30', $v['paidTime']);
        $this->assertTrue($v['manual']);
        // a registered client is linked and told
        $r2 = ReceiptService::issueManual($this->admin, $this->manualInput(['client' => $this->clientPublic, 'client_name' => '', 'txn_id' => 'BOOK-0043']));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'receipt.issued'", [$this->client])['c']);
        $this->assertNotSame('Walk-in Customer', ReceiptService::present($r2)['client']['name']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'receipt.issued'")['c']);
    }

    public function testManualReceiptsAreValidatedAndOneTransactionGetsOneReceipt(): void
    {
        $bad = [
            ['client_name' => '', 'client' => ''],
            ['lines' => [['description' => '', 'amount' => '']]],
            ['lines' => [['description' => 'x', 'amount' => '0.50']]],
            ['discount' => '99999'],
            ['method' => 'barter'],
            ['txn_id' => ''],
            ['paid_time' => '25:99'],
            ['paid_on' => '2999-01-01'],
            ['client' => str_repeat('a', 32)],
        ];
        foreach ($bad as $over) {
            try {
                ReceiptService::issueManual($this->finance, $this->manualInput($over));
                $this->fail('expected a validation error for ' . json_encode($over));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors);
            }
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c']);
        $first = ReceiptService::issueManual($this->finance, $this->manualInput());
        try {
            ReceiptService::issueManual($this->finance, $this->manualInput(['txn_id' => 'book-0042']));
            $this->fail('the same transaction must not get a second receipt');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($first['number'], implode(' ', $e->errors));
        }
    }

    public function testASealedReceiptThatIsAlteredNoLongerVerifiesAndAVoidedOneSaysSo(): void
    {
        $r = ReceiptService::issueManual($this->finance, $this->manualInput());
        $this->assertTrue(ReceiptService::sealMatches($r));
        Database::run('UPDATE receipts SET paid_minor = paid_minor + 100 WHERE id = ?', [$r['id']]);
        $this->assertFalse(ReceiptService::sealMatches(ReceiptService::find($r['public_id'])), 'editing the amount in the database breaks the seal');
        Database::run('UPDATE receipts SET paid_minor = paid_minor - 100 WHERE id = ?', [$r['id']]);
        Database::run("UPDATE receipts SET client_snapshot = REPLACE(client_snapshot, 'Walk-in Customer', 'Somebody Else') WHERE id = ?", [$r['id']]);
        $this->assertFalse(ReceiptService::sealMatches(ReceiptService::find($r['public_id'])), 'so does the client name');
        Database::run("UPDATE receipts SET client_snapshot = REPLACE(client_snapshot, 'Somebody Else', 'Walk-in Customer') WHERE id = ?", [$r['id']]);
        $this->assertTrue(ReceiptService::sealMatches(ReceiptService::find($r['public_id'])));

        try {
            ReceiptService::void($this->finance, $r, 'Issued by mistake, wrong amount');
            $this->fail('finance cannot void');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->expectException(ValidationException::class);
        ReceiptService::void($this->admin, $r, 'short');
    }

    public function testVoidingKeepsTheReceiptAndShowsItAsVoid(): void
    {
        $r = ReceiptService::issueManual($this->finance, $this->manualInput());
        ReceiptService::void($this->admin, $r, 'Issued by mistake, wrong amount');
        $v = ReceiptService::present(ReceiptService::find($r['public_id']));
        $this->assertSame('void', $v['effective']);
        $this->assertSame('Issued by mistake, wrong amount', $v['voidReason']);
        $this->assertTrue($v['sealOk'], 'voiding is a status change, not a tampering');
        try {
            ReceiptService::void($this->admin, ReceiptService::find($r['public_id']), 'Trying a second time to void it');
            $this->fail('already void');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already void', implode(' ', $e->errors));
        }
    }

    public function testAReversedPaymentShowsTheReceiptAsReversed(): void
    {
        $p = $this->verifiedPayment();
        ClientPaymentService::refund($this->admin, $p, 100000, 'Chargeback from the bank', 'reversed');
        $this->assertSame('reversed', ReceiptService::present(ReceiptService::forPayment((int) $p['id']))['effective']);
    }

    public function testThePdfFollowsTheTemplateAndCarriesTheVerificationDetails(): void
    {
        $r = ReceiptService::issueManual($this->finance, $this->manualInput());
        $pdf = DocumentPdf::receiptDocument(ReceiptService::present($r));
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        foreach (['PAYMENT ', 'RECEIPT', 'CLIENT DETAILS', 'PAYMENT DETAILS', 'TOTAL PAID', 'AMOUNT IN WORDS', 'Payment acknowledgement', 'Thank You!', 'VERIFY THIS RECEIPT', $r['number']] as $needle) {
            $this->assertStringContainsString($needle, $pdf, $needle);
        }
        $this->assertStringContainsString('Rupees Four Thousand Five Hundred Only', $pdf);
    }

    // ------------------------------------------------------------------------------------------------ over HTTP

    public function testStaffAndClientPagesAreGated(): void
    {
        $p = $this->verifiedPayment();
        $r = ReceiptService::forPayment((int) $p['id']);
        $fin = $this->loggedIn('fin@x.test');
        $this->assertSame(200, $fin->get('/receipts')['status']);
        $list = $fin->get('/receipts')['body'];
        $this->assertStringContainsString($r['number'], $list);
        $page = $fin->get('/receipts/' . $r['public_id']);
        $this->assertSame(200, $page['status']);
        foreach (['PAYMENT <em>RECEIPT</em>', 'CLIENT DETAILS', 'PAYMENT DETAILS', 'TOTAL PAID', 'AMOUNT IN WORDS', 'Rupees One Thousand Only', $r['number'], 'VERIFY THIS RECEIPT'] as $needle) {
            $this->assertStringContainsString($needle, $page['body'], $needle);
        }
        $this->assertSame(1, substr_count($page['body'], '<svg class="qr-svg"'), 'the verification QR');
        $this->assertStringNotContainsString('<script', str_replace('<script src=', '', $page['body']), 'no inline script');
        $this->assertSame(200, $fin->get('/receipts/new')['status']);
        $this->assertStringStartsWith('%PDF-', $fin->get('/receipts/' . $r['public_id'] . '/pdf')['body']);
        $this->assertStringNotContainsString('Void this receipt', $page['body'], 'finance cannot void');
        $this->assertStringContainsString('Void this receipt', $this->loggedIn('admin@x.test')->get('/receipts/' . $r['public_id'])['body']);

        foreach (['mgr@x.test', 'dev@x.test', 'alpha@client.test'] as $who) {
            $u = $this->loggedIn($who);
            foreach (['/receipts', '/receipts/new', '/receipts/' . $r['public_id'], '/receipts/' . $r['public_id'] . '/pdf'] as $path) {
                $this->assertSame(403, $u->get($path)['status'], "{$who} {$path}");
            }
        }
        $alpha = $this->loggedIn('alpha@client.test');
        $this->assertSame(200, $alpha->get('/my/receipt/' . $r['public_id'])['status']);
        $this->assertStringContainsString($r['number'], $alpha->get('/my/receipts')['body']);
        $this->assertStringStartsWith('%PDF-', $alpha->get('/my/receipt/' . $r['public_id'] . '/pdf')['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/receipt/' . $r['public_id'])['status']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/receipt/' . $r['public_id'] . '/pdf')['status']);
        $this->assertSame(200, $alpha->get('/my/receipts/' . $p['public_id'])['status'], 'the older per-payment link still works');
    }

    public function testIssuingByHandOverHttpNeedsThePasswordAndTheForm(): void
    {
        $fin = $this->loggedIn('fin@x.test');
        $in = ['client' => '', 'client_name' => 'Walk-in Customer', 'client_email' => '', 'client_phone' => '', 'client_address' => '', 'lines' => [['description' => 'Logo design', 'amount' => '2500']], 'discount' => '0', 'tax' => '0',
            'method' => 'upi', 'txn_id' => 'UPI-OUTSIDE-1', 'paid_on' => AppTime::today(), 'paid_time' => '09:15', 'against' => '', 'note' => ''];
        $fin->post('/receipts', $in + ['confirm_password' => 'wrong'], '/receipts/new');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c'], 'a wrong password issues nothing');
        $res = $fin->post('/receipts', $in + ['confirm_password' => self::PASSWORD], '/receipts/new');
        $this->assertSame(303, $res['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c']);
        $row = Database::one('SELECT * FROM receipts');
        $this->assertSame('/receipts/' . $row['public_id'], $res['location']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post('/receipts', $in + ['confirm_password' => self::PASSWORD], '/account')['status']);
        // voiding needs the permission and the password
        $this->assertSame(403, $fin->post('/receipts/' . $row['public_id'] . '/void', ['reason' => 'Issued by mistake today', 'confirm_password' => self::PASSWORD], '/receipts/new')['status']);
        $adm = $this->loggedIn('admin@x.test');
        $adm->post('/receipts/' . $row['public_id'] . '/void', ['reason' => 'Issued by mistake today', 'confirm_password' => 'wrong'], '/receipts/' . $row['public_id']);
        $this->assertSame('valid', Database::one('SELECT status FROM receipts')['status']);
        $adm->post('/receipts/' . $row['public_id'] . '/void', ['reason' => 'Issued by mistake today', 'confirm_password' => self::PASSWORD], '/receipts/' . $row['public_id']);
        $this->assertSame('void', Database::one('SELECT status FROM receipts')['status']);
    }

    public function testThePublicVerificationPageNeedsNoLoginAndShowsOnlyWhatIsSafe(): void
    {
        $p = $this->verifiedPayment();
        $r = ReceiptService::forPayment((int) $p['id']);
        $anon = $this->client();
        $form = $anon->get('/verify');
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('Verify a payment receipt', $form['body']);
        $this->assertStringContainsString('noindex', $form['body']);
        $this->assertStringContainsString('href="/verify"', $anon->get('/contact')['body'], 'the public site footer links to it');

        $res = $anon->get('/verify/' . $r['verify_code']);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Verified: this payment was received', $res['body']);
        foreach ([$r['number'], '₹1,000.00'] as $needle) {
            $this->assertStringContainsString($needle, $res['body']);
        }
        $this->assertSame('no-store', $anon->lastHeaders['cache-control']);
        // nothing private: no email, phone, address, full transaction reference or full client name
        foreach (['alpha@client.test', 'UTR100200300', 'Alpha'] as $secret) {
            $this->assertStringNotContainsString($secret, $res['body'], $secret);
        }
        $this->assertStringContainsString('••••0300', $res['body']);
        // lower case, dashes and spaces in the code are fine; the typed form needs the matching number
        $this->assertStringContainsString('Verified', $anon->get('/verify/' . strtolower(substr($r['verify_code'], 0, 4)) . '-' . substr($r['verify_code'], 4, 4) . '-' . substr($r['verify_code'], 8))['body']);
        $this->assertStringContainsString('Verified', $anon->get('/verify?number=' . rawurlencode($r['number']) . '&code=' . $r['verify_code'])['body']);
        $wrongNo = $anon->get('/verify?number=KIS-PR-2026-9999&code=' . $r['verify_code']);
        $this->assertSame(404, $wrongNo['status']);
        $this->assertStringContainsString('No matching receipt found', $wrongNo['body']);
        $this->assertSame(404, $anon->get('/verify/AAAAAAAAAAAA')['status']);
        $this->assertSame(404, $anon->get('/verify/not-a-code')['status']);
    }

    public function testTheVerificationPageSaysWhenAReceiptIsVoidTamperedOrReversed(): void
    {
        $p = $this->verifiedPayment();
        $r = ReceiptService::forPayment((int) $p['id']);
        $anon = $this->client();
        Database::run('UPDATE receipts SET paid_minor = paid_minor + 100 WHERE id = ?', [$r['id']]);
        $body = $anon->get('/verify/' . $r['verify_code'])['body'];
        $this->assertStringContainsString('could not be verified', $body);
        $this->assertStringNotContainsString('Verified: this payment was received', $body);
        Database::run('UPDATE receipts SET paid_minor = paid_minor - 100 WHERE id = ?', [$r['id']]);
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($p['public_id']), 100000, 'Chargeback from the bank', 'reversed');
        $this->assertStringContainsString('reversed', $anon->get('/verify/' . $r['verify_code'])['body']);
        $m = ReceiptService::issueManual($this->finance, $this->manualInput());
        ReceiptService::void($this->admin, $m, 'Issued by mistake, wrong amount');
        $v = $anon->get('/verify/' . $m['verify_code'])['body'];
        $this->assertStringContainsString('has been voided', $v);
        $this->assertStringContainsString('Issued by mistake, wrong amount', $v);
    }

    public function testVerificationAttemptsAreRateLimited(): void
    {
        $anon = $this->client();
        $last = 200;
        for ($i = 0; $i < 45; $i++) {
            $res = $anon->get('/verify/' . str_pad((string) $i, 12, 'A'));
            $last = $res['status'];
            if (str_contains($res['body'], 'Too many checks')) {
                $this->assertGreaterThan(30, $i, 'a normal person is never blocked');
                return;
            }
        }
        $this->fail('guessing codes must be rate limited (last status ' . $last . ')');
    }
}
