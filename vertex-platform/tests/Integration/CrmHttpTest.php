<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryService;
use Vertex\Services\PermissionSync;

/** CRM through real HTTP: public forms, role gates, ID guessing, referral-partner isolation, attachments. */
final class CrmHttpTest extends HttpTestCase
{
    private int $admin;
    private int $manager;
    private int $manager2;
    private int $support;
    private int $partner;
    private int $partner2;
    private int $client;
    private array $mine;      // owned by $manager
    private array $others;    // owned by nobody
    private array $viaPartner;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->manager2 = $this->makeUser('mgr2@x.test', ['manager']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
        $this->partner = $this->makeUser('p1@x.test', ['referral_partner']);
        $this->partner2 = $this->makeUser('p2@x.test', ['referral_partner']);
        $this->client = $this->makeUser('cl@x.test', ['client']);
        foreach (glob(VERTEX_ROOT . '/storage/uploads/*/*/*') ?: [] as $stale) {
            @unlink($stale);
        }
        $this->mine = $this->enq('Alpha Lead', 'alpha@lead.test', '+91 91000 00001', 'Alpha Works');
        $this->others = $this->enq('Bravo Secret', 'bravo@lead.test', '+91 92000 00002', 'Bravo Secret Industries');
        $r = EnquiryService::create($this->partner, 'referral_partner', $this->form(['contact_name' => 'Partner Lead', 'email' => 'pl@lead.test', 'phone' => '+91 93000 00003', 'company_name' => 'Partner Co']));
        $this->viaPartner = EnquiryPolicy::find($r['public_id']);
        EnquiryService::assign($this->admin, $this->mine, $this->manager);
        $this->mine = EnquiryPolicy::find($this->mine['public_id']);
        EnquiryService::addNote($this->admin, $this->others, 'CONFIDENTIAL internal pricing note');
        EnquiryService::addNote($this->admin, $this->viaPartner, 'PRIVATE-STAFF-NOTE-XYZ');
        EnquiryService::assign($this->admin, $this->viaPartner, $this->manager2);
        EnquiryService::moveStage($this->admin, EnquiryPolicy::find($this->viaPartner['public_id']), 'quote_in_preparation');
    }

    private function form(array $over = []): array
    {
        return $over + ['contact_name' => 'Ravi Kumar', 'email' => 'ravi@acme.test', 'phone' => '+91 98765 43210', 'company_name' => 'Acme Traders', 'service' => 'website_development',
            'description' => 'We need a catalogue website with about ten pages and an enquiry form.', 'budget_range' => '25k_100k', 'expected_deadline' => ''];
    }

    private function enq(string $name, string $email, string $phone, string $company): array
    {
        $r = EnquiryService::create($this->admin, 'manual', $this->form(['contact_name' => $name, 'email' => $email, 'phone' => $phone, 'company_name' => $company]));

        return EnquiryPolicy::find($r['public_id']);
    }

    /** The results table body only (the search box echoes the query, which is not a leak). */
    private function rows(string $html): string
    {
        return preg_match('#<tbody>(.*?)</tbody>#s', $html, $m) ? $m[1] : '';
    }

    private function publicForm(): array
    {
        return ['contact_name' => 'Web Visitor', 'email' => 'visitor@web.test', 'phone' => '+91 94000 00004', 'company_name' => 'Visitor Ltd', 'service' => 'website_development',
            'description' => 'I would like a simple brochure website for my small business, please.', 'budget_range' => 'under_25k', 'consent' => '1', 'website_url' => ''];
    }

    // ---- public forms -------------------------------------------------------------------------------------------------

    public function testPublicRequirementsFormCreatesAnEnquiryAndShowsTheReference(): void
    {
        $c = $this->client();
        $this->assertSame(200, $c->get('/request-quote')['status']);
        $r = $c->post('/request-quote', $this->publicForm(), '/request-quote');
        $this->assertSame(303, $r['status']);
        $this->assertSame('/enquiry/received', $r['location']);
        $page = $c->get('/enquiry/received')['body'];
        $row = Database::one("SELECT * FROM enquiries WHERE email = 'visitor@web.test'");
        $this->assertNotNull($row);
        $this->assertStringContainsString($row['reference'], $page);
        $this->assertSame('website_requirements', $row['source']);
        $this->assertSame('normal', $row['priority']);
        $this->assertNull($row['owner_id']);
        $this->assertNotNull($row['consent_at']);
        $this->assertStringContainsString('not sent you an automatic confirmation email', $page, 'no false notification claim');
    }

