<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\TicketAccess;
use Vertex\Services\TicketService;
use Vertex\Services\WarrantyService;

/** The Support Centre over real HTTP: pages, forms, CSRF, escaping, direct URLs, downloads, warranty screens, privacy of internal data. */
final class TicketHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** @return array<string,mixed> */
    private function mkBug(array $over = [], ?array $file = null): array
    {
        $res = TicketService::createBug($this->client, $over + ['project' => $this->alpha['public_id'], 'title' => 'Contact form fails', 'description' => 'The form shows an error message on sending.',
            'steps' => 'Open the contact page and press send.', 'expected' => 'Thank you page', 'actual' => 'Red error banner', 'severity' => 'normal', 'token' => bin2hex(random_bytes(16))], $file === null ? [] : [$file], false, 'UA', false);

        return TicketAccess::find($res['public_id']);
    }

    private function mkSupport(string $cat = 'billing', string $subject = 'A billing question'): array
    {
        $res = TicketService::createSupport($this->client, ['category' => $cat, 'subject' => $subject, 'description' => 'Please explain the charge on my last statement.', 'project' => $this->alpha['public_id']], [], false);

        return TicketAccess::find($res['public_id']);
    }

    private function url(array $t, string $suffix = ''): string
    {
        return '/tickets/' . $t['public_id'] . $suffix;
    }

    public function testClientReportsABugThroughTheFormWithAScreenshot(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        $home = $c->get('/support');
        $this->assertSame(200, $home['status']);
        $this->assertStringContainsString('Report a bug', $home['body']);
        $this->assertStringContainsString('Create a ticket', $home['body']);
        $this->assertStringContainsString('Website warranty', $home['body']);
        $nav = $c->get('/dashboard')['body'];
        $this->assertStringContainsString('href="/support"', $nav);
        $this->assertStringNotContainsString('/support/dashboard', $nav);
        $form = $c->get('/support/bugs/new');
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('Alpha Website', $form['body']);
        $this->assertStringNotContainsString('Bravo Video', $form['body'], 'only the client\'s own projects are offered');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $tk);
        $shot = $this->png('screenshot.png', 'x');
        $fields = ['project' => $this->alpha['public_id'], 'title' => 'Checkout button missing', 'description' => 'The button on the cart page is gone.', 'steps' => 'Add a product and open the cart.',
            'expected' => 'A checkout button', 'actual' => 'No button', 'affected_url' => 'https://alpha.example/cart', 'browser' => 'Chrome 127', 'device' => 'Android phone', 'severity' => 'high', 'token' => $tk[1], 'action' => 'submit'];
        $r = $c->upload('/support/bugs', $fields, ['files[0]' => [$shot['tmp_name'], 'screenshot.png', 'image/png']], '/support/bugs/new');
        $this->assertSame(303, $r['status']);
        $this->assertStringStartsWith('/tickets/', (string) $r['location']);
        $again = $c->upload('/support/bugs', $fields, ['files[0]' => [$shot['tmp_name'], 'screenshot.png', 'image/png']], '/support/bugs/new');
        $this->assertSame($r['location'], $again['location'], 'a resent form returns the same report');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM tickets')['c']);
        $page = $c->get((string) $r['location']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('BUG-', $page['body']);
        $this->assertStringContainsString('screenshot.png', $page['body']);
        $this->assertStringContainsString('Warranty review', $page['body']);
        $this->assertStringContainsString('does not automatically mean it is covered', $page['body']);
        $list = $c->get('/tickets');
        $this->assertStringContainsString('Checkout button', $list['body']);
        $this->assertGreaterThanOrEqual(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'bug.submitted'")['c']);
    }

    public function testDraftSaveAndLaterSubmission(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        $form = $c->get('/support/bugs/new');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $tk);
        $r = $c->upload('/support/bugs', ['project' => $this->alpha['public_id'], 'title' => 'Half-finished', 'token' => $tk[1], 'action' => 'draft'], [], '/support/bugs/new');
        $this->assertSame(303, $r['status']);
        $t = Database::one('SELECT * FROM tickets');
        $this->assertSame('draft', $t['status']);
        $this->assertSame(404, $this->loggedIn('sup@x.test')->get('/tickets/' . $t['public_id'])['status'], 'staff cannot open a draft');
        $this->assertSame(404, $this->loggedIn('admin@x.test')->get('/tickets/' . $t['public_id'])['status']);
        $page = $c->get('/tickets/' . $t['public_id']);
        $this->assertStringContainsString('Half-finished', $page['body']);
        $this->assertStringContainsString('Save as draft', $page['body']);
        $post = $c->post('/tickets/' . $t['public_id'] . '/draft', ['project' => $this->alpha['public_id'], 'title' => 'Half-finished', 'action' => 'submit'], '/support');
        $this->assertSame(303, $post['status']);
        $this->assertSame('draft', Database::one('SELECT status FROM tickets')['status'], 'incomplete: still a draft');
        $this->assertStringContainsString('Description', $c->get('/tickets/' . $t['public_id'])['body']);
        $ok = $c->post('/tickets/' . $t['public_id'] . '/draft', ['project' => $this->alpha['public_id'], 'title' => 'Finished report', 'description' => 'The page crashes when I click.', 'steps' => 'Click the big button.',
            'expected' => 'A page opens', 'actual' => 'Blank page', 'severity' => 'normal', 'action' => 'submit'], '/support');
        $this->assertSame(303, $ok['status']);
        $this->assertSame('open', Database::one('SELECT status FROM tickets')['status']);
        $this->assertSame(200, $this->loggedIn('sup@x.test')->get('/tickets/' . $t['public_id'])['status']);
    }

    public function testCsrfIsRequiredOnEveryTicketMutation(): void
    {
        $t = $this->mkBug();
        $c = $this->loggedIn('alpha@client.test');
        foreach (['/support/bugs', '/support/tickets', $this->url($t, '/reply'), $this->url($t, '/status'), $this->url($t, '/draft')] as $p) {
            $this->assertSame(419, $c->postNoToken($p, ['body' => 'x'])['status'], $p);
        }
        $m = $this->loggedIn('mgr@x.test');
        foreach (['/assign', '/severity', '/priority', '/escalate', '/notes', '/eligibility', '/propose-change'] as $s) {
            $this->assertSame(419, $m->postNoToken($this->url($t, $s), ['x' => 'y'])['status'], $s);
        }
        $this->assertSame('open', Database::one('SELECT status FROM tickets')['status']);
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        $t = $this->mkBug();
        $a = $this->client();
        foreach (['/support', '/tickets', $this->url($t), '/support/dashboard', '/warranties', '/support/bugs/new', '/projects/' . $this->alpha['public_id'] . '/warranty'] as $p) {
            $r = $a->get($p);
            $this->assertSame(303, $r['status'], $p);
            $this->assertSame('/login', $r['location'], $p);
        }
    }

    public function testDirectUrlAccessAcrossClientsAndRoles(): void
    {
        $t = $this->mkBug();
        $bill = $this->mkSupport('billing', 'Billing thing');
        $tech = $this->mkSupport('technical_support', 'Tech thing');
        foreach (['bravo@client.test', 'partner@x.test', 'mgr2@x.test', 'dev2@x.test'] as $who) {
            $c = $this->loggedIn($who);
            foreach ([$t, $bill, $tech] as $x) {
                // 404 when the person holds a ticket permission but not this ticket; 403 at the first gate when they hold none at all
                $this->assertContains($c->get($this->url($x))['status'], [403, 404], "{$who} must be refused {$x['reference']}");
                $this->assertContains($c->post($this->url($x, '/reply'), ['body' => 'let me in'], '/account')['status'], [403, 404], "{$who} reply");
                $this->assertContains($c->post($this->url($x, '/status'), ['status' => 'closed', 'note' => 'closing it'], '/account')['status'], [403, 404], "{$who} status");
            }
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM ticket_messages')['c']);
        // worker on the project: technical yes, billing no
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(200, $dev->get($this->url($t))['status']);
        $this->assertSame(200, $dev->get($this->url($tech))['status']);
        $this->assertSame(404, $dev->get($this->url($bill))['status']);
        $this->assertStringNotContainsString('Billing thing', $dev->get('/tickets')['body']);
        // junk ids
        $c = $this->loggedIn('alpha@client.test');
        foreach (['not-an-id', str_repeat('0', 32), '1', '..%2f..', str_repeat('a', 300)] as $junk) {
            $this->assertSame(404, $c->get('/tickets/' . $junk)['status'], $junk);
        }
    }

    public function testStaffOnlyScreensAreClosedToClientsAndWorkers(): void
    {
        $this->mkBug();
        foreach (['alpha@client.test', 'dev@x.test', 'partner@x.test'] as $who) {
            $c = $this->loggedIn($who);
            $this->assertContains($c->get('/support/dashboard')['status'], [403, 404], "{$who} dashboard");
            $this->assertContains($c->get('/warranties')['status'], [403, 404], "{$who} warranties");
        }
        foreach (['mgr@x.test', 'sup@x.test', 'admin@x.test'] as $who) {
            $c = $this->loggedIn($who);
            $this->assertSame(200, $c->get('/support/dashboard')['status'], $who);
            $this->assertSame(200, $c->get('/tickets')['status'], $who);
            $this->assertSame(200, $c->get('/warranties')['status'], $who);
        }
        $this->assertContains($this->loggedIn('partner@x.test')->get('/support')['status'], [403, 404]);
        $this->assertContains($this->loggedIn('dev@x.test')->get('/support/bugs/new')['status'], [403, 404]);
        $this->assertContains($this->loggedIn('admin@x.test')->get('/support/bugs/new')['status'], [403, 404], 'administrators do not file client bug reports');
    }

    public function testInternalContentNeverAppearsOnTheClientPages(): void
    {
        $t = $this->mkBug(['title' => 'Layout broken']);
        $m = $this->loggedIn('mgr@x.test');
        $m->post($this->url($t, '/notes'), ['body' => 'INTERNAL-NOTE-MARKER root cause is the plugin', 'kind' => 'technical_finding'], $this->url($t));
        $m->post($this->url($t, '/priority'), ['priority' => 'urgent', 'reason' => 'INTERNAL-PRIORITY-MARKER'], $this->url($t));
        $m->post($this->url($t, '/escalate'), ['reason' => 'INTERNAL-ESCALATION-MARKER'], $this->url($t));
        $m->post($this->url($t, '/assign'), ['assignee' => Database::one('SELECT public_id FROM users WHERE id = ?', [$this->manager])['public_id']], $this->url($t));
        $m->post($this->url($t, '/reply'), ['body' => 'PUBLIC-REPLY-MARKER we are on it', 'token' => bin2hex(random_bytes(16))], $this->url($t));
        $staffPage = $m->get($this->url($t))['body'];
        foreach (['INTERNAL-NOTE-MARKER', 'INTERNAL-ESCALATION-MARKER', 'PUBLIC-REPLY-MARKER', 'Internal notes'] as $needle) {
            $this->assertStringContainsString($needle, $staffPage, "staff page shows {$needle}");
        }
        $c = $this->loggedIn('alpha@client.test');
        $clientPages = $c->get($this->url($t))['body'] . $c->get('/tickets')['body'] . $c->get('/support')['body'] . $c->get('/notifications')['body'];
        $this->assertStringContainsString('PUBLIC-REPLY-MARKER', $clientPages);
        foreach (['INTERNAL-NOTE-MARKER', 'INTERNAL-PRIORITY-MARKER', 'INTERNAL-ESCALATION-MARKER', 'Internal notes', 'Priority (internal)', 'Escalated', 'mgr@x.test', 'Assignee', 'staff only'] as $needle) {
            $this->assertStringNotContainsString($needle, $clientPages, "client pages must not contain {$needle}");
        }
        // the client cannot reach the staff actions even by posting to them directly
        foreach (['/notes' => ['body' => 'CLIENT-NOTE'], '/priority' => ['priority' => 'low', 'reason' => 'my reason'], '/assign' => ['assignee' => ''], '/escalate' => ['reason' => 'because'], '/severity' => ['severity' => 'low', 'reason' => 'my view'],
            '/eligibility' => ['decision' => 'covered', 'reason' => 'I say it is covered please'], '/propose-change' => ['amount' => '1']] as $s => $form) {
            $this->assertContains($c->post($this->url($t, $s), $form, '/support')['status'], [403, 404], $s);
        }
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM ticket_notes WHERE body LIKE '%CLIENT-NOTE%'")['c']);
        $this->assertSame('urgent', Database::one('SELECT priority FROM tickets')['priority']);
    }

    public function testAttachmentDownloadsAreAuthorisedPerRoleAndVisibility(): void
    {
        $t = $this->mkBug([], $this->png('evidence.png', 'e1'));
        TicketService::attachInternal($this->manager, $t, $this->png('staff-only.png', 'e2'), false);
        $pub = Database::one("SELECT f.public_id FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.visibility = 'client'")['public_id'];
        $int = Database::one("SELECT f.public_id FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.visibility = 'internal'")['public_id'];
        $c = $this->loggedIn('alpha@client.test');
        $url = $this->url($t, '/files/' . $pub);
        $this->assertSame(200, $c->get($url)['status']);
        $h = $c->headersOf($url);
        $this->assertStringStartsWith('attachment;', $h['content-disposition']);
        $this->assertSame('application/octet-stream', $h['content-type']);
        $this->assertSame('nosniff', $h['x-content-type-options']);
        $this->assertStringNotContainsString(VERTEX_ROOT, implode("\n", $h));
        $this->assertSame(404, $c->get($this->url($t, '/files/' . $int))['status'], 'the client cannot download an internal file');
        $this->assertSame(404, $c->get($this->url($t, '/files/' . str_repeat('0', 32)))['status']);
        $this->assertSame(404, $c->get($this->url($t, '/files/..%2f..%2f.env'))['status']);
        foreach (['bravo@client.test', 'partner@x.test', 'mgr2@x.test', 'dev2@x.test'] as $who) {
            $o = $this->loggedIn($who);
            $this->assertContains($o->get($url)['status'], [403, 404], "{$who} must not download");
            $this->assertContains($o->get($this->url($t, '/files/' . $int))['status'], [403, 404], "{$who} must not download the internal file");
        }
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($this->url($t, '/files/' . $int))['status']);
        $this->assertSame(200, $this->loggedIn('dev@x.test')->get($url)['status']);
        $this->assertSame(303, $this->client()->get($url)['status'], 'anonymous: sent to sign in');
        $other = $this->mkBug(['title' => 'Other bug'], $this->png('other.png', 'o'));
        $opub = Database::one('SELECT f.public_id FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.ticket_id = ?', [$other['id']])['public_id'];
        $this->assertSame(404, $c->get($this->url($t, '/files/' . $opub))['status'], 'a file of another ticket is not reachable through this one');
        $this->assertGreaterThanOrEqual(3, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'ticket.file_downloaded'")['c']);
    }

    public function testUserTextIsEscapedEverywhere(): void
    {
        $x = '<script>alert("x")</script>';
        $t = $this->mkBug(['description' => "D {$x}", 'steps' => "S {$x}", 'expected' => "E {$x}", 'actual' => "A {$x}", 'affected_url' => 'https://a.example/p?q=1&r=x']);
        $m = $this->loggedIn('mgr@x.test');
        $m->post($this->url($t, '/notes'), ['body' => "N {$x}"], $this->url($t));
        $c = $this->loggedIn('alpha@client.test');
        $c->post($this->url($t, '/reply'), ['body' => "R {$x}", 'token' => bin2hex(random_bytes(16))], $this->url($t));
        foreach ([$c->get($this->url($t))['body'], $m->get($this->url($t))['body'], $c->get('/tickets')['body'], $m->get('/tickets')['body'], $m->get('/support/dashboard')['body']] as $html) {
            $this->assertStringNotContainsString('<script>alert', $html);
        }
        $this->assertStringContainsString('&lt;script&gt;', $m->get($this->url($t))['body']);
    }

    public function testClientRepliesConfirmsAndReopensThroughTheScreens(): void
    {
        $t = $this->mkBug();
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(303, $m->post($this->url($t, '/status'), ['status' => 'triaged'], $this->url($t))['status']);
        $m->post($this->url($t, '/status'), ['status' => 'confirmed'], $this->url($t));
        $m->post($this->url($t, '/status'), ['status' => 'in_progress'], $this->url($t));
        $m->post($this->url($t, '/status'), ['status' => 'fixed', 'note' => 'Patched in release 1.4'], $this->url($t));
        $m->post($this->url($t, '/status'), ['status' => 'client_verification'], $this->url($t));
        $this->assertSame('client_verification', Database::one('SELECT status FROM tickets')['status']);
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get($this->url($t))['body'];
        $this->assertStringContainsString('Please check the fix', $page);
        $this->assertStringContainsString('Nothing is closed until you confirm', $page);
        // reopening without an explanation is refused
        $c->post($this->url($t, '/status'), ['status' => 'open', 'note' => ''], $this->url($t));
        $this->assertSame('client_verification', Database::one('SELECT status FROM tickets')['status']);
        $this->assertStringContainsString('Your explanation', $c->get($this->url($t))['body']);
        $c->post($this->url($t, '/status'), ['status' => 'open', 'note' => 'The layout is still broken on my phone.'], $this->url($t));
        $this->assertSame('open', Database::one('SELECT status FROM tickets')['status']);
        // and a reply by the client alone never closes
        $c->post($this->url($t, '/reply'), ['body' => 'Thanks for the quick work!', 'token' => bin2hex(random_bytes(16))], $this->url($t));
        $this->assertSame('open', Database::one('SELECT status FROM tickets')['status']);
        $m->post($this->url($t, '/status'), ['status' => 'closed', 'note' => 'Closing after confirming with the client.'], $this->url($t));
        $this->assertSame('closed', Database::one('SELECT status FROM tickets')['status']);
        $this->assertStringContainsString('Reopen', $c->get($this->url($t))['body']);
    }

    public function testWarrantyScreensAndTheAdministratorOnlyConfiguration(): void
    {
        $url = '/projects/' . $this->alpha['public_id'] . '/warranty';
        $admin = $this->loggedIn('admin@x.test');
        $page = $admin->get($url);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Pending Confirmation', $page['body']);
        $this->assertStringContainsString('has not been confirmed', $page['body']);
        $this->assertStringContainsString('Record or change the warranty', $page['body']);
        // managers and support can look but not change
        foreach (['mgr@x.test', 'sup@x.test'] as $who) {
            $c = $this->loggedIn($who);
            $this->assertSame(200, $c->get($url)['status'], $who);
            $this->assertStringNotContainsString('Record or change the warranty', $c->get($url)['body'], $who);
            $this->assertSame(403, $c->post($url, ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-01-01', 'start_basis' => 'final_delivery', 'confirm_password' => self::PASSWORD], '/account')['status'], $who);
        }
        $this->assertContains($this->loggedIn('dev@x.test')->get($url)['status'], [403, 404], 'workers hold no warranty permission');
        $this->assertContains($this->loggedIn('bravo@client.test')->get($url)['status'], [403, 404]);
        $this->assertContains($this->loggedIn('mgr2@x.test')->get($url)['status'], [403, 404]);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM project_warranties')['c']);
        // the password is required even for an administrator
        $form = ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-20), 'start_basis' => 'client_acceptance', 'quotation_ref' => 'QT-77', 'admin_notes' => 'ADMIN-ONLY-WARRANTY-NOTE'];
        $admin->post($url, $form + ['confirm_password' => 'wrong-password'], $url);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM project_warranties')['c']);
        $this->assertSame(303, $admin->post($url, $form + ['confirm_password' => self::PASSWORD], $url)['status']);
        $w = Database::one('SELECT * FROM project_warranties');
        $this->assertSame(WarrantyService::endDate(AppTime::todayPlus(-20), 6), $w['end_date']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'warranty.configured'")['c']);
        $view = $admin->get($url)['body'];
        $this->assertStringContainsString('Active', $view);
        $this->assertStringContainsString('ADMIN-ONLY-WARRANTY-NOTE', $view);
        // client sees facts but never admin notes; other clients see nothing of it
        $c = $this->loggedIn('alpha@client.test');
        foreach ([$c->get($url)['body'], $c->get('/support/warranty')['body'], $c->get('/support')['body']] as $html) {
            $this->assertStringNotContainsString('ADMIN-ONLY-WARRANTY-NOTE', $html);
            $this->assertStringContainsString('Active', $html);
        }
        $this->assertStringNotContainsString('Alpha Website', $this->loggedIn('bravo@client.test')->get('/support/warranty')['body']);
        // report lists it and filters
        $rep = $this->loggedIn('mgr@x.test')->get('/warranties?status=active')['body'];
        $this->assertStringContainsString('Alpha Website', $rep);
        $this->assertStringNotContainsString('Bravo Video', $rep, 'a manager sees only assigned projects');
        $this->assertStringContainsString('Alpha Website', $admin->get('/warranties?expiring=1')['body'] . $admin->get('/warranties?status=active')['body']);
        $this->assertStringNotContainsString('Record a new version of the warranty terms', $this->loggedIn('mgr@x.test')->get('/warranties')['body']);
        $this->assertStringContainsString('Record a new version of the warranty terms', $admin->get('/warranties')['body']);
    }

    public function testReviewAndChargeableFlowOnTheScreens(): void
    {
        $t = $this->mkBug(['title' => 'New feature request']);
        $m = $this->loggedIn('mgr@x.test');
        $r = $m->post($this->url($t, '/eligibility'), ['decision' => 'potentially_chargeable', 'reason' => 'This asks for a new feature outside the agreed scope.', 'terms' => 'Warranty terms v1', 'considerations' => ['new_feature', 'scope_change']], $this->url($t));
        $this->assertSame(303, $r['status']);
        $this->assertSame('potentially_chargeable', Database::one('SELECT eligibility FROM bug_reports')['eligibility']);
        $staff = $m->get($this->url($t))['body'];
        $this->assertStringContainsString('Additional cost proposal', $staff);
        $this->assertStringContainsString('Request for a new feature', $staff);
        $p = $m->post($this->url($t, '/propose-change'), ['scope_description' => 'Build the requested feature', 'client_reason' => 'Not part of the agreed pages', 'amount' => '12000', 'days' => '5'], $this->url($t));
        $this->assertSame(303, $p['status']);
        $this->assertStringStartsWith('/changes/', (string) $p['location']);
        $c = $this->loggedIn('alpha@client.test');
        $this->assertSame(404, $c->get((string) $p['location'])['status'], 'the client cannot see a draft proposal');
        $client = $c->get($this->url($t))['body'];
        $this->assertStringContainsString('Potentially chargeable', $client);
        $this->assertStringContainsString('may be outside your warranty', $client);
        $this->assertStringNotContainsString('12,000', $client);
        $m->post((string) $p['location'] . '/send', [], (string) $p['location']);
        $page = $c->get((string) $p['location']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('12,000', $page['body']);
        $this->assertStringContainsString('Nothing is charged or invoiced', $page['body']);
        $this->assertStringContainsString(' ' . $t['reference'], $page['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get((string) $p['location'])['status']);
        $this->assertContains($this->loggedIn('dev@x.test')->get((string) $p['location'])['status'], [403, 404], 'workers do not see financial proposals');
        $c->post((string) $p['location'] . '/decision', ['decision' => 'approve', 'comment' => 'ok'], (string) $p['location']);
        $this->assertSame('approved', Database::one('SELECT status FROM change_requests')['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
    }

    public function testQueueFiltersAndTheDashboardRenderForStaff(): void
    {
        $this->mkBug(['title' => 'Alpha broken thing']);
        $this->mkSupport('billing', 'Invoice question');
        $m = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Alpha broken thing', $m->get('/tickets?q=broken')['body']);
        $this->assertStringNotContainsString('Invoice question', $m->get('/tickets?q=broken')['body']);
        $this->assertStringContainsString('Invoice question', $m->get('/tickets?category=billing')['body']);
        $this->assertStringContainsString('Invoice question', $m->get('/tickets?group=unassigned&kind=support')['body']);
        $this->assertSame(200, $m->get("/tickets?status=x'%20OR%201=1&group=%27;--&page=-3&from=zzz")['status']);
        $dash = $m->get('/support/dashboard')['body'];
        $this->assertStringContainsString('Unassigned', $dash);
        $this->assertStringContainsString('No response-time target is configured', $dash);
        $this->assertStringContainsString('Website warranties', $dash);
        $mgr = $this->loggedIn('mgr@x.test')->get('/support/dashboard')['body'];
        $this->assertStringContainsString('Open', $mgr);
        $this->assertStringContainsString('Tickets', $m->get('/dashboard')['body']);
    }

    public function testAllNewPagesAreServedWithTheSecurityHeaders(): void
    {
        $t = $this->mkBug();
        $c = $this->loggedIn('alpha@client.test');
        foreach (['/support', '/support/bugs/new', '/support/tickets/new', '/tickets', $this->url($t), '/support/warranty'] as $p) {
            $h = $c->headersOf($p);
            $this->assertArrayHasKey('content-security-policy', $h, $p);
            $this->assertStringContainsString("script-src 'self'", $h['content-security-policy'], $p);
            $this->assertSame('nosniff', $h['x-content-type-options'] ?? '', $p);
        }
        $this->assertStringNotContainsString(' style="', $c->get($this->url($t))['body'] . $c->get('/support')['body'], 'no inline styles (CSP)');
        $this->assertStringNotContainsString('onclick=', $c->get($this->url($t))['body']);
    }
}
