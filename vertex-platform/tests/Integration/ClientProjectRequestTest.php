<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryService;
use Vertex\Services\InvoiceService;
use Vertex\Services\ProjectService;

/** A client asks for a new project from their dashboard; the project is confirmed automatically when its advance payment is verified. */
final class ClientProjectRequestTest extends HttpTestCase
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
    private function form(array $over = []): array
    {
        return $over + ['project_name' => 'Studio catalogue website', 'project_category' => 'website', 'services' => ['website_development'], 'problem_statement' => 'We sell furniture and customers cannot see our catalogue online.',
            'expected_outcome' => 'A fast catalogue site with an enquiry form.', 'features' => 'Catalogue, search, enquiry form', 'preferred_tech' => '', 'budget_range' => '', 'timeline' => 'one_month', 'notes' => ''];
    }

    public function testAClientSendsARequestFromTheirDashboardAndFollowsIt(): void
    {
        $alpha = $this->loggedIn('alpha@client.test');
        $dash = $alpha->get('/dashboard')['body'];
        $this->assertStringContainsString('Request a new project', $dash, 'a button on the dashboard');
        $this->assertStringContainsString('href="/my/project-requests"', $dash, 'and a menu entry');
        $page = $alpha->get('/my/project-requests/new');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('name="problem_statement"', $page['body']);
        $this->assertStringNotContainsString('name="email"', $page['body'], 'the client is already known: no identity fields to retype');

        $r = $alpha->post('/my/project-requests', $this->form(), '/my/project-requests/new');
        $this->assertSame(303, $r['status']);
        $this->assertSame('/my/project-requests', $r['location']);
        $e = Database::one("SELECT * FROM enquiries WHERE source = 'client_portal'");
        $this->assertNotNull($e);
        $this->assertSame((int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'], (int) $e['client_id'], 'already linked to the client');
        $this->assertSame('alpha@client.test', $e['email'], 'identity comes from the client record');
        $this->assertSame('none', $e['duplicate_status']);
        $this->assertStringContainsString('Studio catalogue website', $e['description']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'enquiry.received'")['c'], 'the team is told');

        $list = $alpha->get('/my/project-requests')['body'];
        $this->assertStringContainsString('Studio catalogue website', $list);
        $this->assertStringContainsString($e['reference'], $list);
        $this->assertStringContainsString('Received. Our team is reviewing it', $list);
        $this->assertStringContainsString($e['reference'], $this->loggedIn('admin@x.test')->get('/crm/enquiries')['body'], 'the team sees it in the CRM');

        // validation errors keep what was typed
        $bad = $alpha->post('/my/project-requests', $this->form(['problem_statement' => 'short', 'services' => []]), '/my/project-requests/new');
        $this->assertSame('/my/project-requests/new', $bad['location']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM enquiries WHERE source = 'client_portal'")['c']);

        // other people: another client sees nothing of it; staff and strangers cannot use the client form
        $this->assertStringNotContainsString($e['reference'], $this->loggedIn('bravo@client.test')->get('/my/project-requests')['body']);
        foreach (['mgr@x.test', 'dev@x.test'] as $who) {
            $this->assertSame(403, $this->loggedIn($who)->get('/my/project-requests/new')['status'], $who);
        }
        $this->assertSame(303, $this->client()->get('/my/project-requests')['status']);
    }

    public function testTheTeamTurnsItIntoAProjectForTheSameClientAndTheAdvancePaymentConfirmsIt(): void
    {
        $alpha = $this->loggedIn('alpha@client.test');
        $alpha->post('/my/project-requests', $this->form(), '/my/project-requests/new');
        $e = Database::one("SELECT * FROM enquiries WHERE source = 'client_portal'");
        $clientsBefore = (int) Database::one('SELECT COUNT(*) c FROM clients')['c'];

        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'won', null, true);
        $this->assertStringContainsString('Accepted', $alpha->get('/my/project-requests')['body']);
        $conv = EnquiryService::convert($this->admin, EnquiryPolicy::find($e['public_id']), []); // no client chosen: it is the client's own request
        $this->assertSame($clientsBefore, (int) Database::one('SELECT COUNT(*) c FROM clients')['c'], 'no second client record is made');
        $project = Database::one('SELECT * FROM projects WHERE id = ?', [$conv['project_id']]);
        $this->assertSame('draft', $project['status']);
        $this->assertSame((int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'], (int) $project['client_id']);
        $list = $alpha->get('/my/project-requests')['body'];
        $this->assertStringContainsString('confirmed and scheduled as soon as the advance payment is received', $list);
        $this->assertStringContainsString($conv['project_code'], $list);

        // an invoice for the project; a payment that is NOT marked as the advance changes nothing
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Advance', 'items' => [['description' => 'Advance for the website', 'quantity' => '1', 'unit' => '5000']], 'discount' => '0', 'project' => $conv['project_public_id'], 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $inv = InvoiceService::find($d['public_id']);
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => '1000', 'method' => 'bank_transfer', 'external_ref' => 'UTR-OTHER-1', 'received_on' => AppTime::today()]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));
        $this->assertSame('draft', Database::one('SELECT status FROM projects WHERE id = ?', [$project['id']])['status']);

        // the advance, recorded by hand: nothing happens until it is VERIFIED, then the project is fixed
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'advance', 'gross' => '2000', 'method' => 'bank_transfer', 'external_ref' => 'UTR-ADV-1', 'received_on' => AppTime::today()]);
        $this->assertSame('draft', Database::one('SELECT status FROM projects WHERE id = ?', [$project['id']])['status'], 'recorded but not verified');
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));
        $this->assertSame('ready_to_start', Database::one('SELECT status FROM projects WHERE id = ?', [$project['id']])['status'], 'verified advance fixes the project');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.confirmed_by_advance'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'project.confirmed'", [$this->client])['c'], 'the client is told');
        $this->assertStringContainsString('is confirmed', $alpha->get('/my/project-requests')['body']);

        // a later payment never moves a project that is already further along
        Database::run("UPDATE projects SET status = 'in_progress' WHERE id = ?", [$project['id']]);
        $this->assertFalse(ProjectService::confirmAfterAdvance((int) $project['id'], 'ANY'));
        $this->assertSame('in_progress', Database::one('SELECT status FROM projects WHERE id = ?', [$project['id']])['status']);
    }

    public function testAnAdvancePaidOnlineIsRecognisedToo(): void
    {
        $alpha = $this->loggedIn('alpha@client.test');
        $alpha->post('/my/project-requests', $this->form(), '/my/project-requests/new');
        $e = Database::one("SELECT * FROM enquiries WHERE source = 'client_portal'");
        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($e['public_id']), 'won', null, true);
        $conv = EnquiryService::convert($this->admin, EnquiryPolicy::find($e['public_id']), []);
        Database::run("UPDATE projects SET status = 'awaiting_payment' WHERE id = ?", [$conv['project_id']]);
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Advance', 'items' => [['description' => 'Advance', 'quantity' => '1', 'unit' => '5000']], 'discount' => '0', 'project' => $conv['project_public_id'], 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $p = ClientPaymentService::recordGateway(InvoiceService::find($d['public_id']), 200000, 'Razorpay pay_AdvanceOnline1', 'Confirmed by Razorpay', 'advance');
        $this->assertTrue($p['created']);
        $this->assertSame('advance', Database::one('SELECT payment_role FROM client_payments WHERE id = ?', [$p['id']])['payment_role']);
        $this->assertSame('ready_to_start', Database::one('SELECT status FROM projects WHERE id = ?', [$conv['project_id']])['status']);
        // an invoice without a project can never be an advance
        $d2 = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Other', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '900']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d2['public_id']));
        $p2 = ClientPaymentService::recordGateway(InvoiceService::find($d2['public_id']), 90000, 'Razorpay pay_NoProject001', 'Confirmed by Razorpay', 'advance');
        $this->assertSame('other', Database::one('SELECT payment_role FROM client_payments WHERE id = ?', [$p2['id']])['payment_role']);
    }

    public function testThePaymentRequestFormOffersTheAdvanceChoiceOnlyForProjectInvoices(): void
    {
        $project = Database::one('SELECT public_id FROM projects WHERE id = ?', [$this->alpha['id']]);
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Advance', 'items' => [['description' => 'Advance', 'quantity' => '1', 'unit' => '5000']], 'discount' => '0', 'project' => $project['public_id'], 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $fin = $this->loggedIn('fin@x.test');
        $this->assertStringContainsString('The ADVANCE payment', $fin->get('/invoices/' . $d['public_id'] . '/pay-request')['body']);
        $d2 = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Other', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '900']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d2['public_id']));
        $this->assertStringNotContainsString('The ADVANCE payment', $fin->get('/invoices/' . $d2['public_id'] . '/pay-request')['body']);
    }
}
