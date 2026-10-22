<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AiService;
use Vertex\Services\BusinessProfile;
use Vertex\Services\CmsBlocks;
use Vertex\Services\CmsService;
use Vertex\Services\Http;
use Vertex\Services\InvoiceService;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\Settings;

/** Phase 12 services: settings store, CMS, AI assistance and the Razorpay gateway (against a simulated Razorpay and a simulated AI provider). */
final class Phase12ServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private static string $pub = '';
    /** @var array<string,mixed> */
    private array $saved = [];
    private string $clientPublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        if (self::$pub === '') { // the media folder constant can only be defined once per process
            self::$pub = sys_get_temp_dir() . '/vtx-pub-' . bin2hex(random_bytes(4));
            mkdir(self::$pub, 0777, true);
            define('VERTEX_PUBLIC', self::$pub);
        }
        exec('rm -rf ' . escapeshellarg(self::$pub . '/uploads'));
        foreach (['ai', 'razorpay', 'features', 'site', 'app.url', 'mail', 'uploads'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        Config::set('mail.driver', 'none');
        foreach (glob(dirname((string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads')) . '/cache/razorpay-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        $this->tearDownPhase3();
    }

    private function invalid(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    private function forbidden(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Access should have been refused.');
        } catch (HttpException $e) {
            $this->assertContains($e->status, [403, 404]);
        }
    }

    // ------------------------------------------------------------------------------------------------ settings

    public function testOnlyTheOwnerCanSaveSettingsAndSecretsAreSealedAndNeverEchoed(): void
    {
        $this->forbidden(fn () => Settings::save($this->admin, 'email', ['mail__host' => 'smtp.example.com']));
        Settings::save($this->owner, 'email', ['mail__driver' => 'smtp', 'mail__host' => 'smtp.example.com', 'mail__port' => '587', 'mail__encryption' => 'tls', 'mail__username' => 'u@example.com', 'mail__password' => 'S3cret-smtp-pass!', 'mail__from_address' => 'no-reply@example.com', 'mail__from_name' => 'Vertex']);
        $this->assertSame('smtp.example.com', Config::get('mail.host'));
        $this->assertSame(587, Config::get('mail.port'));
        $this->assertSame('S3cret-smtp-pass!', Config::get('mail.password'), 'the running app sees the opened secret');
        $row = Database::one("SELECT * FROM app_settings WHERE setting_key = 'mail.password'");
        $this->assertSame(1, (int) $row['is_secret']);
        $this->assertStringNotContainsString('S3cret', (string) $row['value_text'], 'stored sealed, not in plain text');
        $screen = json_encode(Settings::forScreen());
        $this->assertStringNotContainsString('S3cret', (string) $screen, 'the admin screen never receives a secret');
        $this->assertTrue(array_values(array_filter(Settings::forScreen()['email'], fn ($f) => $f['key'] === 'mail.password'))[0]['secret_set']);
        $audit = json_encode(Database::all("SELECT * FROM audit_logs WHERE action = 'settings.changed'"));
        $this->assertStringContainsString('mail.password', (string) $audit);
        $this->assertStringNotContainsString('S3cret', (string) $audit, 'audit names the key, never the value');
        // leaving the secret empty keeps it
        Settings::save($this->owner, 'email', ['mail__host' => 'smtp2.example.com', 'mail__password' => '']);
        $this->assertSame('S3cret-smtp-pass!', Config::get('mail.password'));
        $this->assertSame('smtp2.example.com', Config::get('mail.host'));
    }

    public function testSettingsValidationAndReset(): void
    {
        $this->invalid(fn () => Settings::save($this->owner, 'website', ['site__facebook' => 'http://insecure.example.com']), 'https://');
        $this->invalid(fn () => Settings::save($this->owner, 'website', ['site__email' => 'not-an-email']), 'email');
        $this->invalid(fn () => Settings::save($this->owner, 'website', ['site__tagline' => 'Hi <script>']), '<');
        $this->invalid(fn () => Settings::save($this->owner, 'email', ['mail__driver' => 'carrier-pigeon']), 'options');
        $this->invalid(fn () => Settings::save($this->owner, 'email', ['mail__port' => 'abc']), 'number');
        $this->invalid(fn () => Settings::save($this->owner, 'nonsense', []), 'Unknown');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM app_settings')['c'], 'a failed save writes nothing');
        Settings::save($this->owner, 'website', ['site__tagline' => 'Built to a standard']);
        $this->assertSame('Built to a standard', Config::get('site.tagline'));
        Settings::save($this->owner, 'website', [], ['site.tagline']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM app_settings WHERE setting_key = 'site.tagline'")['c']);
    }

    public function testASettingThatIsNotOnTheReviewedListIsNeverApplied(): void
    {
        Database::run("INSERT INTO app_settings (setting_key, value_text, is_secret, updated_at) VALUES ('database.password', 'hax', 0, UTC_TIMESTAMP())");
        $before = Config::get('database.password');
        Settings::reload();
        $this->assertSame($before, Config::get('database.password'));
    }

    // ------------------------------------------------------------------------------------------------ CMS

    /** @return array{public_id:string,id:int} */
    private function page(string $title = 'About us', string $slug = 'studio'): array
    {
        return CmsService::create($this->manager, $title, $slug);
    }

    private function row(string $pub): array
    {
        return CmsService::find($pub);
    }

    public function testPagesBlocksVersionsAndRestore(): void
    {
        $p = $this->page();
        $this->assertSame('draft', $this->row($p['public_id'])['status']);
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'hero', ['heading' => 'We build things', 'subheading' => 'Websites and video']);
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'text', ['heading' => 'Story', 'body' => "First paragraph.\n\n- one\n- two"]);
        $row = $this->row($p['public_id']);
        $this->assertSame(3, (int) $row['current_version']);
        $this->assertCount(2, CmsService::blocksOf($row));
        CmsService::moveBlock($this->manager, $row, 1, 'up');
        $this->assertSame('text', CmsService::blocksOf($this->row($p['public_id']))[0]['type']);
        CmsService::updateBlock($this->manager, $this->row($p['public_id']), 1, ['heading' => 'Changed heading']);
        CmsService::deleteBlock($this->manager, $this->row($p['public_id']), 0);
        $row = $this->row($p['public_id']);
        $this->assertCount(1, CmsService::blocksOf($row));
        $this->assertCount(6, CmsService::versions($row), 'creation + every change is a version');
        // restore version 3 (hero + text): history grows, nothing is lost
        CmsService::restore($this->manager, $row, 3);
        $row = $this->row($p['public_id']);
        $this->assertCount(2, CmsService::blocksOf($row));
        $this->assertSame(7, (int) $row['current_version']);
        // a save that changes nothing makes no version
        CmsService::updateDetails($this->manager, $row, ['title' => $row['title'], 'slug' => $row['slug']]);
        $this->assertSame(7, (int) $this->row($p['public_id'])['current_version']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'cms.page_changed'")['c']);
    }

    public function testAddressRulesReservedNamesAndDuplicates(): void
    {
        foreach (['login', 'admin', 'dashboard', 'assets', 'uploads', 'f', 'my'] as $bad) {
            $this->invalid(fn () => CmsService::create($this->manager, 'Sneaky', $bad), 'platform');
        }
        $this->invalid(fn () => CmsService::create($this->manager, 'Sneaky', 'admin/cms'), 'platform');
        $this->invalid(fn () => CmsService::create($this->manager, 'Odd', 'Has Spaces'), 'letters');
        $this->invalid(fn () => CmsService::create($this->manager, 'Odd', '../etc'), 'letters');
        $this->invalid(fn () => CmsService::create($this->manager, 'Deep', 'a/b/c'), 'letters');
        $this->page();
        $this->invalid(fn () => CmsService::create($this->manager, 'Again', 'studio'), 'already');
        $this->assertNotNull(CmsService::create($this->manager, 'Home', 'home'));
        $this->invalid(fn () => CmsService::create($this->manager, 'Home again', 'home'), 'already');
        $this->forbidden(fn () => CmsService::create($this->support, 'Nope', 'nope'));
        $this->forbidden(fn () => CmsService::create($this->client, 'Nope', 'nope'));
    }

    public function testPublishingNeedsContentAndPublishRightsAndLivePagesAreProtected(): void
    {
        $p = $this->page();
        $this->forbidden(fn () => CmsService::setStatus($this->manager, $this->row($p['public_id']), 'published')); // a manager drafts, an administrator publishes
        $this->invalid(fn () => CmsService::setStatus($this->admin, $this->row($p['public_id']), 'published'), 'at least one block');
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'text', ['body' => 'Hello visitors.']);
        $this->assertNull(CmsService::findLive('studio'), 'a draft is not public');
        CmsService::setStatus($this->admin, $this->row($p['public_id']), 'published');
        $this->assertNotNull(CmsService::findLive('studio'));
        // the manager may not change a live page
        $this->forbidden(fn () => CmsService::addBlock($this->manager, $this->row($p['public_id']), 'text', ['body' => 'Sneaky edit']));
        CmsService::addBlock($this->admin, $this->row($p['public_id']), 'text', ['body' => 'Admin edit']);
        CmsService::setStatus($this->admin, $this->row($p['public_id']), 'draft');
        $this->assertNull(CmsService::findLive('studio'));
        CmsService::setStatus($this->admin, $this->row($p['public_id']), 'archived');
        $this->assertNull(CmsService::findLive('studio'));
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'cms.page_published'"));
    }

    public function testScheduledPublishingGoesLiveWhenDue(): void
    {
        $p = $this->page();
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'text', ['body' => 'Later.']);
        $this->invalid(fn () => CmsService::schedule($this->admin, $this->row($p['public_id']), '2001-01-01 10:00'), 'future');
        $this->invalid(fn () => CmsService::schedule($this->admin, $this->row($p['public_id']), 'tomorrow'), 'date');
        CmsService::schedule($this->admin, $this->row($p['public_id']), gmdate('Y-m-d H:i', time() + 86400 * 3));
        $this->assertSame('scheduled', $this->row($p['public_id'])['status']);
        $this->assertNull(CmsService::findLive('studio'), 'not live before its time');
        $this->assertSame(0, CmsService::publishDue());
        Database::run("UPDATE cms_pages SET publish_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?", [$p['id']]);
        $this->assertNotNull(CmsService::findLive('studio'), 'due pages are live even before the scheduler runs');
        $this->assertSame(1, CmsService::publishDue());
        $this->assertSame('published', $this->row($p['public_id'])['status']);
        $this->assertSame(0, CmsService::publishDue(), 'idempotent');
    }

    public function testChangingTheAddressOfALivePageLeavesARedirect(): void
    {
        $p = $this->page();
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'text', ['body' => 'Content.']);
        CmsService::setStatus($this->admin, $this->row($p['public_id']), 'published');
        CmsService::updateDetails($this->admin, $this->row($p['public_id']), ['title' => 'About us', 'slug' => 'who-we-are']);
        $this->assertNotNull(CmsService::findLive('who-we-are'));
        $this->assertNull(CmsService::findLive('studio'));
        $this->assertSame(['code' => 301, 'to' => '/who-we-are'], CmsService::redirectFor('/studio'));
        $this->assertSame(1, (int) Database::one("SELECT hits FROM cms_redirects WHERE from_path = '/studio'")['hits']);
    }

    public function testRedirectsAreValidatedAndCannotShadowThePlatformOrOpenRedirectToHttp(): void
    {
        $this->forbidden(fn () => CmsService::addRedirect($this->manager, '/old', '/new', '301'));
        CmsService::addRedirect($this->admin, '/old-page', '/new-page', '301');
        CmsService::addRedirect($this->admin, '/promo', 'https://example.com/offer', '302');
        $this->assertSame(302, CmsService::redirectFor('/promo')['code']);
        foreach ([['/login', '/x'], ['/admin/users', '/x'], ['/ok', 'http://insecure.example.com'], ['/ok', 'javascript:alert(1)'], ['/ok', '//evil.example.com'], ['/same', '/same'], ['no-slash/../x', '/y'], ['/', '/y']] as [$from, $to]) {
            $this->invalid(fn () => CmsService::addRedirect($this->admin, $from, $to, '301'));
        }
        $id = (int) Database::one("SELECT id FROM cms_redirects WHERE from_path = '/old-page'")['id'];
        CmsService::deleteRedirect($this->admin, $id);
        $this->assertNull(CmsService::redirectFor('/old-page'));
    }

    public function testBlockContentCannotInjectScriptOrUnsafeLinks(): void
    {
        $this->invalid(fn () => CmsBlocks::clean('hero', ['heading' => 'Hi <script>alert(1)</script>']), '<');
        $this->invalid(fn () => CmsBlocks::clean('hero', ['heading' => 'Ok', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)']), 'start with');
        $this->invalid(fn () => CmsBlocks::clean('cta', ['heading' => 'Ok', 'button_label' => 'Go', 'button_url' => 'data:text/html;base64,AAAA']));
        $this->invalid(fn () => CmsBlocks::clean('nonsense', []));
        $this->invalid(fn () => CmsBlocks::clean('text', ['body' => '']), 'required');
        $this->invalid(fn () => CmsBlocks::clean('image', ['image' => str_repeat('a', 32)]), 'library');
        // the long-text block accepts angle brackets as TEXT and escapes them on the way out
        $b = CmsBlocks::clean('text', ['heading' => 'Safe', 'body' => "<script>alert('x')</script> and **bold** and [click](javascript:alert(1)) and [ok](https://example.com)\n- <img src=x onerror=alert(1)>"]);
        $html = CmsBlocks::html($b);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        // stored rows that were tampered with directly are still escaped when shown
        $evil = CmsBlocks::html(['type' => 'hero', 'heading' => '"><script>alert(1)</script>', 'button_label' => 'x', 'button_url' => 'javascript:alert(1)', 'image' => '']);
        $this->assertStringNotContainsString('<script>', $evil);
        $this->assertStringNotContainsString('javascript:', $evil);
        $lines = CmsBlocks::html(['type' => 'services', 'heading' => 'S', 'items' => "Web | <b>x</b> | javascript:alert(1)\nVideo | ok | /video"]);
        $this->assertStringNotContainsString('<b>', $lines);
        $this->assertStringNotContainsString('javascript:', $lines);
        $this->assertStringContainsString('href="/video"', $lines);
    }

    public function testMenusAreValidatedAndOnlyPublishersCanSaveThem(): void
    {
        $this->forbidden(fn () => CmsService::saveMenu($this->manager, 'header', "Home | /"));
        CmsService::saveMenu($this->admin, 'header', "Home | /\nServices | /services\nCall | tel:+911234567890\nMail | mailto:hi@example.com\nPartner | https://example.com");
        $this->assertCount(5, CmsService::menu('header'));
        $this->assertStringContainsString('Services | /services', CmsService::menuText('header'));
        foreach (["Bad | javascript:alert(1)", "<b>x</b> | /x", "NoUrl", "Too | " . str_repeat('x', 3), str_repeat("A | /a\n", 13)] as $bad) {
            if ($bad === "Too | xxx") {
                continue;
            }
            $this->invalid(fn () => CmsService::saveMenu($this->admin, 'footer', $bad));
        }
        $this->forbidden(fn () => CmsService::saveMenu($this->admin, 'sidebar', 'A | /a'));
        $this->assertSame([], CmsService::menu('footer'));
    }

    public function testSitemapListsOnlyPublicIndexablePages(): void
    {
        Config::set('app.url', 'https://vis.test');
        $mk = function (string $title, string $slug, bool $publish, bool $noindex = false): void {
            $p = CmsService::create($this->admin, $title, $slug);
            CmsService::addBlock($this->admin, CmsService::find($p['public_id']), 'text', ['body' => 'x']);
            if ($noindex) {
                CmsService::updateDetails($this->admin, CmsService::find($p['public_id']), ['title' => $title, 'slug' => $slug, 'noindex' => '1']);
            }
            if ($publish) {
                CmsService::setStatus($this->admin, CmsService::find($p['public_id']), 'published');
            }
        };
        $mk('Services', 'what-we-offer', true);
        $mk('Secret draft', 'secret-draft', false);
        $mk('Hidden', 'hidden', true, true);
        $mk('Legal', 'legal/privacy', true);
        $locs = array_column(CmsService::sitemapEntries(), 'loc');
        $this->assertContains('https://vis.test/', $locs);
        $this->assertContains('https://vis.test/what-we-offer', $locs);
        $this->assertContains('https://vis.test/legal/privacy', $locs);
        $this->assertNotContains('https://vis.test/secret-draft', $locs);
        $this->assertNotContains('https://vis.test/hidden', $locs);
    }

    public function testMediaLibraryAcceptsRealPicturesOnlyAndProtectsPicturesInUse(): void
    {
        $this->forbidden(fn () => CmsService::upload($this->support, $this->png(), 'A test picture', false));
        $m = CmsService::upload($this->manager, $this->png('logo.png'), 'Our logo', false);
        $row = CmsService::mediaByPublicId($m['public_id']);
        $this->assertSame('image/png', $row['mime']);
        $file = self::$pub . CmsService::mediaUrl($row);
        $this->assertFileExists($file);
        $this->assertMatchesRegularExpression('#/uploads/cms/\d{4}/[a-f0-9]{24}\.png$#', CmsService::mediaUrl($row), 'random name, not the uploaded one');
        $this->assertFileExists(self::$pub . '/uploads/cms/.htaccess', 'scripts are blocked in the uploads folder');
        // php disguised as a picture, text, an oversize picture, empty files
        $this->invalid(fn () => CmsService::upload($this->manager, $this->tmp('shell.png', "<?php system(\$_GET['c']); ?>"), 'A test picture', false), 'JPEG');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->invalid(fn () => CmsService::upload($this->manager, $this->tmp('x.png', $png . "<?php echo 1; ?>"), 'A test picture', false), 'code');
        $this->invalid(fn () => CmsService::upload($this->manager, $this->tmp('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'A test picture', false), 'JPEG');
        $this->invalid(fn () => CmsService::upload($this->manager, $this->tmp('x.png', ''), 'A test picture', false));
        $this->invalid(fn () => CmsService::upload($this->manager, ['name' => 'a.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE], 'A test picture', false));
        // a picture used by a page cannot be deleted from under it
        $p = $this->page();
        CmsService::addBlock($this->manager, $this->row($p['public_id']), 'image', ['image' => $m['public_id'], 'caption' => 'Logo']);
        $html = CmsService::renderBlocks($this->row($p['public_id']));
        $this->assertStringContainsString('alt="Our logo"', $html);
        $this->assertStringContainsString(CmsService::mediaUrl($row), $html);
        $this->invalid(fn () => CmsService::deleteMedia($this->manager, $m['public_id']), 'used');
        CmsService::deleteBlock($this->manager, $this->row($p['public_id']), 0);
        CmsService::deleteMedia($this->manager, $m['public_id']);
        $this->assertFileDoesNotExist($file);
        $this->assertNull(CmsService::mediaByPublicId($m['public_id']));
    }

    public function testWebsitePicturesAreIncludedInBackups(): void
    {
        $m = CmsService::upload($this->manager, $this->png('logo.png'), 'Our logo', false);
        $row = CmsService::mediaByPublicId($m['public_id']);
        $dir = sys_get_temp_dir() . '/vtx-bk-' . bin2hex(random_bytes(4));
        mkdir($dir, 0750, true);
        $b = \Vertex\Services\BackupService::create($dir);
        $this->assertTrue(\Vertex\Services\BackupService::verify($b['path'])['ok']);
        $zip = new \ZipArchive();
        $zip->open($b['path']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertContains('uploads/website-media/' . substr((string) $row['created_at'], 0, 4) . '/' . $row['file_name'], $names);
        $this->assertSame([], array_filter($names, fn (string $n): bool => str_ends_with($n, '.htaccess')), 'the guard file is not part of the data');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testReusableBlocksUpdateEveryPageAndCannotNest(): void
    {
        CmsService::saveReusable($this->manager, null, 'Quote banner', 'cta', ['heading' => 'Ready to start?', 'button_label' => 'Get a quote', 'button_url' => '/request-quote']);
        $block = CmsService::reusable()[0];
        $this->invalid(fn () => CmsService::saveReusable($this->manager, null, 'Quote banner', 'cta', ['heading' => 'x', 'button_label' => 'y', 'button_url' => '/z']), 'already');
        $this->invalid(fn () => CmsService::saveReusable($this->manager, null, 'Loop', 'reusable', ['ref' => $block['public_id']]), 'cannot contain');
        $a = $this->page('Page A', 'page-a');
        $b = $this->page('Page B', 'page-b');
        foreach ([$a, $b] as $pg) {
            CmsService::addBlock($this->manager, $this->row($pg['public_id']), 'reusable', ['ref' => $block['public_id']]);
        }
        $this->assertStringContainsString('Ready to start?', CmsService::renderBlocks($this->row($a['public_id'])));
        CmsService::saveReusable($this->manager, $block['public_id'], 'Quote banner', 'cta', ['heading' => 'Let us talk', 'button_label' => 'Get a quote', 'button_url' => '/request-quote']);
        foreach ([$a, $b] as $pg) {
            $html = CmsService::renderBlocks($this->row($pg['public_id']));
            $this->assertStringContainsString('Let us talk', $html);
            $this->assertStringNotContainsString('Ready to start?', $html);
        }
        $this->invalid(fn () => CmsService::deleteReusable($this->manager, $block['public_id']), 'used');
        $this->invalid(fn () => CmsBlocks::clean('reusable', ['ref' => str_repeat('b', 32)]), 'reusable');
    }

    public function testCompanyFactsAppearInTheContactBlockEscaped(): void
    {
        Settings::save($this->owner, 'website', ['site__email' => 'hello@vis.example', 'site__phone' => '+91 90000 11111', 'site__whatsapp' => '919000011111', 'site__address' => "1 Main Road\nMumbai", 'site__facebook' => 'https://facebook.com/vis']);
        $html = CmsBlocks::html(['type' => 'contact', 'heading' => 'Reach us']);
        foreach (['mailto:hello@vis.example', 'tel:+919000011111', 'https://wa.me/919000011111', '1 Main Road', 'https://facebook.com/vis'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    // ------------------------------------------------------------------------------------------------ AI

    private function aiOn(): void
    {
        Config::set('ai.enabled', true);
        Config::set('ai.api_key', 'sk-test-key-123');
        Config::set('ai.provider', 'anthropic');
        Config::set('ai.monthly_cap', 500);
    }

    /** @param list<array{method:string,url:string,headers:array<string,string>,body:?string}> $log */
    private function fakeAi(array &$log, string $answer = 'A helpful draft.', int $status = 200): void
    {
        Http::fake(function (string $m, string $url, array $h, ?string $body) use (&$log, $answer, $status): array {
            $log[] = ['method' => $m, 'url' => $url, 'headers' => $h, 'body' => $body];

            return ['status' => $status, 'body' => (string) json_encode(['content' => [['type' => 'text', 'text' => $answer]]])];
        });
    }

    public function testAiIsOffUntilConfiguredAndNeedsThePermission(): void
    {
        $log = [];
        $this->fakeAi($log);
        $this->invalid(fn () => AiService::draftDocument($this->manager, 'email', 'Write a thank-you note to a client.'), 'turned off');
        $this->aiOn();
        $this->forbidden(fn () => AiService::draftDocument($this->client, 'email', 'Write a thank-you note to a client.'));
        $this->forbidden(fn () => AiService::draftDocument($this->support, 'email', 'Write a thank-you note to a client.'));
        $this->assertSame([], $log, 'no call is made for a refused user');
        Config::set('features.ai', false);
        $this->invalid(fn () => AiService::draftDocument($this->manager, 'email', 'Write a thank-you note to a client.'), 'off');
        Config::set('features.ai', true);
        $this->assertSame('A helpful draft.', AiService::draftDocument($this->manager, 'email', 'Write a thank-you note to a client.'));
        $this->assertCount(1, $log);
        $this->assertSame('sk-test-key-123', $log[0]['headers']['x-api-key']);
        $this->assertStringStartsWith('https://api.anthropic.com/', $log[0]['url']);
    }

    public function testAiRequestsAreLoggedWithoutTheTextAndLimited(): void
    {
        $log = [];
        $this->fakeAi($log, 'Draft text');
        $this->aiOn();
        AiService::draftDocument($this->manager, 'proposal', 'Please write a proposal for a small shop website with SECRET-MARKER details.');
        $row = Database::one('SELECT * FROM ai_requests');
        $this->assertSame([$this->manager, 'draft_proposal', 'ok'], [(int) $row['user_id'], $row['feature'], $row['status']]);
        $this->assertGreaterThan(0, (int) $row['prompt_chars']);
        $this->assertStringNotContainsString('SECRET-MARKER', json_encode($row));
        $this->assertStringNotContainsString('Draft text', json_encode($row));
        // company cap
        Config::set('ai.monthly_cap', 1);
        $this->invalid(fn () => AiService::draftDocument($this->manager, 'email', 'Another request that should be refused.'), 'monthly');
        $this->assertCount(1, $log);
        // per-user hourly limit
        Config::set('ai.monthly_cap', 5000);
        $limit = (int) Config::get('auth.limits.ai_user.0', 30);
        $hit = false;
        for ($i = 0; $i < $limit + 2; $i++) {
            try {
                AiService::draftDocument($this->manager, 'email', 'Write a short message number ' . $i . '.');
            } catch (ValidationException $e) {
                $hit = true;
                $this->assertStringContainsString('hour', implode(' ', $e->errors));
                break;
            }
        }
        $this->assertTrue($hit, 'the per-user hourly limit stops runaway use');
    }

    public function testProviderFailuresAreGenericAndNeverLeakTheKey(): void
    {
        $log = [];
        $this->fakeAi($log, 'ignored', 500);
        $this->aiOn();
        try {
            AiService::draftDocument($this->manager, 'email', 'Write something polite to a client.');
            $this->fail('expected failure');
        } catch (ValidationException $e) {
            $msg = implode(' ', $e->errors);
            $this->assertStringContainsString('could not answer', $msg);
            $this->assertStringNotContainsString('sk-test', $msg);
        }
        $row = Database::one('SELECT * FROM ai_requests');
        $this->assertSame('error', $row['status']);
        $this->assertStringNotContainsString('sk-test', json_encode($row));
        Http::fake(fn () => throw new \RuntimeException('connection reset'));
        $this->invalid(fn () => AiService::draftDocument($this->manager, 'email', 'Write something polite to a client.'), 'could not answer');
        Http::fake(fn () => ['status' => 200, 'body' => '{"content":[]}']);
        $this->invalid(fn () => AiService::draftDocument($this->manager, 'email', 'Write something polite to a client.'), 'could not answer');
    }

    public function testOpenAiCompatibleProviderShape(): void
    {
        $seen = [];
        Http::fake(function (string $m, string $url, array $h, ?string $body) use (&$seen): array {
            $seen = ['url' => $url, 'h' => $h, 'b' => json_decode((string) $body, true)];

            return ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'OpenAI style answer']]]])];
        });
        $this->aiOn();
        Config::set('ai.provider', 'openai');
        Config::set('ai.base_url', 'https://llm.example.com');
        Config::set('ai.model', 'some-model');
        $this->assertSame('OpenAI style answer', AiService::draftDocument($this->manager, 'email', 'Write a short greeting for a client.'));
        $this->assertSame('https://llm.example.com/v1/chat/completions', $seen['url']);
        $this->assertSame('Bearer sk-test-key-123', $seen['h']['Authorization']);
        $this->assertSame('some-model', $seen['b']['model']);
    }

    public function testAiOnlySeesRecordsThePersonMayOpenAndNeverCreatesAnythingByItself(): void
    {
        $log = [];
        $this->fakeAi($log, 'Answer');
        $this->aiOn();
        // dev2 is on Bravo only: asking about "Alpha" must not put Alpha's title in the prompt
        Database::run('UPDATE user_roles SET role_id = (SELECT id FROM roles WHERE slug = ?) WHERE user_id = ?', ['manager', $this->dev2]);
        \Vertex\Services\Gate::flush();
        AiService::ask($this->dev2, 'Which projects are called Alpha or Bravo?');
        $prompt = implode("\n", array_column($log, 'body'));
        $this->assertStringContainsString('Bravo Video', $prompt);
        $this->assertStringNotContainsString('Alpha Website', $prompt, 'records the user cannot open never reach the model');
        // project helpers respect assignment
        $this->forbidden(fn () => AiService::suggestNext($this->manager2, $this->alpha['public_id']));
        $this->forbidden(fn () => AiService::draftTasks($this->manager2, $this->alpha['public_id']));
        $tasksBefore = (int) Database::one('SELECT COUNT(*) c FROM tasks')['c'];
        $this->fakeAi($log, json_encode(['tasks' => [['title' => 'Design homepage', 'description' => 'Wireframes first', 'estimate_minutes' => 240, 'priority' => 'high'], ['title' => '<b>Build</b> contact form', 'priority' => 'weird']]]));
        $drafts = AiService::draftTasks($this->manager, $this->alpha['public_id']);
        $this->assertCount(2, $drafts);
        $this->assertSame('Build contact form', $drafts[1]['title'], 'markup stripped');
        $this->assertSame('normal', $drafts[1]['priority'], 'unknown priority falls back');
        $this->assertSame($tasksBefore, (int) Database::one('SELECT COUNT(*) c FROM tasks')['c'], 'drafting creates nothing');
        $this->assertSame(1, AiService::createTasks($this->manager, $this->alpha['public_id'], [$drafts[0]]));
        $this->assertSame($tasksBefore + 1, (int) Database::one('SELECT COUNT(*) c FROM tasks')['c']);
        $this->forbidden(fn () => AiService::createTasks($this->manager2, $this->alpha['public_id'], [$drafts[0]]));
    }

    public function testBadAiTaskOutputIsRejectedSafely(): void
    {
        foreach (['not json at all', '{"tasks":"x"}', '{"tasks":[]}', '{"tasks":[{"title":"a"}]}', '{"tasks":[{"nope":1}]}'] as $raw) {
            $this->invalid(fn () => AiService::parseTasks($raw));
        }
        $ok = AiService::parseTasks('Sure! {"tasks":[{"title":"Write copy","estimate_minutes":-5,"priority":"URGENT"}]} hope that helps');
        $this->assertSame([null, 'urgent'], [$ok[0]['estimate_minutes'], $ok[0]['priority']]);
    }

    // ------------------------------------------------------------------------------------------------ Razorpay

    /** What the simulated Razorpay says about the order's collection. @var array<string,mixed> */
    private array $rz = [];
    /** @var list<array<string,mixed>> bodies sent to Razorpay */
    private array $lastBodies = [];

    private function razorpayOn(bool $webhook = true): void
    {
        Config::set('app.url', 'https://vis.test');
        Config::set('razorpay.enabled', true);
        Config::set('razorpay.key_id', 'rzp_test_AbCd1234');
        Config::set('razorpay.key_secret', 'SECRETKEY1');
        Config::set('razorpay.qr_mode', 'auto');
        Config::set('razorpay.webhook_secret', $webhook ? 'whsec_test_123' : '');
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
    }

    /**
     * A simulated Razorpay. $o: upi (UPI string for a QR; omitted = QR without a string), qr (false = QR API refuses), paid (amount in paise paid), state (payment link status), http (status error), echo (amount the API repeats).
     *
     * @param list<string> $calls
     * @param array<string,mixed> $o
     */
    private function fakeRazorpay(array &$calls, array $o = []): void
    {
        $this->rz = $o;
        $this->lastBodies = [];
        Http::fake(function (string $m, string $url, array $h, ?string $body) use (&$calls): array {
            $o = $this->rz;
            $calls[] = $m . ' ' . $url;
            $b = $body !== null ? json_decode($body, true) : null;
            if (is_array($b)) {
                $this->lastBodies[] = $b;
            }
            $this->assertStringStartsWith('Basic ' . base64_encode('rzp_test_AbCd1234:SECRETKEY1'), $h['Authorization'] ?? '', 'Razorpay is called with the key id and secret');
            if ($m === 'POST' && str_ends_with($url, '/payments/qr_codes')) {
                if (($o['qr'] ?? true) === false) {
                    return ['status' => 400, 'body' => json_encode(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'QR codes are not enabled']])];
                }
                $d = ['id' => 'qr_TestQr0001', 'entity' => 'qr_code', 'type' => 'upi_qr', 'usage' => 'single_use', 'fixed_amount' => true, 'payment_amount' => $o['echo'] ?? $b['payment_amount'], 'status' => 'active', 'image_url' => 'https://rzp.io/i/AbCdEf'];
                if (isset($o['upi'])) {
                    $d['image_content'] = $o['upi'];
                }

                return ['status' => 200, 'body' => json_encode($d)];
            }
            if ($m === 'POST' && str_contains($url, '/payments/qr_codes/') && str_ends_with($url, '/close')) {
                return ['status' => 200, 'body' => json_encode(['status' => 'closed'])];
            }
            if ($m === 'POST' && str_ends_with($url, '/payment_links')) {
                return ['status' => 200, 'body' => json_encode(['id' => 'plink_TestLink01', 'short_url' => 'https://rzp.io/i/LinkAbc', 'amount' => $o['echo'] ?? $b['amount'], 'accept_partial' => false, 'status' => 'created'])];
            }
            if ($m === 'POST' && str_contains($url, '/payment_links/') && str_ends_with($url, '/cancel')) {
                return ['status' => 200, 'body' => json_encode(['status' => 'cancelled'])];
            }
            if ($m === 'GET' && str_contains($url, '/payments/qr_codes/') && str_contains($url, '/payments')) {
                $items = isset($o['paid']) ? [['id' => 'pay_QrPaid0001', 'amount' => $o['paid'], 'status' => $o['payStatus'] ?? 'captured']] : [];

                return ['status' => $o['http'] ?? 200, 'body' => json_encode(['entity' => 'collection', 'count' => count($items), 'items' => $items])];
            }
            if ($m === 'GET' && str_contains($url, '/payment_links/')) {
                $paid = $o['paid'] ?? 0;

                return ['status' => $o['http'] ?? 200, 'body' => json_encode(['id' => 'plink_TestLink01', 'status' => $o['state'] ?? ($paid > 0 ? 'paid' : 'created'), 'amount' => 100000, 'amount_paid' => $paid,
                    'payments' => $paid > 0 ? [['payment_id' => 'pay_LinkPaid01', 'amount' => $paid, 'status' => 'captured']] : null])];
            }

            if ($m === 'POST' && str_ends_with($url, '/orders')) {
                if (isset($o['orderHttp'])) {
                    return ['status' => $o['orderHttp'], 'body' => json_encode(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'simulated']])];
                }

                return ['status' => 200, 'body' => json_encode(['id' => 'order_TestOrder01', 'entity' => 'order', 'amount' => $o['echo'] ?? $b['amount'], 'currency' => 'INR', 'receipt' => $b['receipt'], 'status' => 'created'])];
            }
            if ($m === 'POST' && str_ends_with($url, '/capture')) {
                return ['status' => 200, 'body' => json_encode(['id' => 'pay_CheckoutPay01', 'status' => 'captured'])];
            }
            if ($m === 'GET' && str_contains($url, '/payments/pay_CheckoutPay01')) {
                return ['status' => $o['http'] ?? 200, 'body' => json_encode(['id' => 'pay_CheckoutPay01', 'order_id' => $o['payOrder'] ?? 'order_TestOrder01', 'amount' => $o['payAmount'] ?? 100000, 'currency' => 'INR', 'status' => $o['payStatus'] ?? 'captured'])];
            }

            return ['status' => 404, 'body' => '{}'];
        });
    }

    /** @return array<string,mixed> */
    private function issuedInvoice(string $total = '1000'): array
    {
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => $total]], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));

        return InvoiceService::find($d['public_id']);
    }

    /** A member of the KIS team asks for an amount (paise) on an invoice. @return array<string,mixed> */
    private function requestPay(array $inv, int $minor = 100000, ?int $by = null): array
    {
        return RazorpayGateway::createRequest($by ?? $this->finance, $inv, $minor, '');
    }

    private function sign(string $body, string $secret = 'whsec_test_123'): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public function testOnlyTheTeamCanRequestAPaymentAndTheAmountIsExactlyWhatTheyType(): void
    {
        $calls = [];
        $this->fakeRazorpay($calls);
        $inv = $this->issuedInvoice('1000');
        $this->invalid(fn () => $this->requestPay($inv), 'not switched on');
        $this->razorpayOn();
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->client));
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->client2));
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->dev));
        $this->assertSame([], $calls, 'no call to Razorpay for refused users');
        $o = $this->requestPay($inv, 25050);
        $this->assertSame(['pending', 25050, (int) $inv['id'], $this->finance, 'razorpay'], [$o['status'], (int) $o['amount_minor'], (int) $o['invoice_id'], (int) $o['created_by'], $o['provider']]);
        $this->assertMatchesRegularExpression('/^KIS\d+X[A-F0-9]{12}$/', $o['merchant_order_id']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c'], 'requesting is not paying');
        $amounts = array_values(array_filter(array_map(fn ($b) => $b['payment_amount'] ?? $b['amount'] ?? null, $this->lastBodies), fn ($a) => $a !== null));
        $this->assertSame([25050], array_values(array_unique($amounts)), 'every call to Razorpay carried exactly the typed amount');
        $this->invalid(fn () => $this->requestPay($inv, 99), 'smallest');
        $this->invalid(fn () => $this->requestPay($inv, 100001), 'more than');
        Config::set('app.url', 'http://vis.test');
        $this->invalid(fn () => $this->requestPay($inv, 100000), 'not switched on');
    }

    public function testAFixedAmountUpiQrIsUsedWhenRazorpayReturnsItsUpiStringAndAWrongAmountIsRefused(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=rzpqr.x@icici&pn=Krazy&am=250.50&cu=INR&tr=QR1']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 25050);
        $this->assertSame(['upi_qr', 'qr_TestQr0001', 'https://rzp.io/i/AbCdEf'], [$o['flow'], $o['provider_order_id'], $o['qr_image_url']]);
        $this->assertStringContainsString('am=250.50', $o['qr_payload']);
        $qrBody = array_values(array_filter($this->lastBodies, fn ($b) => ($b['type'] ?? '') === 'upi_qr'))[0];
        $this->assertSame(['single_use', true, 25050], [$qrBody['usage'], $qrBody['fixed_amount'], $qrBody['payment_amount']]);
        $this->assertGreaterThanOrEqual(time() + 120, $qrBody['close_by'], 'Razorpay needs at least two minutes');
        $this->assertSame([], array_filter($calls, fn ($c) => str_contains($c, '/payment_links')), 'no link needed');

        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=rzpqr.x@icici&am=999.00&tr=QR2']);
        $this->invalid(fn () => $this->requestPay($inv, 25050), 'different amount');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c'], 'the mismatching request was not stored');

        $this->fakeRazorpay($calls, ['echo' => 100]); // Razorpay answers for another amount
        $this->invalid(fn () => $this->requestPay($inv, 25050), 'different amount');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c']);
    }

    public function testAPaymentLinkForTheExactAmountIsUsedWhenThereIsNoUsableQr(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['qr' => false]); // the QR feature is not enabled on the account
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 25050);
        $this->assertSame(['payment_link', 'plink_TestLink01', 'https://rzp.io/i/LinkAbc'], [$o['flow'], $o['provider_order_id'], $o['qr_payload']]);
        $link = array_values(array_filter($this->lastBodies, fn ($b) => isset($b['reference_id'])))[0];
        $this->assertSame([25050, 'INR', false, $o['merchant_order_id']], [$link['amount'], $link['currency'], $link['accept_partial'], $link['reference_id']]);
        $this->assertGreaterThanOrEqual(time() + 15 * 60, $link['expire_by'], 'Razorpay payment links must live at least 15 minutes');

        // a QR with no UPI string is closed so there is never a second way to pay, then the link is used
        $this->fakeRazorpay($calls); // QR answer without image_content
        $o2 = $this->requestPay($inv, 10000);
        $this->assertSame('payment_link', $o2['flow']);
        $this->assertNotEmpty(array_filter($calls, fn ($c) => str_contains($c, '/payments/qr_codes/qr_TestQr0001/close')));
        // link-only mode never touches the QR API
        Config::set('razorpay.qr_mode', 'link');
        $calls = [];
        $this->fakeRazorpay($calls);
        $this->requestPay($inv, 10000);
        $this->assertSame([], array_filter($calls, fn ($c) => str_contains($c, '/qr_codes')));
        // a link for a different amount is cancelled and refused
        $this->fakeRazorpay($calls, ['echo' => 5]);
        $this->invalid(fn () => $this->requestPay($inv, 10000), 'different amount');
        $this->assertNotEmpty(array_filter($calls, fn ($c) => str_contains($c, '/cancel')));
    }

    public function testAPaymentIsRecordedOnlyWhenRazorpayHasACapturedPaymentOfExactlyTheAmount(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $mid = $o['merchant_order_id'];
        $this->assertSame(['state' => 'PENDING', 'recorded' => false], RazorpayGateway::reconcile($mid));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q', 'paid' => 100000, 'payStatus' => 'authorized']);
        $this->assertSame('PENDING', RazorpayGateway::reconcile($mid)['state'], 'authorized is not captured');
        $this->fakeRazorpay($calls, ['paid' => 100000]);
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => true], RazorpayGateway::reconcile($mid));
        $p = Database::one('SELECT * FROM client_payments');
        $this->assertSame(['verified', 'gateway', 'gateway', 100000, (int) $inv['id']], [$p['status'], $p['source'], $p['method'], (int) $p['gross_minor'], (int) $p['invoice_id']]);
        $this->assertSame('Razorpay pay_QrPaid0001', $p['external_ref']);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $row = Database::one('SELECT * FROM gateway_orders');
        $this->assertSame(['completed', 'pay_QrPaid0001', (int) $p['id']], [$row['status'], $row['provider_txn_id'], (int) $row['payment_id']]);
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => false], RazorpayGateway::reconcile($mid), 'asking again never doubles the payment');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertNotNull(Database::one("SELECT 1 x FROM notifications WHERE user_id = ? AND event_type = 'payment.confirmed'", [$this->client]));
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'client_payment.gateway_recorded'"));
        $this->forbidden(fn () => RazorpayGateway::reconcile('KIS999XAAAAAAAAAAAA'));
    }

    public function testAPaymentLinkThatIsPaidIsRecordedAndAnAmountMismatchRecordsNothing(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['qr' => false]);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $this->fakeRazorpay($calls, ['paid' => 5000, 'state' => 'partially_paid']); // somebody paid a different amount
        try {
            RazorpayGateway::reconcile($o['merchant_order_id']);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different amount', $e->getMessage());
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertSame('pending', Database::one('SELECT status FROM gateway_orders')['status']);
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'gateway.amount_mismatch'"));
        $this->fakeRazorpay($calls, ['paid' => 100000]);
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => true], RazorpayGateway::reconcile($o['merchant_order_id']));
        $this->assertSame('Razorpay pay_LinkPaid01', Database::one('SELECT external_ref FROM client_payments')['external_ref']);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
    }

    public function testARequestCanBeWithdrawnAndALatePaymentIsStillRecorded(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $this->assertTrue(RazorpayGateway::isLive($o));
        $this->forbidden(fn () => RazorpayGateway::cancelRequest($this->client, $o));
        RazorpayGateway::cancelRequest($this->finance, $o);
        $o = RazorpayGateway::findRequest($o['public_id']);
        $this->assertSame('cancelled', $o['status']);
        $this->assertFalse(RazorpayGateway::isLive($o));
        $this->assertSame([], RazorpayGateway::liveRequests((int) $inv['id']));
        $this->assertNotEmpty(array_filter($calls, fn ($c) => str_contains($c, '/payments/qr_codes/qr_TestQr0001/close')), 'Razorpay is asked to close the QR');
        $this->invalid(fn () => RazorpayGateway::cancelRequest($this->finance, $o), 'no longer open');
        $this->fakeRazorpay($calls, ['paid' => 100000]); // the customer had already paid
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => true], RazorpayGateway::reconcile($o['merchant_order_id']));
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
    }

    public function testStatusApiErrorsNeverRecordAPayment(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $this->fakeRazorpay($calls, ['paid' => 100000, 'http' => 500]);
        $this->expectException(\RuntimeException::class);
        try {
            RazorpayGateway::reconcile($o['merchant_order_id']);
        } finally {
            $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        }
    }

    public function testWebhookNeedsTheRightSignatureAndTheBodyIsNeverBelieved(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $mid = $o['merchant_order_id'];
        $body = json_encode(['event' => 'qr_code.credited', 'payload' => ['qr_code' => ['entity' => ['id' => 'qr_TestQr0001']], 'payment' => ['entity' => ['id' => 'pay_Fake', 'amount' => 100000, 'status' => 'captured', 'notes' => ['merchant_order_id' => $mid]]]]]);
        $this->assertSame(401, RazorpayGateway::webhook('', (string) $body)['status']);
        $this->assertSame(401, RazorpayGateway::webhook($this->sign((string) $body, 'wrong-secret'), (string) $body)['status']);
        $this->assertSame(401, RazorpayGateway::webhook($this->sign((string) $body . ' '), (string) $body)['status'], 'signature of different bytes');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        // a correctly signed webhook claiming a payment: the server asks Razorpay, which says nothing was paid
        $r = RazorpayGateway::webhook($this->sign((string) $body), (string) $body, 'evt_1');
        $this->assertSame(['status' => 200, 'outcome' => 'pending'], $r);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c'], 'the claim in the body is not believed');
        // once Razorpay itself reports the payment, the same webhook records it exactly once, even when delivered twice
        $this->fakeRazorpay($calls, ['paid' => 100000]);
        $this->assertSame(['status' => 200, 'outcome' => 'completed'], RazorpayGateway::webhook($this->sign((string) $body), (string) $body, 'evt_2'));
        RazorpayGateway::webhook($this->sign((string) $body), (string) $body, 'evt_2');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM gateway_webhook_events WHERE provider = 'razorpay'")['c'], 'events are logged once per event id');
        // unknown or malformed bodies are acknowledged without effect
        $this->assertSame(['status' => 200, 'outcome' => 'ignored'], RazorpayGateway::webhook($this->sign('not json'), 'not json'));
        $none = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_X']]]]);
        $this->assertSame(['status' => 200, 'outcome' => 'ignored'], RazorpayGateway::webhook($this->sign((string) $none), (string) $none));
        $unk = json_encode(['payload' => ['payment_link' => ['entity' => ['id' => 'plink_Unknown0001', 'notes' => ['merchant_order_id' => 'KIS99X0123456789AB']]]]]);
        $this->assertSame(['status' => 200, 'outcome' => 'unknown_order'], RazorpayGateway::webhook($this->sign((string) $unk), (string) $unk));
    }

    public function testWebhookIsRefusedWhenNoWebhookSecretIsSaved(): void
    {
        $this->razorpayOn(false);
        $this->assertSame(401, RazorpayGateway::webhook('', '{}')['status']);
        $this->assertSame(401, RazorpayGateway::webhook(hash_hmac('sha256', '{}', ''), '{}')['status'], 'an empty secret can never match');
    }

    public function testReconcilePendingPicksUpOrdersTheCustomerNeverReturnedFrom(): void
    {
        $calls = [];
        $this->razorpayOn();
        $this->fakeRazorpay($calls, ['upi' => 'upi://pay?pa=x@icici&am=1000.00&tr=Q']);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv, 100000);
        $this->fakeRazorpay($calls, ['paid' => 100000]);
        $this->assertSame(0, RazorpayGateway::reconcilePending(), 'too new: the customer may still be paying');
        Database::run('UPDATE gateway_orders SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)');
        $this->assertSame(1, RazorpayGateway::reconcilePending());
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(0, RazorpayGateway::reconcilePending());
        Config::set('razorpay.enabled', false);
        $this->assertSame(0, RazorpayGateway::reconcilePending());
    }

    public function testStatusExplainsWhatIsMissing(): void
    {
        $this->assertFalse(RazorpayGateway::status()['ready']);
        $this->razorpayOn(false);
        $s = RazorpayGateway::status();
        $this->assertTrue($s['ready']);
        $this->assertStringContainsString('webhook', $s['reason']);
        $this->assertStringContainsString('Test mode', $s['reason']);
        $this->assertSame('test', RazorpayGateway::mode());
        Config::set('razorpay.key_id', 'rzp_live_Abc');
        $this->assertSame('live', RazorpayGateway::mode());
        Config::set('app.url', 'http://insecure.test');
        $this->assertFalse(RazorpayGateway::status()['ready']);
        $this->assertFalse(RazorpayGateway::enabled());
    }

    // ------------------------------------------------------------------------------------------------ Standard Web Checkout

    private function checkoutSig(string $order, string $pay, string $secret = 'SECRETKEY1'): string
    {
        return hash_hmac('sha256', $order . '|' . $pay, $secret);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} the invoice and a checkout request for it */
    private function checkoutRequest(array &$calls, array $o = []): array
    {
        $this->razorpayOn();
        $this->fakeRazorpay($calls, $o);
        $inv = $this->issuedInvoice('1000');
        $r = RazorpayGateway::createRequest($this->finance, $inv, 100000, '', 'checkout');
        $this->assertSame('checkout', $r['flow']);
        $this->assertSame([], $calls, 'nothing is sent to Razorpay until the client presses Pay');

        return [$inv, $r];
    }

    public function testCheckoutCreatesOneRazorpayOrderForTheExactAmountAndNeverExposesTheSecret(): void
    {
        $calls = [];
        [, $r] = $this->checkoutRequest($calls);
        $d = RazorpayGateway::createCheckoutOrder($r);
        $this->assertSame(['order_id' => 'order_TestOrder01', 'amount' => 100000, 'currency' => 'INR'], array_intersect_key($d, ['order_id' => 1, 'amount' => 1, 'currency' => 1]));
        $this->assertSame('rzp_test_AbCd1234', $d['key_id']);
        $this->assertStringNotContainsString('SECRETKEY1', json_encode($d));
        $this->assertSame(100000, $this->lastBodies[0]['amount']);
        $this->assertSame($r['merchant_order_id'], $this->lastBodies[0]['receipt']);
        $r = RazorpayGateway::findRequest($r['public_id']);
        RazorpayGateway::createCheckoutOrder($r);
        $this->assertCount(1, array_filter($calls, fn ($c) => str_ends_with($c, '/orders')), 'pressing Pay twice reuses the same Razorpay order');
    }

    public function testCheckoutErrorsMapToTheRightCodes(): void
    {
        $calls = [];
        [, $r] = $this->checkoutRequest($calls, ['orderHttp' => 401]);
        foreach ([['orderHttp' => 401, 401], ['orderHttp' => 400, 500], ['orderHttp' => 500, 500]] as $case) {
            $this->fakeRazorpay($calls, ['orderHttp' => $case['orderHttp']]);
            try {
                RazorpayGateway::createCheckoutOrder($r);
                $this->fail('expected an error');
            } catch (\RuntimeException $e) {
                $this->assertSame($case[0], $e->getCode());
            }
        }
        $this->fakeRazorpay($calls, ['echo' => 500]); // Razorpay answers for another amount
        try {
            RazorpayGateway::createCheckoutOrder($r);
            $this->fail('expected an error');
        } catch (\RuntimeException $e) {
            $this->assertSame(500, $e->getCode());
        }
        Database::run("UPDATE gateway_orders SET amount_minor = 50 WHERE id = ?", [$r['id']]);
        try {
            RazorpayGateway::createCheckoutOrder(RazorpayGateway::findRequest($r['public_id']));
            $this->fail('expected an error');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode(), 'below Rs 1.00');
        }
        Database::run("UPDATE gateway_orders SET status = 'cancelled' WHERE id = ?", [$r['id']]);
        $this->expectExceptionCode(400);
        RazorpayGateway::createCheckoutOrder(RazorpayGateway::findRequest($r['public_id']));
    }

    public function testACheckoutPaymentIsRecordedOnlyWithAValidSignatureForTheExactCapturedAmount(): void
    {
        $calls = [];
        [$inv, $r] = $this->checkoutRequest($calls);
        RazorpayGateway::createCheckoutOrder($r);
        $r = RazorpayGateway::findRequest($r['public_id']);
        $good = $this->checkoutSig('order_TestOrder01', 'pay_CheckoutPay01');

        // malformed / missing fields and wrong signatures: 400 and nothing is recorded
        foreach ([['', 'order_TestOrder01', $good], ['pay_CheckoutPay01', '', $good], ['pay_CheckoutPay01', 'order_TestOrder01', ''],
            ['pay_CheckoutPay01', 'order_TestOrder01', $this->checkoutSig('order_TestOrder01', 'pay_CheckoutPay01', 'WRONG')],
            ['pay_CheckoutPay01', 'order_OtherOrder9', $this->checkoutSig('order_OtherOrder9', 'pay_CheckoutPay01')]] as [$pay, $ord, $sig]) {
            $v = RazorpayGateway::verifyCheckout($r, $pay, $ord, $sig);
            $this->assertFalse($v['ok']);
            $this->assertSame(400, $v['status']);
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);

        // a correct signature for a payment of another amount is still refused
        $this->fakeRazorpay($calls, ['payAmount' => 5000]);
        $this->assertSame(400, RazorpayGateway::verifyCheckout($r, 'pay_CheckoutPay01', 'order_TestOrder01', $good)['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);

        // authorized payments are captured, then recorded
        $this->fakeRazorpay($calls, ['payStatus' => 'authorized']);
        $v = RazorpayGateway::verifyCheckout($r, 'pay_CheckoutPay01', 'order_TestOrder01', $good);
        $this->assertTrue($v['ok']);
        $this->assertTrue($v['recorded']);
        $this->assertNotEmpty(array_filter($calls, fn ($c) => str_ends_with($c, '/capture')));
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame('Razorpay pay_CheckoutPay01', Database::one('SELECT external_ref FROM client_payments')['external_ref']);

        // verifying again never doubles the payment
        $v = RazorpayGateway::verifyCheckout(RazorpayGateway::findRequest($r['public_id']), 'pay_CheckoutPay01', 'order_TestOrder01', $good);
        $this->assertFalse($v['recorded']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
    }

    public function testAnUncapturedCheckoutPaymentWaitsAndRecordsNothing(): void
    {
        $calls = [];
        [$inv, $r] = $this->checkoutRequest($calls, ['payStatus' => 'created']);
        RazorpayGateway::createCheckoutOrder($r);
        $r = RazorpayGateway::findRequest($r['public_id']);
        $v = RazorpayGateway::verifyCheckout($r, 'pay_CheckoutPay01', 'order_TestOrder01', $this->checkoutSig('order_TestOrder01', 'pay_CheckoutPay01'));
        $this->assertFalse($v['ok']);
        $this->assertSame(202, $v['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status']);
    }
}
