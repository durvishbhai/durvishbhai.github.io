<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\RevisionAccess;
use Vertex\Services\RevisionService;
use Vertex\Services\RevisionTerms;

/** Revision requests, cost proposals and revision policy over real HTTP. */
final class RevisionHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    /** @var array<string,mixed> */
    private array $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->d = $this->makeDeliverable(['title' => 'Explainer video']);
        $this->publishVersion($this->d, 1);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** @return array<string,mixed> */
    private function submitAsClient(string $text = 'Please shorten the intro by ten seconds.'): array
    {
        $res = RevisionService::submit($this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => $text], [], false);

        return RevisionAccess::find($res['public_id']);
    }

    private function chargeable(array $r): array
    {
        RevisionService::transition($this->support, $r, 'under_review');
        RevisionService::classify($this->manager, RevisionAccess::find($r['public_id']), 'chargeable', ['reason' => 'INTERNAL-REASON: new scene not in storyboard', 'scope_description' => 'Shoot one extra scene', 'client_reason' => 'The agreed storyboard has no such scene', 'amount' => '7500', 'days' => '2']);

        return RevisionAccess::findChange(Database::one('SELECT public_id FROM change_requests ORDER BY id DESC LIMIT 1')['public_id']);
    }

    public function testClientSubmitsARevisionWithAnAttachmentAndSeesItsStatus(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        $form = $c->get('/deliverables/' . $this->d['public_id'] . '/revisions/new');
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('V1', $form['body']);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $t);
        preg_match('/name="version" value="([a-f0-9]{32})"/', $form['body'], $v);
        $f = $this->png('reference.png', 'ref');
        $post = fn () => $c->upload('/deliverables/' . $this->d['public_id'] . '/revisions', ['description' => 'The logo needs to be bigger.', 'token' => $t[1], 'version' => $v[1]], ['files[0]' => [$f['tmp_name'], 'reference.png', 'image/png']], '/deliverables/' . $this->d['public_id'] . '/revisions/new');
        $r1 = $post();
        $this->assertSame(303, $r1['status']);
        $this->assertStringStartsWith('/revisions/', (string) $r1['location']);
        $r2 = $post(); // the browser re-sent the form
        $this->assertSame($r1['location'], $r2['location'], 'the same request is returned');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM revision_files')['c']);
        $page = $c->get((string) $r1['location']);
        $this->assertStringContainsString('REV-', $page['body']);
        $this->assertStringContainsString('Received', $page['body']);
        $this->assertStringContainsString('The logo needs to be bigger.', $page['body']);
        $this->assertStringContainsString('reference.png', $page['body']);
        $this->assertStringContainsString('Received', $c->get('/deliverables/' . $this->d['public_id'])['body'], 'the deliverable page lists the client\'s request with its status');
        $this->assertStringContainsString('REV-', $c->get('/revisions')['body']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'revision.submitted' AND actor_id <> " . $this->client)['c']);
        // the revision form also refuses short text and bad files with a message
        $c->upload('/deliverables/' . $this->d['public_id'] . '/revisions', ['description' => 'short', 'token' => bin2hex(random_bytes(16)), 'version' => $v[1]], [], '/deliverables/' . $this->d['public_id'] . '/revisions/new');
        $this->assertStringContainsString('Describe the change', $c->get('/deliverables/' . $this->d['public_id'] . '/revisions/new')['body']);
        $bad = $this->tmp('evil.php', '<?php echo 1;');
        $c->upload('/deliverables/' . $this->d['public_id'] . '/revisions', ['description' => 'A valid description here', 'token' => bin2hex(random_bytes(16)), 'version' => $v[1]], ['files[0]' => [$bad['tmp_name'], 'evil.php', 'application/x-php']], '/deliverables/' . $this->d['public_id'] . '/revisions/new');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c']);
    }

    public function testOnlyTheClientCanOpenTheFormAndOnlyForPublishedWork(): void
    {
        $url = '/deliverables/' . $this->d['public_id'] . '/revisions/new';
        foreach (['bravo@client.test' => [404], 'mgr@x.test' => [403], 'admin@x.test' => [403], 'dev@x.test' => [403], 'partner@x.test' => [403]] as $who => $codes) {
            $this->assertContains($this->loggedIn($who)->get($url)['status'], [403, 404], $who);
        }
        $draft = $this->makeDeliverable(['title' => 'Unpublished work']);
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get('/deliverables/' . $draft['public_id'] . '/revisions/new')['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM revision_requests')['c']);
    }

    public function testStaffQueueDetailAndClientIsolation(): void
    {
        $r = $this->submitAsClient();
        $url = '/revisions/' . $r['public_id'];
        foreach (['sup@x.test', 'mgr@x.test', 'admin@x.test'] as $who) {
            $page = $this->loggedIn($who)->get($url);
            $this->assertSame(200, $page['status'], $who);
            $this->assertStringContainsString('Client\'s request', $page['body']);
            $this->assertStringContainsString('Internal notes', $page['body']);
        }
        $this->assertStringContainsString($r['reference'], $this->loggedIn('sup@x.test')->get('/revisions')['body']);
        $this->assertStringContainsString($r['reference'], $this->loggedIn('sup@x.test')->get('/revisions?status=submitted')['body']);
        $this->assertStringNotContainsString($r['reference'], $this->loggedIn('sup@x.test')->get('/revisions?status=resolved')['body']);
        foreach (['bravo@client.test', 'dev@x.test', 'dev2@x.test', 'mgr2@x.test', 'partner@x.test'] as $who) {
            $got = $this->loggedIn($who)->get($url);
            $this->assertContains($got['status'], [403, 404], $who);
            $this->assertStringNotContainsString('Please shorten the intro', $got['body']);
            $this->assertStringNotContainsString($r['reference'], $this->loggedIn($who)->get('/revisions')['body'] ?? '');
        }
        foreach (['/revisions/' . str_repeat('0', 32), '/revisions/xyz', '/revisions/' . $r['public_id'] . '/files/' . str_repeat('1', 32)] as $p) {
            $this->assertSame(404, $this->loggedIn('alpha@client.test')->get($p)['status'], $p);
        }
        $client = $this->loggedIn('alpha@client.test')->get($url)['body'];
        foreach (['Internal notes', 'Classification', 'Included or chargeable', 'staff only', 'Administrator override'] as $leak) {
            $this->assertStringNotContainsString($leak, $client, "the client page must not contain '{$leak}'");
        }
    }

    public function testStatusMovesAreEnforcedOverHttp(): void
    {
        $r = $this->submitAsClient();
        $url = '/revisions/' . $r['public_id'];
        $client = $this->loggedIn('alpha@client.test');
        $this->assertSame(403, $client->post($url . '/status', ['status' => 'under_review'], $url)['status'], 'the client cannot start a review');
        $this->assertSame(403, $client->post($url . '/classify', ['decision' => 'included', 'reason' => 'I classify my own request'], $url)['status']);
        $this->assertSame(403, $client->post($url . '/notes', ['body' => 'client note attempt'], $url)['status']);
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post($url . '/status', ['status' => 'under_review'], '/account')['status']);
        $this->assertSame('submitted', RevisionAccess::find($r['public_id'])['status']);
        $sup = $this->loggedIn('sup@x.test');
        $sup->post($url . '/status', ['status' => 'under_review'], $url);
        $this->assertSame('under_review', RevisionAccess::find($r['public_id'])['status']);
        $this->assertSame(403, $sup->post($url . '/classify', ['decision' => 'included', 'reason' => 'Support cannot classify'], $url)['status']);
        $sup->post($url . '/status', ['status' => 'resolved'], $url); // not a listed move
        $this->assertSame('under_review', RevisionAccess::find($r['public_id'])['status']);
        $this->assertSame(419, $sup->postNoToken($url . '/status', ['status' => 'rejected', 'client_note' => 'x y z'])['status']);
        $client->post($url . '/status', ['status' => 'cancelled'], $url);
        $this->assertSame('cancelled', RevisionAccess::find($r['public_id'])['status'], 'the client may cancel their own request');
    }

    public function testChargeableFlowOverHttpAndTheClientSeesOnlyTheProposal(): void
    {
        $r = $this->submitAsClient();
        $c = $this->chargeable($r);
        $cu = '/changes/' . $c['public_id'];
        $client = $this->loggedIn('alpha@client.test');
        $this->assertSame(404, $client->get($cu)['status'], 'a draft proposal is invisible to the client');
        $this->assertStringNotContainsString('chargeable', strtolower($client->get('/revisions/' . $r['public_id'])['body']));
        $this->assertStringContainsString('Under review', $client->get('/revisions/' . $r['public_id'])['body']);
        $mgr = $this->loggedIn('mgr@x.test');
        $staffPage = $mgr->get($cu);
        $this->assertSame(200, $staffPage['status']);
        $this->assertStringContainsString('Send to client', $staffPage['body']);
        $this->assertSame(403, $this->loggedIn('sup@x.test')->post($cu . '/send', [], '/account')['status'], 'support cannot send proposals');
        $this->assertSame(403, $client->post($cu . '/send', [], '/account')['status']);
        $mgr->post($cu . '/send', [], $cu);
        $this->assertSame('pending_client', Database::one('SELECT status FROM change_requests')['status']);

        $page = $client->get($cu);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Shoot one extra scene', $page['body']);
        $this->assertStringContainsString('7,500.00', $page['body']);
        $this->assertStringContainsString('2 extra day', $page['body']);
        $this->assertStringContainsString('original scope and approved price are not changed', $page['body']);
        $this->assertStringContainsString('Approve this additional work', $page['body']);
        $this->assertStringNotContainsString('INTERNAL-REASON', $page['body']);
        $this->assertStringNotContainsString('Send to client', $page['body']);
        $this->assertStringContainsString('Additional cost proposal', $client->get('/revisions/' . $r['public_id'])['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get($cu)['status']);
        $this->assertContains($this->loggedIn('dev@x.test')->get($cu)['status'], [403, 404]);

        foreach (['admin@x.test', 'mgr@x.test', 'sup@x.test'] as $who) {
            $this->assertSame(403, $this->loggedIn($who)->post($cu . '/decision', ['decision' => 'approve'], '/account')['status'], "{$who} cannot approve for the client");
        }
        $this->assertSame('pending_client', Database::one('SELECT status FROM change_requests')['status']);
        $this->assertSame(419, $client->postNoToken($cu . '/decision', ['decision' => 'approve'])['status']);
        $client->post($cu . '/decision', ['decision' => 'approve', 'comment' => 'Approved'], $cu);
        $this->assertSame('approved', Database::one('SELECT status FROM change_requests')['status']);
        $this->assertSame('approved_for_work', RevisionAccess::find($r['public_id'])['status']);
        $client->post($cu . '/decision', ['decision' => 'reject'], $cu);
        $this->assertSame('approved', Database::one('SELECT status FROM change_requests')['status'], 'a second click changes nothing');
        $this->assertStringContainsString('Approved for work', $client->get('/revisions/' . $r['public_id'])['body']);
    }

    public function testAdministratorOverrideNeedsThePasswordAndPolicyPageIsAdminOnly(): void
    {
        $r = $this->submitAsClient();
        $this->chargeable($r);
        $url = '/revisions/' . $r['public_id'];
        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Administrator override', $admin->get($url)['body']);
        $this->assertStringNotContainsString('Administrator override', $this->loggedIn('mgr@x.test')->get($url)['body']);
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post($url . '/override', ['reason' => 'A manager overriding it', 'confirm_password' => self::PASSWORD], '/account')['status']);
        $admin->post($url . '/override', ['reason' => 'Goodwill concession for a loyal client', 'confirm_password' => 'wrong-password-123'], $url);
        $this->assertSame('potentially_chargeable', RevisionAccess::find($r['public_id'])['status'], 'a wrong password changes nothing');
        $admin->post($url . '/override', ['reason' => 'Goodwill concession for a loyal client', 'confirm_password' => self::PASSWORD], $url);
        $this->assertSame('included_revision', RevisionAccess::find($r['public_id'])['status']);

        $page = '/admin/revision-policies';
        $this->assertSame(200, $admin->get($page)['status']);
        $this->assertStringContainsString('Nothing is assumed', $admin->get($page)['body']);
        $this->assertSame(200, $this->loggedIn('mgr@x.test')->get($page)['status'], 'classifiers may read the policies');
        $this->assertStringNotContainsString('Save new version', $this->loggedIn('mgr@x.test')->get($page)['body']);
        foreach (['alpha@client.test', 'dev@x.test', 'sup@x.test', 'partner@x.test'] as $who) {
            $this->assertSame(403, $this->loggedIn($who)->get($page)['status'], $who);
        }
        $form = ['scope' => 'default', 'included' => '2', 'terms' => 'Two rounds included', 'source_reference' => 'QT-77', 'reason' => 'From the signed quotation'];
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post($page, $form + ['confirm_password' => self::PASSWORD], $page)['status']);
        $admin->post($page, $form + ['confirm_password' => 'wrong-password-123'], $page);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM revision_policies')['c']);
        $admin->post($page, $form + ['confirm_password' => self::PASSWORD], $page);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM revision_policies')['c']);
        $this->assertStringContainsString('QT-77', $admin->get($page)['body']);
        $this->assertSame(2, RevisionTerms::forProject($this->alphaRow())['limit']);
    }

    public function testRevisionAttachmentDownloadsAreAuthorisedThroughTheRequest(): void
    {
        $f = $this->png('brief.png', 'b');
        $res = RevisionService::submit($this->client, DeliverablePolicy::find($this->d['public_id']), ['description' => 'See the attached brief file'], [$f], false);
        $r = RevisionAccess::find($res['public_id']);
        $file = Database::one('SELECT f.public_id FROM revision_files rf JOIN stored_files f ON f.id = rf.file_id WHERE rf.revision_id = ?', [$r['id']]);
        $url = '/revisions/' . $r['public_id'] . '/files/' . $file['public_id'];
        $this->assertSame(200, $this->loggedIn('alpha@client.test')->get($url)['status']);
        $this->assertSame(200, $this->loggedIn('sup@x.test')->get($url)['status']);
        foreach (['bravo@client.test', 'dev@x.test', 'dev2@x.test', 'mgr2@x.test', 'partner@x.test'] as $who) {
            $this->assertContains($this->loggedIn($who)->get($url)['status'], [403, 404], $who);
        }
        $h = $this->loggedIn('alpha@client.test')->headersOf($url);
        $this->assertStringStartsWith('attachment;', $h['content-disposition']);
        // a file id from another revision cannot be fetched through this one
        $other = $this->submitAsClient('Another request without files');
        $this->assertSame(404, $this->loggedIn('alpha@client.test')->get('/revisions/' . $other['public_id'] . '/files/' . $file['public_id'])['status']);
        $this->assertSame(3, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'revision.file_downloaded'")['c'], 'client (twice) and support; refused attempts are not audited as downloads');
    }

    public function testMultipleClientsNeverSeeEachOthersRequestsOrProposals(): void
    {
        $r = $this->submitAsClient('ALPHA-ONLY request text for isolation');
        $c = $this->chargeable($r);
        $b = $this->loggedIn('bravo@client.test');
        foreach (['/revisions', '/my/deliverables', '/notifications', '/revisions/' . $r['public_id'], '/changes/' . $c['public_id']] as $p) {
            $body = $b->get($p)['body'];
            $this->assertStringNotContainsString('ALPHA-ONLY', $body, $p);
            $this->assertStringNotContainsString('Explainer video', $body, $p);
        }
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM revision_requests WHERE requested_by = " . $this->client)['c']);
        $this->assertSame(0, \Vertex\Services\RevisionQuery::paginate($this->client2, [])['total']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = " . $this->client2)['c']);
    }
}
