<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\BusinessProfile;
use Vertex\Services\CouponService;
use Vertex\Services\InvoiceService;

/** The client's own "Pay online" button (Razorpay checkout) and the coupon box on their invoice page. */
final class ClientOnlinePaymentTest extends HttpTestCase
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
        Config::set('mail.driver', 'none');
        Config::set('razorpay.enabled', true);
        Config::set('razorpay.key_id', 'rzp_test_abcdefghij');
        Config::set('razorpay.key_secret', 'secret');
        Config::set('app.url', 'https://portal.example.test');
        BusinessProfile::save($this->admin, ['trade_name' => 'KIS', 'tax_statement' => 'Tax is not applicable.']);
    }

    protected function tearDown(): void
    {
        Config::set('app.url', '');
        $this->tearDownPhase3();
    }

    private function coupon(string $code, string $appliesTo, string $percent = '10'): void
    {
        CouponService::create($this->owner, ['code' => $code, 'discount_type' => 'percent', 'percent' => $percent, 'applies_to' => $appliesTo]);
    }

    private function issued(string $unit): array
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'project' => '', 'items' => [['description' => 'Website', 'quantity' => '1', 'unit' => $unit]], 'discount' => '0']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return InvoiceService::find($d['public_id']);
    }

    public function testTheClientEntersACouponOnTheirInvoicePageAndOnlyTheOwnerCan(): void
    {
        $this->coupon('PROJ10', 'project', '10');
        $inv = $this->issued('5000');
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/my/invoices/' . $inv['public_id']);
        $this->assertStringContainsString('Have a coupon code?', $page['body']);
        $this->assertStringContainsString('Apply coupon', $page['body']);
        $this->assertSame(303, $c->post('/my/invoices/' . $inv['public_id'] . '/coupon', ['coupon_code' => 'proj10'], '/my/invoices/' . $inv['public_id'])['status']);
        $after = $c->get('/my/invoices/' . $inv['public_id'])['body'];
        $this->assertStringContainsString('4,500.00', $after, 'the balance after the coupon');
        $this->assertStringNotContainsString('Have a coupon code?', $after, 'one coupon per invoice');
        $other = $this->loggedIn('bravo@client.test');
        $this->assertSame(404, $other->post('/my/invoices/' . $inv['public_id'] . '/coupon', ['coupon_code' => 'proj10'], '/my/invoices')['status']);
        $this->assertSame(404, $other->post('/my/invoices/' . $inv['public_id'] . '/pay-online', [], '/my/invoices')['status']);
    }

    public function testPayOnlineMakesOneRazorpayCheckoutRequestForWhatIsStillDue(): void
    {
        Config::set('razorpay.qr_mode', 'checkout');
        $this->coupon('PROJ10', 'project', '10');
        $inv = $this->issued('5000');
        \Vertex\Services\CouponService::applyToInvoice($this->client, $inv, 'proj10');
        $inv = InvoiceService::find($inv['public_id']);
        $o = \Vertex\Services\RazorpayGateway::createClientRequest($this->client, $inv);
        $this->assertSame(450000, (int) $o['amount_minor'], 'the request is for the balance after the coupon');
        $this->assertSame('checkout', $o['flow']);
        $again = \Vertex\Services\RazorpayGateway::createClientRequest($this->client, $inv);
        $this->assertSame($o['public_id'], $again['public_id'], 'pressing again reuses the open request');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c']);
    }

    public function testPayOnlineIsRefusedWhenRazorpayIsOffAndTheButtonIsNotShown(): void
    {
        $inv = $this->issued('1000');
        $c = $this->loggedIn('alpha@client.test'); // the test web server runs with Razorpay off
        $this->assertStringNotContainsString('online</button>', $c->get('/my/invoices/' . $inv['public_id'])['body']);
        $this->assertSame(303, $c->post('/my/invoices/' . $inv['public_id'] . '/pay-online', [], '/my/invoices/' . $inv['public_id'])['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c']);
    }
}
