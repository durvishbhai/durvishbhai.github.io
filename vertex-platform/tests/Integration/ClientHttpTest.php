<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;
use Vertex\Services\ClientPolicy;
use Vertex\Services\ClientService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryService;
use Vertex\Services\PermissionSync;
use Vertex\Services\ProjectAssignmentService;

/** Client records: isolation, scope, restricted notes, duplicate safety, merge, archive. HTTP + service level. */
final class ClientHttpTest extends HttpTestCase
{
    private int $admin;
    private int $manager;      // assigned to Alpha's project
    private int $manager2;     // assigned to nothing
    private int $support;      // assigned to Alpha's project (no notes permission)
    private int $partner;
    private int $userA;        // portal login of client Alpha
    private int $userB;
    private int $clientA;
    private int $clientB;
    private array $cA;
    private array $cB;
    private array $projA;
    private array $projB;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->manager2 = $this->makeUser('mgr2@x.test', ['manager']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
        $this->partner = $this->makeUser('p1@x.test', ['referral_partner']);
        $this->userA = $this->makeUser('alpha@client.test', ['client']);
        $this->userB = $this->makeUser('bravo@client.test', ['client']);
        $this->clientA = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->userA])['id'];
        $this->clientB = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->userB])['id'];
        $this->cA = ClientPolicy::find((string) Database::one('SELECT public_id FROM clients WHERE id = ?', [$this->clientA])['public_id']);
        $this->cB = ClientPolicy::find((string) Database::one('SELECT public_id FROM clients WHERE id = ?', [$this->clientB])['public_id']);
        $this->projA = $this->makeProject($this->userA, 'Alpha Website');
        $this->projB = $this->makeProject($this->userB, 'Bravo Video');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->manager, 'lead');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->support, 'member');
        ClientService::addNote($this->admin, ClientPolicy::find($this->cA['public_id']), 'ALPHA-SECRET-NOTE: pays late, handle gently');
    }

    private function expect403(callable $fn, string $why): void
    {
        try {
            $fn();
            $this->fail("Expected 403: {$why}");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status, $why);
        }
    }

    private function expectInvalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected validation error '{$needle}'");
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' | ', $e->errors));
        }
    }

    // ---- client portal isolation -----------------------------------------------------------------------------------------

    public function testClientOpensOnlyTheirOwnRecordAndNeverNotesOrOtherClients(): void
    {
        $a = $this->loggedIn('alpha@client.test');
        $mine = $a->get('/my/company');
        $this->assertSame('/clients/' . $this->cA['public_id'], $mine['location']);
        $page = $a->get('/clients/' . $this->cA['public_id']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Alpha Website', $page['body'], 'own projects listed');
        $this->assertStringNotContainsString('ALPHA-SECRET-NOTE', $page['body'], 'restricted notes are never shown to the client');
        $this->assertStringNotContainsString('Internal notes', $page['body']);
        $this->assertStringNotContainsString('Merge a duplicate', $page['body']);
        $this->assertStringNotContainsString('Edit details', $page['body']);
        $this->assertStringNotContainsString('Record history', $page['body']);

        $this->assertSame(404, $a->get('/clients/' . $this->cB['public_id'])['status'], 'cross-client access');
        $this->assertSame(403, $a->get('/clients')['status'], 'a client cannot list clients');
        $this->assertSame(403, $a->get('/clients/create')['status']);
        foreach (['1', '2', "x' OR '1'='1", '../clients', str_repeat('f', 32)] as $guess) {
            $this->assertSame(404, $a->get('/clients/' . rawurlencode($guess))['status'], $guess);
        }
        $this->assertSame(200, $this->loggedIn('bravo@client.test')->get('/clients/' . $this->cB['public_id'])['status']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get('/clients/' . $this->cA['public_id'])['status']);
    }

    public function testClientCannotModifyTheirOwnOrAnyRecordOrAddNotes(): void
    {
        $a = $this->loggedIn('alpha@client.test');
        foreach ([$this->cA, $this->cB] as $c) {
            $u = '/clients/' . $c['public_id'];
            foreach (['' => ['contact_name' => 'Hacked', 'email' => 'h@x.test'], '/status' => ['status' => 'inactive'], '/notes' => ['body' => 'self note'], '/archive' => ['confirm_password' => self::PASSWORD],
                '/merge' => ['target' => $this->cB['public_id'], 'confirm_password' => self::PASSWORD], '/invite' => []] as $suffix => $form) {
                $this->assertSame(403, $a->post($u . $suffix, $form, '/account')['status'], "POST {$u}{$suffix}");
            }
        }
        $this->assertSame('active', Database::one('SELECT status FROM clients WHERE id = ?', [$this->clientA])['status']);
        $this->assertStringNotContainsString('Hacked', (string) Database::one('SELECT contact_name FROM clients WHERE id = ?', [$this->clientA])['contact_name']);
    }

    // ---- staff scope -----------------------------------------------------------------------------------------------------

    public function testStaffSeeClientsAccordingToAssignmentScope(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $list = $admin->get('/clients')['body'];
        $this->assertStringContainsString('alpha@client.test', $list);
        $this->assertStringContainsString('bravo@client.test', $list);

        $m = $this->loggedIn('mgr@x.test');
        $mList = $m->get('/clients')['body'];
        $this->assertStringContainsString('alpha@client.test', $mList);
        $this->assertStringNotContainsString('bravo@client.test', $mList, 'only clients of assigned projects');
        $this->assertSame(200, $m->get('/clients/' . $this->cA['public_id'])['status']);
        $this->assertSame(404, $m->get('/clients/' . $this->cB['public_id'])['status']);

        $m2 = $this->loggedIn('mgr2@x.test');
        $this->assertSame(404, $m2->get('/clients/' . $this->cA['public_id'])['status'], 'not assigned to any of the client\'s projects');
        $this->assertStringContainsString('No clients match', $m2->get('/clients')['body']);

        $this->assertSame(403, $this->loggedIn('p1@x.test')->get('/clients')['status']);
        $this->assertSame(403, $this->loggedIn('p1@x.test')->get('/clients/' . $this->cA['public_id'])['status'], 'no client permission at all');
    }

    public function testRevokingAssignmentRemovesClientVisibilityImmediately(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/clients/' . $this->cA['public_id'])['status']);
        ProjectAssignmentService::revoke($this->admin, $this->projA['id'], $this->manager);
        $this->assertSame(404, $m->get('/clients/' . $this->cA['public_id'])['status']);
    }

    public function testSearchResultsAreScopedForStaff(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $body = $m->get('/clients?q=bravo')['body'];
        $this->assertStringContainsString('No clients match', $body);
        $this->assertStringNotContainsString('bravo@client.test', preg_match('#<tbody>(.*?)</tbody>#s', $body, $mm) ? $mm[1] : '');
    }

    public function testRestrictedNotesVisibleOnlyToThoseWithNotesPermissionAndAccess(): void
    {
        $this->assertStringContainsString('ALPHA-SECRET-NOTE', $this->loggedIn('admin@x.test')->get('/clients/' . $this->cA['public_id'])['body']);
        $this->assertStringContainsString('ALPHA-SECRET-NOTE', $this->loggedIn('mgr@x.test')->get('/clients/' . $this->cA['public_id'])['body']);
        $s = $this->loggedIn('sup@x.test')->get('/clients/' . $this->cA['public_id']);
        $this->assertSame(200, $s['status'], 'support sees the client of their assigned project');
        $this->assertStringNotContainsString('ALPHA-SECRET-NOTE', $s['body'], 'but not the restricted notes');
        $this->assertSame([], ClientService::notes($this->userA, $this->cA), 'never the client themselves');
        $this->assertSame([], ClientService::notes($this->manager2, $this->cA), 'not without access to the client');
    }

    public function testManagerCannotEditArchiveMergeOrInvite(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $u = '/clients/' . $this->cA['public_id'];
        $this->assertSame(403, $m->post($u, ['contact_name' => 'X Y', 'email' => 'x@y.test'], '/account')['status']);
        $this->assertSame(403, $m->post($u . '/archive', ['confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $m->post($u . '/merge', ['target' => $this->cB['public_id'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $m->post($u . '/invite', [], '/account')['status']);
        $this->assertSame(403, $m->get('/clients/create')['status']);
        $this->assertSame(303, $m->post($u . '/notes', ['body' => 'Manager note about the client'], '/account')['status']);
    }

    // ---- creation, duplicates, update ------------------------------------------------------------------------------------

    public function testCreateBlocksProbableDuplicatesUntilExplicitlyConfirmed(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $form = ['client_type' => 'business', 'contact_name' => 'Alpha Person', 'email' => 'ALPHA@client.test', 'phone' => '', 'company_name' => 'Alpha Co'];
        $a->post('/clients', $form, '/account');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM clients WHERE email = 'alpha@client.test'")['c']);
        $this->assertStringContainsString('already exists', $a->get('/clients/create')['body']);

        $a->post('/clients', $form + ['confirm_new' => '1'], '/account');
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM clients WHERE email = 'alpha@client.test'")['c'], 'explicit confirmation allows a separate record');
    }

    public function testNameSimilarityAloneNeverBlocksOrMerges(): void
    {
        $r = ClientService::create($this->admin, ['client_type' => 'individual', 'contact_name' => 'User alpha@client.test', 'email' => 'different@else.test', 'phone' => '+91 90001 00001']);
        $this->assertGreaterThan(0, $r['id'], 'same name, different email/phone: allowed');
        $d = ClientService::findDuplicates('different@else.test', null, null);
        $this->assertCount(1, $d['strong']);
        $this->assertSame(3, (int) Database::one('SELECT COUNT(*) c FROM clients')['c'], 'nothing was merged');
    }

    public function testCreateAndUpdateValidateAndAuditWithoutPersonalDataInTheAuditLog(): void
    {
        $this->expectInvalid(fn () => ClientService::create($this->admin, ['client_type' => 'business', 'contact_name' => 'Biz', 'email' => 'b@biz.test']), 'Company name');
        $this->expectInvalid(fn () => ClientService::create($this->admin, ['client_type' => 'individual', 'contact_name' => 'Web', 'email' => 'w@web.test', 'website' => 'not a site']), 'website');
        $this->expectInvalid(fn () => ClientService::create($this->admin, ['client_type' => 'martian', 'contact_name' => 'Web', 'email' => 'w@web.test']), 'client type');
        $c = ClientService::create($this->admin, ['client_type' => 'business', 'contact_name' => 'Nina Nair', 'email' => 'nina@nair.test', 'phone' => '+91 90002 22222', 'company_name' => 'Nair Foods', 'website' => 'nairfoods.example', 'tax_id' => '29ABCDE1234F1Z5', 'address' => '12 MG Road, Bengaluru']);
        $row = ClientPolicy::find($c['public_id']);
        $this->assertNull($row['user_id']);
        $this->assertSame('nair foods', $row['company_norm']);
        ClientService::update($this->admin, $row, ['client_type' => 'business', 'contact_name' => 'Nina Nair', 'email' => 'nina@nair.test', 'phone' => '+91 90003 33333', 'company_name' => 'Nair Foods', 'website' => 'nairfoods.example', 'tax_id' => '29ABCDE1234F1Z5', 'address' => '12 MG Road, Bengaluru']);
        $audit = Database::one("SELECT meta_json, before_json, after_json FROM audit_logs WHERE action = 'client.updated'");
        $this->assertStringContainsString('phone', $audit['meta_json']);
        $this->assertStringNotContainsString('90003', implode('', $audit));
        $this->assertStringNotContainsString('29ABCDE', (string) Database::one("SELECT GROUP_CONCAT(COALESCE(meta_json,''), COALESCE(after_json,'')) g FROM audit_logs")['g']);
        $this->assertSame('+91 90003 33333', ClientPolicy::find($c['public_id'])['phone']);
    }

    // ---- merge -----------------------------------------------------------------------------------------------------------

    public function testMergeMovesEverythingArchivesTheSourceAndKeepsHistory(): void
    {
        $loose = $this->makeLooseClient('Alpha Duplicate', 'dup@alpha.test', 'Alpha Co', '+91 90004 44444');
        $enq = EnquiryService::create($this->admin, 'manual', ['contact_name' => 'Alpha Duplicate', 'email' => 'dup@alpha.test', 'service' => 'website_development', 'description' => 'Duplicate record lead', 'phone' => '+91 90004 44444']);
        Database::run('UPDATE enquiries SET client_id = ? WHERE id = ?', [$loose['id'], $enq['id']]);
        Database::run("INSERT INTO projects (public_id, code, client_id, title, status, status_changed_at, created_at, updated_at) VALUES (?, 'VIS-2026-9001', ?, 'Dup project', 'draft', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $loose['id']]);
        ClientService::addNote($this->admin, ClientPolicy::find($loose['public_id']), 'Note on the duplicate');

        ClientService::merge($this->owner(), $loose['id'], $this->clientA);

        $this->assertSame($this->clientA, (int) Database::one('SELECT client_id FROM enquiries WHERE id = ?', [$enq['id']])['client_id']);
        $this->assertSame($this->clientA, (int) Database::one("SELECT client_id FROM projects WHERE code = 'VIS-2026-9001'")['client_id']);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM client_notes WHERE client_id = ? AND body IN (\'Note on the duplicate\', \'ALPHA-SECRET-NOTE: pays late, handle gently\')', [$this->clientA])['c']);
        $src = Database::one('SELECT * FROM clients WHERE id = ?', [$loose['id']]);
        $this->assertSame('archived', $src['status']);
        $this->assertSame($this->clientA, (int) $src['merged_into_client_id']);
        $this->assertNotNull($src['archived_at']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients WHERE id = ?', [$loose['id']])['c'], 'the merged-away record still exists');
        $audit = Database::one("SELECT meta_json FROM audit_logs WHERE action = 'client.merged'");
        $this->assertStringContainsString('"projects_moved":1', $audit['meta_json']);
        $this->assertNotNull(Database::one("SELECT 1 x FROM client_notes WHERE client_id = ? AND body LIKE 'Merged duplicate client record%'", [$this->clientA]), 'the merge is documented on the surviving record');
    }

    private function owner(): int
    {
        return $this->makeUser('owner@x.test', ['super_admin']);
    }

    public function testMergeIsRefusedForPortalUsersSelfAndArchivedAndForUnauthorizedActors(): void
    {
        $owner = $this->owner();
        $loose = $this->makeLooseClient('Loose Two', 'l2@x.test');
        $this->expectInvalid(fn () => ClientService::merge($owner, $this->clientA, $this->clientB), 'portal login');
        $this->expectInvalid(fn () => ClientService::merge($owner, $loose['id'], $loose['id']), 'two different');
        $this->expect403(fn () => ClientService::merge($this->manager, $loose['id'], $this->clientA), 'manager');
        $this->expect403(fn () => ClientService::merge($this->userA, $loose['id'], $this->clientA), 'client');
        ClientService::merge($owner, $loose['id'], $this->clientA);
        $this->expectInvalid(fn () => ClientService::merge($owner, $loose['id'], $this->clientB), 'Archived');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM clients WHERE user_id IS NOT NULL AND merged_into_client_id IS NOT NULL')['c']);
    }

    // ---- archive, status, invite ----------------------------------------------------------------------------------------

    public function testArchiveIsBlockedWhileOpenProjectsExistAndNothingIsDeleted(): void
    {
        $owner = $this->owner();
        $this->expectInvalid(fn () => ClientService::archive($owner, $this->cA), 'open project');
        Database::run("UPDATE projects SET status = 'completed' WHERE id = ?", [$this->projA['id']]);
        ClientService::archive($owner, ClientPolicy::find($this->cA['public_id']));
        $this->assertSame('archived', Database::one('SELECT status FROM clients WHERE id = ?', [$this->clientA])['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM projects WHERE id = ?', [$this->projA['id']])['c'], 'projects survive');
        $this->assertNull(ClientPolicy::level($this->manager, ClientPolicy::find($this->cA['public_id'])), 'archived clients leave staff scopes');
        $this->assertNull(ClientPolicy::level($this->userA, ClientPolicy::find($this->cA['public_id'])), 'and the client scope');
        $this->assertSame('all', ClientPolicy::level($this->admin, ClientPolicy::find($this->cA['public_id'])), 'but remain visible to those who can see everything');
        $page = $this->loggedIn('admin@x.test')->get('/clients?archived=1')['body'];
        $this->assertStringContainsString('alpha@client.test', $page);
        $this->assertStringNotContainsString('alpha@client.test', preg_match('#<tbody>(.*?)</tbody>#s', $this->loggedIn('admin@x.test')->get('/clients')['body'], $m) ? $m[1] : '');
    }

    public function testStatusChangeNeedsEditPermissionAndAccess(): void
    {
        ClientService::setStatus($this->admin, $this->cA, 'inactive');
        $this->assertSame('inactive', Database::one('SELECT status FROM clients WHERE id = ?', [$this->clientA])['status']);
        $this->expect403(fn () => ClientService::setStatus($this->manager, ClientPolicy::find($this->cA['public_id']), 'active'), 'manager has no clients.edit');
        $this->expectInvalid(fn () => ClientService::setStatus($this->admin, ClientPolicy::find($this->cA['public_id']), 'archived'), 'active or inactive');
    }

    public function testInviteCreatesAPortalLoginLinkedToTheExistingRecordWithoutASecondClient(): void
    {
        \Vertex\Core\Config::set('mail.driver', 'array');
        \Vertex\Core\Config::set('app.url', 'https://portal.example.test');
        $owner = $this->owner();
        $loose = $this->makeLooseClient('Loose Invitee', 'invitee@x.test', null, '+91 90005 55555');
        $before = (int) Database::one('SELECT COUNT(*) c FROM clients')['c'];
        $r = ClientService::invite($owner, ClientPolicy::find($loose['public_id']));
        $this->assertTrue($r['invite_sent']);
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM clients')['c'], 'no second client record');
        $c = Database::one('SELECT user_id FROM clients WHERE id = ?', [$loose['id']]);
        $this->assertSame($r['user_id'], (int) $c['user_id']);
        $this->assertSame(['client'], array_column((new UserRepository())->rolesOf($r['user_id']), 'slug'));
        $this->assertSame('pending', Database::one('SELECT status FROM users WHERE id = ?', [$r['user_id']])['status']);
        $this->expectInvalid(fn () => ClientService::invite($owner, ClientPolicy::find($loose['public_id'])), 'already has a portal login');
        $this->expect403(fn () => ClientService::invite($this->manager, ClientPolicy::find($this->cA['public_id'])), 'manager');
        \Vertex\Core\Config::set('mail.driver', 'none');
        \Vertex\Core\Config::set('app.url', '');
    }

    public function testClientRecordsAreScopedByRelatedEnquiryVisibility(): void
    {
        $e = EnquiryService::create($this->admin, 'manual', ['contact_name' => 'Alpha Person', 'email' => 'ap@alpha.test', 'service' => 'website_development', 'description' => 'Linked enquiry', 'phone' => '+91 90006 66666']);
        Database::run('UPDATE enquiries SET client_id = ? WHERE id = ?', [$this->clientA, $e['id']]);
        $ref = $e['reference'];
        $this->assertStringContainsString($ref, $this->loggedIn('admin@x.test')->get('/clients/' . $this->cA['public_id'])['body']);
        $this->assertStringNotContainsString($ref, $this->loggedIn('mgr@x.test')->get('/clients/' . $this->cA['public_id'])['body'], 'the manager does not own that enquiry');
        $this->assertStringNotContainsString($ref, $this->loggedIn('alpha@client.test')->get('/clients/' . $this->cA['public_id'])['body'], 'clients never see CRM enquiries');
    }
}
