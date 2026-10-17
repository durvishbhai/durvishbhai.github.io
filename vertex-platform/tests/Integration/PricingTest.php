<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\PermissionSync;
use Vertex\Services\PriceChangeService;
use Vertex\Services\PricingService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/** Priority pricing rules, price-change proposals, client approval and protection of the approved price. */
final class PricingTest extends DatabaseTestCase
{
    private int $admin;
    private int $manager;     // assigned: may set priority and propose
    private int $dev;         // assigned worker
    private int $clientUser;
    private int $otherClient;
    private array $proj;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        // Rules persist across tests (they are configuration); put the shipped defaults back.
        Database::run('DELETE FROM priority_pricing_rules WHERE version > 1 OR service_category <> \'*\'');
        Database::run("UPDATE priority_pricing_rules SET surcharge_type = 'none', fixed_minor = 0, percent_bps = 0, current_flag = 1, superseded_at = NULL");
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->clientUser = $this->makeUser('cl@x.test', ['client']);
        $this->otherClient = $this->makeUser('cl2@x.test', ['client']);
        $this->proj = $this->makeProject($this->clientUser, 'Alpha Site');
        Database::run("UPDATE projects SET service_category = 'website_development', priority = 'normal' WHERE id = ?", [$this->proj['id']]);
        ProjectAssignmentService::assign($this->admin, $this->proj['id'], $this->manager, 'lead');
        ProjectAssignmentService::assign($this->admin, $this->proj['id'], $this->dev, 'member');
    }

    private function project(): array
    {
        return ProjectPolicy::find($this->proj['public_id']);
    }

    private function row(): array
    {
        return Database::one('SELECT * FROM projects WHERE id = ?', [$this->proj['id']]);
    }

    private function rule(string $service, string $priority, string $type, string $value = ''): void
    {
        PricingService::setRule($this->admin, ['service' => $service, 'priority' => $priority, 'type' => $type, 'percent' => $type === 'percent' ? $value : '', 'fixed' => $type === 'fixed' ? $value : '', 'reason' => 'Test setup']);
    }

    private function baseline(string $base = '100000', string $surcharge = '0'): void
    {
        PriceChangeService::recordApproval($this->admin, $this->project(), ['quotation_ref' => 'QT-TEST-001', 'base' => $base, 'surcharge' => $surcharge]);
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

    // ---- calculation ------------------------------------------------------------------------------------------------------

    public function testPercentageSurchargeRoundsHalfUpOnIntegerPaise(): void
    {
        $this->assertSame(1250, PricingService::percentOf(10000, 1250), '12.5% of ₹100.00');
        $this->assertSame(15000, PricingService::percentOf(99999, 1500), '15% of 999.99 = 149.9985 -> ₹150.00');
        $this->assertSame(1, PricingService::percentOf(1, 5000), '0.5 paisa rounds half up to 1');
        $this->assertSame(0, PricingService::percentOf(1, 4999), '0.4999 rounds down');
        $this->assertSame(0, PricingService::percentOf(0, 5000));
        $this->assertSame(3333, PricingService::percentOf(10000, 3333));
        $this->assertSame(500_000_000_000_000, PricingService::percentOf(100_000_000_000_000, PricingService::MAX_PERCENT_BPS), 'no overflow at the largest supported price and rate');
        $this->assertSame(12_345_678, PricingService::percentOf(12_345_678, 10000), '100% is the base itself');
    }

    public function testSurchargeForRuleTypes(): void
    {
        $this->assertSame(0, PricingService::surchargeFor(null, 100000));
        $this->assertSame(0, PricingService::surchargeFor(['surcharge_type' => 'none', 'fixed_minor' => 500, 'percent_bps' => 500], 100000), 'type decides, stale columns are ignored');
        $this->assertSame(250000, PricingService::surchargeFor(['surcharge_type' => 'fixed', 'fixed_minor' => 250000, 'percent_bps' => 0], 1));
        $this->assertSame(1500, PricingService::surchargeFor(['surcharge_type' => 'percent', 'fixed_minor' => 0, 'percent_bps' => 1500], 10000));
        $this->assertSame(0, PricingService::surchargeFor(['surcharge_type' => 'percent', 'fixed_minor' => 0, 'percent_bps' => 1500], -5));
    }

    public function testPercentAndMoneyInputParsing(): void
    {
        $this->assertSame(1250, PricingService::parsePercent('12.5'));
        $this->assertSame(1200, PricingService::parsePercent('12%'));
        $this->assertSame(1, PricingService::parsePercent('0.01'));
        $this->assertSame(50000, PricingService::parsePercent('500'));
        foreach (['0', '0.00', '500.01', '501', '-5', 'abc', '1.234', '', '1e3', ' '] as $bad) {
            try {
                PricingService::parsePercent($bad);
                $this->fail("'{$bad}' must be rejected");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- rules and versions -----------------------------------------------------------------------------------------------

    public function testShippedDefaultsInventNoRates(): void
    {
        foreach (['low', 'normal', 'high', 'urgent'] as $p) {
            $rule = PricingService::ruleFor('website_development', $p);
            $this->assertNotNull($rule);
            $this->assertSame('none', $rule['surcharge_type'], "{$p} has no surcharge until an administrator configures one");
        }
    }

    public function testRulesAreVersionedNotOverwritten(): void
    {
        $this->rule('*', 'urgent', 'percent', '25');
        $this->rule('*', 'urgent', 'fixed', '5000');
        $this->assertFalse(PricingService::setRule($this->admin, ['service' => '*', 'priority' => 'urgent', 'type' => 'fixed', 'fixed' => '5000', 'reason' => 'Same again']), 'identical values write nothing');
        $rows = Database::all("SELECT * FROM priority_pricing_rules WHERE service_category = '*' AND priority = 'urgent' ORDER BY version");
        $this->assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['version'], $rows));
        $this->assertSame([null, null, '1'], array_map(static fn (array $r): ?string => $r['current_flag'] === null ? null : (string) $r['current_flag'], $rows), 'exactly one current version');
        $this->assertNotNull($rows[0]['superseded_at']);
        $this->assertSame(2500, (int) $rows[1]['percent_bps'], 'history keeps what the old value was');
        $this->assertSame(500000, (int) $rows[2]['fixed_minor']);
        $this->assertSame($this->admin, (int) $rows[2]['created_by']);
        $a = Database::all("SELECT * FROM audit_logs WHERE action = 'pricing.rule_changed' ORDER BY id");
        $this->assertCount(2, $a);
        $this->assertStringContainsString('2500', (string) $a[1]['before_json']);
        $this->assertStringContainsString('500000', (string) $a[1]['after_json']);
        $this->expectException(\PDOException::class); // the database itself refuses a second current row
        Database::run("INSERT INTO priority_pricing_rules (service_category, priority, surcharge_type, version, current_flag, created_at) VALUES ('*', 'urgent', 'none', 99, 1, UTC_TIMESTAMP())");
    }

    public function testServiceSpecificRulesOverrideTheDefault(): void
    {
        $this->rule('*', 'high', 'percent', '10');
        $this->rule('website_development', 'high', 'percent', '20');
        $this->assertSame(2000, (int) PricingService::ruleFor('website_development', 'high')['percent_bps']);
        $this->assertSame(1000, (int) PricingService::ruleFor('video_editing', 'high')['percent_bps'], 'other services use the default');
        $this->assertSame(1000, (int) PricingService::ruleFor(null, 'high')['percent_bps']);
    }

    public function testOnlyPricingManagersEditRulesAndInputIsValidated(): void
    {
        $base = ['service' => '*', 'priority' => 'urgent', 'type' => 'percent', 'percent' => '10', 'reason' => 'Because'];
        $this->expect403(fn () => PricingService::setRule($this->manager, $base), 'managers can view pricing but not edit it');
        $this->expect403(fn () => PricingService::setRule($this->dev, $base), 'workers');
        $this->expect403(fn () => PricingService::setRule($this->clientUser, $base), 'clients');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['reason' => ''] + $base), 'reason');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['service' => 'plumbing'] + $base), 'service');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['priority' => 'critical'] + $base), 'priority');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['type' => 'magic'] + $base), 'type');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['percent' => '900'] + $base), 'between');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['type' => 'fixed', 'fixed' => '0'] + $base), 'fixed');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['type' => 'fixed', 'fixed' => '-4'] + $base), 'amount');
        $this->expectInvalid(fn () => PricingService::setRule($this->admin, ['type' => 'fixed', 'fixed' => '100000000000'] + $base), 'between');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM priority_pricing_rules WHERE version > 1')['c']);
    }

    // ---- baseline ---------------------------------------------------------------------------------------------------------

    public function testBaselineIsRecordedOnceByAuthorizedAdministratorsOnly(): void
    {
        $in = ['quotation_ref' => 'QT-2026-0001', 'base' => '1,00,000.50', 'surcharge' => '2500'];
        $this->expect403(fn () => PriceChangeService::recordApproval($this->manager, $this->project(), $in), 'managers');
        $this->expect403(fn () => PriceChangeService::recordApproval($this->dev, $this->project(), $in), 'workers');
        $this->expect403(fn () => PriceChangeService::recordApproval($this->clientUser, $this->project(), $in), 'the client cannot approve their own price');
        $this->expectInvalid(fn () => PriceChangeService::recordApproval($this->admin, $this->project(), ['base' => '0'] + $in), 'greater than zero');
        $this->expectInvalid(fn () => PriceChangeService::recordApproval($this->admin, $this->project(), ['quotation_ref' => ''] + $in), 'reference');
        $this->expectInvalid(fn () => PriceChangeService::recordApproval($this->admin, $this->project(), ['base' => 'ten'] + $in), 'amount');
        PriceChangeService::recordApproval($this->admin, $this->project(), $in);
        $p = $this->row();
        $this->assertSame(10000050 + 250000, (int) $p['approved_amount_minor'], 'stored as integer paise');
        $this->assertSame('QT-2026-0001', $p['approved_quotation_ref']);
        $this->assertSame($this->admin, (int) $p['approved_by']);
        $rev = Database::all('SELECT * FROM price_revisions WHERE project_id = ?', [$this->proj['id']]);
        $this->assertCount(1, $rev);
        $this->assertSame([1, 'original', 10000050, 250000, 10250050], [(int) $rev[0]['revision_no'], $rev[0]['kind'], (int) $rev[0]['base_minor'], (int) $rev[0]['surcharge_minor'], (int) $rev[0]['amount_minor']]);
        $this->expectInvalid(fn () => PriceChangeService::recordApproval($this->admin, $this->project(), ['quotation_ref' => 'QT-OTHER', 'base' => '1'] + $in), 'already recorded');
        $this->assertSame(10250050, (int) $this->row()['approved_amount_minor'], 'the second attempt changed nothing');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'pricing.baseline_recorded'")['c']);
    }

    public function testBaselineCannotBeRecordedOnClosedProjects(): void
    {
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$this->proj['id']]);
        $this->expectInvalid(fn () => $this->baseline(), 'closed');
    }

    // ---- priority changes -------------------------------------------------------------------------------------------------

    public function testWithoutAnApprovedQuotationPriorityChangesDirectly(): void
    {
        $this->rule('*', 'urgent', 'percent', '25');
        $res = PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        $this->assertTrue($res['applied']);
        $this->assertSame('urgent', $this->row()['priority']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_change_requests')['c'], 'nothing was priced, so nothing to approve');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'project.priority_changed'")['c']);
    }

    public function testZeroAdjustmentChangesPriorityDirectly(): void
    {
        $this->baseline(); // default rules: no surcharges anywhere
        $res = PriceChangeService::changePriority($this->manager, $this->project(), 'high');
        $this->assertTrue($res['applied']);
        $this->assertSame('high', $this->row()['priority']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_change_requests')['c']);
        $this->assertSame(10000000, (int) $this->row()['approved_amount_minor']);
    }

    public function testRaisingPriorityAfterApprovalCreatesAProposalAndLeavesEverythingElseAlone(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->baseline('100000', '0'); // ₹1,00,000.00 approved at Normal
        $before = Database::one('SELECT * FROM price_revisions WHERE project_id = ?', [$this->proj['id']]);
        $res = PriceChangeService::changePriority($this->manager, $this->project(), 'urgent', 'Client asked for a rush');
        $this->assertFalse($res['applied']);
        $this->assertSame(2000000, $res['adjustment_minor'], '20% of ₹1,00,000 = ₹20,000');
        $p = $this->row();
        $this->assertSame('normal', $p['priority'], 'priority does not move until the client approves');
        $this->assertSame(10000000, (int) $p['approved_amount_minor'], 'approved amount untouched');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c'], 'no new revision yet');
        $this->assertSame($before, Database::one('SELECT * FROM price_revisions WHERE project_id = ?', [$this->proj['id']]));
        $req = Database::one('SELECT * FROM price_change_requests');
        $this->assertSame(['pending_client', 'normal', 'urgent', 10000000, 0, 2000000, 2000000], [$req['status'], $req['from_priority'], $req['to_priority'], (int) $req['base_minor'], (int) $req['current_surcharge_minor'], (int) $req['proposed_surcharge_minor'], (int) $req['adjustment_minor']]);
        $snap = json_decode($req['rule_snapshot_json'], true);
        $this->assertSame(2000, $snap['percent_bps']);
        $this->assertSame('Client asked for a rush', $snap['staff_note']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'pricing.change_requested'")['c']);
        $act = Database::one("SELECT * FROM project_activity WHERE event_type = 'pricing.change_requested'");
        $this->assertSame('client', $act['visibility']);
        $this->assertStringNotContainsString('20,000', $act['summary'], 'amounts are not leaked into the shared timeline');
    }

    public function testLoweringPriorityIsAlsoAProposalNotASilentCredit(): void
    {
        $this->rule('*', 'urgent', 'fixed', '5000');
        Database::run("UPDATE projects SET priority = 'urgent' WHERE id = ?", [$this->proj['id']]);
        $this->baseline('100000', '5000'); // approved at Urgent with its charge inside
        $res = PriceChangeService::changePriority($this->manager, $this->project(), 'normal');
        $this->assertFalse($res['applied']);
        $this->assertSame(-500000, $res['adjustment_minor']);
        $this->assertSame('urgent', $this->row()['priority']);
        $this->assertSame(10500000, (int) $this->row()['approved_amount_minor']);
    }

    public function testServiceSpecificRuleIsUsedAndRuleChangesDoNotRewriteAPendingProposal(): void
    {
        $this->rule('*', 'high', 'percent', '5');
        $this->rule('website_development', 'high', 'percent', '10');
        $this->baseline('50000');
        PriceChangeService::changePriority($this->manager, $this->project(), 'high');
        $req = Database::one('SELECT * FROM price_change_requests');
        $this->assertSame(500000, (int) $req['proposed_surcharge_minor'], '10% of ₹50,000 (the website rule), not the 5% default');
        $this->rule('website_development', 'high', 'percent', '30'); // rules change afterwards
        $same = Database::one('SELECT * FROM price_change_requests WHERE id = ?', [$req['id']]);
        $this->assertSame(500000, (int) $same['proposed_surcharge_minor'], 'what the client was shown is what they approve');
        PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'approve');
        $this->assertSame(5500000, (int) Database::one('SELECT amount_minor FROM price_revisions WHERE revision_no = 2')['amount_minor']);
    }

    public function testOnlyAuthorizedStaffMayChangePriorityAndPropose(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->baseline();
        $this->expect403(fn () => PriceChangeService::changePriority($this->dev, $this->project(), 'urgent'), 'workers');
        $this->expect403(fn () => PriceChangeService::changePriority($this->clientUser, $this->project(), 'urgent'), 'clients cannot change their own priority/price');
        $outsider = $this->makeUser('mgr2@x.test', ['manager']);
        $this->expect403(fn () => PriceChangeService::changePriority($outsider, $this->project(), 'urgent'), 'unassigned manager');
        // someone with set_priority but without pricing.request_change cannot trigger a priced change
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id AND r.slug = 'manager' JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = 'pricing.request_change'");
        \Vertex\Services\Gate::flush();
        $this->expect403(fn () => PriceChangeService::changePriority($this->manager, $this->project(), 'urgent'), 'priced change needs pricing.request_change');
        $this->assertSame('normal', $this->row()['priority']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_change_requests')['c']);
    }

    public function testValidationAndStaleProtection(): void
    {
        $this->baseline();
        $this->expectInvalid(fn () => PriceChangeService::changePriority($this->manager, $this->project(), 'normal'), 'already has');
        $this->expectInvalid(fn () => PriceChangeService::changePriority($this->manager, $this->project(), 'nonsense'), 'priority');
        $stale = $this->project();
        Database::run("UPDATE projects SET priority = 'high' WHERE id = ?", [$this->proj['id']]);
        $this->expectInvalid(fn () => PriceChangeService::changePriority($this->manager, $stale, 'low'), 'changed while');
        Database::run("UPDATE projects SET status = 'completed' WHERE id = ?", [$this->proj['id']]);
        $this->expectInvalid(fn () => PriceChangeService::changePriority($this->manager, $this->project(), 'low'), 'closed');
    }

    public function testNewProposalSupersedesThePendingOneAndStaffCanWithdraw(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->rule('*', 'high', 'percent', '10');
        $this->baseline();
        PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        PriceChangeService::changePriority($this->manager, $this->project(), 'high');
        $this->assertSame(['superseded', 'pending_client'], array_column(Database::all('SELECT status FROM price_change_requests ORDER BY id'), 'status'));
        $pending = Database::one("SELECT * FROM price_change_requests WHERE status = 'pending_client'");
        $this->expect403(fn () => PriceChangeService::withdraw($this->dev, $this->project(), $pending['public_id']), 'workers');
        $this->expect403(fn () => PriceChangeService::withdraw($this->clientUser, $this->project(), $pending['public_id']), 'withdrawing is a staff action; the client declines instead');
        PriceChangeService::withdraw($this->manager, $this->project(), $pending['public_id']);
        $this->assertSame('withdrawn', Database::one('SELECT status FROM price_change_requests WHERE id = ?', [$pending['id']])['status']);
        $this->expectInvalid(fn () => PriceChangeService::withdraw($this->manager, $this->project(), $pending['public_id']), 'already been decided');
        $this->expectInvalid(fn () => PriceChangeService::respond($this->clientUser, $this->project(), $pending['public_id'], 'approve'), 'already been decided');
    }

    // ---- client decision --------------------------------------------------------------------------------------------------

    public function testClientApprovalAppendsARevisionAndAppliesThePriority(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->baseline('100000');
        PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        $req = Database::one('SELECT * FROM price_change_requests');
        $original = Database::one('SELECT * FROM price_revisions WHERE revision_no = 1');

        $this->expect403(fn () => PriceChangeService::respond($this->manager, $this->project(), $req['public_id'], 'approve'), 'staff cannot approve for the client');
        $this->expect403(fn () => PriceChangeService::respond($this->admin, $this->project(), $req['public_id'], 'approve'), 'not even administrators');
        $this->expect403(fn () => PriceChangeService::respond($this->otherClient, $this->project(), $req['public_id'], 'approve'), 'another client');
        $this->expect403(fn () => PriceChangeService::respond($this->dev, $this->project(), $req['public_id'], 'approve'), 'workers');
        $this->expectInvalid(fn () => PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'maybe'), 'approve or reject');
        $this->assertSame('normal', $this->row()['priority'], 'nothing changed by the refused attempts');

        PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'approve', 'OK, go ahead');
        $p = $this->row();
        $this->assertSame('urgent', $p['priority']);
        $this->assertSame(10000000, (int) $p['approved_amount_minor'], 'the original approved amount is never overwritten');
        $revs = Database::all('SELECT * FROM price_revisions ORDER BY revision_no');
        $this->assertCount(2, $revs);
        $this->assertSame($original, $revs[0], 'revision 1 is byte-for-byte unchanged');
        $this->assertSame([2, 'priority_change', 10000000, 2000000, 12000000], [(int) $revs[1]['revision_no'], $revs[1]['kind'], (int) $revs[1]['base_minor'], (int) $revs[1]['surcharge_minor'], (int) $revs[1]['amount_minor']]);
        $done = Database::one('SELECT * FROM price_change_requests');
        $this->assertSame(['approved', $this->clientUser, (int) $revs[1]['id'], 'OK, go ahead'], [$done['status'], (int) $done['decided_by'], (int) $done['applied_revision_id'], $done['decision_note']]);
        $this->assertSame(12000000, (int) PriceChangeService::currentRevision((int) $this->proj['id'])['amount_minor']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'pricing.change_approved' AND actor_id = " . $this->clientUser)['c']);
        $this->expectInvalid(fn () => PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'approve'), 'already been decided');
        $this->assertCount(2, Database::all('SELECT id FROM price_revisions'), 'a repeated click cannot add a second revision');
    }

    public function testClientRejectionChangesNothing(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->baseline();
        PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        $req = Database::one('SELECT * FROM price_change_requests');
        PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'reject', 'Too expensive');
        $this->assertSame('normal', $this->row()['priority']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
        $this->assertSame(['rejected', 'Too expensive'], [Database::one('SELECT status FROM price_change_requests')['status'], Database::one('SELECT decision_note FROM price_change_requests')['decision_note']]);
        // The team can propose again later; history keeps the rejected one.
        PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        $this->assertSame(['rejected', 'pending_client'], array_column(Database::all('SELECT status FROM price_change_requests ORDER BY id'), 'status'));
    }

    public function testAStaleProposalCannotBeApprovedAndIsMarkedSuperseded(): void
    {
        $this->rule('*', 'urgent', 'percent', '20');
        $this->baseline();
        PriceChangeService::changePriority($this->manager, $this->project(), 'urgent');
        $req = Database::one('SELECT * FROM price_change_requests');
        Database::run("UPDATE projects SET priority = 'low' WHERE id = ?", [$this->proj['id']]); // the project moved on in the meantime
        $this->expectInvalid(fn () => PriceChangeService::respond($this->clientUser, $this->project(), $req['public_id'], 'approve'), 'out of date');
        $this->assertSame('superseded', Database::one('SELECT status FROM price_change_requests WHERE id = ?', [$req['id']])['status'], 'the outcome is persisted even though the call failed');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);
        $this->assertSame('low', $this->row()['priority']);
    }

    public function testOverviewVisibilityFollowsPricingPermissions(): void
    {
        $this->baseline();
        $this->assertTrue(PriceChangeService::canView($this->clientUser, $this->project()));
        $this->assertTrue(PriceChangeService::canView($this->manager, $this->project()));
        $this->assertTrue(PriceChangeService::canView($this->admin, $this->project()));
        $this->assertFalse(PriceChangeService::canView($this->dev, $this->project()), 'workers do not see prices');
        $this->assertFalse(PriceChangeService::canView($this->otherClient, $this->project()), 'another client');
        foreach ([$this->dev, $this->otherClient] as $u) {
            try {
                PriceChangeService::overview($u, $this->project());
                $this->fail('expected 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
        }
        $ov = PriceChangeService::overview($this->clientUser, $this->project());
        $this->assertFalse($ov['staff']);
        $this->assertCount(1, $ov['revisions']);
    }

    // ---- protection of approved prices ------------------------------------------------------------------------------------

    public function testNoApplicationCodeEditsOrDeletesPriceRevisionsOrTheApprovedBaseline(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(VERTEX_ROOT . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('/UPDATE\s+price_revisions|DELETE\s+FROM\s+price_revisions|TRUNCATE\s+(TABLE\s+)?price_revisions|REPLACE\s+INTO\s+price_revisions/i', $src)) {
                $offenders[] = $f->getFilename() . ' modifies price_revisions';
            }
            if (preg_match('/SET[^;"\']*\bapproved_amount_minor\b/i', $src) && !str_ends_with($f->getFilename(), 'PriceChangeService.php')) {
                $offenders[] = $f->getFilename() . ' writes approved_amount_minor';
            }
            if (preg_match('/DELETE\s+FROM\s+price_change_requests|DELETE\s+FROM\s+priority_pricing_rules/i', $src)) {
                $offenders[] = $f->getFilename() . ' deletes pricing history';
            }
        }
        $this->assertSame([], $offenders);
        // approved_amount_minor is assigned exactly once in the whole code base: the one-time baseline.
        $svc = (string) file_get_contents(VERTEX_ROOT . '/app/Services/PriceChangeService.php');
        $this->assertSame(1, preg_match_all('/SET[^;]*approved_amount_minor/i', $svc));
    }

    public function testFinancialRowsAreRestrictedNotCascaded(): void
    {
        $this->baseline();
        $this->expectException(\PDOException::class);
        Database::run('DELETE FROM projects WHERE id = ?', [$this->proj['id']]); // price_revisions FK is RESTRICT
    }
}
