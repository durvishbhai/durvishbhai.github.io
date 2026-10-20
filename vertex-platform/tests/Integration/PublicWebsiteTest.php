<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSeeder;

/**
 * The public corporate website and its admin: pages render, only published content is visible, drafts and unfinished policies stay private,
 * editing changes what visitors see, forms store their answers, uploads stay private, and permissions hold.
 */
final class PublicWebsiteTest extends HttpTestCase
{
    use Phase3Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function seed(): void
    {
        SiteSeeder::run($this->admin, true);
    }

    /** @return array<string,mixed> */
    private function svc(string $name, string $status = 'draft', string $slug = ''): array
    {
        $row = SiteContent::save($this->admin, 'services', null, ['name' => $name, 'icon' => 'web', 'summary' => 'A short summary of ' . $name]);
        if ($slug !== '') {
            Database::run('UPDATE site_services SET slug = ? WHERE id = ?', [$slug, $row['id']]);
        }
        if ($status !== 'draft') {
            SiteContent::setStatus($this->admin, 'services', SiteContent::find('services', $row['public_id']), $status);
        }

        return SiteContent::find('services', $row['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ public pages

    public function testEveryPublicPageRendersAndHasTheBasicsOfAGoodPage(): void
    {
        $this->seed();
        $c = $this->client();
        foreach (['/', '/about', '/services', '/our-team', '/our-clients', '/our-partners', '/portfolio', '/careers', '/hiring', '/contact', '/request-quote', '/faq', '/sitemap'] as $path) {
            $r = $c->get($path);
            $this->assertSame(200, $r['status'], $path);
            $this->assertSame(1, preg_match_all('#<h1[ >]#', $r['body']), "{$path} has exactly one h1");
            $this->assertMatchesRegularExpression('#<title>[^<]{3,}</title>#', $r['body'], $path);
            $this->assertStringContainsString('<meta name="description"', $r['body'], $path);
            $this->assertStringContainsString('rel="canonical"', $r['body'], $path);
            $this->assertStringContainsString('property="og:title"', $r['body'], $path);
            $this->assertStringNotContainsString('[ADMIN TO COMPLETE', $r['body'], $path);
            $this->assertStringNotContainsString('onclick=', $r['body'], $path);
        }
        $this->assertSame(404, $c->get('/no-such-page-here')['status']);
        $this->assertStringContainsString('<h1', $c->get('/no-such-page-here')['body'], 'the 404 page is a designed page');
    }

    public function testStartingContentIsLoadedOnceAndNeverInventsPeoplePartnersOrWork(): void
    {
        $this->seed();
        $this->assertSame(10, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM site_team')['c'], 'only the founder profile supplied by the business');
        $this->assertSame('Durvish Jawale', Database::one('SELECT full_name FROM site_team')['full_name']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_clients')['c'], 'no invented clients');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_partners')['c'], 'no invented partners');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_portfolio')['c'], 'no invented projects');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_jobs')['c'], 'no invented openings');
        $this->assertSame(5, (int) Database::one("SELECT COUNT(*) c FROM site_policies WHERE status = 'draft'")['c']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM site_policies WHERE status = 'published'")['c']);

        $c = $this->client();
        $team = $c->get('/our-team')['body'];
        $this->assertStringContainsString('<h1', $team);
        $this->assertSame(1, preg_match_all('#class="team-card#', $team), 'the team page lists only the founder');
        $this->assertStringContainsString('Durvish Jawale', $team);
        $this->assertStringContainsString('Founder &amp; CEO', $team);
        $this->assertStringContainsString('The Young Books and Northform', $team);
        $this->assertStringContainsString('We work with clients from various countries', $c->get('/our-clients')['body'], 'an empty client list says so instead of inventing logos');
        $this->assertSame(0, preg_match_all('#client-card#', $c->get('/our-clients')['body']));
        $this->assertSame(0, preg_match_all('#class="portfolio-card#', $c->get('/portfolio')['body']));

        SiteSeeder::run($this->admin, false);
        $this->assertSame(10, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'], 'running it again adds nothing');
        Database::run("DELETE FROM site_services WHERE slug = 'ai-solutions'");
        SiteSeeder::run($this->admin, false);
        $this->assertSame(9, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'], 'a deleted item is not brought back');
    }

    public function testOnlyPublishedServicesAreVisibleAndListedAndTheSitemapFollowsThem(): void
    {
        $c = $this->client();
        $draft = $this->svc('Secret Draft Service', 'draft', 'secret-draft');
        $live = $this->svc('Visible Service', 'published', 'visible-service');
        $this->assertSame(404, $c->get('/services/secret-draft')['status'], 'a draft has no public page');
        $this->assertSame(200, $c->get('/services/visible-service')['status']);
        $list = $c->get('/services')['body'];
        $this->assertStringContainsString('Visible Service', $list);
        $this->assertStringNotContainsString('Secret Draft Service', $list);
        $xml = $c->get('/sitemap.xml')['body'];
        $this->assertStringContainsString('/services/visible-service', $xml);
        $this->assertStringNotContainsString('secret-draft', $xml);

        SiteContent::setStatus($this->admin, 'services', SiteContent::find('services', $live['public_id']), 'archived');
        $this->assertSame(404, $c->get('/services/visible-service')['status'], 'archiving takes it off the site at once');
        $this->assertStringNotContainsString('visible-service', $c->get('/sitemap.xml')['body']);
        $this->assertSame($draft['status'], 'draft');
    }

    public function testStoredMarkupIsEscapedEverywhereItIsShown(): void
    {
        try {
            SiteContent::save($this->admin, 'services', null, ['name' => 'Fancy <script>alert(1)</script> Work', 'icon' => 'web', 'summary' => 'x']);
            $this->fail('Markup in a name must be refused.');
        } catch (\Vertex\Core\ValidationException $e) {
            $this->assertNotEmpty($e->errors);
        }
        $row = SiteContent::save($this->admin, 'services', null, ['name' => 'Markup Work', 'icon' => 'web', 'summary' => 'Plain summary',
            'description' => "Hello <b>there</b> <script>alert(1)</script>\n\n[bad](javascript:alert(3))\n\n<img src=x onerror=alert(2)>"]);
        SiteContent::setStatus($this->admin, 'services', SiteContent::find('services', $row['public_id']), 'published');
        $c = $this->client();
        foreach (['/services', '/services/' . $row['slug'], '/sitemap'] as $path) {
            $html = $c->get($path)['body'];
            $this->assertStringNotContainsString('<script>alert(1)', $html, $path);
            $this->assertStringNotContainsString('<img src=x', $html, $path);
            $this->assertStringNotContainsString('<b>there', $html, $path);
            $this->assertStringNotContainsString('href="javascript:', $html, $path);
        }
        $this->assertStringContainsString('&lt;script&gt;', $c->get('/services/' . $row['slug'])['body']);
    }

    public function testPricesAreHiddenUnlessTheEditorTurnsThemOn(): void
    {
        $row = SiteContent::save($this->admin, 'services', null, ['name' => 'Priced Work', 'icon' => 'web', 'summary' => 'Priced', 'starting_price' => '4999']);
        SiteContent::setStatus($this->admin, 'services', SiteContent::find('services', $row['public_id']), 'published');
        $c = $this->client();
        $this->assertStringNotContainsString('4,999', $c->get('/services/' . $row['slug'])['body']);
        SiteContent::save($this->admin, 'services', $row['public_id'], ['name' => 'Priced Work', 'icon' => 'web', 'summary' => 'Priced', 'starting_price' => '4999', 'show_price' => '1']);
        $this->assertStringContainsString('4,999', $c->get('/services/' . $row['slug'])['body']);
    }

    public function testClientsAreEditableGroupedByCountryAndOnlyPublishedOnesShow(): void
    {
        $a = SiteContent::save($this->admin, 'clients', null, ['name' => 'Acme Retail', 'country' => 'India', 'description' => 'Online store']);
        $b = SiteContent::save($this->admin, 'clients', null, ['name' => 'Borealis Studio', 'country' => 'Canada']);
        SiteContent::save($this->admin, 'clients', null, ['name' => 'Hidden Draft Ltd', 'country' => 'Norway']);
        SiteContent::setStatus($this->admin, 'clients', SiteContent::find('clients', $a['public_id']), 'published');
        SiteContent::setStatus($this->admin, 'clients', SiteContent::find('clients', $b['public_id']), 'published');
        $c = $this->client();
        $page = $c->get('/our-clients')['body'];
        $this->assertStringContainsString('Acme Retail', $page);
        $this->assertStringContainsString('Borealis Studio', $page);
        $this->assertStringNotContainsString('Hidden Draft Ltd', $page);
        $this->assertStringContainsString('>Canada<', $page);
        $this->assertStringContainsString('>India<', $page);
        $this->assertLessThan(strpos($page, '>India<'), strpos($page, '>Canada<'), 'countries are listed alphabetically');
        $this->assertStringContainsString('/our-clients', $c->get('/sitemap.xml')['body']);

        // editable from the dashboard
        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Acme Retail', $admin->get('/admin/site/clients')['body']);
        $admin->post('/admin/site/clients/' . $a['public_id'], ['name' => 'Acme Retail Group', 'country' => 'Japan'], '/admin/site/clients/' . $a['public_id']);
        $this->assertStringContainsString('Acme Retail Group', $c->get('/our-clients')['body']);
        $admin->post('/admin/site/clients/' . $a['public_id'] . '/status', ['action' => 'archive', 'confirm_password' => self::PASSWORD], '/admin/site/clients/' . $a['public_id']);
        $this->assertStringNotContainsString('Acme Retail', $c->get('/our-clients')['body']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/site/clients')['status']);
    }

    // ------------------------------------------------------------------------------------------------ policies

    public function testPoliciesStayHiddenUntilCompletedAndEveryChangeIsAVersion(): void
    {
        $this->seed();
        $c = $this->client();
        $this->assertSame(404, $c->get('/privacy-policy')['status'], 'a draft policy is not public');
        $this->assertStringNotContainsString('/privacy-policy', $c->get('/')['body'], 'and is not linked');
        $this->assertStringNotContainsString('privacy-policy', $c->get('/sitemap.xml')['body']);

        $p = SiteContent::find('policies', (string) Database::one("SELECT public_id FROM site_policies WHERE slug = 'privacy-policy'")['public_id']);
        $this->assertMatchesRegularExpression('/\[ADMIN TO COMPLETE/i', (string) $p['body'], 'the draft says what is missing');
        try {
            SiteContent::setStatus($this->admin, 'policies', $p, 'published');
            $this->fail('An unfinished policy must not publish.');
        } catch (\Vertex\Core\ValidationException $e) {
            $this->assertStringContainsString('ADMIN TO COMPLETE', implode(' ', $e->errors));
        }

        $before = (int) Database::one('SELECT COUNT(*) c FROM site_policy_versions WHERE policy_id = ?', [$p['id']])['c'];
        $saved = SiteContent::save($this->admin, 'policies', $p['public_id'], ['title' => 'Privacy Policy', 'effective_date' => '2026-11-01', 'body' => "## What we collect\n\nOnly what you send us in our forms."]);
        $this->assertSame($before + 1, (int) Database::one('SELECT COUNT(*) c FROM site_policy_versions WHERE policy_id = ?', [$p['id']])['c']);
        SiteContent::setStatus($this->admin, 'policies', SiteContent::find('policies', $saved['public_id']), 'published');

        $page = $c->get('/privacy-policy');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('What we collect', $page['body']);
        $this->assertStringContainsString('/privacy-policy', $c->get('/sitemap.xml')['body']);
        $this->assertStringContainsString('/privacy-policy', $c->get('/')['body'], 'a published policy is linked in the footer');

        SiteContent::save($this->admin, 'policies', $p['public_id'], ['title' => 'Privacy Policy', 'effective_date' => '2026-11-01', 'body' => 'Second wording of the policy.']);
        SiteContent::restorePolicy($this->admin, SiteContent::find('policies', $p['public_id']), 2);
        $this->assertStringContainsString('What we collect', (string) SiteContent::find('policies', $p['public_id'])['body'], 'an older version can be restored');
    }

    // ------------------------------------------------------------------------------------------------ admin + permissions

    public function testWebsiteAdminIsStaffOnlyAndPublishingNeedsPublishRights(): void
    {
        $anon = $this->client();
        $this->assertSame(303, $anon->get('/admin/site')['status']);
        foreach (['alpha@client.test', 'partner@x.test', 'dev@x.test'] as $email) {
            $this->assertSame(403, $this->loggedIn($email)->get('/admin/site')['status'], $email);
        }
        $admin = $this->loggedIn('admin@x.test');
        $this->assertSame(200, $admin->get('/admin/site')['status']);
        foreach (['services', 'team', 'clients', 'partners', 'portfolio', 'jobs', 'faqs', 'policies'] as $entity) {
            $this->assertSame(200, $admin->get('/admin/site/' . $entity)['status'], $entity);
        }
        $this->assertSame(200, $admin->get('/admin/site/menus')['status']);
        $this->assertSame(200, $admin->get('/admin/site/sections/home')['status']);

        // A manager may edit drafts but not publish
        $m = $this->loggedIn('mgr@x.test');
        $r = $m->post('/admin/site/services', ['name' => 'Manager Draft', 'icon' => 'web', 'summary' => 'Drafted by a manager'], '/admin/site/services/new');
        $this->assertSame(303, $r['status']);
        $row = Database::one("SELECT * FROM site_services WHERE name = 'Manager Draft'");
        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
        $this->assertSame(403, $m->post('/admin/site/services/' . $row['public_id'] . '/status', ['action' => 'publish'], '/admin/site/services/' . $row['public_id'])['status']);
        $this->assertSame('draft', Database::one('SELECT status FROM site_services WHERE id = ?', [$row['id']])['status']);
        $this->assertSame(403, $m->post('/admin/site/menus/header', ['items' => 'Home | /'], '/admin/site/menus')['status']);

        // The administrator publishes it, and the change is audited
        $admin->post('/admin/site/services/' . $row['public_id'] . '/status', ['action' => 'publish'], '/admin/site/services/' . $row['public_id']);
        $this->assertSame('published', Database::one('SELECT status FROM site_services WHERE id = ?', [$row['id']])['status']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'site.published'")['c']);
        $this->assertSame(200, $this->client()->get('/services/' . $row['slug'])['status']);
    }

    public function testAdminFormsRejectBadInputAndWriteNothing(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $before = (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'];
        $admin->post('/admin/site/services', ['name' => '', 'icon' => 'web', 'summary' => ''], '/admin/site/services/new');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c']);
        $this->assertSame(419, $admin->postNoToken('/admin/site/services', ['name' => 'X', 'icon' => 'web', 'summary' => 'Y'])['status'], 'CSRF is enforced');
        $this->assertSame(404, $admin->get('/admin/site/not-a-list')['status']);
    }

    public function testHeaderMenuIsEditableAndHidesUnpublishedPolicyLinks(): void
    {
        $this->seed();
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/admin/site/menus/header', ['items' => "Start | /\nOur Work | /portfolio\nTalk To Us | /contact"], '/admin/site/menus');
        $html = $this->client()->get('/about')['body'];
        $this->assertStringContainsString('Our Work', $html);
        $this->assertStringContainsString('Talk To Us', $html);
        $admin->post('/admin/site/menus/header/reset', ['confirm_password' => self::PASSWORD], '/admin/site/menus');
        $this->assertStringContainsString('About', $this->client()->get('/about')['body']);
    }

    public function testSectionWordingIsEditableAndCanBeReset(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('name="s[header][f][title]"', $admin->get('/admin/site/sections/about')['body'], 'the editor lists the page heading fields');
        $admin->post('/admin/site/sections/about', ['s' => ['header' => ['enabled' => '1', 'f' => ['title' => 'Our Very Own Heading', 'intro' => 'Hand written intro.']]]], '/admin/site/sections/about');
        $this->assertStringContainsString('Our Very Own Heading', $this->client()->get('/about')['body']);
        $admin->post('/admin/site/sections/about/reset', ['confirm_password' => self::PASSWORD], '/admin/site/sections/about');
        $this->assertStringNotContainsString('Our Very Own Heading', $this->client()->get('/about')['body']);
    }

    // ------------------------------------------------------------------------------------------------ forms

    public function testContactFormKeepsItsExtraAnswersForStaffOnly(): void
    {
        $c = $this->client();
        $r = $c->post('/contact', ['contact_name' => 'Nina Contact', 'email' => 'nina@web.test', 'inquiry_type' => 'partnership', 'subject' => 'Working together', 'description' => 'We would like to talk about a partnership.', 'consent' => '1', 'website_url' => ''], '/contact');
        $this->assertSame(303, $r['status']);
        $e = Database::one("SELECT * FROM enquiries WHERE email = 'nina@web.test'");
        $this->assertSame('contact_form', $e['source']);
        $d = Database::one('SELECT details_json FROM enquiry_details WHERE enquiry_id = ?', [$e['id']]);
        $this->assertStringContainsString('Working together', (string) $d['details_json']);

        $page = $this->loggedIn('admin@x.test')->get('/crm/enquiries/' . $e['public_id'])['body'];
        $this->assertStringContainsString('Working together', $page);
        $this->assertStringContainsString('Partnership', $page);

        $bad = $this->client();
        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $bad->post('/contact', ['contact_name' => 'No Type', 'email' => 'nt@web.test', 'inquiry_type' => 'bogus', 'subject' => 'x', 'description' => 'Short but long enough text', 'consent' => '1', 'website_url' => ''], '/contact');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'an unknown inquiry type is refused');
    }

    public function testRequirementsFormStoresAnswersAndAPrivateAttachment(): void
    {
        $file = $this->png('brief.png');
        $c = $this->client();
        $r = $c->upload('/request-quote', [
            'contact_name' => 'Rita Requirements', 'email' => 'rita@web.test', 'project_name' => 'Shop relaunch', 'project_category' => 'website', 'services' => ['website_development'],
            'problem_statement' => 'Our current shop is slow and hard to update by ourselves.', 'expected_outcome' => 'A fast shop we can edit.', 'timeline' => 'one_month', 'consent' => '1', 'website_url' => '',
        ], ['attachments[0]' => [$file['tmp_name'], 'brief.png', 'image/png']], '/request-quote');
        $this->assertSame(303, $r['status'], $r['body']);
        $e = Database::one("SELECT * FROM enquiries WHERE email = 'rita@web.test'");
        $this->assertNotNull($e);
        $files = Database::all('SELECT f.* FROM enquiry_files ef JOIN stored_files f ON f.id = ef.file_id WHERE ef.enquiry_id = ?', [$e['id']]);
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $files[0]['stored_path'], 'stored under a random private name');
        $this->assertStringNotContainsString($files[0]['stored_path'], $c->get('/enquiry/received')['body']);

        $url = '/crm/enquiries/' . $e['public_id'] . '/files/' . $files[0]['public_id'];
        $this->assertSame(303, $this->client()->get($url)['status'], 'visitors cannot fetch it');
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get($url)['status'], 'clients cannot fetch it');
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get($url)['status'], 'staff who can see the enquiry can');
    }

    public function testRequirementsFormRejectsADisallowedAttachmentAndStoresNothing(): void
    {
        $bad = $this->tmp('payload.php', "<?php echo 'x';");
        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $c = $this->client();
        $c->upload('/request-quote', [
            'contact_name' => 'Evil Eve', 'email' => 'eve@web.test', 'project_name' => 'Anything', 'project_category' => 'website', 'services' => ['website_development'],
            'problem_statement' => 'Some perfectly long problem statement here.', 'expected_outcome' => 'Some outcome', 'consent' => '1', 'website_url' => '',
        ], ['attachments[0]' => [$bad['tmp_name'], 'payload.php', 'application/x-php']], '/request-quote');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM enquiry_files')['c']);
    }

    // ------------------------------------------------------------------------------------------------ careers

    public function testJobApplicationIsStoredPrivatelyAndReviewedByAuthorisedStaffOnly(): void
    {
        $job = SiteContent::save($this->admin, 'jobs', null, ['title' => 'Freelance Video Editor', 'engagement_type' => 'freelance']);
        $c = $this->client();
        $this->assertSame(404, $c->get('/careers/' . $job['slug'])['status'], 'a draft opening is not public');
        SiteContent::setStatus($this->admin, 'jobs', SiteContent::find('jobs', $job['public_id']), 'published');
        $this->assertStringContainsString('Freelance Video Editor', $c->get('/careers')['body']);
        $this->assertSame(200, $c->get('/careers/' . $job['slug'])['status']);

        $resume = $this->png('work-sample.png');
        $r = $c->upload('/careers/apply', ['full_name' => 'Arjun Applicant', 'email' => 'arjun@web.test', 'role' => 'Freelance Video Editor', 'skills_experience' => 'Five years of editing short-form video for brands.',
            'intro' => 'I would love to work with your team on video projects.', 'consent' => '1', 'website_url' => ''], ['resume' => [$resume['tmp_name'], 'work-sample.png', 'image/png']], '/careers');
        $this->assertSame(303, $r['status'], $r['body']);
        $this->assertSame('/careers/received', $r['location']);
        $app = Database::one("SELECT * FROM site_job_applications WHERE email = 'arjun@web.test'");
        $this->assertNotNull($app);
        $this->assertStringContainsString($app['reference'], $c->get('/careers/received')['body']);
        $this->assertNotNull($app['file_id']);

        $this->assertSame(303, $this->client()->get('/admin/careers')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/careers')['status']);
        $this->assertSame(403, $this->loggedIn('dev@x.test')->get('/admin/careers/' . $app['public_id'])['status']);
        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Arjun Applicant', $admin->get('/admin/careers')['body']);
        $this->assertSame(200, $admin->get('/admin/careers/' . $app['public_id'])['status']);
        $dl = $admin->headersOf('/admin/careers/' . $app['public_id'] . '/file');
        $this->assertStringContainsString('attachment', $dl['content-disposition'] ?? '');

        $admin->post('/admin/careers/' . $app['public_id'], ['status' => 'shortlisted', 'internal_notes' => 'Strong samples'], '/admin/careers/' . $app['public_id']);
        $this->assertSame('shortlisted', Database::one('SELECT status FROM site_job_applications WHERE id = ?', [$app['id']])['status']);
    }

    public function testJobApplicationNeedsConsentAndIsProtectedAgainstBots(): void
    {
        $base = ['full_name' => 'Bot Person', 'email' => 'bot@web.test', 'role' => 'Other', 'skills_experience' => 'Plenty of relevant experience in the field.', 'intro' => 'A sufficiently long introduction text.', 'website_url' => ''];
        $c = $this->client();
        $c->post('/careers/apply', $base, '/careers');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'], 'no consent, nothing stored');
        $c->post('/careers/apply', ['consent' => '1', 'website_url' => 'http://spam.example'] + $base, '/careers');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'], 'honeypot submissions are discarded');
        $this->assertSame(419, $c->postNoToken('/careers/apply', ['consent' => '1'] + $base)['status']);
        $bad = $this->tmp('cv.exe', 'MZ binary');
        $c->upload('/careers/apply', ['consent' => '1'] + $base, ['resume' => [$bad['tmp_name'], 'cv.exe', 'application/octet-stream']], '/careers');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'], 'an executable résumé is refused');
    }

    // ------------------------------------------------------------------------------------------------ hiring page

    /** @return array<string,string> */
    private function hiringForm(array $over = []): array
    {
        return $over + ['role' => 'Video Editor', 'full_name' => 'Hira Hiring', 'email' => 'hira@web.test', 'experience' => '1to3', 'availability' => '2weeks',
            'skills_experience' => 'Premiere Pro and DaVinci Resolve, short-form and YouTube edits.', 'portfolio_url' => 'https://example.com/reel', 'intro' => 'I would like to edit videos for your team.', 'consent' => '1', 'website_url' => ''];
    }

    public function testHiringPageListsTheFourRolesWithTheSalaryNoteAndAnInteractiveForm(): void
    {
        $c = $this->client();
        $r = $c->get('/hiring');
        $this->assertSame(200, $r['status']);
        foreach (['Full Stack Developer', 'Frontend Developer', 'Backend Developer', 'Video Editor'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $r['body'], $role);
            $this->assertStringContainsString('<option value="' . $role . '"', $r['body'], $role);
        }
        $this->assertStringContainsString('Salary will be decided after the meeting', $r['body']);
        $this->assertStringContainsString('data-hire-form', $r['body']);
        $this->assertStringContainsString('action="/hiring/apply"', $r['body']);
        $this->assertStringContainsString('/hiring', $c->get('/sitemap.xml')['body']);
        $this->assertStringContainsString("We're hiring", $c->get('/about')['body'], 'an announcement bar shows on other pages while hiring is open');
        $this->assertStringNotContainsString('announce', $r['body'], 'but not on the hiring page itself');
        $this->assertStringContainsString('href="/hiring"', $c->get('/')['body']);
        $this->assertStringContainsString('role-card is-selected', $c->get('/hiring?role=Backend+Developer')['body'], 'a role can be pre-selected from a link');
    }

    public function testHiringApplicationIsStoredWithTheRoleExperienceAndAvailability(): void
    {
        $c = $this->client();
        $resume = $this->png('reel.png');
        $r = $c->upload('/hiring/apply', $this->hiringForm(), ['resume' => [$resume['tmp_name'], 'reel.png', 'image/png']], '/hiring');
        $this->assertSame(303, $r['status'], $r['body']);
        $this->assertSame('/hiring/received', $r['location']);
        $app = Database::one("SELECT * FROM site_job_applications WHERE email = 'hira@web.test'");
        $this->assertNotNull($app);
        $this->assertSame('Video Editor', $app['role_applied']);
        $this->assertStringContainsString('Experience: 1 to 3 years', $app['skills_experience']);
        $this->assertStringContainsString('Available: Within 2 weeks', $app['skills_experience']);
        $this->assertStringContainsString($app['reference'], $c->get('/hiring/received')['body']);
        $this->assertStringContainsString('Hira Hiring', $this->loggedIn('admin@x.test')->get('/admin/careers/' . $app['public_id'])['body']);

        $before = (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'];
        $c->post('/hiring/apply', $this->hiringForm(['role' => 'Astronaut', 'email' => 'x@web.test']), '/hiring');
        $c->post('/hiring/apply', $this->hiringForm(['experience' => 'forever', 'email' => 'y@web.test']), '/hiring');
        $c->post('/hiring/apply', $this->hiringForm(['consent' => '', 'email' => 'z@web.test']), '/hiring');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'], 'unknown role, bad option and missing consent store nothing');
        $this->assertSame(419, $c->postNoToken('/hiring/apply', $this->hiringForm(['email' => 'w@web.test']))['status']);
    }

    public function testAdminCanOpenCloseOrHideTheHiringPage(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $c = $this->client();
        $this->assertSame(200, $admin->get('/admin/site/hiring')['status']);
        $this->assertStringContainsString('Hiring page status', $admin->get('/admin/site/hiring')['body']);

        // Closed: the page stays, says so, and has no form; applications are refused
        $admin->post('/admin/site/hiring/state', ['state' => 'closed'], '/admin/site/hiring');
        $closed = $c->get('/hiring');
        $this->assertSame(200, $closed['status']);
        $this->assertStringContainsString('Hiring is currently closed', $closed['body']);
        $this->assertStringNotContainsString('data-hire-form', $closed['body']);
        $this->assertStringNotContainsString("We're hiring", $c->get('/about')['body'], 'no announcement while closed');
        $before = (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'];
        $r = $c->post('/hiring/apply', $this->hiringForm(['email' => 'late@web.test']));
        $this->assertSame('/hiring', $r['location']);
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c'], 'no application is taken while closed');
        $this->assertStringContainsString('/hiring', $c->get('/sitemap.xml')['body'], 'a closed page is still a page');

        // Hidden: gone for visitors, menus, footer and sitemap
        $admin->post('/admin/site/hiring/state', ['state' => 'hidden'], '/admin/site/hiring');
        $this->assertSame(404, $c->get('/hiring')['status']);
        $home = $c->get('/')['body'];
        $this->assertStringNotContainsString('href="/hiring"', $home);
        $this->assertStringNotContainsString('/hiring', $c->get('/sitemap.xml')['body']);
        $this->assertStringNotContainsString('/hiring', $c->get('/sitemap')['body']);

        // Open again
        $admin->post('/admin/site/hiring/state', ['state' => 'open'], '/admin/site/hiring');
        $this->assertSame(200, $c->get('/hiring')['status']);
        $this->assertStringContainsString('data-hire-form', $c->get('/hiring')['body']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'site.hiring_state'")['c']);
        $this->assertSame('invalid', $admin->post('/admin/site/hiring/state', ['state' => 'bogus'], '/admin/site/hiring')['status'] === 422 ? 'invalid' : 'other');
    }

    public function testOnlyPublishersCanChangeTheHiringStatus(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/admin/site/hiring')['status'], 'a manager can see the screen');
        $this->assertSame(403, $m->post('/admin/site/hiring/state', ['state' => 'hidden'], '/admin/site/hiring')['status']);
        $this->assertSame(200, $this->client()->get('/hiring')['status'], 'unchanged');
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/site/hiring')['status']);
        $this->assertSame(303, $this->client()->get('/admin/site/hiring')['status']);
    }

    public function testHiringWordingAndRolesAreEditableAndSurviveAWordingReset(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/admin/site/hiring/state', ['state' => 'closed'], '/admin/site/hiring');
        $admin->post('/admin/site/sections/hiring', ['s' => ['roles' => ['enabled' => '1', 'f' => ['heading' => 'Where we need people', 'items' => "Data Analyst | Make sense of numbers.\nVideo Editor | Edit videos."]]]], '/admin/site/sections/hiring');
        $admin->post('/admin/site/hiring/state', ['state' => 'open'], '/admin/site/hiring');
        $page = $this->client()->get('/hiring')['body'];
        $this->assertStringContainsString('Where we need people', $page);
        $this->assertStringContainsString('data-role="Data Analyst"', $page);
        $this->assertStringNotContainsString('data-role="Backend Developer"', $page);
        $admin->post('/admin/site/hiring/state', ['state' => 'closed'], '/admin/site/hiring');
        $admin->post('/admin/site/sections/hiring/reset', ['confirm_password' => self::PASSWORD], '/admin/site/sections/hiring');
        $this->assertStringContainsString('Hiring is currently closed', $this->client()->get('/hiring')['body'], 'resetting the wording keeps the status');
    }

    // ------------------------------------------------------------------------------------------------ misc

    public function testFaqPageIsSearchableAndRobotsAndSitemapAreSane(): void
    {
        $this->seed();
        $c = $this->client();
        $faq = $c->get('/faq')['body'];
        $this->assertStringContainsString('data-faq-search', $faq);
        $this->assertGreaterThan(3, preg_match_all('#<details#', $faq));
        $robots = $c->get('/robots.txt')['body'];
        $this->assertStringContainsString('Sitemap:', $robots);
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $xml = $c->get('/sitemap.xml')['body'];
        $this->assertStringContainsString('<loc>', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
        $this->assertStringNotContainsString('/login', $xml);
    }

    public function testCmsPagesCannotShadowTheNewWebsiteAddresses(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        foreach (['our-team', 'hiring', 'our-clients', 'our-partners', 'portfolio', 'careers', 'faq', 'privacy-policy', 'terms-and-conditions', 'sitemap', 'request-quote'] as $slug) {
            $before = (int) Database::one('SELECT COUNT(*) c FROM cms_pages')['c'];
            $admin->post('/admin/cms', ['title' => 'Shadow ' . $slug, 'slug' => $slug], '/admin/cms');
            $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM cms_pages')['c'], "{$slug} is reserved");
        }
    }
}
