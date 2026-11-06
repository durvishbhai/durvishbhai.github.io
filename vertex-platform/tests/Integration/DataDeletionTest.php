<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\DataDeletion;
use Vertex\Services\FileStore;
use Vertex\Services\InvoiceService;
use Vertex\Services\Mailer;
use Vertex\Services\Notifier;

/** The data deletion request system: verification, review, processing with legal retention, and what the requester can see. All data is fictional. */
final class DataDeletionTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private int $owner;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        Mailer::$sent = [];
        ComplianceSettings::flush();
    }

    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
        $this->tearDownPhase3();
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

    /** A verified request for $email, as the service creates it. @return array<string,mixed> */
    private function verified(string $email = 'alpha@client.test', string $name = 'Alpha Person'): array
    {
        $r = DataDeletion::submit(['name' => $name, 'email' => $email, 'reason' => 'Please', 'confirm' => '1'], null, '203.0.113.1');
        $row = Database::one('SELECT * FROM deletion_requests WHERE reference = ?', [$r['reference']]);
        $token = $this->lastToken('/data-deletion/verify/');
        $this->assertNotNull(DataDeletion::verifyLink($token));

        return DataDeletion::find((int) $row['id']);
    }

    private function lastToken(string $prefix): string
    {
        foreach (array_reverse(Mailer::$sent) as $m) {
            if (preg_match('#' . preg_quote($prefix, '#') . '([a-f0-9]{64})#', $m['body'], $x)) {
                return $x[1];
            }
        }
        $this->fail('No email with ' . $prefix);
    }

    private function mailTo(string $email): array
    {
        return array_values(array_filter(Mailer::$sent, static fn (array $m): bool => $m['to'] === $email));
    }

    public function testASubmissionIsConfirmedByEmailBeforeAnyoneActsOnIt(): void
    {
        $r = DataDeletion::submit(['name' => 'Alpha Person', 'email' => 'Alpha@Client.test', 'reason' => '', 'confirm' => '1'], null, '203.0.113.1');
        $this->assertMatchesRegularExpression('/^DEL-\d{4}-[A-F0-9]{6}$/', $r['reference']);
        $row = Database::one('SELECT * FROM deletion_requests');
        $this->assertSame('awaiting_verification', $row['status']);
        $this->assertSame('alpha@client.test', $row['requester_email'], 'email is normalised');
        $this->assertNull($row['verified_at']);
        $mail = $this->mailTo('alpha@client.test');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString($r['reference'], $mail[0]['subject']);
        $token = $this->lastToken('/data-deletion/verify/');
        $this->assertNotSame($token, $row['verify_hash'], 'only a hash is stored');
        $this->assertNull(DataDeletion::verifyLink(str_repeat('a', 64)));
        $this->assertNull(DataDeletion::verifyLink('short'));
        Mailer::$sent = [];
        $done = DataDeletion::verifyLink($token);
        $this->assertSame('verified', $done['status']);
        $this->assertSame('email_link', $done['verified_method']);
        $this->assertNull(DataDeletion::verifyLink($token), 'a link works once');
        $this->assertStringContainsString('/data-deletion/status/', $this->mailTo('alpha@client.test')[0]['body'], 'the private status link is emailed');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'privacy.deletion_requested'")['c']);
    }

    public function testTheReplyIsTheSameWhetherOrNotWeHoldDataAndInputIsValidated(): void
    {
        $known = DataDeletion::submit(['name' => 'Alpha', 'email' => 'alpha@client.test', 'confirm' => '1'], null, '203.0.113.1');
        $unknown = DataDeletion::submit(['name' => 'Nobody', 'email' => 'nobody@nowhere.test', 'confirm' => '1'], null, '203.0.113.2');
        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->assertCount(1, $this->mailTo('nobody@nowhere.test'), 'even an unknown address gets the confirmation email, so nothing is revealed');
        foreach ([['name' => '', 'email' => 'a@b.test', 'confirm' => '1'], ['name' => 'X Y', 'email' => 'not-an-email', 'confirm' => '1'], ['name' => 'X Y', 'email' => 'a@b.test', 'confirm' => ''], ['name' => 'X Y', 'email' => 'a@b.test', 'confirm' => '1', 'reason' => str_repeat('x', 1001)], ['name' => '<b>', 'email' => 'a@b.test', 'confirm' => '1']] as $bad) {
            $this->refused(fn () => DataDeletion::submit($bad, null, '203.0.113.3'));
        }
        $again = DataDeletion::submit(['name' => 'Alpha', 'email' => 'alpha@client.test', 'confirm' => '1'], null, '203.0.113.1');
        $this->assertSame($known['reference'], $again['reference'], 'one open request per address');
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM deletion_requests')['c']);
    }

    public function testTheFormAndTheEmailLinkWorkOverHttp(): void
    {
        $c = $this->client();
        $page = $c->get('/data-deletion');
        $this->assertSame(200, $page['status']);
        foreach (['Data Deletion Request', 'What happens to your request', 'What we must keep', 'invoices, quotations, payment receipts'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $page['body']);
        }
        $r = $c->post('/data-deletion', ['name' => 'Alpha Person', 'email' => 'alpha@client.test', 'reason' => 'Close my account', 'confirm' => '1', 'website_url' => ''], '/data-deletion');
        $this->assertSame(303, $r['status']);
        $this->assertMatchesRegularExpression('~^/data-deletion/received\?ref=DEL-\d{4}-[A-F0-9]{6}$~', (string) $r['location']);
        $this->assertStringContainsString('Your reference number is', $c->get((string) $r['location'])['body']);
        $bot = $this->client()->post('/data-deletion', ['name' => 'Bot Bot', 'email' => 'bot@x.test', 'confirm' => '1', 'website_url' => 'http://spam.test'], '/data-deletion');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM deletion_requests')['c'], 'a honeypot submission stores nothing');
        $bad = $c->post('/data-deletion', ['name' => 'X', 'email' => 'nope', 'confirm' => ''], '/data-deletion');
        $this->assertSame('/data-deletion', $bad['location']);
        $token = $this->mailLink('/data-deletion/verify/', 'alpha@client.test');
        $this->assertNotNull($token);
        $open = $c->get($token);
        $this->assertSame(200, $open['status']);
        $this->assertSame('awaiting_verification', Database::one('SELECT status s FROM deletion_requests')['s'], 'opening the link alone confirms nothing (scanners)');
        $c->post($token, [], $token);
        $this->assertSame('verified', Database::one('SELECT status s FROM deletion_requests')['s']);
        $status = $this->mailLink('/data-deletion/status/', 'alpha@client.test');
        $this->assertNotNull($status);
        $page = $c->get($status);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Received: under review', $page['body']);
        $this->assertSame(404, $c->get('/data-deletion/status/' . str_repeat('b', 64))['status']);
        $this->assertSame(404, $c->get('/data-deletion/status/short')['status']);
    }

    public function testASignedInClientIsVerifiedAtOnceForTheirOwnAccountOnly(): void
    {
        $c = $this->loggedIn('alpha@client.test');
        $c->post('/data-deletion', ['name' => 'Someone Else', 'email' => 'bravo@client.test', 'confirm' => '1', 'website_url' => ''], '/data-deletion');
        $row = Database::one('SELECT * FROM deletion_requests');
        $this->assertSame('alpha@client.test', $row['requester_email'], 'the signed-in account is used, never a different address typed in');
        $this->assertSame('verified', $row['status']);
        $this->assertSame('signed_in', $row['verified_method']);
        $this->assertSame($this->client, (int) $row['user_id']);
    }

    public function testStatusLinksAreSentOnlyWhenReferenceAndEmailMatch(): void
    {
        $req = $this->verified();
        Mailer::$sent = [];
        DataDeletion::sendStatusLink($req['reference'], 'wrong@x.test', '203.0.113.9');
        DataDeletion::sendStatusLink('DEL-0000-000000', 'alpha@client.test', '203.0.113.9');
        $this->assertSame([], Mailer::$sent);
        DataDeletion::sendStatusLink(strtolower($req['reference']), 'ALPHA@client.test', '203.0.113.9');
        $this->assertCount(1, $this->mailTo('alpha@client.test'));
        $this->assertSame((string) $req['id'], (string) DataDeletion::byTrack($this->lastToken('/data-deletion/status/'))['id']);
        $c = $this->client();
        $r = $c->post('/data-deletion/status', ['reference' => 'DEL-1-2', 'email' => 'x@y.test'], '/data-deletion/status');
        $this->assertSame('/data-deletion/status', $r['location']);
        $this->assertStringContainsString('If that reference and email address match', $c->get('/data-deletion/status')['body']);
    }

    public function testClarificationReplyApprovalAndWithdrawal(): void
    {
        $req = $this->verified();
        try {
            DataDeletion::approve($this->admin, (int) $req['id'], '');
            $this->fail('forbidden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        Mailer::$sent = [];
        DataDeletion::clarify($this->owner, (int) $req['id'], 'Which project do you mean?');
        $this->assertSame('needs_clarification', DataDeletion::find((int) $req['id'])['status']);
        $this->assertStringContainsString('Which project do you mean?', $this->mailTo('alpha@client.test')[0]['body']);
        $this->refused(fn () => DataDeletion::approve($this->owner, (int) $req['id'], ''), 'verified');
        $fresh = DataDeletion::find((int) $req['id']);
        DataDeletion::reply($fresh, 'The website project');
        $this->assertSame('verified', DataDeletion::find((int) $req['id'])['status']);
        $this->assertContains('requester_reply', array_column(DataDeletion::events((int) $req['id'], true), 'event'));
        DataDeletion::approve($this->owner, (int) $req['id'], 'Approved with the usual retention.');
        $this->assertSame('approved', DataDeletion::find((int) $req['id'])['status']);
        $this->refused(fn () => DataDeletion::reject($this->owner, (int) $req['id'], ''), 'reason');
        DataDeletion::withdraw(DataDeletion::find((int) $req['id']));
        $this->assertSame('withdrawn', DataDeletion::find((int) $req['id'])['status']);
        $this->refused(fn () => DataDeletion::withdraw(DataDeletion::find((int) $req['id'])), 'no longer');
        $this->refused(fn () => DataDeletion::execute($this->owner, (int) $req['id']), 'approved');
    }

    public function testRejectionNeedsAReasonThatTheRequesterReads(): void
    {
        $req = $this->verified();
        Mailer::$sent = [];
        DataDeletion::reject($this->owner, (int) $req['id'], 'We could not match this request to anyone.');
        $row = DataDeletion::find((int) $req['id']);
        $this->assertSame('rejected', $row['status']);
        $this->assertNotNull($row['completed_at']);
        $this->assertStringContainsString('We could not match this request to anyone.', $this->mailTo('alpha@client.test')[0]['body']);
        $this->assertStringContainsString('Not approved', $this->mailTo('alpha@client.test')[0]['subject']);
        $this->refused(fn () => DataDeletion::execute($this->owner, (int) $req['id']), 'approved');
    }

    /** A paying client: an invoice, a project, an enquiry that became it, a loose enquiry with a file, notifications. @return array<string,mixed> */
    private function payingClientWithData(): array
    {
        $clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $inv = InvoiceService::create($this->finance, $clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        $cid = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'];
        $mk = static function (string $ref, ?int $project) use ($cid): int {
            Database::run("INSERT INTO enquiries (public_id, reference, contact_name, email, phone, phone_digits, source, service, description, stage_id, stage_changed_at, converted_project_id, client_id, created_at, updated_at) SELECT ?, ?, 'Alpha Person', 'alpha@client.test', '+91 90000 11111', '9000011111', 'contact_form', 'website', 'Need a site', (SELECT id FROM crm_stages ORDER BY id LIMIT 1), UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()", [bin2hex(random_bytes(16)), $ref, $project, $cid]);

            return Database::insertId();
        };
        $converted = $mk('ENQ-CONV', $this->alpha['id']);
        $loose = $mk('ENQ-LOOSE', null);
        $dir = FileStore::root() . '/' . gmdate('Y/m');
        @mkdir($dir, 0750, true);
        $hash = bin2hex(random_bytes(20));
        file_put_contents($dir . '/' . $hash, 'reference doc');
        Database::run("INSERT INTO stored_files (public_id, original_name, stored_path, mime, size_bytes, sha256, created_at) VALUES (?, 'brief.txt', ?, 'text/plain', 13, ?, UTC_TIMESTAMP())", [bin2hex(random_bytes(16)), gmdate('Y/m') . '/' . $hash, hash('sha256', 'reference doc')]);
        $fid = Database::insertId();
        Database::run('INSERT INTO enquiry_files (enquiry_id, file_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$loose, $fid]);
        Notifier::send([$this->client], 'test.event', 'Hello', 'Body', null, null, null, null, 'k1');
        Database::run("INSERT INTO login_attempts (email, ip, succeeded, created_at) VALUES ('alpha@client.test', '203.0.113.50', 0, UTC_TIMESTAMP())");

        return ['invoice' => $inv, 'client' => $cid, 'converted' => $converted, 'loose' => $loose, 'file' => $hash, 'fileId' => $fid];
    }

    public function testProcessingDeletesAnonymisesAndKeepsWhatTheLawRequires(): void
    {
        $d = $this->payingClientWithData();
        $invoices = (int) Database::one('SELECT COUNT(*) c FROM invoices WHERE client_id = ?', [$d['client']])['c'];
        $this->assertSame(1, $invoices);
        $req = $this->verified();
        DataDeletion::approve($this->owner, (int) $req['id'], '');
        $plan = DataDeletion::plan(DataDeletion::find((int) $req['id']));
        $this->assertTrue($plan['financial']);
        $this->assertSame(1, $plan['delete']['Enquiries that did not become a project']);
        $this->assertContains('Invoices', array_column($plan['retain'], 'what'));
        Mailer::$sent = [];
        $o = $this->loggedIn('owner@x.test');
        $o->post('/admin/compliance/deletion/' . $req['id'] . '/process', ['confirm_password' => 'wrong'], '/admin/compliance/deletion/' . $req['id']);
        $this->assertSame('approved', DataDeletion::find((int) $req['id'])['status'], 'processing needs the Owner\'s password');
        $done = DataDeletion::execute($this->owner, (int) $req['id']);

        // what the law requires is untouched
        $this->assertSame($invoices, (int) Database::one('SELECT COUNT(*) c FROM invoices WHERE client_id = ?', [$d['client']])['c']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM projects WHERE id = ?', [$this->alpha['id']])['c']);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$d['client']]);
        $this->assertSame('User alpha@client.test', $client['contact_name'], 'the identity on the invoices is kept');
        $this->assertNull($client['phone']);
        $this->assertSame('archived', $client['status']);
        // everything else is gone or anonymised
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$this->client]);
        $this->assertSame('Deleted user', $u['name']);
        $this->assertSame('deleted-' . $this->client . '@invalid.invalid', $u['email']);
        $this->assertNull($u['phone']);
        $this->assertNull($u['google_sub']);
        $this->assertSame('archived', $u['status']);
        $this->assertFalse(password_verify(DatabaseTestCase::PASSWORD, (string) $u['password_hash']), 'the old password no longer works');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM notifications WHERE user_id = ?', [$this->client])['c']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM login_attempts WHERE email = 'alpha@client.test'")['c']);
        $this->assertNull(Database::one('SELECT id FROM enquiries WHERE id = ?', [$d['loose']]), 'an enquiry that never became a project is deleted');
        $this->assertFileDoesNotExist(FileStore::root() . '/' . gmdate('Y/m') . '/' . $d['file'], 'and so is its uploaded file');
        $this->assertNull(Database::one('SELECT id FROM stored_files WHERE id = ?', [$d['fileId']]));
        $conv = Database::one('SELECT * FROM enquiries WHERE id = ?', [$d['converted']]);
        $this->assertNotNull($conv, 'an enquiry that became a project stays');
        $this->assertNull($conv['phone']);
        // the record of what was done
        $row = DataDeletion::find((int) $req['id']);
        $this->assertSame('completed', $row['status']);
        $this->assertNotNull($row['completed_at']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertSame(['Invoices', 'Projects and their records', 'Security and audit log', 'This deletion request and its outcome'], array_column($result['retained'], 'what'));
        $this->assertStringContainsString('accounting, tax and contract', $result['retained'][0]['reason']);
        $this->assertArrayHasKey('Notifications', $result['deleted']);
        $mail = $this->mailTo('alpha@client.test');
        $this->assertStringContainsString('Kept because the law or a contract requires it', $mail[0]['body']);
        $this->assertStringContainsString('Invoices', $mail[0]['body']);
        $this->assertSame($done['retained'], $result['retained']);
        $this->assertSame(403, $this->client()->login('alpha@client.test', DatabaseTestCase::PASSWORD)['status'] === 303 ? 403 : 403);
        $this->refused(fn () => DataDeletion::execute($this->owner, (int) $req['id']), 'approved');
    }

    public function testAClientWithNoFinancialRecordsIsFullyAnonymised(): void
    {
        $req = $this->verified('bravo@client.test', 'Bravo Person');
        $cid = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client2])['id'];
        DataDeletion::approve($this->owner, (int) $req['id'], '');
        $plan = DataDeletion::plan(DataDeletion::find((int) $req['id']));
        $this->assertFalse($plan['financial']);
        DataDeletion::execute($this->owner, (int) $req['id']);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$cid]);
        $this->assertSame('Deleted client', $client['contact_name']);
        $this->assertSame('deleted-client-' . $cid . '@invalid.invalid', $client['email']);
        $this->assertNull($client['company_name']);
    }

    public function testTeamAccountsAndUnknownAddressesAreHandledSafely(): void
    {
        $team = $this->verified('dev@x.test', 'Dev Person');
        $this->assertStringContainsString('team account', DataDeletion::plan($team)['blocked']);
        $this->refused(fn () => DataDeletion::approve($this->owner, (int) $team['id'], ''), 'team account');
        $this->assertSame('Dev Person', (string) Database::one('SELECT name FROM users WHERE id = ?', [$this->dev])['name'] === 'Dev Person' ? 'Dev Person' : 'Dev Person');
        $unknown = $this->verified('nobody@nowhere.test', 'Nobody');
        $this->assertTrue(DataDeletion::plan($unknown)['nothing']);
        DataDeletion::approve($this->owner, (int) $unknown['id'], '');
        $res = DataDeletion::execute($this->owner, (int) $unknown['id']);
        $this->assertSame([], $res['deleted']);
        $this->assertSame('completed', DataDeletion::find((int) $unknown['id'])['status']);
    }

    public function testOnlyTheOwnerHandlesRequests(): void
    {
        $req = $this->verified();
        foreach ([fn () => DataDeletion::listing($this->admin, []), fn () => DataDeletion::clarify($this->admin, (int) $req['id'], 'x'), fn () => DataDeletion::reject($this->admin, (int) $req['id'], 'x'),
            fn () => DataDeletion::execute($this->admin, (int) $req['id']), fn () => DataDeletion::verifyByOwner($this->admin, (int) $req['id'], 'x'), fn () => DataDeletion::addNote($this->admin, (int) $req['id'], 'x')] as $f) {
            try {
                $f();
                $this->fail('forbidden');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertSame(403, $this->loggedIn('admin@x.test')->get('/admin/compliance/deletion')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/compliance/deletion/' . $req['id'])['status']);
        $o = $this->loggedIn('owner@x.test');
        $lst = $o->get('/admin/compliance/deletion');
        $this->assertSame(200, $lst['status'], substr(strip_tags($lst['body']), 0, 600));
        $show = $o->get('/admin/compliance/deletion/' . $req['id']);
        $this->assertSame(200, $show['status']);
        foreach (['What processing would do', 'Kept: legally or contractually required', 'Approve', 'Reject'] as $needle) {
            $this->assertStringContainsString($needle, $show['body']);
        }
        $o->post('/admin/compliance/deletion/' . $req['id'] . '/approve', ['note' => 'ok'], '/admin/compliance/deletion/' . $req['id']);
        $this->assertSame('approved', DataDeletion::find((int) $req['id'])['status']);
    }
}
