<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\ClientApprovalService;
use Vertex\Services\DeliverableService;
use Vertex\Services\RateLimiter;
use Vertex\Tests\Support\HttpClient;

/** Deliverables over real HTTP: uploads, authorised downloads, visibility on every screen, approvals with CSRF and confirmation. */
final class DeliverableHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    protected static function extraEnv(): array
    {
        return ['UPLOAD_MAX_MB' => '1'];
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

    private function up(HttpClient $c, string $path, array $form, array $file, string $formPage = '/account'): array
    {
        return $c->upload($path, $form, ['file' => $file], $formPage);
    }

    private function fileArgs(array $f, string $mime = 'image/png'): array
    {
        return [$f['tmp_name'], $f['name'], $mime];
    }

    private function url(array $d, string $suffix = ''): string
    {
        return '/deliverables/' . $d['public_id'] . $suffix;
    }

    private function dlUrl(array $d, int $no = 1): string
    {
        return $this->url($d, '/versions/' . $this->ver($d, $no)['public_id'] . '/download');
    }

    // ---- uploads ------------------------------------------------------------------------------------------------------------

    public function testUploadPermissionsAndTheDraftIsNotPublished(): void
    {
        $path = '/projects/' . $this->alpha['public_id'] . '/deliverables';
        $form = ['title' => 'Homepage mock-up', 'category' => 'graphic_design', 'description' => 'First pass'];
        foreach (['alpha@client.test', 'sup@x.test', 'dev2@x.test', 'mgr2@x.test', 'partner@x.test'] as $who) {
            $r = $this->up($this->loggedIn($who), $path, $form, $this->fileArgs($this->png()));
            $this->assertContains($r['status'], [403, 404], "{$who} must not be able to upload");
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM deliverables')['c']);
        $anon = $this->client();
        $this->assertSame(419, $this->client()->postNoToken($path, $form)['status'], 'CSRF is required');
        $this->assertSame(303, $anon->get($path)['status'], 'anonymous visitors are sent to sign in');

        $dev = $this->loggedIn('dev@x.test');
        $r = $this->up($dev, $path, $form, $this->fileArgs($this->png('mockup.png')));
        $this->assertSame(303, $r['status']);
        $this->assertStringStartsWith('/deliverables/', (string) $r['location']);
        $d = Database::one('SELECT * FROM deliverables');
        $this->assertSame(['draft', $this->dev], [$d['status'], (int) $d['created_by']]);
        $v = Database::one('SELECT * FROM deliverable_versions');
        $this->assertSame(['draft', null], [$v['status'], $v['published_at']]);
        $stored = Database::one('SELECT * FROM stored_files');
        $this->assertSame('mockup.png', $stored['original_name']);
        $this->assertFileExists(\Vertex\Services\FileStore::root() . '/' . $stored['stored_path']);
        $this->assertStringContainsString('Homepage mock-up', $dev->get('/projects/' . $this->alpha['public_id'] . '/deliverables')['body']);
        $this->assertStringNotContainsString('Homepage mock-up', $this->loggedIn('alpha@client.test')->get('/projects/' . $this->alpha['public_id'] . '/deliverables')['body'], 'the client sees nothing of a draft');
        $this->assertStringNotContainsString('Homepage mock-up', $this->loggedIn('alpha@client.test')->get('/my/deliverables')['body']);
    }

    public function testDisallowedTypesOversizedFilesAndTraversalNames(): void
    {
        $dev = $this->loggedIn('dev@x.test');
        $path = '/projects/' . $this->alpha['public_id'] . '/deliverables';
        $form = ['title' => 'Bad upload', 'category' => 'other'];
        $filesBefore = glob(\Vertex\Services\FileStore::root() . '/' . gmdate('Y/m') . '/*') ?: [];
        $bad = [
            $this->tmp('shell.php', '<?php system($_GET["x"]);'), $this->tmp('page.html', '<script>alert(1)</script>'), $this->tmp('x.png', "MZ fake exe"),
            $this->png('logo.php.png'), $this->tmp('empty.png', ''),
        ];
        foreach ($bad as $f) {
            $r = $this->up($dev, $path, $form, $this->fileArgs($f));
            $this->assertSame(303, $r['status']);
            $this->assertSame($path, $r['location'], 'returned to the form with an error');
            $this->assertStringContainsString('alert-error', $dev->get($path)['body'], "{$f['name']}: a clear validation message is shown");
        }
        $this->up($dev, $path, $form, $this->fileArgs($this->tmp('shell.php', '<?php echo 1;')));
        $this->assertStringContainsString('not allowed', $dev->get($path)['body']);
        $big = $this->png('huge.png', str_repeat('A', 1_300_000)); // limit is 1 MB for this server
        $r = $this->up($dev, $path, $form, $this->fileArgs($big));
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('larger than', $dev->get($path)['body']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM deliverables')['c']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM stored_files')['c']);
        $this->assertSame($filesBefore, glob(\Vertex\Services\FileStore::root() . '/' . gmdate('Y/m') . '/*') ?: [], 'rejected uploads leave nothing in storage');
        // traversal-style names are neutralised, never used as a path
        $f = $this->png();
        $r = $this->up($dev, $path, ['title' => 'Traversal name', 'category' => 'other'], [$f['tmp_name'], '../../../public/pwn.png', 'image/png']);
        $this->assertSame(303, $r['status']);
        $row = Database::one('SELECT * FROM stored_files');
        $this->assertSame('pwn.png', $row['original_name']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $row['stored_path']);
        $this->assertFileDoesNotExist(VERTEX_ROOT . '/public/pwn.png');
        $this->assertFileDoesNotExist(VERTEX_ROOT . '/pwn.png');
    }

    // ---- downloads ----------------------------------------------------------------------------------------------------------

    public function testDownloadAuthorisationMatrixAndSecureHeaders(): void
    {
        $d = $this->makeDeliverable(['title' => 'Logo set'], $this->dev, $this->png('logo-final.png', 'logo'));
        $url = $this->dlUrl($d);
        // draft: only people on the team who may see drafts
        $r = $this->loggedIn('dev@x.test')->get($url);
        $this->assertSame(200, $r['status']);
        $this->assertSame(file_get_contents($this->png()['tmp_name']) . 'logo', $r['body']);
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($url)['status']);
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get($url)['status']);
        foreach (['alpha@client.test' => 'client of the project (draft)', 'bravo@client.test' => 'another client', 'dev2@x.test' => 'worker on another project', 'mgr2@x.test' => 'unassigned manager',
            'sup@x.test' => 'support has no draft access', 'partner@x.test' => 'referral partner'] as $who => $why) {
            $this->assertContains($this->loggedIn($who)->get($url)['status'], [403, 404], "{$why} must not download a draft");
        }
        $this->assertSame(303, $this->client()->get($url)['status'], 'anonymous: sent to sign in');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'deliverable.downloaded' AND actor_id IN (" . $this->client . ',' . $this->client2 . ',' . $this->dev2 . ')')['c']);

        $this->publishVersion($d, 1);
        $c = $this->loggedIn('alpha@client.test');
        $ok = $c->get($url);
        $this->assertSame(200, $ok['status'], 'the owning client can download a published version');
        $h = $c->headersOf($url);
        $this->assertSame('application/octet-stream', $h['content-type'], 'never served as its own type');
        $this->assertStringStartsWith('attachment;', $h['content-disposition']);
        $this->assertStringContainsString('logo-final.png', $h['content-disposition']);
        $this->assertSame('nosniff', $h['x-content-type-options']);
        $this->assertStringContainsString('no-store', $h['cache-control']);
        $this->assertArrayNotHasKey('x-powered-by', $h);
        $this->assertStringNotContainsString('storage', implode("\n", $h), 'no filesystem path in any header');
        $this->assertStringNotContainsString(VERTEX_ROOT, $ok['body']);
        foreach (['bravo@client.test', 'dev2@x.test', 'mgr2@x.test', 'partner@x.test'] as $who) {
            $this->assertContains($this->loggedIn($who)->get($url)['status'], [403, 404], "{$who}");
        }
        $this->assertSame(200, $this->loggedIn('sup@x.test')->get($url)['status'], 'support may download published work');
        $this->assertSame(6, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'deliverable.downloaded' AND entity_id = " . $d['id'])['c'], 'dev, mgr, admin, the client (twice) and support: each successful download is audited, no refused one is');
    }

    public function testUnpublishedVersionsAreNotDownloadableEvenWithTheirRealIds(): void
    {
        $d = $this->makeDeliverable(['title' => 'Two versions'], $this->manager, $this->png('v1.png', '1'));
        $this->publishVersion($d, 1);
        DeliverableService::addVersion($this->manager, $this->freshD($d), [], $this->png('v2-secret.png', 'two'), false);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertSame(200, $c->get($this->dlUrl($d, 1))['status']);
        $this->assertSame(404, $c->get($this->dlUrl($d, 2))['status'], 'the client cannot fetch the unpublished V2 by guessing its id');
        $this->assertStringNotContainsString('v2-secret', $c->get($this->url($d))['body'], 'its name is not on the page either');
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($this->dlUrl($d, 2))['status']);
        // wrong/forged ids
        $v2 = $this->ver($d, 2)['public_id'];
        foreach ([$this->url($d, '/versions/' . str_repeat('0', 32) . '/download'), $this->url($d, '/versions/not-an-id/download'), $this->url($d, '/versions/1/download'), $this->url($d, '/versions/../download'), '/deliverables/' . str_repeat('0', 32) . '/versions/' . $v2 . '/download'] as $p) {
            $this->assertSame(404, $c->get($p)['status'], $p);
        }
        // a version id from ANOTHER deliverable cannot be used through this deliverable's URL
        $other = $this->makeDeliverable(['title' => 'Other one']);
        $this->publishVersion($other, 1);
        $this->assertSame(404, $c->get($this->url($d, '/versions/' . $this->ver($other, 1)['public_id'] . '/download'))['status']);
        // stored files cannot be reached by their storage path
        $stored = Database::one('SELECT stored_path FROM stored_files LIMIT 1')['stored_path'];
        foreach (['/storage/uploads/' . $stored, '/uploads/' . $stored, '/' . $stored, '/../storage/uploads/' . $stored] as $p) {
            $this->assertSame(404, $c->get($p)['status'], "direct URL {$p} must not serve a file");
        }
    }

    public function testDownloadsAreRateLimited(): void
    {
        $d = $this->makeDeliverable([], $this->manager);
        $this->publishVersion($d, 1);
        for ($i = 0; $i < 205; $i++) {
            RateLimiter::hit('download_user', (string) $this->client);
        }
        $this->assertSame(429, $this->loggedIn('alpha@client.test')->get($this->dlUrl($d))['status']);
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($this->dlUrl($d))['status'], 'the limit is per user');
    }

    public function testExternalLinkVersionsHaveNoDownloadButAreShown(): void
    {
        $res = DeliverableService::create($this->manager, $this->alphaRow(), ['title' => 'Prototype', 'category' => 'website_files', 'external_url' => 'https://www.figma.com/file/abc/Proto'], null, false);
        $d = \Vertex\Services\DeliverablePolicy::find($res['public_id']);
        $this->publishVersion($d, 1);
        $page = $this->loggedIn('alpha@client.test')->get($this->url($d))['body'];
        $this->assertStringContainsString('https://www.figma.com/file/abc/Proto', $page);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $page);
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get($this->dlUrl($d))['status'], 'there is no file to download');
    }

    // ---- client screens -----------------------------------------------------------------------------------------------------

    public function testClientScreensNeverLeakInternalMaterial(): void
    {
        $d = $this->makeDeliverable(['title' => 'Intro video', 'description' => 'Public description', 'change_notes' => 'LEAK-CHANGE-NOTE', 'label' => 'Final cut'], $this->dev);
        $draftOnly = $this->makeDeliverable(['title' => 'LEAK-DRAFT-ONLY-TITLE'], $this->dev);
        DeliverableService::submitForReview($this->dev, $this->freshD($d), $this->ver($d, 1));
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'changes_requested', 'LEAK-QA-DISCUSSION fix the audio');
        DeliverableService::submitForReview($this->dev, $this->freshD($d), $this->ver($d, 1));
        DeliverableService::review($this->admin, $this->freshD($d), $this->ver($d, 1), 'approved', 'LEAK-QA-OK');
        DeliverableService::publish($this->admin, $this->freshD($d), $this->ver($d, 1));
        DeliverableService::addVersion($this->manager, $this->freshD($d), ['change_notes' => 'LEAK-V2-NOTES'], $this->png('LEAK-V2-FILE.png', 'v2'), false);
        $c = $this->loggedIn('alpha@client.test');
        $pages = [
            '/my/deliverables' => $c->get('/my/deliverables'), 'project' => $c->get('/projects/' . $this->alpha['public_id'] . '/deliverables'), 'detail' => $c->get($this->url($d)),
            'compare' => $c->get($this->url($d, '/compare')), 'timeline' => $c->get('/projects/' . $this->alpha['public_id'] . '/timeline'), 'project overview' => $c->get('/projects/' . $this->alpha['public_id']),
            'notifications' => $c->get('/notifications'), 'revisions' => $c->get('/revisions'),
        ];
        foreach ($pages as $name => $r) {
            $this->assertSame(200, $r['status'], $name);
            foreach (['LEAK-', 'Internal QA', 'internal review', 'Approved Internally', 'Pending Review', 'Draft', 'staff only', 'change_notes'] as $leak) {
                $this->assertStringNotContainsString($leak, $r['body'], "{$name} must not contain '{$leak}'");
            }
        }
        $this->assertStringContainsString('Intro video', $pages['/my/deliverables']['body']);
        $this->assertStringContainsString('Ready for your review', $pages['/my/deliverables']['body']);
        $this->assertStringContainsString('Public description', $pages['detail']['body']);
        $this->assertStringNotContainsString('V2', $pages['detail']['body'], 'the unpublished V2 does not exist for the client');
        $this->assertSame(404, $c->get($this->url($draftOnly))['status']);
        $this->assertSame(404, $c->get($this->url($draftOnly, '/compare'))['status']);
        $this->assertSame(404, $c->get($this->url($draftOnly, '/respond'))['status']);
        $this->assertSame(404, $c->get($this->url($draftOnly, '/revisions/new'))['status']);
    }

    public function testEmptyStatesAndStaffOnlyScreensAreClosedToClients(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        $this->assertStringContainsString('No deliverables published yet', $c->get('/my/deliverables')['body']);
        foreach (['/deliverables', '/qa'] as $p) {
            $r = $c->get($p);
            $this->assertContains($r['status'], [303, 403], $p);
        }
        $this->assertSame(403, $c->get('/admin/revision-policies')['status']);
        $this->assertSame(403, $c->post('/projects/' . $this->alpha['public_id'] . '/deliverables', ['title' => 'Nope'], '/account')['status']);
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(403, $dev->get('/qa')['status'], 'workers do not review');
        $this->assertSame(200, $dev->get('/deliverables')['status']);
        $this->assertStringContainsString('No deliverables match', $dev->get('/deliverables')['body']);
        $this->assertSame(403, $this->loggedIn('partner@x.test')->get('/deliverables')['status']);
        $this->assertSame(403, $this->loggedIn('partner@x.test')->get('/my/deliverables')['status']);
    }

    // ---- workflow over HTTP -------------------------------------------------------------------------------------------------

    public function testStaffWorkflowPermissionsOverHttp(): void
    {
        $d = $this->makeDeliverable(['title' => 'Workflow test'], $this->dev);
        $vp = $this->ver($d, 1)['public_id'];
        $base = $this->url($d, '/versions/' . $vp);
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(303, $dev->post($base . '/submit', [], $this->url($d))['status']);
        $this->assertSame('pending_review', $this->ver($d, 1)['status']);
        $this->assertSame(403, $dev->post($base . '/review', ['decision' => 'approved'], $this->url($d))['status'], 'worker cannot approve');
        $this->assertSame(403, $dev->post($base . '/publish', [], $this->url($d))['status'], 'worker cannot publish');
        $this->assertSame(403, $dev->post($this->url($d, '/archive'), [], $this->url($d))['status'], 'worker cannot archive');
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post($base . '/publish', [], '/account')['status']);
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->post($base . '/submit', [], '/account')['status']);
        $mgr = $this->loggedIn('mgr@x.test');
        $mgr->post($base . '/review', ['decision' => 'approved', 'notes' => ''], $this->url($d));
        $this->assertSame('approved_internal', $this->ver($d, 1)['status']);
        $this->assertNull($this->freshD($d)['first_published_at'], 'approval did not publish');
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get($this->url($d))['status']);
        $mgr->post($base . '/publish', [], $this->url($d));
        $this->assertSame('published', $this->ver($d, 1)['status']);
        $this->assertSame(200, $this->loggedIn('alpha@client.test')->get($this->url($d))['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c']);
        // the staff detail page shows controls only for what the viewer may do
        $page = $dev->get($this->url($d))['body'];
        $this->assertStringNotContainsString('Publish to client', $page);
        $this->assertStringContainsString('Add a new version', $page);
    }

    public function testClientApprovalWithConfirmationScreenAndCsrf(): void
    {
        $d = $this->makeDeliverable(['title' => 'Approve me'], $this->manager);
        $this->publishVersion($d, 1);
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get($this->url($d));
        $this->assertStringContainsString('Approve this version', $page['body']);
        $this->assertStringContainsString('Request a revision', $page['body']);
        $confirm = $c->get($this->url($d, '/respond?action=approve'));
        $this->assertSame(200, $confirm['status']);
        $this->assertStringContainsString('V1 only', $confirm['body'], 'the confirmation spells out exactly what is approved');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c'], 'viewing the confirmation records nothing');
        preg_match('/name="version" value="([a-f0-9]{32})"/', $confirm['body'], $v);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $confirm['body'], $t);
        $form = ['action' => 'approved', 'version' => $v[1], 'token' => $t[1], 'feedback' => 'Looks great'];
        $this->assertSame(419, $c->postNoToken($this->url($d, '/respond'), $form)['status'], 'CSRF');
        $this->assertSame(303, $c->post($this->url($d, '/respond'), $form, $this->url($d, '/respond?action=approve'))['status']);
        $this->assertSame(303, $c->post($this->url($d, '/respond'), $form, $this->url($d, '/respond?action=approve'))['status'], 'a double submit is harmless');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c']);
        $after = $c->get($this->url($d));
        $this->assertStringContainsString('You approved this version', $after['body']);
        $this->assertStringContainsString('Looks great', $after['body']);
        // staff and other users cannot respond for the client
        foreach (['admin@x.test', 'mgr@x.test', 'bravo@client.test', 'partner@x.test'] as $who) {
            $r = $this->loggedIn($who)->post($this->url($d, '/respond'), ['action' => 'approved', 'version' => $v[1], 'token' => bin2hex(random_bytes(16))], '/account');
            $this->assertContains($r['status'], [403, 404], $who);
        }
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c']);
        $this->assertContains($this->loggedIn('admin@x.test')->get($this->url($d, '/respond?action=approve'))['status'], [403, 404]);
    }

    public function testRejectionRequiresAReasonOverHttp(): void
    {
        $d = $this->makeDeliverable(['title' => 'Reject me'], $this->manager);
        $v = $this->publishVersion($d, 1);
        $c = $this->loggedIn('alpha@client.test');
        $c->post($this->url($d, '/respond'), ['action' => 'rejected', 'version' => $v['public_id'], 'token' => bin2hex(random_bytes(16)), 'feedback' => ''], $this->url($d, '/respond?action=reject'));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM client_approvals')['c']);
        $this->assertStringContainsString('Reason is required', $c->get($this->url($d, '/respond?action=reject'))['body']);
        $c->post($this->url($d, '/respond'), ['action' => 'rejected', 'version' => $v['public_id'], 'token' => bin2hex(random_bytes(16)), 'feedback' => 'Wrong brand colours'], $this->url($d, '/respond?action=reject'));
        $this->assertSame('rejected', ClientApprovalService::stateFor($this->freshD($d))['key']);
        $this->assertStringContainsString('Wrong brand colours', $this->loggedIn('mgr@x.test')->get($this->url($d))['body'], 'staff see the client feedback');
    }

    public function testOutputIsEscapedAndNavigationMatchesTheRole(): void
    {
        $d = $this->makeDeliverable(['title' => 'Escape test', 'description' => '<img src=x onerror=alert(1)> & "quotes"'], $this->manager);
        $this->publishVersion($d, 1);
        foreach (['mgr@x.test', 'alpha@client.test'] as $who) {
            $body = $this->loggedIn($who)->get($this->url($d))['body'];
            $this->assertStringNotContainsString('<img src=x', $body);
            $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $body, 'CSP forbids inline script');
        }
        $client = $this->loggedIn('alpha@client.test')->get('/my/deliverables')['body'];
        $this->assertStringContainsString('My deliverables', $client);
        $this->assertStringNotContainsString('>QA<', $client);
        $this->assertStringNotContainsString('Revision policy', $client);
        $staff = $this->loggedIn('admin@x.test')->get('/deliverables')['body'];
        $this->assertStringContainsString('>QA<', $staff);
        $this->assertStringNotContainsString('My deliverables', $staff);
    }

    public function testNotificationsPageIsPersonalAndMarkingReadOnlyAffectsTheOwner(): void
    {
        $d = $this->makeDeliverable([], $this->manager);
        $this->publishVersion($d, 1);
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/notifications');
        $this->assertStringContainsString('A deliverable is ready for your review', $page['body']);
        $this->assertStringContainsString('They are not emailed', $page['body']);
        $this->assertStringContainsString('Notifications (1)', $c->get('/dashboard')['body'], 'unread count in the navigation');
        $mine = (int) Database::one('SELECT id FROM notifications WHERE user_id = ?', [$this->client])['id'];
        $other = $this->loggedIn('bravo@client.test');
        $this->assertStringNotContainsString('ready for your review', $other->get('/notifications')['body'], "another client's notifications are not shown");
        $other->post('/notifications/read', ['id' => (string) $mine], '/notifications');
        $this->assertNull(Database::one('SELECT read_at FROM notifications WHERE id = ?', [$mine])['read_at'], "another user cannot mark someone else's notification read");
        $c->post('/notifications/read', ['id' => (string) $mine], '/notifications');
        $this->assertNotNull(Database::one('SELECT read_at FROM notifications WHERE id = ?', [$mine])['read_at']);
        $this->assertStringNotContainsString('Notifications (', $c->get('/dashboard')['body']);
    }
}