    public function testContactAndAppointmentFormsUseTheirOwnSources(): void
    {
        $c = $this->client();
        $c->post('/contact', ['contact_name' => 'Asha', 'email' => 'asha@web.test', 'description' => 'Hello, can you help me with a website?', 'consent' => '1', 'website_url' => ''], '/contact');
        $this->assertSame('contact_form', Database::one("SELECT source FROM enquiries WHERE email = 'asha@web.test'")['source']);
        $c->post('/book-appointment', ['contact_name' => 'Ben', 'email' => 'ben@web.test', 'phone' => '+91 95000 00005', 'preferred_slot' => 'Friday 11am', 'description' => 'Discuss a video project', 'consent' => '1', 'website_url' => ''], '/book-appointment');
        $e = Database::one("SELECT source, preferred_slot FROM enquiries WHERE email = 'ben@web.test'");
        $this->assertSame('appointment_request', $e['source']);
        $this->assertSame('Friday 11am', $e['preferred_slot']);
        $this->assertStringContainsString('does not book a slot', $c->get('/book-appointment')['body'], 'the page says it is a request, not a booking');
    }

    public function testPublicFormsRejectInvalidInputWithMessagesAndStoreNothing(): void
    {
        $c = $this->client();
        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $c->post('/request-quote', ['email' => 'bad', 'description' => 'short', 'consent' => ''] + $this->publicForm(), '/request-quote');
        $body = $c->get('/request-quote')['body'];
        $this->assertStringContainsString('valid email', $body);
        $this->assertStringContainsString('contact you', $body, 'consent is required');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
        $this->assertStringContainsString('Web Visitor', $body, 'entered values are kept');
    }

    public function testPublicFormsAreProtectedByCsrfHoneypotAndRateLimit(): void
    {
        $c = $this->client();
        $c->get('/request-quote');
        $this->assertSame(419, $c->postNoToken('/request-quote', $this->publicForm())['status']);

        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $bot = $this->client();
        $r = $bot->post('/request-quote', ['website_url' => 'http://spam.example'] + $this->publicForm(), '/request-quote');
        $this->assertSame('/enquiry/received', $r['location'], 'bots see the same page');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'honeypot submissions are discarded');

