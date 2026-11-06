<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AssetRegister;
use Vertex\Services\ComplianceDashboard;
use Vertex\Services\SiteContent;

/** The Owner's compliance checklist, the asset and licence register, and permission to show client work. All data is fictional. */
final class ComplianceDashboardTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    public function testTheChecklistHasTheTwentyRequirementsAllPendingAndNoScore(): void
    {
        $items = ComplianceDashboard::items();
        $this->assertCount(20, $items);
        $this->assertSame(array_fill(0, 20, 'pending'), array_column($items, 'status'), 'nothing is Complete until the Owner says so');
        $this->assertSame(['pending' => 20, 'complete' => 0, 'needs_attention' => 0, 'legal_review' => 0, 'not_applicable' => 0], ComplianceDashboard::counts());
        $this->assertSame(['Pending', 'Complete', 'Needs attention', 'Requires legal review', 'Not applicable'], array_values(ComplianceDashboard::STATUSES));
        foreach ($items as $i) {
            $this->assertNotEmpty($i['checks'], $i['item_key'] . ' shows what the system could check');
        }
        $page = $this->loggedIn('owner@x.test')->get('/admin/compliance/checklist');
        $this->assertSame(200, $page['status']);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*%/', strip_tags($page['body']), 'no invented percentage');
        $this->assertDoesNotMatchRegularExpression('/>\s*(Compliant|Fully compliant)\s*</i', $page['body'], 'no compliance label');
        $this->assertStringContainsString('Requires legal review', $page['body']);
    }

    public function testOnlyTheOwnerSetsAStatusAndItIsAudited(): void
    {
        ComplianceDashboard::update($this->owner, 'privacy_policy', ['status' => 'legal_review', 'responsible' => 'Counsel', 'next_action' => 'Send to lawyer']);
        $row = Database::one("SELECT * FROM compliance_items WHERE item_key = 'privacy_policy'");
        $this->assertSame(['legal_review', 'Counsel', 'Send to lawyer'], [$row['status'], $row['responsible'], $row['next_action']]);
        $this->assertNotNull($row['last_reviewed_at']);
        $this->assertSame(1, ComplianceDashboard::counts()['legal_review']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'compliance.item_updated'")['c']);
        foreach ([[$this->admin, 'administrator'], [$this->manager, 'manager']] as [$uid, $who]) {
            try {
                ComplianceDashboard::update($uid, 'terms', ['status' => 'complete']);
                $this->fail($who . ' must not set a compliance status');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->expectException(ValidationException::class);
        ComplianceDashboard::update($this->owner, 'terms', ['status' => 'compliant']);
    }

    public function testChecklistRoutesAreOwnerOnly(): void
    {
        $this->assertSame(403, $this->loggedIn('admin@x.test')->get('/admin/compliance/checklist')['status']);
        $this->assertSame(403, $this->loggedIn('admin@x.test')->get('/admin/compliance/assets')['status']);
        $c = $this->loggedIn('owner@x.test');
        $r = $c->post('/admin/compliance/checklist/terms', ['status' => 'needs_attention', 'next_action' => 'Fill the placeholders'], '/admin/compliance/checklist');
        $this->assertSame(303, $r['status']);
        $this->assertSame('needs_attention', Database::one("SELECT status FROM compliance_items WHERE item_key = 'terms'")['status']);
        $this->assertSame(404, $c->post('/admin/compliance/checklist/nope', ['status' => 'complete'], '/admin/compliance/checklist')['status']);
    }

    public function testTheAssetRegisterListsWhatShipsButInventsNoLicence(): void
    {
        $rows = AssetRegister::all();
        $names = array_column($rows, 'name');
        $this->assertContains('krazy-logo.png', $names);
        $this->assertContains('System font stack', $names);
        foreach ($rows as $r) {
            if ($r['asset_key'] !== 'system:font-stack') {
                $this->assertSame('unverified', $r['status']);
                $this->assertNull($r['licence'], $r['name'] . ' has no licence recorded until the Owner records one');
                $this->assertNull($r['source']);
            }
        }
        $s = AssetRegister::summary();
        $this->assertGreaterThan(0, $s['unverified']);
        $this->assertSame(1, $s['not_needed']);
        $this->assertSame(count($rows), count(AssetRegister::all()), 'listing again does not duplicate');
    }

    public function testAnAssetCanBeConfirmedOnlyWithASourceAndALicence(): void
    {
        AssetRegister::all();
        $logo = Database::one("SELECT id FROM asset_register WHERE name = 'krazy-logo.png'");
        try {
            AssetRegister::save($this->owner, (int) $logo['id'], ['name' => 'krazy-logo.png', 'kind' => 'image', 'status' => 'confirmed']);
            $this->fail('A confirmation needs a source and a licence');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('where it came from', implode(' ', $e->errors));
        }
        AssetRegister::save($this->owner, (int) $logo['id'], ['name' => 'krazy-logo.png', 'kind' => 'image', 'source' => 'Designed for KIS', 'evidence' => 'Design agreement dated (fictional)', 'status' => 'confirmed']);
        $this->assertSame('confirmed', Database::one('SELECT status FROM asset_register WHERE id = ?', [$logo['id']])['status']);
        $before = (int) Database::one('SELECT COUNT(*) c FROM asset_register')['c'];
        AssetRegister::all();
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM asset_register')['c']);
        $this->expectException(HttpException::class);
        AssetRegister::save($this->admin, null, ['name' => 'x', 'kind' => 'other', 'status' => 'unverified']);
    }

    public function testClientWorkIsPublishedOnlyWithRecordedPermission(): void
    {
        foreach (['portfolio' => ['title' => 'Shop for a fictional client', 'summary' => 'A small shop website.', 'category' => 'Web'], 'clients' => ['name' => 'Fictional Client Co', 'country' => 'India']] as $entity => $in) {
            $row = SiteContent::save($this->admin, $entity, null, $in);
            $this->assertSame(0, (int) $row['permission_confirmed']);
            try {
                SiteContent::setStatus($this->admin, $entity, $row, 'published');
                $this->fail("{$entity} must not publish without permission");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('permission', implode(' ', $e->errors));
            }
            $row = SiteContent::save($this->admin, $entity, $row['public_id'], $in + ['permission_confirmed' => '1', 'permission_note' => 'WhatsApp message of 1 Jan (fictional)']);
            SiteContent::setStatus($this->admin, $entity, $row, 'published');
            $this->assertSame('published', Database::one("SELECT status FROM site_{$entity} WHERE id = ?", [$row['id']])['status']);
        }
        $this->assertSame(0, ComplianceDashboard::unconfirmedPublished());
        Database::run("UPDATE site_portfolio SET permission_confirmed = 0 WHERE status = 'published'");
        $this->assertSame(1, ComplianceDashboard::unconfirmedPublished(), 'a published item without permission is flagged on the checklist');
        $claims = array_values(array_filter(ComplianceDashboard::items(), static fn (array $i): bool => $i['item_key'] === 'unsupported_claims'))[0];
        $this->assertFalse($claims['checks'][0]['ok']);
    }
}
