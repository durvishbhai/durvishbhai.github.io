<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\RenewalPricing;
use Vertex\Services\RenewalScheduler;
use Vertex\Services\RenewalService;

/** Domain and hosting renewals over real HTTP: client isolation, role gates, password step-up, CSRF, escaping, CSV export. */
final class RenewalHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    private int $owner;
    private string $clientId;
    private string $client2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->clientId = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $this->client2Id = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client2])['public_id'];
        RenewalPricing::add($this->owner, ['service_type' => 'domain', 'period_months' => '12', 'base' => '1000', 'markup' => '200', 'tax_percent' => '18', 'effective_from' => '2020-01-01', 'note' => 'Standard']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function svc(string $identifier = 'shop-one.com', ?string $client = null, array $over = []): array
    {
        $r = RenewalService::create($this->manager, $client ?? $this->clientId, $over + ['service_type' => 'domain', 'identifier' => $identifier, 'provider' => 'Fictional Registrar', 'activated_on' => '2024-01-01',
            'expires_on' => date('Y-m-d', strtotime('+7 days')), 'period_months' => '12', 'internal_notes' => 'STAFF-ONLY-NOTE', 'client_notes' => 'Visible note', 'client_reminders' => '1', 'project' => '']);

        return RenewalService::find($r['public_id']);
    }

    public function testStaffCanAddAServiceThroughTheFormAndWorkersAndClientsCannotOpenStaffScreens(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/renewals')['status']);
        $this->assertSame(200, $m->get('/renewals/new')['status']);
        $r = $m->post('/renewals', ['client' => $this->clientId, 'service_type' => 'domain', 'identifier' => 'Form-Added.com', 'provider' => 'Registrar', 'activated_on' => '2025-01-01', 'expires_on' => '2027-01-01', 'period_months' => '12', 'client_reminders' => '1'], '/renewals/new');
        $this->assertSame(303, $r['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_services WHERE identifier = 'form-added.com'")['c']);
        foreach (['dev@x.test', 'sup@x.test', 'partner@x.test', 'alpha@client.test'] as $who) {
            $c = $this->loggedIn($who);
            foreach (['/renewals', '/renewals/new', '/renewals/prices', '/renewals/export'] as $p) {
                $this->assertSame(403, $c->get($p)['status'], "{$who} must not open {$p}");
            }
        }
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post('/renewals', ['client' => $this->clientId, 'service_type' => 'domain', 'identifier' => 'x.com'], '/account')['status']);
    }

    public function testAClientSeesOnlyTheirOwnServicesAndNothingInternal(): void
    {
        $mine = $this->svc('mine-only.com');
        $theirs = $this->svc('theirs-only.com', $this->client2Id);
        $c = $this->loggedIn('alpha@client.test');
        $list = $c->get('/my/services');
        $this->assertSame(200, $list['status']);
        $this->assertStringContainsString('mine-only.com', $list['body']);
        $this->assertStringNotContainsString('theirs-only.com', $list['body']);
        $show = $c->get('/my/services/' . $mine['public_id']);
        $this->assertSame(200, $show['status']);
        foreach (['STAFF-ONLY-NOTE', 'internal notes'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $show['body']);
        }
        $this->assertSame(404, $c->get('/my/services/' . $theirs['public_id'])['status'], 'IDOR across clients');
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/services/' . $mine['public_id'])['status']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/my/services')['status'], 'staff use the staff screens');
        // a client cannot reach or act on the staff endpoints for their own service
        $this->assertSame(403, $c->get('/renewals/' . $mine['public_id'])['status']);
        $this->assertSame(403, $c->post('/renewals/' . $mine['public_id'], ['action' => 'cancel', 'reason' => 'I do not want it', 'confirm_password' => self::PASSWORD], '/my/services')['status']);
        $this->assertSame('awaiting_admin_review', RenewalService::find($mine['public_id'])['status']);
    }

    public function testApprovalCancelAndConfirmationNeedTheRightPermissionAndThePassword(): void
    {
        $s = $this->svc();
        $this->assertSame('awaiting_admin_review', $s['status']);
        $url = '/renewals/' . $s['public_id'];
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get($url)['status']);
        $this->assertSame(403, $m->post($url, ['action' => 'approve', 'price_mode' => 'list', 'confirm_password' => self::PASSWORD], $url)['status'], 'a manager is refused');
        $this->assertSame('awaiting_admin_review', RenewalService::find($s['public_id'])['status']);
        $a = $this->loggedIn('admin@x.test');
        $a->post($url, ['action' => 'approve', 'price_mode' => 'list', 'period_months' => '12', 'confirm_password' => 'wrong'], $url);
        $this->assertSame('awaiting_admin_review', RenewalService::find($s['public_id'])['status'], 'a wrong password does not approve');
        $a->post($url, ['action' => 'approve', 'price_mode' => 'list', 'period_months' => '12', 'confirm_password' => self::PASSWORD], $url);
        $this->assertSame('approved_for_renewal', RenewalService::find($s['public_id'])['status']);
        $this->assertStringContainsString('1,416.00', $a->get($url)['body']);
        // the client now sees the approved price but no internal text
        $client = $this->loggedIn('alpha@client.test')->get('/my/services/' . $s['public_id'])['body'];
        $this->assertStringContainsString('1,416.00', $client);
        $this->assertStringNotContainsString('STAFF-ONLY-NOTE', $client);
        // cancel needs the password and the approve permission
        $m->post($url, ['action' => 'cancel', 'reason' => 'Manager tries to cancel it', 'confirm_password' => self::PASSWORD], $url);
        $this->assertSame('approved_for_renewal', RenewalService::find($s['public_id'])['status']);
        $a->post($url, ['action' => 'cancel', 'reason' => 'Client moved to another agency.', 'confirm_password' => 'bad'], $url);
        $this->assertSame('approved_for_renewal', RenewalService::find($s['public_id'])['status']);
    }

    public function testThePaymentThenProviderConfirmationFlowOverHttpAndPaymentNeverRenews(): void
    {
        $s = $this->svc();
        $url = '/renewals/' . $s['public_id'];
        $a = $this->loggedIn('admin@x.test');
        $m = $this->loggedIn('mgr@x.test');
        $a->post($url, ['action' => 'approve', 'price_mode' => 'list', 'period_months' => '12', 'confirm_password' => self::PASSWORD], $url);
        $m->post($url, ['action' => 'invoice', 'invoice_ref' => 'INV-H1', 'amount' => '1416.00'], $url);
        $m->post($url, ['action' => 'invoice_sent'], $url);
        $dup = ['action' => 'payment', 'amount' => '1416', 'method' => 'upi', 'reference' => 'UPI-HTTP-1', 'received_on' => date('Y-m-d')];
        $m->post($url, $dup, $url);
        $m->post($url, $dup, $url);
        $x = RenewalService::find($s['public_id']);
        $this->assertSame('payment_received', $x['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) AS c FROM renewal_payments')['c'], 'a double submit records one payment');
        $this->assertSame($s['expires_on'], $x['expires_on'], 'paid but NOT renewed');
        $this->assertStringContainsString('Provider renewal', $m->get($url)['body']);
        $m->post($url, ['action' => 'start'], $url);
        $new = date('Y-m-d', strtotime('+372 days'));
        $conf = ['action' => 'confirm', 'confirmation_ref' => 'REG-HTTP-1', 'confirmed_on' => date('Y-m-d'), 'new_expiry' => $new];
        $m->post($url, $conf + ['confirm_password' => self::PASSWORD], $url);
        $this->assertSame('renewal_in_progress', RenewalService::find($s['public_id'])['status'], 'a manager cannot record the provider confirmation');
        $a->post($url, $conf + ['confirm_password' => 'wrong'], $url);
        $this->assertSame('renewal_in_progress', RenewalService::find($s['public_id'])['status']);
        $a->post($url, $conf + ['confirm_password' => self::PASSWORD], $url);
        $y = RenewalService::find($s['public_id']);
        $this->assertSame('renewed', $y['status']);
        $this->assertSame($new, $y['expires_on']);
        $clientPage = $this->loggedIn('alpha@client.test')->get('/my/services/' . $s['public_id'])['body'];
        $this->assertStringNotContainsString('REG-HTTP-1', $clientPage, 'the provider confirmation reference is staff-only');
    }

    public function testServiceTextIsEscapedAndCsvExportIsGuarded(): void
    {
        $this->svc('xss-test.com', null, ['internal_notes' => '<script>alert(1)</script>', 'client_notes' => '"><img src=x onerror=alert(2)>']);
        $a = $this->loggedIn('admin@x.test');
        $id = Database::one('SELECT public_id FROM renewal_services')['public_id'];
        $body = $a->get('/renewals/' . $id)['body'];
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $client = $this->loggedIn('alpha@client.test')->get('/my/services/' . $id)['body'];
        $this->assertStringNotContainsString('<img src=x', $client);
        $csv = $this->loggedIn('mgr@x.test')->get('/renewals/export');
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('xss-test.com', $csv['body']);
        $h = $this->loggedIn('mgr@x.test')->headersOf('/renewals/export');
        $this->assertStringContainsString('text/csv', strtolower(implode(' ', $h)));
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'renewal.exported'")['c']);
    }

    public function testThePriceListIsEditedOnlyByThePricesPermissionWithThePassword(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $this->assertSame(200, $a->get('/renewals/prices')['status']);
        $form = ['service_type' => 'hosting', 'period_months' => '12', 'base' => '4000', 'markup' => '0', 'discount' => '0', 'tax_percent' => '18', 'effective_from' => '2026-01-01', 'note' => 'Hosting price'];
        $a->post('/renewals/prices', $form + ['confirm_password' => 'bad'], '/renewals/prices');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_prices WHERE service_type = 'hosting'")['c']);
        $a->post('/renewals/prices', $form + ['confirm_password' => self::PASSWORD], '/renewals/prices');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) AS c FROM renewal_prices WHERE service_type = 'hosting'")['c']);
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/renewals/prices')['status'], 'managers may read the list');
        $this->assertSame(403, $m->post('/renewals/prices', $form + ['effective_from' => '2027-01-01', 'confirm_password' => self::PASSWORD], '/renewals/prices')['status']);
    }

    public function testTheDashboardWarnsWhenTheScheduledTaskHasNotRun(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertStringContainsString('Reminders are not running', $m->get('/renewals')['body']);
        RenewalScheduler::run();
        $this->assertStringNotContainsString('Reminders are not running', $m->get('/renewals')['body']);
    }
}
