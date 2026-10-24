<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\InvoiceService;
use Vertex\Services\ManualUpi;
use Vertex\Services\ReceiptService;

/** Manual UPI: the business's own QR + UPI ID, the client's transaction ID and proof, the team's approval, and never alongside Razorpay. */
final class ManualUpiTest extends HttpTestCase
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
        Config::set('razorpay.enabled', false);
    }

    protected function tearDown(): void
    {
        Config::set('razorpay.enabled', false);
        $this->tearDownPhase3();
    }

    private function on(bool $proof = true): void
    {
        Database::run("INSERT INTO manual_upi_settings (id, enabled, upi_id, payee_name, instructions, require_proof, updated_at) VALUES (1, 1, 'kis@testbank', 'KIS Pune', 'Approved within one working day', ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE enabled = 1, upi_id = 'kis@testbank', payee_name = 'KIS Pune', instructions = 'Approved within one working day', require_proof = VALUES(require_proof)", [$proof ? 1 : 0]);
    }

    /** @return array<string,mixed> */
    private function invoice(string $total = '1000'): array
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => $total]], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return InvoiceService::find($d['public_id']);
    }

    private function png(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'png');
        $im = imagecreatetruecolor(40, 40);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 20, 120, 220));
        imagepng($im, $f);

        return $f;
    }

    public function testItIsOffByDefaultAndAClientIsToldToContactUs(): void
    {
        $inv = $this->invoice();
        $page = $this->loggedIn('alpha@client.test')->get('/my/invoices/' . $inv['public_id'])['body'];
        $this->assertStringNotContainsString('Pay by UPI', $page);
        $this->assertStringNotContainsString('Submit for approval', $page);
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get('/my/upi-qr')['status']);
        $this->expectException(ValidationException::class);
        ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv['public_id']), ['amount' => '100', 'method' => 'upi', 'reference' => 'UTR123456789', 'paid_on' => AppTime::today()]);
    }

    public function testOnlyTheOwnerSideCanConfigureItWithThePasswordAndRazorpayBlocksIt(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Manual UPI payments', $admin->get('/admin/manual-upi')['body']);
        $this->assertSame(403, $this->loggedIn('fin@x.test')->get('/admin/manual-upi')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->post('/admin/manual-upi', [], '/account')['status']);

        // password, a valid UPI ID, and an image for the QR
        $form = ['enabled' => '1', 'require_proof' => '1', 'upi_id' => 'kis@testbank', 'payee_name' => 'KIS Pune', 'instructions' => 'Approved within one working day'];
        $admin->post('/admin/manual-upi', $form + ['confirm_password' => 'wrong'], '/admin/manual-upi');
        $this->assertSame(0, (int) (Database::one('SELECT enabled FROM manual_upi_settings WHERE id = 1')['enabled'] ?? 0));
        $admin->post('/admin/manual-upi', ['upi_id' => 'not a upi id'] + $form + ['confirm_password' => self::PASSWORD], '/admin/manual-upi');
        $this->assertSame(0, (int) (Database::one('SELECT enabled FROM manual_upi_settings WHERE id = 1')['enabled'] ?? 0));
        $png = $this->png();
        $admin->upload('/admin/manual-upi', $form + ['confirm_password' => self::PASSWORD], ['qr' => [$png, 'bank-qr.png', 'image/png']], '/admin/manual-upi');
        $s = ManualUpi::settings();
        $this->assertSame(1, (int) $s['enabled']);
        $this->assertSame('kis@testbank', $s['upi_id']);
        $this->assertNotNull($s['qr_file_id']);
        $this->assertTrue(ManualUpi::active());
        $img = $admin->get('/admin/manual-upi/qr');
        $this->assertSame(200, $img['status']);
        $this->assertStringStartsWith("\x89PNG", $img['body']);
        $this->assertSame('image/png', $admin->lastHeaders['content-type']);
        // a script or document cannot be the QR
        $bad = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($bad, '<?php echo 1;');
        $admin->upload('/admin/manual-upi', $form + ['confirm_password' => self::PASSWORD], ['qr' => [$bad, 'qr.php', 'text/x-php']], '/admin/manual-upi');
        $this->assertSame($s['qr_file_id'], ManualUpi::settings()['qr_file_id'], 'the saved QR is kept when a bad file is sent');

        // Razorpay and manual UPI are alternatives
        Config::set('razorpay.enabled', true);
        $this->assertFalse(ManualUpi::active(), 'Razorpay on: manual UPI is not used');
        $this->assertStringContainsString('Razorpay online payments are switched on', ManualUpi::status()['reason']);
        try {
            ManualUpi::save($this->admin, $form, null);
            $this->fail('cannot switch manual UPI on while Razorpay is on');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('switch Razorpay off first', implode(' ', $e->errors));
        }
    }

    public function testAClientPaysSendsProofAndTheTeamApprovesIt(): void
    {
        $this->on();
        $inv = $this->invoice('1000');
        $alpha = $this->loggedIn('alpha@client.test');
        $page = $alpha->get('/my/invoices/' . $inv['public_id'])['body'];
        foreach (['Pay by UPI', 'kis@testbank', 'KIS Pune', 'Approved within one working day', 'name="proof"', 'name="reference"', 'Submit for approval', 'upi://pay?pa=kis%40testbank'] as $needle) {
            $this->assertStringContainsString($needle, html_entity_decode($page, ENT_QUOTES), $needle);
        }

        // no proof -> refused; with proof -> sent to the team, NOT yet paid
        $form = ['amount' => '1000', 'method' => 'upi', 'reference' => 'UTR202610240001', 'paid_on' => AppTime::today()];
        $alpha->post('/my/invoices/' . $inv['public_id'] . '/pay', $form, '/my/invoices/' . $inv['public_id']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c'], 'proof is required');
        $proof = $this->png();
        $r = $alpha->upload('/my/invoices/' . $inv['public_id'] . '/pay', $form, ['proof' => [$proof, 'payment.png', 'image/png']], '/my/invoices/' . $inv['public_id']);
        $this->assertSame(303, $r['status']);
        $row = Database::one('SELECT * FROM client_payments');
        $this->assertSame('pending_verification', $row['status']);
        $this->assertSame('client_claim', $row['source']);
        $this->assertNotNull($row['proof_file_id']);
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status'], 'not paid until approved');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'client_payment.needs_verification'")['c'], 'the team is told');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c']);
        $this->assertStringContainsString('awaiting approval', $alpha->get('/my/invoices/' . $inv['public_id'])['body']);

        // the same transaction cannot be sent twice
        $alpha->upload('/my/invoices/' . $inv['public_id'] . '/pay', $form, ['proof' => [$proof, 'payment.png', 'image/png']], '/my/invoices/' . $inv['public_id']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);

        // the team sees the proof and approves; only then is it successful
        $fin = $this->loggedIn('fin@x.test');
        $show = $fin->get('/client-payments/' . $row['public_id']);
        $this->assertStringContainsString('/proof">proof of payment', $show['body']);
        $dl = $fin->get('/client-payments/' . $row['public_id'] . '/proof');
        $this->assertSame(200, $dl['status']);
        $this->assertStringStartsWith("\x89PNG", $dl['body']);
        $this->assertSame(403, $alpha->get('/client-payments/' . $row['public_id'] . '/proof')['status'], 'the proof is for the team');
        $adm = $this->loggedIn('admin@x.test');
        $adm->post('/client-payments/' . $row['public_id'] . '/verify', ['received' => '1000.00', 'role' => 'other', 'confirm_password' => self::PASSWORD], '/client-payments/' . $row['public_id']);
        $this->assertSame('verified', Database::one('SELECT status FROM client_payments')['status']);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM receipts')['c'], 'the receipt is made on approval');
        $this->assertStringContainsString('Approved: payment successful', $alpha->get('/my/invoices/' . $inv['public_id'])['body']);
        $this->assertNotNull(ReceiptService::forPayment((int) $row['id']));
    }

    public function testProofCanBeMadeOptionalAndRazorpayTurnsTheWholeFlowOff(): void
    {
        $this->on(false);
        $inv = $this->invoice('500');
        $own = InvoiceService::requireOwn($this->client, $inv['public_id']);
        $res = ClientPaymentService::claim($this->client, $own, ['amount' => '500', 'method' => 'upi', 'reference' => 'UTR-NOPROOF-01', 'paid_on' => AppTime::today()]);
        $this->assertNotEmpty($res['reference']);
        $this->assertNull(Database::one('SELECT proof_file_id FROM client_payments')['proof_file_id']);

        // switch Razorpay on: the client no longer sees or can use the manual flow
        Config::set('razorpay.enabled', true);
        $inv2 = $this->invoice('700');
        try {
            ClientPaymentService::claim($this->client, InvoiceService::requireOwn($this->client, $inv2['public_id']), ['amount' => '700', 'method' => 'upi', 'reference' => 'UTR-AFTER-RZP-1', 'paid_on' => AppTime::today()]);
            $this->fail('manual claims are refused while Razorpay is on');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not available', implode(' ', $e->errors));
        }
        // the web server process has its own configuration: model "Razorpay on" there through its environment instead of this process
        $this->assertFalse(ManualUpi::active());
    }
}
