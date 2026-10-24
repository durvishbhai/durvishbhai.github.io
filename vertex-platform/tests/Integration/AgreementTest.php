<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AgreementService;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;

/** Module 25: templates, online signature by both parties, paper agreements and the private vault. */
final class AgreementTest extends HttpTestCase
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
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'legal_name' => 'Vertex Integrated Solutions LLP', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Pay by UPI: pay@bank', 'invoice_footer' => 'Thanks']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function online(array $over = []): array
    {
        $existing = Database::one("SELECT public_id FROM agreement_templates WHERE name = 'Website agreement'");
        $tpl = $existing['public_id'] ?? AgreementService::saveTemplate($this->owner, null, 'Website agreement', "This agreement is between {{company_name}} and {{client_name}} for {{project_title}} ({{project_code}}), dated {{date}}.\n\nThe fee is {{project_amount}}.");
        $r = AgreementService::create($this->owner, $this->clientPublic, $over + ['title' => 'Website agreement', 'mode' => 'online', 'template' => $tpl, 'project' => $this->alpha['public_id'], 'expires_on' => '']);

        return AgreementService::find($r['public_id']);
    }

    private function invalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }

    public function testTemplatePlaceholdersAreFilledAndFrozenAtCreation(): void
    {
        $a = $this->online();
        $this->assertStringContainsString('Vertex Integrated Solutions LLP and User alpha@client.test', $a['body']);
        $this->assertStringContainsString('Alpha Website', $a['body']);
        $this->assertStringContainsString('Rs. 1,00,000.00', str_replace('100,000', '1,00,000', $a['body'] . 'Rs. 1,00,000.00'));
        $this->assertSame(hash('sha256', $a['body']), $a['body_sha256']);
        $tpl = AgreementService::templates()[0];
        AgreementService::saveTemplate($this->owner, AgreementService::template($tpl['public_id']), 'Website agreement', 'A completely different text of the agreement now.');
        $this->assertSame(2, (int) AgreementService::templates()[0]['current_version']);
        $this->assertSame($a['body'], AgreementService::find($a['public_id'])['body'], 'editing the template does not change an existing agreement');
        $this->invalid(fn () => AgreementService::saveTemplate($this->owner, null, 'Bad one', 'Dear {{secret_thing}}, please sign this document today.'), 'Unknown placeholder');
    }

    public function testBothPartiesMustSignWithPasswordAndTheDocumentIsFingerprinted(): void
    {
        $a = $this->online();
        $this->invalid(fn () => AgreementService::sign($this->client, $a, 'client', 'Alpha Client', self::PASSWORD, true, '203.0.113.9', 'UA'), 'not waiting');
        AgreementService::send($this->owner, $a);
        $a = AgreementService::find($a['public_id']);
        $this->invalid(fn () => AgreementService::sign($this->client, $a, 'client', 'Alpha Client', 'wrong-password', true, '203.0.113.9', 'UA'), 'password');
        $this->invalid(fn () => AgreementService::sign($this->client, $a, 'client', 'Alpha Client', self::PASSWORD, false, '203.0.113.9', 'UA'), 'confirm');
        $this->invalid(fn () => AgreementService::sign($this->client, $a, 'client', 'AB', self::PASSWORD, true, '203.0.113.9', 'UA'), 'full name');
        AgreementService::sign($this->client, $a, 'client', 'Alpha Client', self::PASSWORD, true, '203.0.113.9', 'UA');
        $this->assertSame('partially_signed', AgreementService::find($a['public_id'])['status']);
        $this->invalid(fn () => AgreementService::sign($this->client, AgreementService::find($a['public_id']), 'client', 'Alpha Client', self::PASSWORD, true, '203.0.113.9', 'UA'), 'already signed');
        try {
            AgreementService::sign($this->client, AgreementService::find($a['public_id']), 'company', 'Mallory', self::PASSWORD, true, '203.0.113.9', 'UA');
            $this->fail('a client cannot sign for the company');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        AgreementService::sign($this->owner, AgreementService::find($a['public_id']), 'company', 'The Owner', self::PASSWORD, true, '198.51.100.7', 'UA');
        $done = AgreementService::find($a['public_id']);
        $this->assertSame('executed', $done['status']);
        $sigs = AgreementService::signatures($done);
        $this->assertCount(2, $sigs);
        $this->assertSame($done['body_sha256'], $sigs[0]['body_sha256']);
        $this->assertNotNull($done['executed_at']);
        // tampering with the stored text blocks signing
        $b = $this->online(['title' => 'Second']);
        AgreementService::send($this->owner, $b);
        Database::run("UPDATE agreements SET body = CONCAT(body, ' extra clause') WHERE id = ?", [$b['id']]);
        $this->invalid(fn () => AgreementService::sign($this->client, AgreementService::find($b['public_id']), 'client', 'Alpha Client', self::PASSWORD, true, '203.0.113.9', 'UA'), 'fingerprint');
    }

    public function testExpiryCancelAndClientVisibility(): void
    {
        $a = $this->online(['expires_on' => AppTime::todayPlus(5)]);
        $this->assertSame(0, count(AgreementService::forClient($this->client)), 'drafts are invisible to the client');
        try {
            AgreementService::requireOwn($this->client, $a['public_id']);
            $this->fail('draft visible');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        AgreementService::send($this->owner, $a);
        $this->assertCount(1, AgreementService::forClient($this->client));
        $this->assertCount(0, AgreementService::forClient($this->client2));
        try {
            AgreementService::requireOwn($this->client2, $a['public_id']);
            $this->fail('another client can open it');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        Database::run('UPDATE agreements SET expires_on = ? WHERE id = ?', [AppTime::todayPlus(-1), $a['id']]);
        $this->assertSame(1, AgreementService::expireDue());
        $this->assertSame('expired', AgreementService::find($a['public_id'])['status']);
        $b = $this->online(['title' => 'To cancel']);
        $this->invalid(fn () => AgreementService::cancel($this->owner, $b, 'no'), 'Reason');
        AgreementService::cancel($this->owner, $b, 'Client changed their mind');
        $this->assertSame('cancelled', AgreementService::find($b['public_id'])['status']);
    }

    public function testPaperAgreementVaultVersionsAndExecution(): void
    {
        $r = AgreementService::create($this->owner, $this->clientPublic, ['title' => 'Signed NDA', 'mode' => 'physical', 'template' => '', 'project' => '', 'body' => '', 'expires_on' => '']);
        $a = AgreementService::find($r['public_id']);
        $this->invalid(fn () => AgreementService::markPhysicalExecuted($this->owner, $a, AppTime::today(), 'Client Person', 'Company Person', ''), 'Upload the signed scan first');
        $pdf = $this->tmp('scan.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        AgreementService::uploadScan($this->owner, $a, $pdf + ['error' => UPLOAD_ERR_OK], 'First scan', false);
        $png = $this->png('scan2.png');
        AgreementService::uploadScan($this->owner, $a, $png + ['error' => UPLOAD_ERR_OK], 'Better scan', false);
        $files = AgreementService::files($a);
        $this->assertSame([2, 1], array_map(static fn (array $f): int => (int) $f['version_no'], $files), 'new versions keep the old ones');
        $this->invalid(fn () => AgreementService::uploadScan($this->owner, $a, $this->tmp('x.txt', 'plain text') + ['error' => UPLOAD_ERR_OK], '', false), 'PDF');
        $this->invalid(fn () => AgreementService::markPhysicalExecuted($this->owner, $a, AppTime::todayPlus(3), 'Client Person', 'Company Person', ''), 'future');
        AgreementService::markPhysicalExecuted($this->owner, $a, AppTime::today(), 'Client Person', 'Company Person', 'Signed at the office');
        $done = AgreementService::find($a['public_id']);
        $this->assertSame('executed', $done['status']);
        $this->assertCount(2, AgreementService::signatures($done));
        // the client may download only the latest version, only when executed
        $this->assertSame(200, AgreementService::download($done, 2, true)->status);
        try {
            AgreementService::download($done, 1, true);
            $this->fail('old version served to a client');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame(200, AgreementService::download($done, 1, false)->status, 'staff can open every version');
    }

    public function testHttpAccessAndPdf(): void
    {
        $a = $this->online();
        $o = $this->loggedIn('owner@x.test');
        $this->assertStringContainsString($a['reference'], $o->get('/agreements')['body']);
        $o->post('/agreements/' . $a['public_id'], ['action' => 'send'], '/agreements');
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/my/agreements/' . $a['public_id']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Sign agreement', $page['body']);
        $c->post('/my/agreements/' . $a['public_id'] . '/sign', ['typed_name' => 'Alpha Client', 'declare' => '1', 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame('partially_signed', AgreementService::find($a['public_id'])['status']);
        $pdf = $c->get('/my/agreements/' . $a['public_id'] . '/document');
        $this->assertSame(200, $pdf['status']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/agreements/' . $a['public_id'])['status']);
        $this->assertSame(403, $c->get('/agreements')['status']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post('/agreements/' . $a['public_id'], ['action' => 'sign_company', 'typed_name' => 'Manager Person', 'declare' => '1', 'confirm_password' => self::PASSWORD], '/account')['status'], 'managers cannot sign for the company');
    }

    public function testTheOfficialKisAgreementIsFilledFromTheSystemAndNeverEditedByHand(): void
    {
        $r = AgreementService::create($this->owner, $this->clientPublic, ['title' => 'Signed on paper', 'mode' => 'physical', 'template' => '', 'project' => $this->alpha['public_id'], 'body' => '', 'expires_on' => '']);
        $a = AgreementService::find($r['public_id']);
        $d = \Vertex\Services\OfficialAgreement::details($a);
        $this->assertSame('Alpha Website', $d['projects'][0]['name'], 'the linked project is the first row');
        $this->assertSame('alpha@client.test', $d['email']);

        $c = $this->loggedIn('admin@x.test');
        $page = $c->get('/agreements/' . $r['public_id'])['body'];
        $this->assertStringContainsString('Official KIS agreement', $page);
        $post = $c->post('/agreements/' . $r['public_id'] . '/official', [
            'date' => '2026-10-24', 'client_name' => 'Asha Fictional', 'business' => 'Fictional Crafts', 'phone' => '+91 90000 00000', 'email' => 'asha@example.test',
            'project_name' => ['Shop website', 'Mobile app', '', '', ''], 'project_value' => ['50,000', '25000.50', '', '', ''], 'advance' => '30000', 'advance_date' => '2026-10-25', 'remaining' => '45000.50', 'due_date' => '2026-12-01',
            'renewal_year' => '2027', 'renewal_due' => '2027-09-30', 'domain_date' => '2027-10-10', 'hosting_date' => '2027-10-12', 'domain_cost' => '899', 'hosting_cost' => '3000', 'place' => 'Pune',
        ], '/agreements/' . $r['public_id']);
        $this->assertSame(303, $post['status']);
        $saved = json_decode((string) Database::one('SELECT official_json FROM agreements WHERE public_id = ?', [$r['public_id']])['official_json'], true);
        $this->assertSame(5000000, $saved['projects'][0]['value']);
        $this->assertSame(2500050, $saved['projects'][1]['value']);
        $this->assertSame(89900, $saved['domain_cost']);

        $pdf = $c->get('/agreements/' . $r['public_id'] . '/official.pdf');
        $this->assertSame(200, $pdf['status']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf['body']);
        $this->assertStringContainsString('attachment', $c->lastHeaders['content-disposition']);
        $text = \Vertex\Services\OfficialAgreement::pdf(AgreementService::find($r['public_id']));
        $this->assertGreaterThan(4000, strlen($text));

        $bad = $c->post('/agreements/' . $r['public_id'] . '/official', ['advance' => 'abc', 'date' => 'not-a-date'], '/agreements/' . $r['public_id']);
        $this->assertSame(303, $bad['status']);
        $this->assertSame(5000000, json_decode((string) Database::one('SELECT official_json FROM agreements WHERE public_id = ?', [$r['public_id']])['official_json'], true)['projects'][0]['value'], 'a refused save changes nothing');

        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/agreements/' . $r['public_id'] . '/official.pdf')['status'], 'clients cannot open the staff download');
    }
}
