<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\HtmlSanitizer;
use Vertex\Services\LegalDocuments;

/** Compliance & legal: versioned documents, publication rules, sanitising, Owner-only access, and what the public sees. */
final class LegalDocumentsTest extends HttpTestCase
{
    use LegalTestSupport;

    private int $owner;
    private int $admin;
    private int $client;

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
        $this->client = $this->makeUser('client@x.test', ['client']);
        ComplianceSettings::flush();
    }

    private function facts(): void
    {
        ComplianceSettings::save($this->owner, 'business', ['proprietor_name' => 'Asha Test Proprietor', 'operator_name' => 'Ravi Test Operator', 'jurisdiction' => 'Testville', 'brand_name' => 'Krazy Integrated Solutions', 'abbreviation' => 'KIS',
            'legal_enterprise_name' => 'Swamini Mahila Gruh Udyog', 'legal_structure' => 'Sole Proprietorship', 'business_units' => 'Krazy Integrated Solutions; The Young Books', 'operator_role' => 'Authorised operational representative', 'gst_status' => 'Not registered for GST']);
        ComplianceSettings::save($this->owner, 'contact', ['contact_email' => 'hello@kis.test', 'whatsapp' => '+91 90000 11111', 'grievance_email' => 'privacy@kis.test']);
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

    public function testTheDocumentsAreSeededAsDraftsAndTheirPagesSaySoHonestly(): void
    {
        $docs = LegalDocuments::documents();
        $keys = array_column($docs, 'doc_key');
        foreach (['privacy-policy', 'terms-and-conditions', 'refund-policy', 'cancellation-policy', 'cookie-policy', 'payment-policy', 'accessibility-statement', 'security-disclosure'] as $k) {
            $this->assertContains($k, $keys);
        }
        foreach ($docs as $d) {
            $this->assertNull($d['live'], $d['doc_key'] . ' is not published until the Owner publishes it');
            $this->assertNotNull($d['draft']);
        }
        $c = $this->client();
        $page = $c->get('/privacy-policy');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('being finalised', $page['body']);
        $this->assertStringContainsString('noindex', $page['body']);
        $this->assertSame(301, $c->get('/refund-and-cancellation-policy')['status']);
        $this->assertSame(404, $c->get('/not-a-policy-at-all')['status']);
    }

    public function testNothingCanGoLiveWhileTheOwnersOwnFactsAreMissing(): void
    {
        $this->refused(fn () => $this->pub('privacy-policy'), 'Legal proprietor');
        $this->facts();
        $this->pub('privacy-policy');
        $live = LegalDocuments::live('privacy-policy');
        $this->assertSame(1, (int) $live['version_no']);
        $this->assertNotNull($live['published_at']);
        $this->assertSame($this->owner, (int) $live['published_by']);
    }

    public function testThePublicPageShowsTheFilledInTextAndVersion(): void
    {
        $this->facts();
        $this->pub('privacy-policy', ['effective_date' => '2026-12-01']);
        $page = $this->client()->get('/privacy-policy');
        $this->assertSame(200, $page['status']);
        $b = $page['body'];
        foreach (['Krazy Integrated Solutions is a business unit operated under Swamini Mahila Gruh Udyog, a sole proprietorship.', 'Asha Test Proprietor', 'Ravi Test Operator', 'hello@kis.test', 'Version 1', '1 December 2026', 'On this page', 'Information we collect', 'Children'] as $needle) {
            $this->assertStringContainsString($needle, $b);
        }
        $this->assertStringNotContainsString('{{', $b, 'every token was filled in');
        $this->assertStringNotContainsString('being finalised', $b);
        $this->assertStringContainsString('privacy-policy', $this->client()->get('/')['body'], 'linked from the footer');
    }

    public function testVersionsAreKeptAndRestorableAndMaterialChangesNeedASummary(): void
    {
        $this->facts();
        $this->pub('cookie-policy');
        $v1 = LegalDocuments::live('cookie-policy');
        $body = (string) $v1['body_html'];
        LegalDocuments::saveDraft($this->owner, 'cookie-policy', ['title' => 'Cookie Policy', 'body_html' => str_replace('We do not use analytics', 'We do not use any analytics', $body), 'is_material' => '1']);
        $this->refused(fn () => $this->pub('cookie-policy'), 'summary');
        LegalDocuments::saveDraft($this->owner, 'cookie-policy', ['title' => 'Cookie Policy', 'body_html' => str_replace('We do not use analytics', 'We do not use any analytics', $body), 'is_material' => '1', 'change_summary' => 'Clarified analytics wording']);
        $this->pub('cookie-policy');
        $this->assertSame(2, (int) LegalDocuments::live('cookie-policy')['version_no']);
        $versions = LegalDocuments::versions('cookie-policy');
        $this->assertSame(['published', 'superseded'], array_column($versions, 'status'));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE d.doc_key = 'cookie-policy' AND v.status = 'published'")['c'], 'exactly one live version');
        LegalDocuments::restore($this->owner, 'cookie-policy', 1);
        $this->assertSame(2, (int) LegalDocuments::live('cookie-policy')['version_no'], 'a restore only makes a draft');
        $draft = Database::one("SELECT v.* FROM legal_document_versions v JOIN legal_documents d ON d.id = v.document_id WHERE v.status = 'draft' AND d.doc_key = 'cookie-policy'");
        $this->assertSame(3, (int) $draft['version_no']);
        $this->assertSame($body, $draft['body_html']);
        $this->pub('cookie-policy');
        $this->assertSame(3, (int) LegalDocuments::live('cookie-policy')['version_no']);
        $this->assertGreaterThanOrEqual(5, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action LIKE 'legal.%'")['c']);
    }

    public function testRequiredSectionsCannotBeRemoved(): void
    {
        $this->facts();
        $v = LegalDocuments::version('refund-policy', 1);
        $cut = (string) preg_replace('~<h2 id="your-legal-rights">.*?(?=<h2 id="how-refunds-are-paid">)~s', '', (string) $v['body_html']);
        $this->assertNotSame($v['body_html'], $cut);
        LegalDocuments::saveDraft($this->owner, 'refund-policy', ['title' => 'Refund Policy', 'body_html' => $cut]);
        $this->refused(fn () => $this->pub('refund-policy'), 'Your legal rights');
        $this->assertNull(LegalDocuments::live('refund-policy'));
    }

    public function testUnsafeHtmlIsStrippedWhenSaving(): void
    {
        $this->facts();
        $evil = '<h2>Information we collect</h2><p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <a href="https://ok.test/p" style="color:red">good</a></p><iframe src="https://evil.test"></iframe><img src=x onerror=alert(1)>';
        LegalDocuments::saveDraft($this->owner, 'security-disclosure', ['title' => 'Security Disclosure', 'body_html' => $evil]);
        $saved = (string) Database::one("SELECT body_html b FROM legal_document_versions WHERE status = 'draft' AND document_id = (SELECT id FROM legal_documents WHERE doc_key = 'security-disclosure')")['b'];
        foreach (['<script', 'onclick', 'javascript:', '<iframe', 'onerror', 'style=', '<img'] as $bad) {
            $this->assertStringNotContainsString($bad, $saved);
        }
        $this->assertStringContainsString('href="https://ok.test/p"', $saved);
        $this->assertStringContainsString('rel="noopener noreferrer"', $saved);
        $this->assertSame('Hello', trim(strip_tags(HtmlSanitizer::clean('<p>Hello</p>'))));
    }

    public function testScheduledPublicationGoesLiveByItselfAndCanBeCancelled(): void
    {
        $this->facts();
        $this->pub('payment-policy');
        LegalDocuments::saveDraft($this->owner, 'payment-policy', ['title' => 'Payment Policy', 'body_html' => '<h2>How payments work</h2><p>New text</p><h2>Taxes and invoices</h2><p>x</p><h2>Failed or disputed payments</h2><p>y</p>']);
        $this->reviewDraft('payment-policy');
        $this->refused(fn () => LegalDocuments::schedule($this->owner, 'payment-policy', gmdate('Y-m-d\TH:i', time() - 3600)), 'future');
        LegalDocuments::schedule($this->owner, 'payment-policy', gmdate('Y-m-d\TH:i', time() + 86400));
        $this->assertSame(1, (int) LegalDocuments::live('payment-policy')['version_no'], 'not live yet');
        $this->refused(fn () => LegalDocuments::saveDraft($this->owner, 'payment-policy', ['title' => 'x', 'body_html' => '<p>x</p>']), 'scheduled');
        LegalDocuments::cancelSchedule($this->owner, 'payment-policy');
        $this->assertSame('draft', Database::one("SELECT status s FROM legal_document_versions WHERE version_no = 2")['s']);
        LegalDocuments::schedule($this->owner, 'payment-policy', gmdate('Y-m-d\TH:i', time() + 86400));
        Database::run("UPDATE legal_document_versions SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE status = 'scheduled'");
        $live = LegalDocuments::live('payment-policy');
        $this->assertSame(2, (int) $live['version_no'], 'the schedule fired');
        $this->assertStringContainsString('New text', $this->client()->get('/payment-policy')['body']);
    }

    public function testMandatoryDocumentsCannotBeTakenOfflineOthersCan(): void
    {
        $this->facts();
        $this->pub('privacy-policy');
        $this->pub('payment-policy');
        $this->refused(fn () => LegalDocuments::unpublish($this->owner, 'privacy-policy', 'oops'), 'mandatory');
        $this->assertNotNull(LegalDocuments::live('privacy-policy'));
        $this->refused(fn () => LegalDocuments::unpublish($this->owner, 'payment-policy', ''), 'reason');
        LegalDocuments::unpublish($this->owner, 'payment-policy', 'Under revision');
        $this->assertNull(LegalDocuments::live('payment-policy'));
        $this->assertStringContainsString('being finalised', $this->client()->get('/payment-policy')['body']);
    }

    public function testOnlyTheOwnerCanManageCompliance(): void
    {
        foreach ([[$this->admin, 'administrator'], [$this->client, 'client']] as [$u]) {
            foreach ([fn () => LegalDocuments::publish($u, 'privacy-policy'), fn () => LegalDocuments::saveDraft($u, 'privacy-policy', ['title' => 'x', 'body_html' => '<p>x</p>']),
                fn () => LegalDocuments::restore($u, 'privacy-policy', 1), fn () => ComplianceSettings::save($u, 'business', ['proprietor_name' => 'X'])] as $f) {
                try {
                    $f();
                    $this->fail('should be forbidden');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->status);
                }
            }
        }
        $a = $this->loggedIn('admin@x.test');
        $this->assertSame(403, $a->get('/admin/compliance')['status']);
        $this->assertSame(403, $a->get('/admin/compliance/documents/privacy-policy')['status']);
        $this->assertSame(403, $a->post('/admin/compliance/documents/privacy-policy/save', ['title' => 'x', 'body_html' => '<p>x</p>'], '/account')['status']);
        $this->assertSame(403, $this->loggedIn('client@x.test')->get('/admin/compliance')['status']);
        $this->assertSame(303, $this->client()->get('/admin/compliance')['status'], 'signed-out visitors are sent to sign in');
        $this->assertSame(200, $this->loggedIn('owner@x.test')->get('/admin/compliance')['status']);
    }

    public function testTheOwnerWorksThroughTheForms(): void
    {
        $this->facts();
        $this->approveFacts();
        $o = $this->loggedIn('owner@x.test');
        $page = $o->get('/admin/compliance/documents/security-disclosure');
        $this->assertSame(200, $page['status']);
        foreach (['Save draft', 'Preview', 'Publish now', 'Schedule publication', 'data-rich-editor', 'Version history', 'legal-editor.js'] as $needle) {
            $this->assertStringContainsString($needle, $page['body']);
        }
        $body = '<h2>How we protect the website</h2><p>Edited by the Owner with the form.</p><h2>Reporting a problem</h2><p>Write to {{email}}.</p>';
        $o->post('/admin/compliance/documents/security-disclosure/save', ['title' => 'Security Disclosure', 'body_html' => $body, 'change_summary' => 'Test'], '/admin/compliance/documents/security-disclosure');
        $this->assertStringContainsString('Edited by the Owner', (string) Database::one("SELECT body_html b FROM legal_document_versions WHERE status = 'draft' AND document_id = (SELECT id FROM legal_documents WHERE doc_key = 'security-disclosure')")['b']);
        $preview = $o->post('/admin/compliance/documents/security-disclosure/preview', ['title' => 'Security Disclosure', 'body_html' => $body], '/admin/compliance/documents/security-disclosure');
        $this->assertSame(200, $preview['status']);
        $this->assertStringContainsString('PREVIEW ONLY', $preview['body']);
        $this->assertStringContainsString('hello@kis.test', $preview['body'], 'tokens are filled in the preview');
        $this->assertNull(LegalDocuments::live('security-disclosure'), 'a preview publishes nothing');
        $o->post('/admin/compliance/documents/security-disclosure/publish', ['title' => 'Security Disclosure', 'body_html' => $body, 'confirm_password' => 'wrong'], '/admin/compliance/documents/security-disclosure');
        $this->assertNull(LegalDocuments::live('security-disclosure'), 'publishing needs the Owner\'s password');
        $o->post('/admin/compliance/documents/security-disclosure/publish', ['title' => 'Security Disclosure', 'body_html' => $body, 'confirm_password' => DatabaseTestCase::PASSWORD], '/admin/compliance/documents/security-disclosure');
        $this->assertNotNull(LegalDocuments::live('security-disclosure'));
        $this->assertStringContainsString('Edited by the Owner', $this->client()->get('/security-disclosure')['body']);
        $this->assertSame(200, $o->get('/admin/compliance/documents/security-disclosure/versions/1')['status']);
    }

    public function testSettingsValidateAndFeedThePublicBusinessAndContactPages(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $o->post('/admin/compliance/settings/contact', ['contact_email' => 'not-an-email', 'whatsapp' => '+91 90000 22222'], '/admin/compliance/settings/contact');
        $this->assertSame('', ComplianceSettings::get('contact_email'), 'a bad email is refused');
        $this->facts();
        ComplianceSettings::save($this->owner, 'business', ['udyam_number' => 'UDYAM-XX-00-0000000', 'shop_act_number' => 'SHOP-TEST-1']);
        $hidden = $this->client()->get('/legal-business-information')['body'] . $this->client()->get('/contact-and-grievance')['body'];
        foreach (['Asha Test Proprietor', 'Ravi Test Operator', 'UDYAM-XX-00-0000000', 'SHOP-TEST-1', 'hello@kis.test', '+91 90000 11111', 'privacy@kis.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $hidden, $secret . ' stays internal until the Owner approves it');
        }
        $this->approveFacts();
        $biz = $this->client()->get('/legal-business-information')['body'];
        $this->assertStringContainsString('UDYAM-XX-00-0000000', $biz);
        $this->assertStringContainsString('SHOP-TEST-1', $biz);
        foreach (['operated under Swamini Mahila Gruh Udyog, a sole proprietorship', 'Asha Test Proprietor', 'Ravi Test Operator', 'The Young Books', 'Not registered for GST', 'hello@kis.test'] as $needle) {
            $this->assertStringContainsString($needle, $biz);
        }
        $this->assertStringNotContainsString('Registered with', $biz);
        ComplianceSettings::save($this->owner, 'business', ['public_address' => '12 Test Street, Testville', 'show_address' => '']);
        $this->assertStringNotContainsString('12 Test Street', $this->client()->get('/legal-business-information')['body'], 'the address stays private unless published');
        ComplianceSettings::save($this->owner, 'business', ['public_address' => '12 Test Street, Testville', 'show_address' => '1']);
        $this->assertStringContainsString('12 Test Street', $this->client()->get('/legal-business-information')['body']);
        $contact = $this->client()->get('/contact-and-grievance')['body'];
        $this->assertStringContainsString('privacy@kis.test', $contact);
        $this->assertStringContainsString('+91 90000 11111', $contact);
        $this->assertStringNotContainsString('company', mb_strtolower($biz), 'the business is never described as a company');
    }
}
