<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\LegalDocuments;
use Vertex\Services\PendingActions;

/** Verified business facts stay internal until approved; flagged documents stay drafts until a lawyer's review is recorded; the registration numbers are Owner-only. All data is fictional. */
final class VerifiedFactsTest extends HttpTestCase
{
    use LegalTestSupport;

    private int $owner;
    private int $admin;

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
        ComplianceSettings::flush();
        ComplianceSettings::save($this->owner, 'business', ['proprietor_name' => 'Test Proprietor', 'operator_name' => 'Test Operator', 'udyam_number' => 'UDYAM-XX-00-1111111', 'shop_act_number' => 'SA-2222', 'jurisdiction' => 'Testville', 'governing_law' => 'India']);
        ComplianceSettings::save($this->owner, 'contact', ['contact_email' => 'official@kis.test', 'whatsapp' => '+91 90000 33333', 'grievance_email' => 'grievance@kis.test']);
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }

    public function testOnlyTheOwnerCanEditTheUrnAndShopActNumber(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $page = $o->get('/admin/compliance/settings/business');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Udyam Registration Number (URN)', $page['body']);
        $this->assertStringContainsString('Shop Act licence / registration number', $page['body']);
        $this->assertStringContainsString('UDYAM-XX-00-1111111', $page['body']);
        $o->post('/admin/compliance/settings/business', ['udyam_number' => 'UDYAM-XX-00-9999999', 'shop_act_number' => 'SA-9999'], '/admin/compliance/settings/business');
        ComplianceSettings::flush();
        $this->assertSame(['UDYAM-XX-00-9999999', 'SA-9999'], [ComplianceSettings::get('udyam_number'), ComplianceSettings::get('shop_act_number')]);
        $a = $this->loggedIn('admin@x.test');
        $this->assertSame(403, $a->get('/admin/compliance/settings/business')['status']);
        $this->assertSame(403, $a->request('POST', '/admin/compliance/settings/business', ['_token' => $a->token('/account'), 'udyam_number' => 'HACK'])['status']);
        $this->expectException(HttpException::class);
        ComplianceSettings::save($this->admin, 'business', ['udyam_number' => 'HACK']);
    }

    public function testFactsStayInternalUntilTheOwnerApprovesThem(): void
    {
        $public = $this->client()->get('/legal-business-information')['body'] . $this->client()->get('/contact-and-grievance')['body'];
        foreach (['UDYAM-XX-00-1111111', 'SA-2222', 'Test Proprietor', 'Test Operator', 'official@kis.test', '+91 90000 33333', 'grievance@kis.test'] as $v) {
            $this->assertStringNotContainsString($v, $public, $v);
        }
        ComplianceSettings::save($this->owner, 'disclosure', ['approve_udyam' => '1']);
        $public = $this->client()->get('/legal-business-information')['body'];
        $this->assertStringContainsString('UDYAM-XX-00-1111111', $public, 'approved');
        $this->assertStringNotContainsString('SA-2222', $public, 'each fact is approved on its own');
        $this->assertStringNotContainsString('Test Proprietor', $public);
    }

    public function testNothingIsApprovedByDefaultAndGstIsNeverClaimed(): void
    {
        foreach (['proprietor', 'operator', 'udyam', 'shop_act', 'email', 'whatsapp', 'grievance'] as $f) {
            $this->assertFalse(ComplianceSettings::approved($f), $f);
        }
        $biz = mb_strtolower($this->client()->get('/legal-business-information')['body']);
        $this->assertStringContainsString('not registered for gst', $biz);
        $this->assertDoesNotMatchRegularExpression('/gstin|gst number|gst registration number/', $biz);
        $this->assertSame('', ComplianceSettings::get('retention_years'), 'no retention period is invented');
    }

    public function testAFactThatIsNotApprovedBlocksTheDocumentThatNeedsIt(): void
    {
        $this->refused(fn () => LegalDocuments::publish($this->owner, 'security-disclosure'), 'you have not approved it for public display');
        $this->approveFacts();
        LegalDocuments::publish($this->owner, 'security-disclosure');
        $this->assertNotNull(LegalDocuments::live('security-disclosure'));
    }

    public function testADocumentWithLegalFlagsStaysADraftUntilTheReviewIsRecordedForThatExactText(): void
    {
        $this->approveFacts();
        $this->refused(fn () => LegalDocuments::publish($this->owner, 'privacy-policy'), 'Requires Legal Review');
        $this->assertNull(LegalDocuments::live('privacy-policy'));
        // a non-Owner cannot record a review
        try {
            LegalDocuments::recordReview($this->admin, 'privacy-policy', 'Counsel', '2026-01-01', '');
            $this->fail('Only the Owner records a review');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->refused(fn () => LegalDocuments::recordReview($this->owner, 'privacy-policy', '', '2026-01-01', ''), 'lawyer');
        $this->refused(fn () => LegalDocuments::recordReview($this->owner, 'privacy-policy', 'Counsel', '2999-01-01', ''), 'date');
        LegalDocuments::recordReview($this->owner, 'privacy-policy', 'Test Counsel (fictional)', '2026-01-01', 'ok');
        // editing the text afterwards voids the review
        $doc = LegalDocuments::document('privacy-policy');
        $v = Database::one("SELECT * FROM legal_document_versions WHERE document_id = ? AND status = 'draft'", [$doc['id']]);
        LegalDocuments::saveDraft($this->owner, 'privacy-policy', ['title' => $v['title'], 'body_html' => $v['body_html'] . '<p>One more sentence.</p>']);
        $this->refused(fn () => LegalDocuments::publish($this->owner, 'privacy-policy'), 'Requires Legal Review');
        LegalDocuments::recordReview($this->owner, 'privacy-policy', 'Test Counsel (fictional)', '2026-01-02', '');
        LegalDocuments::publish($this->owner, 'privacy-policy');
        $this->assertNotNull(LegalDocuments::live('privacy-policy'));
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM legal_reviews')['c'], 'both reviews are kept');
    }

    public function testEveryDocumentWithFlagsIsListedAsRequiringLegalReviewAndNothingIsAutoPublished(): void
    {
        foreach (LegalDocuments::documents() as $d) {
            $this->assertNull($d['live'], $d['title'] . ' is not published automatically');
        }
        $pending = PendingActions::all();
        $legal = array_filter($pending, static fn (array $p): bool => $p['type'] === 'legal');
        $text = implode(' | ', array_column($legal, 'text'));
        foreach (['Privacy Policy', 'Terms and Conditions', 'Refund Policy', 'Cancellation Policy', 'Cookie Policy', 'Payment Policy', 'Accessibility Statement', 'proposals only'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $approve = implode(' | ', array_column(array_filter($pending, static fn (array $p): bool => $p['type'] === 'approve'), 'text'));
        $this->assertStringContainsString('Udyam Registration Number (URN)', $approve);
        $this->assertStringContainsString('Shop Act number', $approve);
        $info = implode(' | ', array_column(array_filter($pending, static fn (array $p): bool => $p['type'] === 'info'), 'text'));
        $this->assertStringContainsString('Grievance contact: name', $info);
        $this->assertStringContainsString('Record retention', $info);
        $page = $this->loggedIn('owner@x.test')->get('/admin/compliance');
        $this->assertStringContainsString('Pending actions', $page['body']);
        $this->assertStringContainsString('Requires Legal Review', $page['body']);
        $this->assertSame(403, $this->loggedIn('admin@x.test')->get('/admin/compliance')['status']);
    }

    public function testTheChecklistIsNeverMarkedCompleteByTheSystem(): void
    {
        $this->approveFacts();
        $this->assertSame(0, \Vertex\Services\ComplianceDashboard::counts()['complete']);
    }
}
