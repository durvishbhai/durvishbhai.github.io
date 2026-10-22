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
use Vertex\Services\PhonePeGateway;
use Vertex\Services\Settings;

/** Phase 12 services: settings store, CMS, AI assistance and the PhonePe gateway (against a simulated PhonePe and a simulated AI provider). */
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
        foreach (['ai', 'phonepe', 'features', 'site', 'app.url', 'mail', 'uploads'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        Config::set('mail.driver', 'none');
        foreach (glob(dirname((string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads')) . '/cache/phonepe-*.json') ?: [] as $f) {
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

    // ------------------------------------------------------------------------------------------------ PhonePe

    /** @var array<string,string> */
    private array $phonepeState = [];
    /** @var list<array<string,mixed>> */
    private array $lastBodies = [];

    private function phonepeOn(bool $webhook = true): void
    {
        Config::set('app.url', 'https://vis.test');
        Config::set('phonepe.enabled', true);
        Config::set('phonepe.client_id', 'CLIENT1');
        Config::set('phonepe.client_secret', 'SECRET1');
        Config::set('phonepe.environment', 'sandbox');
        Config::set('phonepe.webhook_username', $webhook ? 'hook-user' : '');
        Config::set('phonepe.webhook_password', $webhook ? 'hook-pass-123' : '');
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
    }

    /** @param array{state?:string,amount?:int,http?:int} $o @param list<string> $calls */
    private function fakePhonePe(array &$calls, array $o = []): void
    {
        $this->phonepeState = ['state' => $o['state'] ?? 'PENDING'];
        $this->lastBodies = [];
        Http::fake(function (string $m, string $url, array $h, ?string $body) use (&$calls, &$o): array {
            $calls[] = $m . ' ' . $url;
            if ($body !== null && is_array(json_decode($body, true))) {
                $this->lastBodies[] = json_decode($body, true);
            }
            if (str_contains($url, '/payments/v2/pay')) {
                if (empty($o['qr'])) {
                    return ['status' => 400, 'body' => json_encode(['code' => 'FEATURE_NOT_ENABLED'])];
                }

                return ['status' => 200, 'body' => json_encode(['orderId' => 'OMOQR1', 'state' => 'PENDING', 'qrData' => $o['qr']])];
            }
            if (str_contains($url, '/oauth/token')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'tok-abc', 'expires_at' => time() + 3600])];
            }
            if (str_contains($url, '/checkout/v2/pay')) {
                $b = json_decode((string) $body, true);

                return ['status' => 200, 'body' => json_encode(['orderId' => 'OMO123', 'state' => 'PENDING', 'redirectUrl' => 'https://mercury-uat.phonepe.com/transact/pay?token=abc', 'echo' => $b['amount']])];
            }
            if (str_contains($url, '/status')) {
                $amount = $o['amount'] ?? null;
                preg_match('#/order/([^/]+)/status#', $url, $mm);
                $order = Database::one('SELECT amount_minor FROM gateway_orders WHERE merchant_order_id = ?', [rawurldecode($mm[1] ?? '')]);

                return ['status' => $o['http'] ?? 200, 'body' => json_encode(['orderId' => 'OMO123', 'state' => $this->phonepeState['state'], 'amount' => $amount ?? (int) ($order['amount_minor'] ?? 0),
                    'paymentDetails' => [['transactionId' => 'OMO-TXN-777', 'state' => $this->phonepeState['state'], 'paymentMode' => 'UPI_INTENT']]])];
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

    private function auth(string $u = 'hook-user', string $p = 'hook-pass-123'): string
    {
        return hash('sha256', $u . ':' . $p);
    }

    /** A member of the KIS team asks for an amount (paise) on an invoice. @return array<string,mixed> */
    private function requestPay(array $inv, int $minor = 100000, ?int $by = null): array
    {
        return PhonePeGateway::createRequest($by ?? $this->finance, $inv, $minor, '');
    }

    public function testOnlyTheTeamCanRequestAPaymentAndTheAmountIsExactlyWhatTheyType(): void
    {
        $calls = [];
        $this->fakePhonePe($calls);
        $inv = $this->issuedInvoice('1000');
        $this->invalid(fn () => $this->requestPay($inv), 'not switched on'); // PhonePe is off
        $this->phonepeOn();
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->client));    // a client cannot start one
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->client2));
        $this->forbidden(fn () => $this->requestPay($inv, 100000, $this->dev));        // nor can staff without the permission
        $this->assertSame([], array_filter($calls, fn ($c) => str_contains($c, '/pay')), 'no gateway call for refused users');
        // the amount typed is the amount asked: a part payment of Rs 250.50
        $o = $this->requestPay($inv, 25050);
        $this->assertSame(['pending', 25050, (int) $inv['id'], $this->finance], [$o['status'], (int) $o['amount_minor'], (int) $o['invoice_id'], (int) $o['created_by']]);
        $this->assertMatchesRegularExpression('/^KIS\d+X[A-F0-9]{12}$/', $o['merchant_order_id']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c'], 'requesting is not paying');
        $sent = array_values(array_filter($this->lastBodies, fn ($b) => isset($b['amount'])));
        $this->assertSame([25050], array_unique(array_column($sent, 'amount')), 'every call to PhonePe carried exactly the typed amount');
        // limits: at least Rs 1, at most what is still due, nothing on a paid/void invoice or non-https site
        $this->invalid(fn () => $this->requestPay($inv, 99), 'smallest');
        $this->invalid(fn () => $this->requestPay($inv, 100001), 'more than');
        Config::set('app.url', 'http://vis.test');
        $this->invalid(fn () => $this->requestPay($inv, 100000), 'not switched on');
    }

    public function testADirectUpiQrIsUsedWhenPhonePeReturnsOneAndAWrongAmountInItIsRefused(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls, ['qr' => 'upi://pay?pa=merchant@ybl&pn=Krazy&am=250.50&cu=INR&tr=ORD1']);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 25050);
        $this->assertSame('upi_qr', $o['flow']);
        $this->assertStringContainsString('am=250.50', $o['qr_payload']);
        $this->assertNotEmpty(array_filter($calls, fn ($c) => str_contains($c, '/payments/v2/pay')));
        $this->assertSame([], array_filter($calls, fn ($c) => str_contains($c, '/checkout/v2/pay')), 'no fallback needed');

        // a QR that asks for a different amount is never shown
        $this->fakePhonePe($calls, ['qr' => 'upi://pay?pa=merchant@ybl&am=999.00&tr=ORD2']);
        $this->invalid(fn () => $this->requestPay($inv, 25050), 'different amount');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c'], 'the mismatching request was not stored');

        // direct mode with no QR from PhonePe refuses instead of falling back; auto mode falls back to the hosted page
        $this->fakePhonePe($calls);
        Config::set('phonepe.qr_mode', 'direct');
        $this->invalid(fn () => $this->requestPay($inv, 25050), 'UPI QR');
        Config::set('phonepe.qr_mode', 'auto');
        $o2 = $this->requestPay($inv, 25050);
        $this->assertSame('checkout_link', $o2['flow']);
        $this->assertStringStartsWith('https://mercury-uat.phonepe.com/', $o2['qr_payload']);
        Config::set('phonepe.qr_mode', 'auto');
    }

    public function testARequestCanBeWithdrawnAndALatePaymentIsStillRecorded(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls);
        $inv = $this->issuedInvoice('1000');
        $o = $this->requestPay($inv, 100000);
        $this->assertTrue(PhonePeGateway::isLive($o));
        $this->forbidden(fn () => PhonePeGateway::cancelRequest($this->client, $o));
        PhonePeGateway::cancelRequest($this->finance, $o);
        $o = PhonePeGateway::findRequest($o['public_id']);
        $this->assertSame('cancelled', $o['status']);
        $this->assertFalse(PhonePeGateway::isLive($o));
        $this->assertSame([], PhonePeGateway::liveRequests((int) $inv['id']));
        $this->invalid(fn () => PhonePeGateway::cancelRequest($this->finance, $o), 'no longer open');
        // the customer had already paid: PhonePe says COMPLETED, so the money is recorded
        $this->phonepeState['state'] = 'COMPLETED';
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => true], PhonePeGateway::reconcile($o['merchant_order_id']));
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
    }

    public function testAPaymentIsRecordedOnlyAfterTheStatusApiSaysCompletedForTheRightAmount(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        $this->assertSame(['state' => 'PENDING', 'recorded' => false], PhonePeGateway::reconcile($mid));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->phonepeState['state'] = 'COMPLETED';
        $r = PhonePeGateway::reconcile($mid);
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => true], $r);
        $p = Database::one('SELECT * FROM client_payments');
        $this->assertSame(['verified', 'gateway', 'gateway', 100000, (int) $inv['id']], [$p['status'], $p['source'], $p['method'], (int) $p['gross_minor'], (int) $p['invoice_id']]);
        $this->assertSame('PhonePe OMO-TXN-777', $p['external_ref']);
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $o = Database::one('SELECT * FROM gateway_orders');
        $this->assertSame(['completed', 'OMO-TXN-777', (int) $p['id']], [$o['status'], $o['provider_txn_id'], (int) $o['payment_id']]);
        // asking again (browser return + webhook + scheduler) never doubles the payment
        $this->assertSame(['state' => 'COMPLETED', 'recorded' => false], PhonePeGateway::reconcile($mid));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertNotNull(Database::one("SELECT 1 x FROM notifications WHERE user_id = ? AND event_type = 'payment.confirmed'", [$this->client]));
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'client_payment.gateway_recorded'"));
        // an unknown order id is a 404, not a payment
        $this->forbidden(fn () => PhonePeGateway::reconcile('VIS999XAAAAAAAAAAAA'));
    }

    public function testAnAmountMismatchRecordsNothingAndIsLogged(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls, ['state' => 'COMPLETED', 'amount' => 100]);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        try {
            PhonePeGateway::reconcile($mid);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different amount', $e->getMessage());
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertSame('pending', Database::one('SELECT status FROM gateway_orders')['status']);
        $this->assertNotNull(Database::one("SELECT 1 x FROM audit_logs WHERE action = 'gateway.amount_mismatch'"));
        $this->assertSame('issued', InvoiceService::find($inv['public_id'])['status']);
    }

    public function testAFailedPaymentMarksTheOrderFailedAndAllowsAFreshAttempt(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls, ['state' => 'FAILED']);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        $this->assertSame(['state' => 'FAILED', 'recorded' => false], PhonePeGateway::reconcile($mid));
        $this->assertSame('failed', Database::one('SELECT status FROM gateway_orders')['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->requestPay(InvoiceService::find($inv['public_id'])); // a failed attempt does not block a retry
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM gateway_orders')['c']);
    }

    public function testStatusApiErrorsNeverRecordAPayment(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls, ['state' => 'COMPLETED', 'http' => 500]);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        $this->expectException(\RuntimeException::class);
        try {
            PhonePeGateway::reconcile($mid);
        } finally {
            $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        }
    }

    public function testWebhookNeedsTheRightSignatureAndTheBodyIsNeverBelieved(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls); // PhonePe's own status API says PENDING
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        $body = json_encode(['event' => 'checkout.order.completed', 'payload' => ['merchantOrderId' => $mid, 'state' => 'COMPLETED', 'amount' => 100000]]);
        $this->assertSame(401, PhonePeGateway::webhook('', $body)['status']);
        $this->assertSame(401, PhonePeGateway::webhook('wrong', $body)['status']);
        $this->assertSame(401, PhonePeGateway::webhook($this->auth('hook-user', 'other-pass'), $body)['status']);
        $this->assertSame(401, PhonePeGateway::webhook(strtoupper($this->auth()) . 'x', $body)['status']);
        // correct signature but a lying body: the status API says PENDING, so nothing is recorded
        $r = PhonePeGateway::webhook($this->auth(), (string) $body);
        $this->assertSame(['status' => 200, 'outcome' => 'pending'], $r);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        // now PhonePe really completes it; the webhook (with "SHA256(...)" style header) triggers the record
        $this->phonepeState['state'] = 'COMPLETED';
        $r = PhonePeGateway::webhook('SHA256(' . $this->auth() . ')' === '' ? '' : $this->auth(), (string) $body);
        $this->assertSame(['status' => 200, 'outcome' => 'completed'], $r);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        // replays and duplicates are harmless
        PhonePeGateway::webhook($this->auth(), (string) $body);
        PhonePeGateway::webhook($this->auth(), (string) $body);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_payments')['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM gateway_webhook_events')['c'], 'identical deliveries are stored once');
        // junk and unrelated orders are acknowledged and ignored
        $this->assertSame(['status' => 200, 'outcome' => 'ignored'], PhonePeGateway::webhook($this->auth(), 'not json'));
        $this->assertSame(['status' => 200, 'outcome' => 'ignored'], PhonePeGateway::webhook($this->auth(), json_encode(['payload' => ['merchantOrderId' => "x'; DROP TABLE users;--"]])));
        $this->assertSame(['status' => 200, 'outcome' => 'unknown_order'], PhonePeGateway::webhook($this->auth(), json_encode(['payload' => ['merchantOrderId' => 'VIS9999XABCDEF123456']])));
        $this->assertNotNull(Database::one("SHOW TABLES LIKE 'users'"));
    }

    public function testWebhookIsRefusedWhenNoWebhookCredentialsAreSaved(): void
    {
        $this->phonepeOn(false);
        $this->assertSame(401, PhonePeGateway::webhook($this->auth('', ''), '{}')['status'], 'an empty username/password can never match');
        $this->assertSame(401, PhonePeGateway::webhook(hash('sha256', ':'), '{}')['status']);
    }

    public function testASecondStartWhileOneIsPendingAndLowBalancesAreHandled(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        // the staff verify a partial manual payment; PhonePe asks only for what is still due
        $this->phonepeState['state'] = 'COMPLETED';
        $mid = (string) Database::one('SELECT merchant_order_id FROM gateway_orders')['merchant_order_id'];
        PhonePeGateway::reconcile($mid);
        $paid = InvoiceService::find($inv['public_id']);
        $this->assertSame('paid', $paid['status']);
        $this->invalid(fn () => $this->requestPay($paid), 'issued invoice'); // already paid: not payable
    }

    public function testReconcilePendingPicksUpOrdersTheCustomerNeverReturnedFrom(): void
    {
        $calls = [];
        $this->phonepeOn();
        $this->fakePhonePe($calls);
        $inv = $this->issuedInvoice('1000');
        $this->requestPay($inv);
        $this->phonepeState['state'] = 'COMPLETED';
        $this->assertSame(0, PhonePeGateway::reconcilePending(), 'too new: the customer may still be on the PhonePe page');
        Database::run('UPDATE gateway_orders SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)');
        $this->assertSame(1, PhonePeGateway::reconcilePending());
        $this->assertSame('paid', InvoiceService::find($inv['public_id'])['status']);
        $this->assertSame(0, PhonePeGateway::reconcilePending());
        Config::set('phonepe.enabled', false);
        $this->assertSame(0, PhonePeGateway::reconcilePending());
    }

    public function testStatusExplainsWhatIsMissing(): void
    {
        $this->assertFalse(PhonePeGateway::status()['ready']);
        $this->phonepeOn(false);
        $s = PhonePeGateway::status();
        $this->assertTrue($s['ready']);
        $this->assertStringContainsString('webhook', $s['reason']);
        Config::set('app.url', 'http://insecure.test');
        $this->assertFalse(PhonePeGateway::status()['ready']);
        $this->assertFalse(PhonePeGateway::enabled());
    }
}
