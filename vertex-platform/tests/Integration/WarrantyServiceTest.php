<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\Audit;
use Vertex\Services\Gate;
use Vertex\Services\WarrantyService;

/** Warranty dates, status derivation, who may configure it, history and audit. */
final class WarrantyServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        Config::set('app.timezone', 'Asia/Kolkata');
        Config::set('support.warranty_end_day_before', false);
    }

    protected function tearDown(): void
    {
        AppTime::freeze(null);
        $this->tearDownPhase3();
    }

    private function expect(callable $fn, int $status): void
    {
        try {
            $fn();
            $this->fail("Expected HTTP {$status}");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status);
        }
    }

    /** @return list<array{string,int,string}> */
    public static function endDates(): array
    {
        return [
            'plain six months' => ['2026-01-15', 6, '2026-07-15'],
            'clamps 31 Aug to the end of February' => ['2026-08-31', 6, '2027-02-28'],
            'clamps to 29 Feb in a leap year' => ['2027-08-31', 6, '2028-02-29'],
            'start on 29 Feb' => ['2028-02-29', 12, '2029-02-28'],
            'year rollover' => ['2026-11-30', 3, '2027-02-28'],
            '31 Jan + 1 month' => ['2026-01-31', 1, '2026-02-28'],
            'start on the 1st' => ['2026-03-01', 6, '2026-09-01'],
            'long contract' => ['2026-05-10', 24, '2028-05-10'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endDates')]
    public function testEndDateIsTheSameCalendarDayMonthsLater(string $start, int $months, string $end): void
    {
        $this->assertSame($end, WarrantyService::endDate($start, $months, false));
    }

    public function testDayBeforeModeEndsTheDayBefore(): void
    {
        $this->assertSame('2026-07-14', WarrantyService::endDate('2026-01-15', 6, true));
        $this->assertSame('2027-02-27', WarrantyService::endDate('2026-08-31', 6, true));
        Config::set('support.warranty_end_day_before', true);
        $this->assertSame('2026-07-14', WarrantyService::endDate('2026-01-15', 6), 'the configured default applies');
    }

    public function testInvalidInputsAreRejected(): void
    {
        foreach ([['2026-02-30', 6], ['not-a-date', 6], ['2026-01-01', 0], ['2026-13-01', 6]] as [$d, $m]) {
            try {
                WarrantyService::endDate($d, $m, false);
                $this->fail("{$d} {$m} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNoRecordedWarrantyIsPendingConfirmationOnlyForWebsiteProjects(): void
    {
        $this->assertSame('pending_confirmation', WarrantyService::forProject($this->alphaRow())['status']);
        $this->assertNull(WarrantyService::forProject($this->alphaRow())['row']['start_date'] ?? null, 'no date is invented');
        $this->assertSame('not_applicable', WarrantyService::forProject(['id' => $this->bravo['id'], 'service_category' => 'video_editing'])['status']);
        $this->assertSame('not_applicable', WarrantyService::statusFor(null, null)['status']);
    }

    public function testStartDateIsNeverGuessedFromProjectStatus(): void
    {
        Database::run("UPDATE projects SET status = 'completed', completed_at = UTC_TIMESTAMP() WHERE id = ?", [$this->alpha['id']]);
        $w = WarrantyService::forProject($this->alphaRow());
        $this->assertSame('pending_confirmation', $w['status'], 'a completed project does not start the warranty by itself');
        $this->assertFalse($w['recorded']);
        $this->assertNull(Database::one('SELECT 1 x FROM project_warranties WHERE project_id = ?', [$this->alpha['id']]));
        $hints = WarrantyService::startHints($this->alphaRow());
        $this->assertNotNull($hints['project_completed'], 'shown as a hint to a human only');
    }

    public function testRecordingWithoutAStartDateStaysPending(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '', 'start_basis' => '']);
        $w = WarrantyService::forProject($this->alphaRow());
        $this->assertSame('pending_confirmation', $w['status']);
        $this->assertNull($w['row']['end_date']);
        $this->assertSame(6, (int) $w['row']['duration_months']);
    }

    public function testStartDateNeedsABasisAndBasisNeedsADate(): void
    {
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-01-10', 'start_basis' => '']));
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '', 'start_basis' => 'final_delivery']));
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '0', 'start_date' => '2026-01-10', 'start_basis' => 'final_delivery']));
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '999', 'start_date' => '2026-01-10', 'start_basis' => 'final_delivery']));
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-31', 'start_basis' => 'final_delivery']));
        $this->assertNull(Database::one('SELECT 1 x FROM project_warranties'));
    }

    private function expectValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a validation error');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testStatusBoundariesUseTheApplicationTimeZone(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-03-10', 'start_basis' => 'client_acceptance']);
        $row = WarrantyService::forProject($this->alphaRow())['row'];
        $this->assertSame('2026-09-10', $row['end_date']);
        // 22:00 UTC on 9 Mar is already 10 Mar 03:30 in Kolkata: the warranty has started
        AppTime::freeze('2026-03-09 22:00:00');
        $this->assertSame('active', WarrantyService::forProject($this->alphaRow())['status']);
        AppTime::freeze('2026-03-09 17:00:00'); // 9 Mar 22:30 local: not started yet
        $this->assertSame('scheduled', WarrantyService::forProject($this->alphaRow())['status']);
        AppTime::freeze('2026-09-10 17:00:00'); // 10 Sep 22:30 local: the last day is still covered
        $this->assertSame('active', WarrantyService::forProject($this->alphaRow())['status']);
        $this->assertSame(0, WarrantyService::forProject($this->alphaRow())['days_left']);
        AppTime::freeze('2026-09-10 19:00:00'); // 11 Sep 00:30 local
        $this->assertSame('expired', WarrantyService::forProject($this->alphaRow())['status']);
    }

    public function testContractSpecificDurationAndDayBeforeConfigurationAreHonoured(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '12', 'start_date' => '2026-01-31', 'start_basis' => 'contract_date', 'quotation_ref' => 'QT-2026-0042']);
        $row = WarrantyService::forProject($this->alphaRow())['row'];
        $this->assertSame(12, (int) $row['duration_months']);
        $this->assertSame('2027-01-31', $row['end_date']);
        $this->assertSame('QT-2026-0042', $row['quotation_ref']);
        $this->assertSame('contract_date', $row['start_basis']);
        Config::set('support.warranty_end_day_before', true);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '12', 'start_date' => '2026-01-31', 'start_basis' => 'contract_date', 'quotation_ref' => 'QT-2026-0042', 'reason' => 'Contract says day before']);
        $this->assertSame('2027-01-30', WarrantyService::forProject($this->alphaRow())['row']['end_date']);
    }

    public function testNotApplicableAndSuspendedStatuses(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'not_applicable']);
        $this->assertSame('not_applicable', WarrantyService::forProject($this->alphaRow())['status']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-10), 'start_basis' => 'final_delivery', 'reason' => 'Contract confirmed']);
        $this->assertSame('active', WarrantyService::forProject($this->alphaRow())['status']);
        WarrantyService::setSuspended($this->admin, $this->alphaRow(), true, 'Unpaid balance, per agreement clause 7');
        $this->assertSame('suspended', WarrantyService::forProject($this->alphaRow())['status']);
        WarrantyService::setSuspended($this->admin, $this->alphaRow(), false, 'Balance paid');
        $this->assertSame('active', WarrantyService::forProject($this->alphaRow())['status']);
    }

    public function testOnlyAdministratorsMayConfigureTheWarranty(): void
    {
        $in = ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-01-10', 'start_basis' => 'final_delivery'];
        foreach ([$this->manager, $this->support, $this->dev, $this->client, $this->partner] as $u) {
            $this->expect(fn () => WarrantyService::record($u, $this->alphaRow(), $in), 403);
        }
        $this->expect(fn () => WarrantyService::setSuspended($this->manager, $this->alphaRow(), true, 'Because I can'), 403);
        $this->expect(fn () => WarrantyService::recordTerms($this->manager, ['title' => 'T', 'terms' => 'Some wording here']), 403);
        $this->assertNull(Database::one('SELECT 1 x FROM project_warranties'));
        $this->assertTrue(Gate::can('warranty.manage', $this->admin));
        $this->assertFalse(Gate::can('warranty.manage', $this->manager), 'managers and support no longer hold the contractual permission');
        $this->assertFalse(Gate::can('warranty.manage', $this->support));
        $this->assertTrue(Gate::can('warranty.view', $this->manager));
    }

    public function testEveryChangeIsInTheHistoryAndTheAuditLog(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-01', 'start_basis' => 'final_delivery']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-15', 'start_basis' => 'client_acceptance', 'reason' => 'Client accepted two weeks later']);
        $h = WarrantyService::history($this->alphaRow());
        $this->assertCount(2, $h);
        $this->assertSame('changed', $h[0]['event']);
        $this->assertSame('Client accepted two weeks later', $h[0]['reason']);
        $this->assertSame('2026-02-01', json_decode($h[0]['before_json'], true)['start_date']);
        $this->assertSame('2026-08-15', json_decode($h[0]['after_json'], true)['end_date']);
        $actions = array_column(Database::all("SELECT action FROM audit_logs WHERE action LIKE 'warranty.%' ORDER BY id"), 'action');
        $this->assertSame(['warranty.configured', 'warranty.changed', 'warranty.dates_changed'], $actions);
        $this->assertTrue(Audit::verify()['ok']);
        // a change needs a reason once a warranty exists, and an identical save writes nothing
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '12', 'start_date' => '2026-02-15', 'start_basis' => 'client_acceptance']));
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-15', 'start_basis' => 'client_acceptance', 'reason' => 'no change']);
        $this->assertCount(2, WarrantyService::history($this->alphaRow()));
    }

    public function testTermsAreVersionedAndProjectsKeepTheirVersion(): void
    {
        $v1 = WarrantyService::recordTerms($this->admin, ['title' => 'Standard terms', 'terms' => 'Wording as signed in 2026.', 'default_months' => '6', 'source_reference' => 'MSA-1']);
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-01', 'start_basis' => 'final_delivery', 'terms_version' => (string) $v1]);
        $v2 = WarrantyService::recordTerms($this->admin, ['title' => 'Revised terms', 'terms' => 'New wording.']);
        $this->assertSame([1, 2], [$v1, $v2]);
        $w = WarrantyService::forProject($this->alphaRow());
        $this->assertSame(1, (int) $w['row']['terms_version'], 'the project keeps the version it was recorded with');
        $this->assertSame('Wording as signed in 2026.', $w['row']['terms_text']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM warranty_terms WHERE current_flag = 1')['c']);
        $this->expectValidation(fn () => WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-01', 'start_basis' => 'final_delivery', 'terms_version' => '77', 'reason' => 'x year']));
    }

    public function testReportFiltersStatusesAndExpiringAndHonoursProjectScope(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => AppTime::todayPlus(-170), 'start_basis' => 'final_delivery']);
        $all = WarrantyService::report($this->admin, []);
        $this->assertSame(2, $all['total']);
        $this->assertSame('active', WarrantyService::report($this->admin, ['status' => 'active'])['rows'][0]['wstatus']);
        $this->assertSame(1, WarrantyService::report($this->admin, ['expiring' => true])['total'], 'ends within 30 days');
        $this->assertSame(0, WarrantyService::report($this->admin, ['status' => 'expired'])['total']);
        $this->assertSame(1, WarrantyService::report($this->admin, ['q' => 'Alpha'])['total']);
        // staff only see projects they are assigned to; a client has no staff report; a worker without warranty.view sees nothing
        $this->assertSame(1, WarrantyService::report($this->manager, [])['total']);
        $this->assertSame(0, WarrantyService::report($this->client, [])['total']);
        $this->assertSame(0, WarrantyService::report($this->dev, [])['total']);
    }

    public function testClientsSeeOnlyTheirOwnProjectsWithoutAdminNotes(): void
    {
        WarrantyService::record($this->admin, $this->alphaRow(), ['eligibility' => 'eligible', 'months' => '6', 'start_date' => '2026-02-01', 'start_basis' => 'final_delivery', 'admin_notes' => 'SECRET-ADMIN-NOTE', 'project_terms' => 'Visible project term']);
        $mine = WarrantyService::forClient($this->client);
        $this->assertCount(1, $mine);
        $this->assertSame('Alpha Website', $mine[0]['project']['title']);
        $this->assertStringNotContainsString('SECRET-ADMIN-NOTE', json_encode($mine));
        $this->assertSame('Visible project term', $mine[0]['project_terms']);
        $this->assertSame([], WarrantyService::forClient($this->partner));
        $this->assertCount(1, WarrantyService::forClient($this->client2));
        $this->assertStringNotContainsString('Alpha', json_encode(WarrantyService::forClient($this->client2)));
    }
}
