<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\PermissionSync;
use Vertex\Services\PriceChangeService;
use Vertex\Services\PricingService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/** Pricing admin page, project pricing tab and the client approval flow over HTTP. */
final class PricingHttpTest extends HttpTestCase
{
    private int $admin;
    private int $manager;
    private int $dev;
    private int $userA;
    private int $userB;
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        Database::run('DELETE FROM priority_pricing_rules WHERE version > 1 OR service_category <> \'*\'');
        Database::run("UPDATE priority_pricing_rules SET surcharge_type = 'none', fixed_minor = 0, percent_bps = 0, current_flag = 1, superseded_at = NULL");
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->userA = $this->makeUser('alpha@client.test', ['client']);
        $this->userB = $this->makeUser('bravo@client.test', ['client']);
        $this->alpha = $this->makeProject($this->userA, 'Alpha Website');
        Database::run("UPDATE projects SET service_category = 'website_development', priority = 'normal' WHERE id = ?", [$this->alpha['id']]);
        ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $this->manager, 'lead');
        ProjectAssignmentService::assign($this->admin, $this->alpha['id'], $this->dev, 'member');
    }

    private function url(string $suffix = ''): string
    {
        return '/projects/' . $this->alpha['public_id'] . '/pricing' . $suffix;
    }

    private function seedProposal(): array
    {
        PricingService::setRule($this->admin, ['service' => '*', 'priority' => 'urgent', 'type' => 'percent', 'percent' => '20', 'reason' => 'Test setup rule']);
        PriceChangeService::recordApproval($this->admin, ProjectPolicy::find($this->alpha['public_id']), ['quotation_ref' => 'QT-HTTP-1', 'base' => '100000']);
        PriceChangeService::changePriority($this->manager, ProjectPolicy::find($this->alpha['public_id']), 'urgent');

        return Database::one('SELECT * FROM price_change_requests');
    }

    public function testPricingRulesPageAndStepUpPassword(): void
    {
        $pw = DatabaseTestCase::PASSWORD;
        $mgr = $this->loggedIn('mgr@x.test');
        $page = $mgr->get('/admin/pricing');
        $this->assertSame(200, $page['status'], 'managers may view pricing rules');
        $this->assertStringNotContainsString('Save new version', $page['body'], 'no edit form without pricing.manage');
        $form = ['service' => '*', 'priority' => 'urgent', 'type' => 'percent', 'percent' => '15', 'reason' => 'Rush work premium'];
        $this->assertSame(403, $mgr->post('/admin/pricing', $form + ['confirm_password' => $pw], '/admin/pricing')['status']);
        $this->assertSame(403, $this->loggedIn('dev@x.test')->get('/admin/pricing')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->get('/admin/pricing')['status']);

        $admin = $this->loggedIn('admin@x.test');
        $this->assertStringContainsString('Save new version', $admin->get('/admin/pricing')['body']);
        $admin->post('/admin/pricing', $form + ['confirm_password' => 'wrong-password-123'], '/admin/pricing');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM priority_pricing_rules WHERE service_category = '*' AND priority = 'urgent'")['c'], 'wrong password: nothing written');
        $admin->post('/admin/pricing', $form + ['confirm_password' => ''], '/admin/pricing');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM priority_pricing_rules WHERE service_category = '*' AND priority = 'urgent'")['c']);
        $ok = $admin->post('/admin/pricing', $form + ['confirm_password' => $pw], '/admin/pricing');
        $this->assertSame(303, $ok['status']);
        $this->assertSame(1500, (int) Database::one("SELECT percent_bps FROM priority_pricing_rules WHERE service_category = '*' AND priority = 'urgent' AND current_flag = 1")['percent_bps']);
        $after = $admin->get('/admin/pricing')['body'];
        $this->assertStringContainsString('15% of the base price', $after);
        $this->assertStringContainsString('Rush work premium', $after);
        $admin->post('/admin/pricing', ['percent' => '501'] + $form + ['confirm_password' => $pw], '/admin/pricing');
        $this->assertStringContainsString('between 0.01 and 500', $admin->get('/admin/pricing')['body'], 'validation message shown');
        $this->assertSame(1500, (int) Database::one("SELECT percent_bps FROM priority_pricing_rules WHERE current_flag = 1 AND service_category = '*' AND priority = 'urgent'")['percent_bps']);
        $this->assertSame(419, $admin->postNoToken('/admin/pricing', $form + ['confirm_password' => $pw])['status'], 'posts without a CSRF token are refused');
    }

    public function testPricingTabVisibilityAndAccess(): void
    {
        $this->seedProposal();
        $this->assertStringContainsString('Priority change awaiting the client', $this->loggedIn('alpha@client.test')->get($this->url())['body']);
        $this->assertStringContainsString('Pricing', $this->loggedIn('alpha@client.test')->get('/projects/' . $this->alpha['public_id'])['body']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->get($this->url())['status'], 'another client');
        $this->assertSame(404, $this->loggedIn('dev@x.test')->get($this->url())['status'], 'workers have no pricing permission');
        $devPage = $this->loggedIn('dev@x.test')->get('/projects/' . $this->alpha['public_id'])['body'];
        $this->assertStringNotContainsString('/pricing"', $devPage, 'no Pricing tab for workers');
        $mgr = $this->loggedIn('mgr@x.test')->get($this->url());
        $this->assertSame(200, $mgr['status']);
        $this->assertStringContainsString('Withdraw proposal', $mgr['body']);
        $this->assertStringNotContainsString('Approve this change', $mgr['body'], 'staff cannot approve for the client');
        $this->assertStringNotContainsString('Record the approved quotation', $mgr['body']);
        $outsider = $this->makeUser('mgr2@x.test', ['manager']);
        $this->assertSame(404, $this->loggedIn('mgr2@x.test')->get($this->url())['status'], 'an unassigned manager cannot see the project at all');
    }

    public function testClientApprovesOverHttpAndStaffCannot(): void
    {
        $req = $this->seedProposal();
        $path = $this->url('/requests/' . $req['public_id'] . '/decision');
        $this->assertSame(403, $this->loggedIn('mgr@x.test')->post($path, ['decision' => 'approve'], $this->url())['status']);
        $this->assertSame(403, $this->loggedIn('admin@x.test')->post($path, ['decision' => 'approve'], '/admin/pricing')['status']);
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post($path, ['decision' => 'approve'], '/projects')['status']);
        $this->assertSame(404, $this->loggedIn('bravo@client.test')->post($path, ['decision' => 'approve'], '/projects')['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
        $this->assertSame('normal', Database::one('SELECT priority FROM projects WHERE id = ?', [$this->alpha['id']])['priority']);

        $client = $this->loggedIn('alpha@client.test');
        $r = $client->post($path, ['decision' => 'approve', 'comment' => 'Fine'], $this->url());
        $this->assertSame(303, $r['status']);
        $this->assertSame('urgent', Database::one('SELECT priority FROM projects WHERE id = ?', [$this->alpha['id']])['priority']);
        $this->assertSame(12000000, (int) Database::one('SELECT amount_minor FROM price_revisions WHERE revision_no = 2')['amount_minor']);
        $page = $client->get($this->url())['body'];
        $this->assertStringContainsString('120,000.00', $page, 'the new approved total is shown');
        $this->assertStringContainsString('Priority change', $page);
        $client->post($path, ['decision' => 'approve'], $this->url());
        $this->assertSame(2, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c'], 'replaying the request adds nothing');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM price_revisions WHERE amount_minor <> base_minor + surcharge_minor")['c']);
    }

    public function testPriorityChangeAndBaselineFormsOverHttp(): void
    {
        $mgr = $this->loggedIn('mgr@x.test');
        $r = $mgr->post('/projects/' . $this->alpha['public_id'] . '/priority', ['priority' => 'high', 'return' => 'pricing'], $this->url());
        $this->assertSame(303, $r['status']);
        $this->assertSame('high', Database::one('SELECT priority FROM projects WHERE id = ?', [$this->alpha['id']])['priority'], 'no quotation yet: direct change');
        $this->assertSame(403, $this->loggedIn('dev@x.test')->post('/projects/' . $this->alpha['public_id'] . '/priority', ['priority' => 'low'], '/projects')['status']);
        $this->assertSame(403, $this->loggedIn('alpha@client.test')->post('/projects/' . $this->alpha['public_id'] . '/priority', ['priority' => 'low'], '/projects')['status']);

        $form = ['quotation_ref' => 'QT-HTTP-9', 'base' => '75000', 'surcharge' => '0'];
        $this->assertSame(403, $mgr->post($this->url('/baseline'), $form + ['confirm_password' => DatabaseTestCase::PASSWORD], $this->url())['status'], 'managers cannot record approvals');
        $admin = $this->loggedIn('admin@x.test');
        $admin->post($this->url('/baseline'), $form + ['confirm_password' => 'nope-nope-nope'], $this->url());
        $this->assertNull(Database::one('SELECT approved_at FROM projects WHERE id = ?', [$this->alpha['id']])['approved_at'], 'wrong password: nothing recorded');
        $this->assertStringContainsString('Record the approved quotation', $admin->get($this->url())['body']);
        $admin->post($this->url('/baseline'), $form + ['confirm_password' => DatabaseTestCase::PASSWORD], $this->url());
        $this->assertSame(7500000, (int) Database::one('SELECT approved_amount_minor FROM projects WHERE id = ?', [$this->alpha['id']])['approved_amount_minor']);
        $this->assertStringNotContainsString('Record the approved quotation', $admin->get($this->url())['body'], 'the form disappears once recorded');
        $admin->post($this->url('/baseline'), ['quotation_ref' => 'QT-OVERWRITE', 'base' => '1'] + $form + ['confirm_password' => DatabaseTestCase::PASSWORD], $this->url());
        $this->assertSame('QT-HTTP-9', Database::one('SELECT approved_quotation_ref FROM projects WHERE id = ?', [$this->alpha['id']])['approved_quotation_ref']);
    }
}
