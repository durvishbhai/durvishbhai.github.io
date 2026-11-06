<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\Csv;

/** Phase 9: global search, CSV export/import, recycle bin: scope, safety and audit. */
final class DataManagementHttpTest extends HttpTestCase
{
    private function adminClient(): \Vertex\Tests\Support\HttpClient
    {
        $this->makeUser('adm@x.test', ['administrator']);

        return $this->loggedIn('adm@x.test');
    }

    public function testSearchOnlyReturnsWhatTheUserMayOpen(): void
    {
        $owner = $this->makeUser('co@x.test', ['client']);
        $other = $this->makeUser('other@x.test', ['client']);
        $this->makeProject($owner, 'Zephyr portal');
        $this->makeProject($other, 'Zephyr secret');
        $c = $this->loggedIn('co@x.test');
        $body = $c->get('/search?q=Zephyr')['body'];
        $this->assertStringContainsString('Zephyr portal', $body);
        $this->assertStringNotContainsString('Zephyr secret', $body, "another client's project never appears");
        $this->assertStringNotContainsString('Users', $body, 'a client sees no user directory results');
        $this->assertStringContainsString('Type at least 2', $c->get('/search?q=a')['body']);
        $a = $this->adminClient();
        $this->assertStringContainsString('Zephyr secret', $a->get('/search?q=Zephyr')['body']);
        $this->assertStringContainsString('adm@x.test', $a->get('/search?q=adm@x')['body']);
    }

    public function testExportRespectsScopeNeutralisesFormulasAndIsAudited(): void
    {
        $this->makeLooseClient('=HYPERLINK("http://evil")', 'f@x.test');
        $this->makeLooseClient('Plain Person', 'p@x.test');
        $a = $this->adminClient();
        $r = $a->get('/admin/data/export/clients');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString("'=HYPERLINK", $r['body'], 'formula cells are neutralised');
        $this->assertStringContainsString('Plain Person', $r['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'data.exported'")['c']);
        $c = $this->client();
        $this->makeUser('cl@x.test', ['client']);
        $cl = $this->loggedIn('cl@x.test');
        $this->assertSame(403, $cl->get('/admin/data/export/clients')['status']);
        $this->assertSame(404, $a->get('/admin/data/export/nonsense')['status']);
        $this->assertSame('-5', Csv::safeCell('-5'));
        $this->assertSame("'@a", Csv::safeCell('@a'));
    }

    public function testClientImportChecksFirstThenImportsAndRejectsBadAndDuplicateRows(): void
    {
        $this->makeLooseClient('Existing Person', 'exists@x.test', null, '+91 98765 11111');
        $a = $this->adminClient();
        $csv = "contact_name,email,phone,company_name\nAsha Verma,asha@x.test,+91 90000 11111,Verma Traders\nExisting Person,exists@x.test,,\nBad Row,not-an-email,,\nAsha Verma,asha@x.test,,\n";
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $csv);
        $r = $a->upload('/admin/data/import/clients', ['mode' => 'check'], ['file' => [$tmp, 'clients.csv', 'text/csv']], '/admin/data');
        $this->assertSame(303, $r['status']);
        $page = $a->get('/admin/data')['body'];
        $this->assertStringContainsString('nothing was saved', $page);
        $this->assertStringContainsString('Line 3', $page);
        $this->assertStringContainsString('Line 4', $page);
        $this->assertStringContainsString('Line 5', $page);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM clients WHERE email = 'asha@x.test'")['c']);
        $a->upload('/admin/data/import/clients', ['mode' => 'import'], ['file' => [$tmp, 'clients.csv', 'text/csv']], '/admin/data');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM clients WHERE email = 'asha@x.test'")['c'], 'only the one valid row is created');
        $h = Database::one('SELECT total_rows, imported_rows, failed_rows FROM data_imports');
        $this->assertSame([4, 1, 3], [(int) $h['total_rows'], (int) $h['imported_rows'], (int) $h['failed_rows']]);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'data.imported'")['c']);
        // wrong columns / non-csv are refused outright
        file_put_contents($tmp, "name,mail\nA,b\n");
        $a->upload('/admin/data/import/clients', ['mode' => 'import'], ['file' => [$tmp, 'bad.csv', 'text/csv']], '/admin/data');
        $this->assertStringContainsString('Missing column', $a->get('/admin/data')['body'] . $a->get('/admin/data')['body']);
        @unlink($tmp);
    }

    public function testEnquiryImportMapsServiceLabels(): void
    {
        $a = $this->adminClient();
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, "contact_name,email,description,service,priority\nRavi Kumar,ravi@x.test,We need a website with an online menu.,Website development,high\n");
        $a->upload('/admin/data/import/enquiries', ['mode' => 'import'], ['file' => [$tmp, 'leads.csv', 'text/csv']], '/admin/data');
        $e = Database::one("SELECT service, priority, source FROM enquiries WHERE email = 'ravi@x.test'");
        $this->assertNotNull($e, strip_tags($a->get('/admin/data')['body']));
        $this->assertSame(['website_development', 'high', 'manual'], [$e['service'], $e['priority'], $e['source']]);
        @unlink($tmp);
    }

    public function testRecycleBinListsArchivedRecordsAndRestoresWithPasswordAndReason(): void
    {
        $cl = $this->makeLooseClient('Gone Client', 'gone@x.test');
        Database::run("UPDATE clients SET status = 'archived', archived_at = UTC_TIMESTAMP() WHERE id = ?", [$cl['id']]);
        $a = $this->adminClient();
        $this->assertStringContainsString('Gone Client', $a->get('/admin/recycle-bin')['body']);
        $a->post('/admin/recycle-bin/restore', ['type' => 'clients', 'id' => $cl['public_id'], 'reason' => 'Archived by mistake', 'confirm_password' => 'wrong'], '/admin/recycle-bin');
        $this->assertNotNull(Database::one('SELECT archived_at FROM clients WHERE id = ?', [$cl['id']])['archived_at']);
        $a->post('/admin/recycle-bin/restore', ['type' => 'clients', 'id' => $cl['public_id'], 'reason' => 'Archived by mistake', 'confirm_password' => self::PASSWORD], '/admin/recycle-bin');
        $row = Database::one('SELECT archived_at, status FROM clients WHERE id = ?', [$cl['id']]);
        $this->assertNull($row['archived_at']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'recycle.restored'")['c']);
        $this->makeUser('cl2@x.test', ['client']);
        $this->assertSame(403, $this->loggedIn('cl2@x.test')->get('/admin/recycle-bin')['status']);
    }

    public function testCustomRoleCreationThroughTheUi(): void
    {
        $this->makeUser('own@x.test', ['super_admin']);
        $o = $this->loggedIn('own@x.test');
        $this->assertStringContainsString('New custom role', $o->get('/admin/roles')['body']);
        $o->post('/admin/roles', ['name' => 'Account Manager', 'description' => 'Accounts', 'level' => '40', 'copy_from' => 'support_staff', 'confirm_password' => self::PASSWORD], '/admin/roles/new');
        $this->assertNotNull(Database::one("SELECT id FROM roles WHERE slug = 'account_manager' AND is_system = 0"));
        $this->assertSame(200, $o->get('/admin/roles/account_manager')['status']);
        $this->assertStringContainsString('Account Manager', $o->get('/admin/users/create')['body'], 'assignable on the user form');
        $a = $this->adminClient();
        $this->assertSame(403, $a->get('/admin/roles/new')['status']);
    }
}
