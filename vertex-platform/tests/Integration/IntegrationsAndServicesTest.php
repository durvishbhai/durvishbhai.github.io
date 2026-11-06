<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\CookieConsent;
use Vertex\Services\EnquiryExtras;
use Vertex\Services\Integrations;
use Vertex\Services\ServiceAvailability;
use Vertex\Services\SiteContent;
use Vertex\Services\SiteSeeder;

/** The third-party services register (public disclosure, cookie list, CSP, consent version) and the service availability manager. */
final class IntegrationsAndServicesTest extends HttpTestCase
{
    private int $owner;
    private int $admin;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Vertex\Services\PermissionSync::run(true);
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
    }

    private function refused(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    private function analytics(array $over = []): array
    {
        return $over + ['name' => 'Plausible Analytics', 'category' => 'analytics', 'state' => 'active', 'show_public' => '1', 'purpose' => 'Counts page views without profiling.', 'data_shared' => 'Page address and a coarse device type.',
            'provider_url' => 'https://plausible.io', 'privacy_url' => 'https://plausible.io/privacy', 'cookie_category' => 'analytics', 'cookie_details' => "pl_session | Counts a visit | 30 minutes", 'script_url' => 'https://plausible.io/js/script.js'];
    }

    public function testThePublicPageShowsWhatIsReallyUsedAndMakesNoClaimsAboutTheRest(): void
    {
        $page = $this->client()->get('/third-party-services');
        $this->assertSame(200, $page['status']);
        $b = $page['body'];
        foreach (['Hostinger', 'Razorpay', 'Gmail SMTP', 'Services we use today', 'No analytics or advertising', 'Google Analytics', 'Meta Pixel'] as $needle) {
            $this->assertStringContainsString($needle, $b);
        }
        $inUse = substr($b, (int) strpos($b, 'Services we use today'), (int) strpos($b, 'Possible in the future') - (int) strpos($b, 'Services we use today'));
        $this->assertStringNotContainsString('Gemini', $inUse, 'Gemini is not in use');
        $this->assertStringNotContainsString('Cloudflare Turnstile', $inUse);
        $this->assertStringContainsString('None of them is in use now', $b);
        $this->assertStringContainsString('Gemini API', substr($b, (int) strpos($b, 'Possible in the future')), 'listed only as a future possibility');
        $this->assertStringContainsString('rel="noopener noreferrer"', $b);
        $this->assertStringContainsString('/third-party-services', $this->client()->get('/')['body'], 'linked from the footer');
    }

    public function testTheOwnerAddsAnOptionalServiceAndEverythingFollows(): void
    {
        $v0 = CookieConsent::version();
        $id = Integrations::save($this->owner, null, $this->analytics());
        $this->assertSame($v0 + 1, CookieConsent::version(), 'visitors are asked again for a new optional service');
        $page = $this->client()->get('/third-party-services')['body'];
        $this->assertStringContainsString('Plausible Analytics', $page);
        $this->assertStringNotContainsString('No analytics or advertising', $page, 'the claim disappears when an analytics service is in use');
        $this->assertStringContainsString('pl_session', $this->client()->get('/cookie-settings')['body'], 'its cookie is in the list');
        $home = $this->client()->get('/');
        $this->assertStringContainsString('https://plausible.io/js/script.js', $home['body'], 'the banner knows the script (it loads only after consent)');
        $this->assertDoesNotMatchRegularExpression('~<script[^>]+src="https://plausible~', $home['body'], 'but the page itself never loads it');
        $csp = $this->client()->headersOf('/')['content-security-policy'] ?? '';
        $this->assertStringContainsString("script-src 'self' https://plausible.io", $csp);
        Integrations::save($this->owner, $id, $this->analytics(['state' => 'disabled']));
        $this->assertStringNotContainsString('plausible.io', $this->client()->headersOf('/')['content-security-policy'] ?? '', 'a stopped service leaves the CSP');
        $this->assertStringContainsString('No analytics or advertising', $this->client()->get('/third-party-services')['body']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'compliance.integration_saved' AND entity_id = ?", [$id])['c']);
    }

    public function testOnlySafeValuesAreAccepted(): void
    {
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['script_url' => 'javascript:alert(1)'])), 'https://');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['script_url' => 'http://plausible.io/x.js'])), 'https://');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['script_url' => 'https://203.0.113.9/x.js'])), 'named host');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['cookie_category' => 'necessary'])), 'OPTIONAL');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['cookie_details' => 'only a name'])), 'name | purpose');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['name' => '<b>x</b>'])), 'name');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['settings_link' => 'https://evil.test'])), 'Administration');
        $this->refused(fn () => Integrations::save($this->owner, null, $this->analytics(['privacy_url' => 'ftp://x.test'])), 'https://');
        $this->assertSame(7, (int) Database::one('SELECT COUNT(*) c FROM compliance_integrations')['c'], 'nothing was added beyond the seeded services');
    }

    public function testAServiceThatStoresDataOnTheDeviceCannotBeHidden(): void
    {
        $id = Integrations::save($this->owner, null, $this->analytics(['show_public' => '']));
        $this->assertSame(1, (int) Integrations::find($id)['show_public']);
        $quiet = Integrations::save($this->owner, null, ['name' => 'Internal tool', 'category' => 'other', 'state' => 'active', 'show_public' => '', 'cookie_category' => 'none']);
        $this->assertSame(0, (int) Integrations::find($quiet)['show_public']);
        $this->assertStringNotContainsString('Internal tool', $this->client()->get('/third-party-services')['body']);
    }

    public function testTheRegisterWarnsWhenItDisagreesWithTheRealSwitches(): void
    {
        Config::set('razorpay.enabled', false);
        Config::set('mail.driver', 'none');
        $w = implode(' | ', Integrations::warnings());
        $this->assertStringContainsString('Razorpay: Disclosed as in use, but the technical switch is OFF', $w);
        $this->assertStringContainsString('Gmail SMTP', $w);
        Config::set('razorpay.enabled', true);
        Config::set('mail.driver', 'smtp');
        $this->assertSame([], Integrations::warnings());
        Config::set('google.enabled', true);
        $this->assertStringContainsString('Google sign-in: The technical switch is ON but this is not disclosed as in use', implode(' ', Integrations::warnings()));
        Config::set('google.enabled', false);
        Config::set('mail.driver', 'none');
        Config::set('razorpay.enabled', false);
    }

    public function testOnlyTheOwnerManagesTheRegisterAndServices(): void
    {
        Integrations::ensureSeeded();
        foreach ([fn () => Integrations::save($this->admin, null, $this->analytics()), fn () => ServiceAvailability::set($this->admin, 'x', 'suspended', ''), fn () => Integrations::markReviewed($this->admin, 1)] as $f) {
            try {
                $f();
                $this->fail('forbidden');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        foreach (['/admin/compliance/integrations', '/admin/compliance/integrations/new', '/admin/compliance/services'] as $u) {
            $this->assertSame(403, $this->loggedIn('admin@x.test')->get($u)['status'], $u);
            $this->assertSame(200, $this->loggedIn('owner@x.test')->get($u)['status'], $u);
        }
        $this->assertSame(403, $this->loggedIn('admin@x.test')->post('/admin/compliance/integrations/new', $this->analytics(), '/account')['status']);
    }

    public function testTheOwnerEditsTheRegisterThroughTheForm(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $o->post('/admin/compliance/integrations/new', $this->analytics(['name' => 'Form Added', 'state' => 'planned', 'cookie_category' => 'none', 'script_url' => '', 'cookie_details' => '']), '/admin/compliance/integrations/new');
        $this->assertNotNull(Database::one("SELECT 1 FROM compliance_integrations WHERE name = 'Form Added'"));
        $this->assertStringContainsString('Form Added', $this->client()->get('/third-party-services')['body']);
        $this->assertSame(200, $o->get('/admin/compliance/integrations')['status']);
    }

    // ------------------------------------------------------------------------------------------------ services

    public function testTheServiceListMatchesWhatKisReallyOffers(): void
    {
        SiteSeeder::run($this->owner);
        ServiceAvailability::reconcile();
        $active = array_column(array_filter(ServiceAvailability::all(), static fn (array $r): bool => $r['availability'] === 'active' && $r['status'] === 'published'), 'name');
        foreach (['Website Development', 'Web Application Development', 'Custom Management Systems and Software', 'iOS Application and Software Development', 'Android Application Development', 'AI Solutions', 'AI Automation', 'Logo Creation', 'T-shirt Designing', 'Graphic Design'] as $name) {
            $this->assertContains($name, $active, $name . ' is offered');
        }
        $all = array_column(ServiceAvailability::all(), 'availability', 'slug');
        $this->assertSame('discontinued', $all['social-media-management'] ?? 'discontinued');
        $this->assertSame([], array_filter(array_keys($all), static fn (string $s): bool => in_array($s, ['thumbnail-design', 'content-writing'], true) && $all[$s] === 'active'), 'services that are not on the list are not advertised');
        $page = $this->client()->get('/services')['body'];
        $this->assertStringContainsString('Logo Creation', $page);
        $this->assertStringNotContainsString('Social Media Management', $page);
        $this->assertStringNotContainsString('Thumbnail Design', $page);
        $this->assertStringNotContainsString('Content Writing', $page);
    }

    public function testExistingServicesAreReconciledWithoutDeletingAnything(): void
    {
        foreach ([['short-form-video-editing', 'Short-Form Video Editing'], ['social-media-management', 'Social Media Management'], ['thumbnail-design', 'Thumbnail Design'], ['website-development', 'Website Development']] as $i => [$slug, $name]) {
            Database::run("INSERT INTO site_services (public_id, slug, name, summary, status, position, created_at, updated_at) VALUES (?, ?, ?, 'x', 'published', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [bin2hex(random_bytes(16)), $slug, $name, $i]);
        }
        $before = (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'];
        ServiceAvailability::reconcile();
        $by = array_column(ServiceAvailability::all(), 'availability', 'slug');
        $this->assertSame('suspended', $by['short-form-video-editing']);
        $this->assertSame('discontinued', $by['social-media-management']);
        $this->assertSame('discontinued', $by['thumbnail-design']);
        $this->assertSame('active', $by['website-development']);
        $this->assertSame($before + 9, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'], 'the nine missing offered services were added; nothing was deleted');
        $page = $this->client()->get('/services')['body'];
        $this->assertStringContainsString('Short-Form Video Editing', $page);
        $this->assertStringContainsString('Not accepting projects at the moment', $page, 'suspended services say so');
        $this->assertStringNotContainsString('Social Media Management', $page);
        ServiceAvailability::reconcile();
        $this->assertSame($before + 9, (int) Database::one('SELECT COUNT(*) c FROM site_services')['c'], 'running it again changes nothing');
    }

    public function testOnlyActiveServicesCanBeRequestedAndTheOwnerControlsAvailability(): void
    {
        ServiceAvailability::reconcile();
        $this->assertArrayHasKey('website-development', EnquiryExtras::serviceChoices());
        $pub = (string) Database::one("SELECT public_id FROM site_services WHERE slug = 'website-development'")['public_id'];
        ServiceAvailability::set($this->owner, $pub, 'suspended', 'Fully booked until January');
        $this->assertArrayNotHasKey('website-development', EnquiryExtras::serviceChoices(), 'a suspended service cannot be requested');
        $page = $this->client()->get('/services/website-development');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Fully booked until January', $page['body']);
        $this->assertStringNotContainsString('Request a Project Proposal', $page['body']);
        ServiceAvailability::set($this->owner, $pub, 'coming_soon', '');
        $this->assertStringContainsString('Coming soon', $this->client()->get('/services')['body']);
        ServiceAvailability::set($this->owner, $pub, 'discontinued', 'No longer offered');
        $this->assertSame(404, $this->client()->get('/services/website-development')['status'], 'a discontinued service is not advertised');
        ServiceAvailability::set($this->owner, $pub, 'active', '');
        $this->assertSame(200, $this->client()->get('/services/website-development')['status']);
        $this->assertArrayHasKey('website-development', EnquiryExtras::serviceChoices());
        $this->refused(fn () => ServiceAvailability::set($this->owner, $pub, 'unknown', ''), 'availability');
        $o = $this->loggedIn('owner@x.test');
        $o->post('/admin/compliance/services/' . $pub, ['availability' => 'suspended', 'note' => 'Back in spring'], '/admin/compliance/services');
        $this->assertSame('suspended', Database::one('SELECT availability a FROM site_services WHERE public_id = ?', [$pub])['a']);
    }
}
