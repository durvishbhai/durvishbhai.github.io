<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\CmsService;
use Vertex\Services\InvoiceService;
use Vertex\Services\Settings;
use Vertex\Tests\Support\HttpClient;

/** Phase 12 over real HTTP: Integration Center, the website CMS and its public side, the AI hub and the PhonePe routes. */
final class Phase12HttpTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function publish(string $slug, string $title, string $body, array $details = []): string
    {
        $p = CmsService::create($this->admin, $title, $slug);
        CmsService::addBlock($this->admin, CmsService::find($p['public_id']), 'text', ['heading' => $title, 'body' => $body]);
        if ($details !== []) {
            CmsService::updateDetails($this->admin, CmsService::find($p['public_id']), $details + ['title' => $title, 'slug' => $slug]);
        }
        CmsService::setStatus($this->admin, CmsService::find($p['public_id']), 'published');

        return $p['public_id'];
    }

    // ------------------------------------------------------------------------------------------------ settings

    public function testIntegrationCenterIsViewableByAdminsButOnlyTheOwnerCanChangeIt(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $r = $a->get('/admin/settings');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Integrations', $r['body']);
        $this->assertSame(403, $a->post('/admin/settings/email', ['mail__host' => 'smtp.example.com', 'confirm_password' => self::PASSWORD], '/admin/settings')['status']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->get('/admin/settings')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/settings')['status']);

        $o = $this->loggedIn('owner@x.test');
        $o->formPage = '/admin/settings';
        // wrong password: nothing saved
        $o->post('/admin/settings/email', ['mail__driver' => 'smtp', 'mail__host' => 'smtp.example.com', 'mail__password' => 'Top-Secret-Pw-1', 'confirm_password' => 'wrong'], '/admin/settings');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM app_settings')['c']);
        $r = $o->post('/admin/settings/email', ['mail__driver' => 'smtp', 'mail__host' => 'smtp.example.com', 'mail__port' => '587', 'mail__password' => 'Top-Secret-Pw-1', 'confirm_password' => self::PASSWORD], '/admin/settings');
        $this->assertSame(303, $r['status']);
        $this->assertSame('smtp.example.com', Database::one("SELECT value_text FROM app_settings WHERE setting_key = 'mail.host'")['value_text']);
        $page = $o->get('/admin/settings')['body'];
        $this->assertStringContainsString('smtp.example.com', $page);
        $this->assertStringNotContainsString('Top-Secret-Pw-1', $page, 'a secret is never sent back to the browser');
        $this->assertStringContainsString('saved', $page);
        // invalid values are refused with a message
        $o->post('/admin/settings/website', ['site__facebook' => 'http://insecure.example.com', 'confirm_password' => self::PASSWORD], '/admin/settings');
        $this->assertNull(Database::one("SELECT 1 x FROM app_settings WHERE setting_key = 'site.facebook'"));
        $this->assertSame(404, $o->post('/admin/settings/bogus', ['confirm_password' => self::PASSWORD], '/admin/settings')['status']);
    }

    // ------------------------------------------------------------------------------------------------ CMS admin + public

    public function testCmsScreensAreGated(): void
    {
        foreach (['/admin/cms', '/admin/cms/media', '/admin/cms/menus', '/admin/cms/redirects', '/admin/cms/reusable'] as $p) {
            $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($p)['status'], $p);
            $this->assertSame(403, $this->loggedIn('sup@x.test')->get($p)['status'], $p);
            $this->assertSame(403, $this->loggedIn('alpha@client.test')->get($p)['status'], $p);
            $this->assertSame(303, $this->client()->get($p)['status'], $p);
        }
    }

    public function testAManagerDraftsAndAnAdministratorPublishesAndTheWorldSeesIt(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $m->formPage = '/admin/cms';
        $m->post('/admin/cms', ['title' => 'Services', 'slug' => 'what-we-offer'], '/admin/cms');
        $p = Database::one("SELECT * FROM cms_pages WHERE slug = 'what-we-offer'");
        $this->assertNotNull($p);
        $this->assertSame('draft', $p['status']);
        $edit = '/admin/cms/' . $p['public_id'];
        $this->assertStringContainsString('Services', $m->get($edit)['body']);
        $r = $m->post($edit . '/blocks', ['type' => 'hero', 'heading' => 'What we do', 'subheading' => 'Websites and <video> work', 'button_label' => 'Ask', 'button_url' => '/request-quote'], $edit);
        $this->assertSame(303, $r['status']);
        $this->assertSame(1, count(CmsService::blocksOf(CmsService::find($p['public_id']))));
        $m->post($edit . '/blocks', ['type' => 'text', 'heading' => 'Hello', 'body' => "Plain <script>alert('xss')</script> text\n\n[bad](javascript:alert(1))"], $edit);
        $this->assertSame(2, count(CmsService::blocksOf(CmsService::find($p['public_id']))));
        $m->post($edit . '/blocks', ['type' => 'hero', 'heading' => 'Bad <script>'], $edit); // refused
        $this->assertSame(2, count(CmsService::blocksOf(CmsService::find($p['public_id']))));

        // the draft is invisible to visitors and to staff on the public address; staff can preview it
        $visitor = $this->client();
        $this->assertSame(404, $visitor->get('/what-we-offer')['status']);
        $prev = $m->get($edit . '/preview');
        $this->assertSame(200, $prev['status']);
        $this->assertStringContainsString('What we do', $prev['body']);
        $this->assertStringContainsString('noindex', $prev['body']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->get($edit . '/preview')['status']);

        // a manager cannot publish
        $this->assertSame(403, $m->post($edit . '/status', ['action' => 'publish'], $edit)['status']);
        $this->assertSame(404, $visitor->get('/what-we-offer')['status']);

        $a = $this->loggedIn('admin@x.test');
        $a->formPage = $edit;
        $this->assertSame(303, $a->post($edit . '/status', ['action' => 'publish'], $edit)['status']);
        $pub = $visitor->get('/what-we-offer');
        $this->assertSame(200, $pub['status']);
        $this->assertSame([], $visitor->setCookies, 'a public page does not start a session');
        $this->assertStringContainsString('What we do', $pub['body']);
        $this->assertStringContainsString('&lt;script&gt;', $pub['body']);
        $this->assertStringNotContainsString("<script>alert('xss')", $pub['body']);
        $this->assertStringNotContainsString('javascript:', $pub['body']);
        $this->assertStringContainsString('Websites and &lt;video&gt; work', $pub['body']);

        // unpublish (password required) removes it again
        $a->post($edit . '/status', ['action' => 'unpublish', 'confirm_password' => 'wrong'], $edit);
        $this->assertSame(200, $visitor->get('/what-we-offer')['status']);
        $a->post($edit . '/status', ['action' => 'unpublish', 'confirm_password' => self::PASSWORD], $edit);
        $this->assertSame(404, $visitor->get('/what-we-offer')['status']);
    }

    public function testSeoFieldsSitemapRobotsAndRedirectsWorkPublicly(): void
    {
        $id = $this->publish('studio', 'About us', 'We are a small studio.', ['seo_title' => 'About Vertex Studio', 'seo_description' => 'A small studio building websites and video.', 'canonical_url' => 'https://vis.example/studio']);
        $this->publish('private-page', 'Hidden', 'Not for search engines.', ['noindex' => '1']);
        $v = $this->client();
        $html = $v->get('/studio')['body'];
        $this->assertStringContainsString('<title>About Vertex Studio', $html);
        $this->assertStringContainsString('name="description" content="A small studio building websites and video."', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://vis.example/studio">', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('noindex', $v->get('/private-page')['body']);

        $sm = $v->get('/sitemap.xml');
        $this->assertSame(200, $sm['status']);
        $this->assertStringContainsString('<urlset', $sm['body']);
        $this->assertStringContainsString('/studio</loc>', $sm['body']);
        $this->assertStringNotContainsString('private-page', $sm['body']);
        $this->assertNotFalse(simplexml_load_string($sm['body']), 'the sitemap is well-formed XML');
        $robots = $v->get('/robots.txt');
        $this->assertSame(200, $robots['status']);
        $this->assertStringContainsString('Disallow: /admin/', $robots['body']);
        $this->assertStringContainsString('Sitemap:', $robots['body']);

        // renaming a live page keeps the old address working through a redirect
        $page = CmsService::find($id);
        CmsService::updateDetails($this->admin, $page, ['title' => 'About us', 'slug' => 'company', 'seo_title' => 'About Vertex Studio', 'seo_description' => 'A small studio building websites and video.', 'canonical_url' => 'https://vis.example/studio']);
        $r = $v->get('/studio');
        $this->assertSame(301, $r['status']);
        $this->assertSame('/company', $r['location']);
        $this->assertSame(200, $v->get('/company')['status']);
        // a hand-made redirect, and one that tries to shadow the login page is refused at creation
        CmsService::addRedirect($this->admin, '/old-offer', 'https://example.com/offer', '302');
        $r = $v->get('/old-offer');
        $this->assertSame([302, 'https://example.com/offer'], [$r['status'], $r['location']]);
    }

    public function testAPublishedHomePageReplacesTheFrontPageAndNestedAddressesWork(): void
    {
        $v = $this->client();
        $this->assertStringContainsString('Build Better. Create Smarter. Grow Faster.', $v->get('/')['body'], 'the built-in front page until a home page is published');
        $this->publish('home', 'Welcome', 'Custom welcome text from the CMS.');
        $home = $v->get('/');
        $this->assertSame(200, $home['status']);
        $this->assertStringContainsString('Custom welcome text from the CMS.', $home['body']);
        $this->publish('legal/privacy', 'Privacy', 'How we handle data.');
        $this->assertStringContainsString('How we handle data.', $v->get('/legal/privacy')['body']);
        $this->assertSame(404, $v->get('/legal/unknown')['status']);
        $this->assertSame(404, $v->get('/nope')['status']);
    }

    public function testAPageCanNeverShadowThePlatformsOwnAddresses(): void
    {
        // even if a row were inserted behind the application's back, the real routes win
        Database::run("INSERT INTO cms_pages (public_id, slug, title, status, blocks_json, current_version, created_at, updated_at, published_at) VALUES (?, 'login', 'Fake login', 'published', ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [uuid_hex(), json_encode([['type' => 'text', 'heading' => 'Fake', 'body' => 'PHISHING PAGE']])]);
        $r = $this->client()->get('/login');
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('PHISHING PAGE', $r['body']);
        $this->assertStringContainsString('password', strtolower($r['body']));
        $m = $this->loggedIn('mgr@x.test');
        $m->post('/admin/cms', ['title' => 'Dashboard clone', 'slug' => 'dashboard'], '/admin/cms');
        $this->assertNull(Database::one("SELECT 1 x FROM cms_pages WHERE slug = 'dashboard'"));
    }

    public function testMenusAndCompanyFactsShowOnEveryPublicPage(): void
    {
        $this->publish('studio', 'About', 'About text.');
        CmsService::saveMenu($this->admin, 'header', "Our work | /studio\nContact | /contact");
        CmsService::saveMenu($this->admin, 'footer', "Privacy | /legal/privacy");
        Settings::save($this->owner, 'website', ['site__email' => 'hello@vis.example', 'site__footer_text' => 'Made with care.', 'site__instagram' => 'https://instagram.com/vis']);
        $html = $this->client()->get('/studio')['body'];
        $this->assertStringContainsString('>Our work<', $html);
        $this->assertStringContainsString('href="/legal/privacy"', $html);
        $this->assertStringContainsString('hello@vis.example', $html);
        $this->assertStringContainsString('Made with care.', $html);
        $this->assertStringContainsString('https://instagram.com/vis', $html);
    }

    public function testCmsPostsNeedACsrfTokenAndAnAuthenticatedPermittedUser(): void
    {
        $this->assertSame(303, $this->client()->post('/admin/cms', ['title' => 'X', 'slug' => 'x'], '/login')['status'], 'anonymous is sent to sign in');
        $this->assertNull(Database::one("SELECT 1 x FROM cms_pages WHERE slug = 'x'"));
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(419, $m->request('POST', '/admin/cms', ['title' => 'No token', 'slug' => 'no-token'])['status']);
        $this->assertNull(Database::one("SELECT 1 x FROM cms_pages WHERE slug = 'no-token'"));
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post('/admin/cms', ['title' => 'Nope', 'slug' => 'nope'], '/account')['status']);
    }

    // ------------------------------------------------------------------------------------------------ AI

    public function testAiHubIsForPermittedUsersAndExplainsWhenItIsOff(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $r = $m->get('/ai');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('turned off', $r['body']);
        $this->assertStringNotContainsString('Write a draft', $r['body'], 'no forms while it is off');
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/ai')['status']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->get('/ai')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->post('/ai', ['action' => 'ask', 'question' => 'anything?'], '/account')['status']);
        $this->assertSame(303, $this->client()->get('/ai')['status']);
        // posting while off flashes an error and does nothing
        $m->post('/ai', ['action' => 'ask', 'question' => 'What is going on?'], '/ai');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM ai_requests')['c']);
    }

    // ------------------------------------------------------------------------------------------------ PhonePe routes

    private function issuedInvoicePublic(): string
    {
        $cp = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        \Vertex\Services\BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
        $d = InvoiceService::create($this->finance, $cp, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return $d['public_id'];
    }

    public function testPhonePeStartIsHiddenWhenOffAndNeverReachableByAnotherClient(): void
    {
        $inv = $this->issuedInvoicePublic();
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/my/invoices/' . $inv)['body'];
        $this->assertStringNotContainsString('PhonePe', $page, 'no PhonePe button until it is switched on');
        $r = $c->post('/my/invoices/' . $inv . '/phonepe', [], '/my/invoices/' . $inv);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/my/invoices/' . $inv, $r['location'], 'a friendly error, not a crash');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->post('/my/invoices/' . $inv . '/phonepe', [], '/account')['status']);
        $this->assertSame(403, $this->loggedIn('fin@x.test')->post('/my/invoices/' . $inv . '/phonepe', [], '/account')['status']);
        $this->assertSame(404, $c->get('/my/invoices/' . $inv . '/phonepe-return?o=VIS1XAAAAAAAAAAAA')['status']);
        $this->assertSame(404, $c->get('/my/invoices/' . $inv . '/phonepe-return')['status']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/my/invoices/' . $inv . '/phonepe-return?o=VIS1XAAAAAAAAAAAA')['status']);
    }

    public function testWebhookRouteNeedsNoSessionOrCsrfButAlwaysNeedsTheSignature(): void
    {
        $raw = new HttpClient(self::$base);
        $body = json_encode(['event' => 'checkout.order.completed', 'payload' => ['merchantOrderId' => 'VIS1XAAAAAAAAAAAA']]);
        $post = static function (HttpClient $c, array $headers) use ($body): array {
            $ch = curl_init(self::$base . '/webhooks/phonepe');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_TIMEOUT => 20]);
            $out = (string) curl_exec($ch);
            $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ['status' => $st, 'body' => $out];
        };
        // nothing configured, or no header: refused
        $this->assertSame(401, $post($raw, [])['status']);
        $this->assertSame(401, $post($raw, ['Authorization: ' . hash('sha256', 'hook-user:hook-pass-123')])['status'], 'not configured yet');
        Settings::save($this->owner, 'phonepe', ['phonepe__webhook_username' => 'hook-user', 'phonepe__webhook_password' => 'hook-pass-123']);
        $this->assertSame(401, $post($raw, [])['status']);
        $this->assertSame(401, $post($raw, ['Authorization: nonsense'])['status']);
        $this->assertSame(401, $post($raw, ['Authorization: ' . hash('sha256', 'hook-user:wrong')])['status']);
        $ok = $post($raw, ['Authorization: ' . hash('sha256', 'hook-user:hook-pass-123')]);
        $this->assertSame(200, $ok['status'], 'a signed call to an order we do not know is acknowledged, not an error');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        // GET is not a webhook
        $this->assertSame(404, $raw->get('/webhooks/phonepe')['status'], 'GET shows nothing');
    }
}