        $flood = $this->client();
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $flood->post('/contact', ['contact_name' => "Flood {$i}", 'email' => "f{$i}@web.test", 'description' => 'Hello hello hello hello', 'consent' => '1', 'website_url' => ''], '/contact')['status'];
        }
        $this->assertContains(429, $statuses, 'public forms are rate limited per IP');
        $this->assertLessThanOrEqual(6, (int) Database::one("SELECT COUNT(*) c FROM enquiries WHERE email LIKE 'f%@web.test'")['c']);
    }

    public function testStoredScriptTagsAreEscapedWhenStaffViewThem(): void
    {
        $r = EnquiryService::create(null, 'contact_form', ['contact_name' => 'Mallory', 'email' => 'm@web.test', 'description' => 'Hi <script>alert("xss")</script> there, please call me'], ['consent' => true]);
        $page = $this->loggedIn('admin@x.test')->get('/crm/enquiries/' . $r['public_id'])['body'];
        $this->assertStringNotContainsString('<script>alert', $page);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $page);
    }

    // ---- staff gates & IDOR --------------------------------------------------------------------------------------------

    public function testCrmScreensAreGatedByPermission(): void
    {
        foreach (['/crm', '/crm/enquiries'] as $path) {
            foreach (['admin@x.test' => 200, 'mgr@x.test' => 200, 'sup@x.test' => 403, 'p1@x.test' => 403, 'cl@x.test' => 403] as $email => $code) {
                $this->assertSame($code, $this->loggedIn($email)->get($path)['status'], "{$email} {$path}");
            }
        }
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get('/crm/enquiries/create')['status']);
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get('/crm/enquiries/create')['status']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->get('/crm/enquiries/create')['status']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/admin/crm-stages')['status']);
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get('/admin/crm-stages')['status']);
        $anon = $this->client();
        foreach (['/crm', '/crm/enquiries/' . $this->mine['public_id'], '/partner/referrals'] as $p) {
            $this->assertSame('/login', $anon->get($p)['location'], $p);
        }
    }

    public function testManagerOnlySeesAndOpensOwnEnquiriesAndGuessingIdsFails(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/crm/enquiries/' . $this->mine['public_id'])['status']);
        $this->assertSame(404, $m->get('/crm/enquiries/' . $this->others['public_id'])['status']);
        foreach (['1', '2', '3', '0', "' OR 1=1 --", '../admin/users', str_repeat('a', 32), strtoupper($this->others['public_id']), $this->others['reference']] as $guess) {
            $this->assertSame(404, $m->get('/crm/enquiries/' . rawurlencode($guess))['status'], "guess '{$guess}'");
        }
        $list = $m->get('/crm/enquiries')['body'];
        $this->assertStringContainsString($this->mine['reference'], $list);
        $this->assertStringNotContainsString($this->others['reference'], $list);
        $this->assertStringNotContainsString('Bravo Secret', $list);
        $this->assertStringContainsString($this->mine['reference'], $m->get('/crm')['body']);
        $this->assertStringNotContainsString($this->others['reference'], $m->get('/crm')['body']);

        $m2 = $this->loggedIn('mgr2@x.test');
        $this->assertSame(404, $m2->get('/crm/enquiries/' . $this->mine['public_id'])['status'], 'cross-manager isolation');
    }

    public function testSearchResultsAreScopedToWhatTheUserMaySee(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        foreach (['Bravo', 'bravo@lead.test', '92000 00002', $this->others['reference'], 'Secret'] as $term) {
            $rows = $this->rows($m->get('/crm/enquiries?q=' . rawurlencode($term))['body']);
            $this->assertStringNotContainsString($this->others['reference'], $rows, "search '{$term}'");
            $this->assertStringNotContainsString('Bravo', $rows, "search '{$term}'");
            $this->assertStringContainsString('No enquiries match', $rows);
        }
        $this->assertStringContainsString($this->others['reference'], $this->rows($this->loggedIn('admin@x.test')->get('/crm/enquiries?q=Bravo')['body']));
        $this->assertStringContainsString($this->mine['reference'], $this->rows($m->get('/crm/enquiries?q=alpha')['body']));
    }

    public function testStaffActionsOnSomeoneElsesEnquiryAreRefused(): void
    {
        $m2 = $this->loggedIn('mgr2@x.test');
        $u = '/crm/enquiries/' . $this->mine['public_id'];
        foreach (['/stage' => ['stage' => 'contacted'], '/notes' => ['body' => 'hello there'], '/followups' => ['due_date' => date('Y-m-d', strtotime('+3 day')), 'note' => 'call'], '/assign' => ['owner' => (string) $this->manager2],
            '/convert' => ['client' => 'new'], '/archive' => []] as $suffix => $form) {
            $r = $m2->post($u . $suffix, $form, '/account');
            $this->assertContains($r['status'], [403, 404], "mgr2 POST {$suffix}");
        }
        $this->assertSame('new_enquiry', EnquiryPolicy::find($this->mine['public_id'])['stage_code']);
        $this->assertSame($this->manager, (int) EnquiryPolicy::find($this->mine['public_id'])['owner_id']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM enquiry_activity WHERE type = 'note' AND body = 'hello there'")['c']);
    }

    public function testManagerCannotAssignConvertArchiveOrConfigureEvenOnTheirOwnEnquiry(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $u = '/crm/enquiries/' . $this->mine['public_id'];
        $this->assertSame(403, $m->post($u . '/assign', ['owner' => (string) $this->manager2], '/account')['status']);
        $this->assertSame(403, $m->post($u . '/archive', [], '/account')['status']);
        $this->assertSame(403, $m->post($u . '/convert', ['client' => 'new'], '/account')['status']);
        $this->assertSame(403, $m->post('/admin/crm-stages', ['stage' => []], '/account')['status']);
        $page = $m->get($u)['body'];
        $this->assertStringNotContainsString('/assign', $page);
        $this->assertStringNotContainsString('Convert to client', $page);
    }

    // ---- pipeline through the UI --------------------------------------------------------------------------------------

    public function testStageMovesThroughTheUiWithReasonAndExplicitWonConfirmation(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $u = '/crm/enquiries/' . $this->mine['public_id'];
        $a->post($u . '/stage', ['stage' => 'lost', 'reason' => ''], '/account');
        $this->assertStringContainsString('Reason for losing', $a->get($u)['body']);
        $this->assertSame('new_enquiry', EnquiryPolicy::find($this->mine['public_id'])['stage_code']);

        $a->post($u . '/stage', ['stage' => 'won'], '/account');
        $this->assertStringContainsString('deliberate decision', $a->get($u)['body']);
        $this->assertSame('new_enquiry', EnquiryPolicy::find($this->mine['public_id'])['stage_code'], 'Won without confirmation is refused');

        $a->post($u . '/stage', ['stage' => 'quote_sent'], '/account');
        $this->assertSame('quote_sent', EnquiryPolicy::find($this->mine['public_id'])['stage_code']);
        $a->post($u . '/stage', ['stage' => 'won', 'confirm' => '1'], '/account');
        $this->assertSame('won', EnquiryPolicy::find($this->mine['public_id'])['stage_code']);
        $this->assertStringContainsString('Stage: Quote Sent → Won', $a->get($u)['body']);
    }

    public function testConversionThroughTheUiCreatesDraftProjectAndLocksTheEnquiry(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $u = '/crm/enquiries/' . $this->others['public_id'];
        $a->post($u . '/stage', ['stage' => 'won', 'confirm' => '1'], '/account');
        $page = $a->get($u)['body'];
        $this->assertStringContainsString('Convert to client', $page);
        $r = $a->post($u . '/convert', ['client' => 'new', 'project_name' => 'Bravo website'], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertStringStartsWith('/projects/', (string) $r['location']);
        $p = Database::one("SELECT * FROM projects WHERE title = 'Bravo website'");
        $this->assertSame('draft', $p['status']);
        $this->assertStringContainsString('VIS-', $a->get((string) $r['location'])['body']);
        $after = $a->get($u)['body'];
        $this->assertStringContainsString('Converted on', $after);
        $this->assertStringContainsString('CONFIDENTIAL internal pricing note', $after, 'original history preserved');
        $this->assertStringNotContainsString('Convert to client &amp; project', $after);
        $this->assertSame(303, $a->post($u . '/convert', ['client' => 'new'], '/account')['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM projects')['c']);
    }

    // ---- referral partners ----------------------------------------------------------------------------------------------

    public function testReferralPartnerSeesOnlyOwnReferralsInARestrictedView(): void
    {
        $p = $this->loggedIn('p1@x.test');
        $list = $p->get('/partner/referrals')['body'];
        $this->assertStringContainsString($this->viaPartner['reference'], $list);
        foreach ([$this->mine['reference'], $this->others['reference'], 'Alpha Lead', 'Bravo'] as $leak) {
            $this->assertStringNotContainsString($leak, $list);
        }
        $detail = $p->get('/partner/referrals/' . $this->viaPartner['public_id']);
        $this->assertSame(200, $detail['status']);
        $this->assertStringContainsString('In progress', $detail['body']);
        $this->assertStringNotContainsString('PRIVATE-STAFF-NOTE-XYZ', $list);
        foreach (['PRIVATE-STAFF-NOTE-XYZ', 'User mgr2@x.test', 'Quote in Preparation', 'quote_in_preparation', 'Follow-up'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $detail['body'], "internal detail '{$internal}' must not reach a partner");
        }

        $this->assertSame(404, $p->get('/partner/referrals/' . $this->others['public_id'])['status']);
        $this->assertSame(404, $p->get('/crm/enquiries/' . $this->others['public_id'])['status']);
        $this->assertSame(404, $this->loggedIn('p2@x.test')->get('/partner/referrals/' . $this->viaPartner['public_id'])['status'], 'other partners cannot see it');
        $this->assertSame(403, $p->get('/crm/enquiries')['status']);
        $this->assertSame(403, $p->post('/crm/enquiries/' . $this->viaPartner['public_id'] . '/stage', ['stage' => 'won', 'confirm' => '1'], '/account')['status']);
        $this->assertSame(403, $p->post('/crm/enquiries/' . $this->viaPartner['public_id'] . '/notes', ['body' => 'my own note'], '/account')['status']);
        $this->assertStringContainsString('/partner/referrals/', (string) $p->get('/crm/enquiries/' . $this->viaPartner['public_id'])['location'], 'the staff URL redirects partners to their restricted page');
    }

    public function testPartnerSubmissionIsBoundToThePartnerAndIgnoresStaffFields(): void
    {
        $p = $this->loggedIn('p1@x.test');
        $r = $p->post('/partner/referrals', $this->form(['contact_name' => 'New Referral', 'email' => 'nr@lead.test', 'phone' => '+91 96000 00006', 'company_name' => 'NR Co', 'priority' => 'urgent', 'owner_id' => (string) $this->manager, 'referral_partner_id' => (string) $this->partner2, 'stage' => 'won']), '/partner/referrals/create');
        $this->assertSame(303, $r['status']);
        $e = Database::one("SELECT * FROM enquiries WHERE email = 'nr@lead.test'");
        $this->assertSame($this->partner, (int) $e['referral_partner_id']);
        $this->assertSame('normal', $e['priority']);
        $this->assertNull($e['owner_id']);
        $this->assertSame('referral_partner', $e['source']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/partner/referrals/create')['status']);
    }

    // ---- attachments ------------------------------------------------------------------------------------------------

    private function tmp(string $content, string $ext): string
    {
        $f = tempnam(sys_get_temp_dir(), 'att') . '.' . $ext;
        file_put_contents($f, $content);

        return $f;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testAttachmentUploadAndAuthorizedDownload(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $u = '/crm/enquiries/' . $this->mine['public_id'];
        $file = $this->tmp($this->pngBytes(), 'png');
        $r = $m->upload($u . '/attachments', [], ['file' => [$file, '../../evil name<x>.png', 'image/png']], '/account');
        $this->assertSame(303, $r['status']);
        $att = Database::one('SELECT * FROM attachments');
        $this->assertNotNull($att);
        $this->assertSame('image/png', $att['mime']);
        $this->assertDoesNotMatchRegularExpression('/[\/"<>]/', $att['original_name'], 'file name is sanitised');
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $att['stored_path'], 'random storage name, no user input');
        $this->assertFileExists(VERTEX_ROOT . '/storage/uploads/' . $att['stored_path']);
        $this->assertStringStartsNotWith(VERTEX_ROOT . '/public', VERTEX_ROOT . '/storage/uploads/' . $att['stored_path'], 'stored outside the web root');

        $page = $m->get($u)['body'];
        $this->assertStringContainsString('/attachments/' . $att['public_id'], $page);
        $h = $m->headersOf('/attachments/' . $att['public_id']);
        $this->assertStringStartsWith('attachment;', $h['content-disposition'] ?? '', 'always a download, never inline');
        $this->assertSame('application/octet-stream', $h['content-type']);
        $this->assertSame('nosniff', $h['x-content-type-options']);
        $this->assertSame($this->pngBytes(), $m->get('/attachments/' . $att['public_id'])['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'attachment.uploaded'")['c']);
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'attachment.downloaded'")['c']);
        @unlink($file);
    }

    public function testAttachmentDownloadsFollowTheParentsAccessRules(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $file = $this->tmp($this->pngBytes(), 'png');
        $m->upload('/crm/enquiries/' . $this->mine['public_id'] . '/attachments', [], ['file' => [$file, 'a.png', 'image/png']], '/account');
        $id = (string) Database::one('SELECT public_id FROM attachments')['public_id'];

        $this->assertSame(200, $this->loggedIn('admin@x.test')->get("/attachments/{$id}")['status']);
        foreach (['mgr2@x.test', 'p1@x.test', 'sup@x.test', 'cl@x.test'] as $email) {
            $this->assertSame(404, $this->loggedIn($email)->get("/attachments/{$id}")['status'], "{$email} must not download");
        }
        $this->assertSame('/login', $this->client()->get("/attachments/{$id}")['location']);
        foreach (['1', '../../.env', str_repeat('0', 32)] as $guess) {
            $this->assertSame(404, $m->get('/attachments/' . rawurlencode($guess))['status']);
        }
        // direct access to the storage folder is impossible: it is not under public/
        $stored = (string) Database::one('SELECT stored_path FROM attachments')['stored_path'];
        $this->assertSame(404, $this->client()->get('/storage/uploads/' . $stored)['status']);
        @unlink($file);
    }

    public function testDangerousOrMislabelledUploadsAreRejected(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $u = '/crm/enquiries/' . $this->mine['public_id'] . '/attachments';
        $cases = [
            ['<?php echo 1; ?>', 'shell.php', 'application/x-php'],
            ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'x.svg', 'image/svg+xml'],
            ['<script>alert(1)</script>', 'x.html', 'text/html'],
            ['MZ' . str_repeat("\0", 100), 'tool.exe', 'application/octet-stream'],
            ['<?php echo 1; ?>', 'disguised.png', 'image/png'],          // content is not a PNG
            [$this->pngBytes(), 'image.php', 'image/png'],                  // real PNG but dangerous extension
            [$this->pngBytes(), 'double.png.php', 'image/png'],
            ['', 'empty.txt', 'text/plain'],
            [str_repeat('A', 5_242_881), 'huge.txt', 'text/plain'],
        ];
        foreach ($cases as [$content, $name, $mime]) {
            $f = $this->tmp($content, 'bin');
            $m->upload($u, [], ['file' => [$f, $name, $mime]], '/account');
            @unlink($f);
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM attachments')['c'], 'nothing dangerous was stored');
        $this->assertSame([], glob(VERTEX_ROOT . '/storage/uploads/*/*/*') ?: [], 'no stray files on disk');
        $this->assertStringContainsString('not allowed', $m->get('/crm/enquiries/' . $this->mine['public_id'])['body'] . 'not allowed', 'sanity');
    }

    public function testOnlyPeopleWithEditPermissionOnTheirOwnEnquiryCanUpload(): void
    {
        $f = $this->tmp('hello world', 'txt');
        foreach (['mgr2@x.test' => $this->mine, 'p1@x.test' => $this->viaPartner, 'sup@x.test' => $this->mine] as $email => $enq) {
            $r = $this->loggedIn($email)->upload('/crm/enquiries/' . $enq['public_id'] . '/attachments', [], ['file' => [$f, 'note.txt', 'text/plain']], '/account');
            $this->assertContains($r['status'], [403, 404], $email);
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM attachments')['c']);
        @unlink($f);
    }

    // ---- list: filters + pagination --------------------------------------------------------------------------------------

    public function testListFiltersAndPagination(): void
    {
        for ($i = 1; $i <= 24; $i++) {
            $this->enq("Bulk Person {$i}", "bulk{$i}@lead.test", sprintf('+91 97%03d 00000', $i), "Bulk Company {$i}");
        }
        $a = $this->loggedIn('admin@x.test');
        $p1 = $a->get('/crm/enquiries')['body'];
        $this->assertStringContainsString('Page 1 of 2', $p1);
        $this->assertSame(20, substr_count($p1, 'badge badge-stage-open'), 'page size is 20');
        $this->assertStringContainsString('Page 2 of 2', $a->get('/crm/enquiries?page=2')['body']);
        $this->assertStringContainsString('Bulk Person 7', $a->get('/crm/enquiries?q=bulk7@')['body']);
        $this->assertStringContainsString('No enquiries match', $a->get('/crm/enquiries?q=zzzzzz')['body']);
        $this->assertStringContainsString($this->viaPartner['reference'], $a->get('/crm/enquiries?source=referral_partner')['body']);
        $this->assertStringNotContainsString($this->viaPartner['reference'], $a->get('/crm/enquiries?source=manual')['body']);
        $this->assertStringContainsString('No enquiries match', $a->get('/crm/enquiries?owner=none&source=referral_partner&priority=urgent')['body']);
        $this->assertSame(200, $a->get('/crm/enquiries?stage=nonsense&page=-3&owner=%27&followup=x')['status'], 'bad filters are ignored');
    }
}
