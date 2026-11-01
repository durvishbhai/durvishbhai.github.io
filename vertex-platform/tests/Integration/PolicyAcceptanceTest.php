<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\LegalDocuments;
use Vertex\Services\Mailer;
use Vertex\Services\PolicyAcceptance;

/** Materially updated policies: notice, acceptance, restriction after the deadline or a refusal, reactivation, and the Owner's tools. */
final class PolicyAcceptanceTest extends HttpTestCase
{
    private int $owner;
    private int $admin;
    private int $alpha;
    private int $bravo;

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
        $this->alpha = $this->makeUser('alpha@client.test', ['client']);
        $this->bravo = $this->makeUser('bravo@client.test', ['client']);
        ComplianceSettings::flush();
        PolicyAcceptance::reset();
        ComplianceSettings::save($this->owner, 'business', ['proprietor_name' => 'P Test', 'operator_name' => 'O Test', 'jurisdiction' => 'Testville']);
        ComplianceSettings::save($this->owner, 'contact', ['contact_email' => 'hello@kis.test', 'whatsapp' => '+91 90000 11111']);
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        Mailer::$sent = [];
    }

    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
    }

    /** Publishes v1 (not material), then a material v2 that clients must accept. */
    private function materialUpdate(): void
    {
        LegalDocuments::publish($this->owner, 'terms-and-conditions');
        $v1 = LegalDocuments::live('terms-and-conditions');
        LegalDocuments::saveDraft($this->owner, 'terms-and-conditions', ['title' => 'Terms and Conditions', 'body_html' => str_replace('Limitation of liability', 'Limitation of liability', (string) $v1['body_html']) . '<p>New clause.</p>', 'is_material' => '1', 'change_summary' => 'Added a new clause about X']);
        LegalDocuments::publish($this->owner, 'terms-and-conditions');
        PolicyAcceptance::reset();
    }

    private function pastDeadline(): void
    {
        Database::run('UPDATE legal_document_versions SET accept_by = ? WHERE status = \'published\' AND is_material = 1', [AppTime::todayPlus(-1)]);
        PolicyAcceptance::reset();
    }

    private function versionId(): int
    {
        return (int) Database::one("SELECT v.id FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE d.doc_key = 'terms-and-conditions' AND v.status = 'published'")['id'];
    }

    public function testAMaterialUpdateNotifiesEveryClientAndSetsADeadline(): void
    {
        $this->materialUpdate();
        $subjects = array_column(Mailer::$sent, 'subject', 'to');
        $this->assertSame('We updated our Terms and Conditions: please review it', $subjects['alpha@client.test']);
        $this->assertArrayHasKey('bravo@client.test', $subjects);
        $this->assertArrayNotHasKey('admin@x.test', $subjects, 'staff are not asked');
        $mail = array_values(array_filter(Mailer::$sent, static fn (array $m): bool => $m['to'] === 'alpha@client.test'))[0];
        $this->assertStringContainsString('Added a new clause about X', $mail['body']);
        $this->assertStringContainsString('Nothing is deleted', $mail['body']);
        $this->assertStringContainsString('/policy-acceptance', $mail['body']);
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM legal_notices')['c']);
        $v = Database::one('SELECT accept_by FROM legal_document_versions WHERE id = ?', [$this->versionId()]);
        $this->assertSame(AppTime::todayPlus(14), $v['accept_by']);
        $st = PolicyAcceptance::state($this->alpha);
        $this->assertCount(1, $st['pending']);
        $this->assertFalse($st['restricted'], 'before the deadline nothing is restricted');
        $this->assertCount(0, PolicyAcceptance::state($this->admin)['pending'], 'only clients are asked');
    }

    public function testANonMaterialUpdateAsksNobody(): void
    {
        LegalDocuments::publish($this->owner, 'terms-and-conditions');
        $v1 = LegalDocuments::live('terms-and-conditions');
        LegalDocuments::saveDraft($this->owner, 'terms-and-conditions', ['title' => 'Terms and Conditions', 'body_html' => $v1['body_html'] . '<p>Typo fixed.</p>', 'is_material' => '']);
        LegalDocuments::publish($this->owner, 'terms-and-conditions');
        PolicyAcceptance::reset();
        $this->assertSame([], PolicyAcceptance::state($this->alpha)['pending']);
        $this->assertSame([], Mailer::$sent);
    }

    public function testBeforeTheDeadlineTheClientSeesABannerAndKeepsAccess(): void
    {
        $this->materialUpdate();
        $c = $this->loggedIn('alpha@client.test');
        $dash = $c->get('/dashboard');
        $this->assertSame(200, $dash['status']);
        $this->assertStringContainsString('Updated terms need your review', $dash['body']);
        $page = $c->get('/policy-acceptance');
        $this->assertSame(200, $page['status']);
        foreach (['Terms and Conditions', 'Added a new clause about X', 'I accept', 'I do not accept', 'future services'] as $needle) {
            $this->assertStringContainsString($needle, $page['body']);
        }
    }

    public function testAfterTheDeadlineAccessIsRestrictedButRecordsStayReadable(): void
    {
        $this->materialUpdate();
        $this->pastDeadline();
        $this->assertTrue(PolicyAcceptance::state($this->alpha)['restricted']);
        $c = $this->loggedIn('alpha@client.test');
        $r = $c->get('/dashboard');
        $this->assertSame(303, $r['status']);
        $this->assertSame('/policy-acceptance', $r['location']);
        $this->assertSame(303, $c->get('/my/project-requests')['status']);
        $this->assertSame('/policy-acceptance', $c->post('/projects', ['title' => 'x'], '/policy-acceptance')['location'] ?? '', 'writes are blocked too');
        foreach (['/policy-acceptance', '/account', '/my/invoices', '/my/receipts', '/my/agreements'] as $ok) {
            $res = $c->get($ok);
            $this->assertNotSame('/policy-acceptance', $res['location'], $ok . ' stays reachable');
            $this->assertSame(200, $res['status'], $ok);
        }
        $this->assertStringContainsString('Your account is restricted', $c->get('/policy-acceptance')['body']);
        $this->assertSame(200, $c->get('/privacy-policy')['status'], 'the public legal pages are always open');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients WHERE user_id = ?', [$this->alpha])['c'], 'nothing is deleted');
        $this->assertSame('active', Database::one('SELECT status s FROM users WHERE id = ?', [$this->alpha])['s'], 'the account status itself is untouched');
        // other clients and staff are unaffected
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get('/dashboard')['status']);
        PolicyAcceptance::accept($this->bravo, [$this->versionId()], '203.0.113.9', 'PHPUnit');
        $this->assertSame(200, $this->loggedIn('bravo@client.test')->get('/dashboard')['status']);
    }

    public function testTheRestrictedEmailGoesOutOnceAndAcceptingRestoresAccessWithARecord(): void
    {
        $this->materialUpdate();
        $this->pastDeadline();
        Mailer::$sent = [];
        PolicyAcceptance::noteRestricted($this->alpha);
        PolicyAcceptance::noteRestricted($this->alpha);
        $restricted = array_values(array_filter(Mailer::$sent, static fn (array $m): bool => str_contains($m['subject'], 'is restricted')));
        $this->assertCount(1, $restricted, 'told once');
        Mailer::$sent = [];
        PolicyAcceptance::accept($this->alpha, [$this->versionId()], '203.0.113.5', 'Mozilla/5.0 test');
        $this->assertFalse(PolicyAcceptance::state($this->alpha)['restricted'], 'restored at once');
        $this->assertContains('Your KIS account access is restored', array_column(Mailer::$sent, 'subject'));
        $a = Database::one('SELECT * FROM legal_acceptances WHERE user_id = ?', [$this->alpha]);
        $this->assertSame('accepted', $a['decision']);
        $this->assertSame($this->versionId(), (int) $a['version_id']);
        $this->assertSame('203.0.113.5', $a['ip']);
        $this->assertSame('Mozilla/5.0 test', $a['user_agent']);
        $this->assertNotNull($a['decided_at']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'legal.accepted'")['c']);
    }

    public function testAcceptingAndDecliningOverHttp(): void
    {
        $this->materialUpdate();
        $c = $this->loggedIn('alpha@client.test');
        $vid = $this->versionId();
        $c->post('/policy-acceptance', ['versions' => [(string) $vid], 'decision' => 'accept'], '/policy-acceptance');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM legal_acceptances')['c'], 'the confirmation box is required');
        $d = $c->post('/policy-acceptance', ['versions' => [(string) $vid], 'decision' => 'decline'], '/policy-acceptance');
        $this->assertSame('declined', Database::one('SELECT decision d FROM legal_acceptances WHERE user_id = ?', [$this->alpha])['d']);
        $this->assertSame('/policy-acceptance', $c->get('/dashboard')['location'] ?? '', 'refusing restricts the account at once, even before the deadline');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM clients WHERE user_id = ?', [$this->alpha])['c']);
        $r = $c->post('/policy-acceptance', ['versions' => [(string) $vid], 'decision' => 'accept', 'confirm' => '1'], '/policy-acceptance');
        $this->assertSame('/dashboard', $r['location']);
        $this->assertSame('accepted', Database::one('SELECT decision d FROM legal_acceptances WHERE user_id = ?', [$this->alpha])['d']);
        $this->assertSame(200, $c->get('/dashboard')['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM legal_acceptances WHERE user_id = ?', [$this->alpha])['c'], 'one row per client and version');
    }

    public function testAcceptanceDoesNotReactivateAnUnrelatedlySuspendedAccount(): void
    {
        $this->materialUpdate();
        $this->pastDeadline();
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$this->alpha]);
        PolicyAcceptance::accept($this->alpha, [$this->versionId()], null, null);
        $this->assertSame('deactivated', Database::one('SELECT status s FROM users WHERE id = ?', [$this->alpha])['s'], 'a deactivation for another reason stays');
    }

    public function testRestrictionCanBeSwitchedOff(): void
    {
        $this->materialUpdate();
        $this->pastDeadline();
        ComplianceSettings::save($this->owner, 'acceptance', ['acceptance_days' => '14', 'restriction_enabled' => '']);
        PolicyAcceptance::reset();
        $st = PolicyAcceptance::state($this->alpha);
        $this->assertCount(1, $st['pending']);
        $this->assertFalse($st['restricted']);
        $this->assertSame(200, $this->loggedIn('alpha@client.test')->get('/dashboard')['status']);
    }

    public function testNewClientsAcceptTheCurrentVersionAtSignup(): void
    {
        $this->materialUpdate();
        $new = $this->makeUser('new@client.test', ['client']);
        $this->assertCount(1, PolicyAcceptance::state($new)['pending'], 'an account made another way is asked');
        PolicyAcceptance::recordSignup($new, '203.0.113.7', 'UA');
        $this->assertCount(0, PolicyAcceptance::state($new)['pending']);
        $this->assertSame('signup', Database::one('SELECT method m FROM legal_acceptances WHERE user_id = ?', [$new])['m']);
    }

    public function testTheOwnerSeesStatusSendsRemindersMovesTheDeadlineAndRecordsPaperAcceptance(): void
    {
        $this->materialUpdate();
        $vid = $this->versionId();
        $o = $this->loggedIn('owner@x.test');
        $list = $o->get('/admin/compliance/acceptance');
        $this->assertSame(200, $list['status']);
        $this->assertStringContainsString('Terms and Conditions', $list['body']);
        $this->assertSame(200, $o->get('/admin/compliance/acceptance/' . $vid)['status']);
        Mailer::$sent = [];
        $this->assertSame(2, PolicyAcceptance::remind($this->owner, $vid));
        $this->assertSame(2, count(array_filter(Mailer::$sent, static fn (array $m): bool => str_starts_with($m['subject'], 'Reminder'))));
        PolicyAcceptance::extendDeadline($this->owner, $vid, AppTime::todayPlus(30));
        $this->assertSame(AppTime::todayPlus(30), Database::one('SELECT accept_by a FROM legal_document_versions WHERE id = ?', [$vid])['a']);
        $this->expectException(ValidationException::class);
        try {
            PolicyAcceptance::extendDeadline($this->owner, $vid, AppTime::todayPlus(-2));
        } finally {
            $o->post('/admin/compliance/acceptance/' . $vid . '/record', ['user' => (string) $this->alpha, 'note' => 'Signed on paper', 'confirm_password' => DatabaseTestCase::PASSWORD], '/admin/compliance/acceptance/' . $vid);
            $row = Database::one('SELECT * FROM legal_acceptances WHERE user_id = ?', [$this->alpha]);
            $this->assertSame('admin_record', $row['method']);
            $this->assertSame($this->owner, (int) $row['recorded_by']);
        }
    }

    public function testOnlyTheOwnerManagesAcceptance(): void
    {
        $this->materialUpdate();
        $vid = $this->versionId();
        foreach ([fn () => PolicyAcceptance::remind($this->admin, $vid), fn () => PolicyAcceptance::extendDeadline($this->admin, $vid, AppTime::todayPlus(5)), fn () => PolicyAcceptance::recordFor($this->admin, $this->alpha, $vid, 'x')] as $f) {
            try {
                $f();
                $this->fail('forbidden');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertSame(403, $this->loggedIn('admin@x.test')->get('/admin/compliance/acceptance')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/compliance/acceptance/' . $vid)['status']);
    }
}
