<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\DeliveryService;
use Vertex\Services\Mailer;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/** Project delivery appointments: preparation periods, daily capacity, blocked dates, reservations, manual bookings, client rules, emails and concurrency. */
final class DeliveryServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        Config::set('delivery.default_capacity', 2);
        Config::set('delivery.client_cutoff_days', 3);
        Config::set('delivery.max_reschedules', 2);
        Config::set('delivery.notify_email', 'team@kis.test');
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        Mailer::$sent = [];
    }

    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
        Config::set('delivery.notify_email', '');
        $this->tearDownPhase3();
    }

    private function day(int $n): string
    {
        return AppTime::todayPlus($n);
    }

    /** A project of $clientUser with its delivery plan set (open from today + $prep days). @return array<string,mixed> */
    private function proj(string $title, int $prep = 0, ?int $clientUser = null): array
    {
        $p = $this->makeProject($clientUser ?? $this->client, $title);
        $row = ProjectPolicy::find($p['public_id']);
        DeliveryService::savePlan($this->owner, $row, ['preparation_days' => (string) $prep, 'booking_enabled' => '1']);
        if ($clientUser === null) { // like the Alpha project: the manager and support agent work on it
            foreach ([$this->manager, $this->support] as $u) {
                ProjectAssignmentService::assign($this->admin, (int) $row['id'], $u, 'member');
            }
        }

        return $row;
    }

    private function book(array $project, int $day, string $source = 'client_dashboard', ?int $actor = null, array $opts = []): array
    {
        return DeliveryService::book($project, $this->day($day), $source, $actor ?? ($source === 'client_dashboard' ? $this->client : $this->admin), $opts);
    }

    private function refused(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('Expected the request to be refused.');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    private function tally(string $date, string $status = 'confirmed'): int
    {
        return (int) Database::one('SELECT COUNT(*) c FROM delivery_appointments WHERE appointment_date = ? AND status = ?', [$date, $status])['c'];
    }

    // ------------------------------------------------------------------------------------------------ preparation period

    public function testAProjectWithoutAPlanCannotBeBooked(): void
    {
        $row = ProjectPolicy::find($this->alpha['public_id']);
        $this->assertSame('not_configured', DeliveryService::plan($row)['state']);
        $this->refused(fn () => DeliveryService::book($row, $this->day(5), 'client_dashboard', $this->client), 'not open');
    }

    public function testTheDeliveryDateMustBeAfterTheStartDatePlusTheEstimatedTime(): void
    {
        $p = $this->proj('Sixty', 60);
        $this->assertSame('need_start', DeliveryService::plan($p)['state'], 'the client has to choose a start date first');
        $plan = DeliveryService::plan($p, false, AppTime::today());
        $this->assertSame('open', $plan['state']);
        $this->assertSame($this->day(61), $plan['eligible_from'], 'the day AFTER start + 60 days');
        $this->assertStringContainsString('60 days', $plan['message']);
        foreach ([1, 30, 60] as $d) {
            $this->refused(fn () => $this->book($p, $d), 'estimated time');
        }
        $a = $this->book($p, 61);
        $this->assertSame($this->day(61), $a['appointment_date']);
        $this->assertSame(AppTime::today(), $a['project_start_date'], 'no start date given = today');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM delivery_appointments WHERE appointment_date < ?", [$this->day(61)])['c']);
    }

    public function testAClientWhoStartsLaterCannotChooseADeliveryDateBeforeTheNewEndOfTheEstimate(): void
    {
        $p = $this->proj('Later start', 7); // 7 days
        $start = $this->day(10);
        $this->assertSame($this->day(18), DeliveryService::plan($p, false, $start)['eligible_from'], 'start + 7 days + 1');
        $this->refused(fn () => $this->book($p, 17, 'client_dashboard', null, ['start_date' => $start]), 'estimated time');
        $this->refused(fn () => $this->book($p, 10, 'client_dashboard', null, ['start_date' => $start]), 'estimated time');
        $a = $this->book($p, 18, 'client_dashboard', null, ['start_date' => $start]);
        $this->assertSame($start, $a['project_start_date']);
        // moving the appointment uses the same start date
        $this->refused(fn () => DeliveryService::reschedule($a, $this->day(15), $this->client, false), 'estimated time');
        $this->assertSame($this->day(20), DeliveryService::reschedule($a, $this->day(20), $this->client, false)['appointment_date']);
        $this->assertSame($start, DeliveryService::appointment((int) $a['id'])['project_start_date']);
    }

    public function testTheStartDateItselfIsValidated(): void
    {
        $p = $this->proj('Starts', 3);
        foreach ([$this->day(-1), 'soon', '2026-02-30', $this->day(5000)] as $bad) {
            $this->refused(fn () => $this->book($p, 40, 'client_dashboard', null, ['start_date' => $bad]));
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM delivery_appointments')['c']);
    }

    public function testTheTeamCanFixTheStartDateAndTheClientCannotChangeIt(): void
    {
        $p = $this->proj('Fixed start', 5);
        DeliveryService::savePlan($this->owner, $p, ['preparation_days' => '5', 'preparation_unit' => 'days', 'fixed_start_date' => $this->day(20), 'booking_enabled' => '1']);
        $plan = DeliveryService::plan($p);
        $this->assertSame('open', $plan['state'], 'no start to choose');
        $this->assertTrue($plan['start_fixed']);
        $this->assertSame($this->day(26), $plan['eligible_from']);
        $this->refused(fn () => $this->book($p, 30, 'client_dashboard', null, ['start_date' => $this->day(2)]), 'set by our team');
        $this->refused(fn () => $this->book($p, 25), 'estimated time');
        $a = $this->book($p, 26);
        $this->assertSame($this->day(20), $a['project_start_date']);
    }

    public function testTheEstimateCanBeInDaysWeeksOrMonthsAndAMonthKeepsTheDayOfTheMonth(): void
    {
        $row = fn (int $n, string $u, ?string $earliest = null): array => ['preparation_days' => $n, 'preparation_unit' => $u, 'earliest_date' => $earliest];
        $this->assertSame('2027-03-01', DeliveryService::endOfEstimate($row(2, 'months'), '2027-01-01'));
        $this->assertSame('2027-03-02', DeliveryService::earliestFor($row(2, 'months'), '2027-01-01'), 'start 1 January + 2 months: the first choice is 2 March');
        $this->assertSame('2027-02-28', DeliveryService::endOfEstimate($row(1, 'months'), '2027-01-31'), 'a shorter month ends on its last day');
        $this->assertSame('2027-01-15', DeliveryService::endOfEstimate($row(2, 'weeks'), '2027-01-01'));
        $this->assertSame('2027-01-08', DeliveryService::endOfEstimate($row(7, 'days'), '2027-01-01'));
        $this->assertSame('2027-04-10', DeliveryService::earliestFor($row(2, 'months', '2027-04-10'), '2027-01-01'), 'an optional earliest date can push it later');
        $this->assertSame('2 months', DeliveryService::durationText($row(2, 'months')));
        $this->assertSame('1 week', DeliveryService::durationText($row(1, 'weeks')));
    }

    public function testNovemberFirstExampleWithTheStartDateRule(): void
    {
        $p = $this->makeProject($this->client, 'Example');
        $row = ProjectPolicy::find($p['public_id']);
        DeliveryService::savePlan($this->owner, $row, ['preparation_days' => '60', 'preparation_unit' => 'days', 'booking_enabled' => '1']);
        $s = DeliveryService::settingsRow((int) $row['id']);
        $this->assertSame('2027-01-01', DeliveryService::earliestFor($s, '2026-11-01'), 'start 1 Nov + 60 days = 31 Dec, so 1 January is the first date');
        DeliveryService::savePlan($this->owner, $row, ['preparation_days' => '60', 'preparation_unit' => 'days', 'earliest_date' => '2027-02-01', 'booking_enabled' => '1']);
        $this->assertSame('2027-02-01', DeliveryService::earliestFor(DeliveryService::settingsRow((int) $row['id']), '2026-11-01'), 'the later of the two decides');
    }

    public function testThePlanIsValidatedAndOnlyThoseAllowedCanChangeIt(): void
    {
        $row = ProjectPolicy::find($this->alpha['public_id']);
        foreach ([['preparation_days' => ''], ['preparation_days' => '-5'], ['preparation_days' => '9999'], ['preparation_days' => 'abc'], ['preparation_days' => '10', 'earliest_date' => 'nope'],
            ['preparation_days' => '10', 'earliest_date' => '2030-05-01', 'latest_date' => '2030-04-01'],
            ['preparation_days' => '5', 'preparation_unit' => 'fortnights'], ['preparation_days' => '25', 'preparation_unit' => 'months'], ['preparation_days' => '5', 'fixed_start_date' => 'x']] as $bad) {
            $this->refused(fn () => DeliveryService::savePlan($this->owner, $row, $bad));
        }
        foreach ([$this->manager, $this->support, $this->client] as $u) {
            try {
                DeliveryService::savePlan($u, $row, ['preparation_days' => '10']);
                $this->fail('only delivery.configure may change a plan');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertNull(DeliveryService::settingsRow((int) $row['id']));
    }

    public function testChangingTheDurationKeepsConfirmedAppointmentsAndTellsTheClient(): void
    {
        $p = $this->proj('Changing', 0);
        $a = $this->book($p, 5);
        Mailer::$sent = [];
        $r = DeliveryService::savePlan($this->owner, $p, ['preparation_days' => '30', 'booking_enabled' => '1']);
        $this->assertSame(1, $r['earlier'], 'the confirmed appointment now falls before the new first date');
        $this->assertTrue($r['changed']);
        $still = DeliveryService::appointment((int) $a['id']);
        $this->assertSame('confirmed', $still['status']);
        $this->assertSame($this->day(5), $still['appointment_date']);
        $this->assertContains('Your KIS Delivery Booking Dates Were Updated', array_column(Mailer::$sent, 'subject'));
        $this->assertSame('alpha@client.test', Mailer::$sent[0]['to']);
    }

    public function testBookingCanBeSwitchedOffLatestDateAndProjectStateAreHonoured(): void
    {
        $p = $this->proj('Limits', 0);
        DeliveryService::savePlan($this->owner, $p, ['preparation_days' => '0', 'booking_enabled' => '1', 'latest_date' => $this->day(10)]);
        $this->refused(fn () => $this->book($p, 11), 'last date');
        $this->book($p, 10);
        $q = $this->proj('Off', 0);
        DeliveryService::savePlan($this->owner, $q, ['preparation_days' => '0', 'booking_enabled' => '']);
        $this->refused(fn () => $this->book($q, 5), 'not open');
        $c = $this->proj('Closed', 0);
        Database::run("UPDATE projects SET status = 'cancelled' WHERE id = ?", [$c['id']]);
        $this->refused(fn () => $this->book(ProjectPolicy::find($c['public_id']), 5), 'not available');
    }

    public function testDatesMustBeRealFutureDates(): void
    {
        $p = $this->proj('Dates', 0);
        foreach (['', 'tomorrow', '2026-02-30', $this->day(-1), AppTime::today() . ' 10:00'] as $bad) {
            $this->refused(fn () => DeliveryService::book($p, $bad, 'client_dashboard', $this->client));
        }
        $this->refused(fn () => $this->book($p, 5000), 'too far');
        $this->refused(fn () => $this->book($p, 0), 'first date');
    }

    // ------------------------------------------------------------------------------------------------ capacity

    public function testTwoAppointmentsPerDayThenTheDateIsFullForEveryoneElse(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $c = $this->proj('C');
        $this->book($a, 5);
        $this->assertSame(1, DeliveryService::day($this->day(5))['remaining']);
        $this->book($b, 5, 'client_dashboard', $this->client2);
        $this->assertSame(0, DeliveryService::day($this->day(5))['remaining']);
        $this->refused(fn () => $this->book($c, 5), 'fully booked');
        $this->assertSame(2, $this->tally($this->day(5)));
        $this->book($c, 6);
        $this->assertSame(1, DeliveryService::day($this->day(6))['used']);
    }

    public function testTheCalendarShowsStatesWithoutOtherClientsDetails(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $mine = $this->proj('C', 4);
        $this->book($a, 6);
        $this->book($b, 6, 'client_dashboard', $this->client2);
        DeliveryService::blockDate($this->owner, $this->day(7), 'Holiday');
        $plan = DeliveryService::plan(ProjectPolicy::find($mine['public_id']), false, AppTime::today());
        $cell = function (int $n) use ($plan): array {
            $cal = DeliveryService::month(substr($this->day($n), 0, 7), $plan);
            foreach ($cal['weeks'] as $w) {
                foreach ($w as $d) {
                    if ($d !== null && $d['date'] === $this->day($n)) {
                        return $d;
                    }
                }
            }
            $this->fail('date not in its month');
        };
        $this->assertSame('past', $cell(-1)['state']);
        $this->assertSame('before', $cell(2)['state'], 'inside the estimated time');
        $this->assertSame('full', $cell(6)['state']);
        $this->assertSame('blocked', $cell(7)['state']);
        $this->assertSame('available', $cell(8)['state']);
        $this->assertSame(2, $cell(8)['remaining']);
        $this->assertSame('after', $cell(2000)['state']);
        $json = json_encode($cell(6));
        $this->assertStringNotContainsString('alpha', (string) $json);
        $this->assertStringNotContainsString('Bravo', (string) $json);
    }

    public function testABlockedDateCannotBeBookedButKeepsItsAppointments(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $booked = $this->book($a, 8);
        $this->assertSame(1, DeliveryService::blockDate($this->owner, $this->day(8), 'Team away'), 'one confirmed appointment stays');
        $this->assertSame('confirmed', DeliveryService::appointment((int) $booked['id'])['status']);
        $this->assertTrue(DeliveryService::day($this->day(8))['blocked']);
        $this->assertSame(0, DeliveryService::day($this->day(8))['remaining']);
        $this->refused(fn () => $this->book($b, 8, 'client_dashboard', $this->client2), 'not available');
        $this->refused(fn () => $this->book($b, 8, 'manual', $this->admin), 'not available');
        DeliveryService::unblockDate($this->owner, $this->day(8));
        $this->book($b, 8, 'client_dashboard', $this->client2);
        $this->assertSame(2, $this->tally($this->day(8)));
        $this->assertContains('Delivery Date Blocked — ' . local_date($this->day(8), 'j F Y'), array_column(Mailer::$sent, 'subject'));
        $this->refused(fn () => DeliveryService::blockDate($this->owner, $this->day(9), ''), 'reason');
    }

    public function testReservedCapacityLowersWhatClientsCanBook(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        DeliveryService::reserve($this->owner, $this->day(10), 1, 'Founder visit');
        $d = DeliveryService::day($this->day(10));
        $this->assertSame([2, 0, 1, 1], [$d['cap'], $d['used'], $d['reserved'], $d['remaining']]);
        $this->book($a, 10);
        $this->refused(fn () => $this->book($b, 10, 'client_dashboard', $this->client2), 'fully booked');
        $this->assertSame(1, $this->tally($this->day(10)), 'a reservation is not a client appointment');
        $this->refused(fn () => DeliveryService::reserve($this->owner, $this->day(10), 1, 'Too many'), 'free');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM delivery_capacity_reservations WHERE released_at IS NULL')['c']);
        $id = (int) Database::one('SELECT id FROM delivery_capacity_reservations')['id'];
        DeliveryService::releaseReservation($this->owner, $id);
        $this->book($b, 10, 'client_dashboard', $this->client2);
        $this->assertSame(2, $this->tally($this->day(10)));
    }

    public function testAnAdminReservedSlotUsesUpOneReservationWithoutChangingTotalCapacity(): void
    {
        $a = $this->proj('A');
        $this->refused(fn () => $this->book($a, 12, 'admin_reserved', $this->admin), 'reserve');
        DeliveryService::reserve($this->owner, $this->day(12), 2, 'Planned launches');
        $x = $this->book($a, 12, 'admin_reserved', $this->admin, ['internal_notes' => 'Launch day']);
        $this->assertSame('admin_reserved', $x['booking_source']);
        $d = DeliveryService::day($this->day(12));
        $this->assertSame([1, 1, 0], [$d['used'], $d['reserved'], $d['remaining']], 'one reserved slot became the appointment; the other is still held');
    }

    public function testManualBookingsUseTheSameCapacityAndArePermissionedPerProject(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $m = $this->book($a, 14, 'manual', $this->manager, ['internal_notes' => 'Asked on WhatsApp']);
        $this->assertSame('manual', $m['booking_source']);
        $this->assertSame($this->manager, (int) $m['created_by']);
        $this->assertSame('Asked on WhatsApp', $m['internal_notes']);
        $this->assertSame(1, DeliveryService::day($this->day(14))['used']);
        foreach ([[$this->support, 'support has no manage permission'], [$this->manager2, 'a manager who is not assigned to the project'], [$this->client, 'a client']] as [$u, $why]) {
            try {
                $this->book($b, 14, 'manual', $u);
                $this->fail($why);
            } catch (HttpException $e) {
                $this->assertContains($e->status, [403, 404], $why);
            }
        }
        $this->refused(fn () => $this->book($b, 14, 'manual', $this->admin, ['internal_notes' => 'x<script>']), 'not allowed');
        $this->book($b, 14, 'manual', $this->admin);
        $this->refused(fn () => $this->book($this->proj('C'), 14, 'manual', $this->admin), 'fully booked');
        $this->assertSame(2, $this->tally($this->day(14)));
    }

    public function testOnlyThoseWhoMayConfigureCanOverrideThePreparationPeriod(): void
    {
        $p = $this->proj('Prep', 30);
        $this->refused(fn () => $this->book($p, 3, 'manual', $this->admin), 'estimated time');
        $a = $this->book($p, 3, 'manual', $this->admin, ['override_eligibility' => true]);
        $this->assertSame($this->day(3), $a['appointment_date'], 'the Administrator holds delivery.configure');
        $q = $this->proj('Prep2', 30);
        ProjectAssignmentService::assign($this->admin, (int) $q['id'], $this->manager, 'member');
        $this->refused(fn () => $this->book($q, 3, 'manual', $this->manager, ['override_eligibility' => true]), 'estimated time');
    }

    public function testLoweringTheCapacityNeverRemovesAConfirmedAppointment(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $this->book($a, 15);
        $this->book($b, 15, 'client_dashboard', $this->client2);
        DeliveryService::setDateCapacity($this->owner, $this->day(15), 1);
        $this->assertSame(2, $this->tally($this->day(15)));
        $d = DeliveryService::day($this->day(15));
        $this->assertSame([1, 2, 0], [$d['cap'], $d['used'], $d['remaining']]);
        DeliveryService::setDateCapacity($this->owner, $this->day(16), 4);
        $this->assertSame(4, DeliveryService::day($this->day(16))['cap']);
        DeliveryService::setDateCapacity($this->owner, $this->day(16), null);
        $this->assertSame(2, DeliveryService::day($this->day(16))['cap'], 'back to the default');
        $this->refused(fn () => DeliveryService::setDateCapacity($this->owner, $this->day(16), 99));
        Config::set('delivery.default_capacity', 3);
        $this->assertSame(3, DeliveryService::day($this->day(17))['cap'], 'the default is configurable');
    }

    public function testCapacityControlsNeedThePermissionAndOwnerSettingsAreValidated(): void
    {
        foreach ([$this->manager, $this->support, $this->client] as $u) {
            foreach ([fn () => DeliveryService::blockDate($u, $this->day(5), 'x'), fn () => DeliveryService::reserve($u, $this->day(5), 1, 'x'), fn () => DeliveryService::setDateCapacity($u, $this->day(5), 1), fn () => DeliveryService::saveDefaults($u, ['default_capacity' => '5'])] as $f) {
                try {
                    $f();
                    $this->fail('not allowed');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->status);
                }
            }
        }
        DeliveryService::saveDefaults($this->admin, ['default_capacity' => '3', 'notify_email' => 'ops@kis.test', 'public_mode' => 'both', 'client_cutoff_days' => '5']);
        $this->assertSame(3, DeliveryService::defaultCapacity());
        $this->assertSame('ops@kis.test', Config::get('delivery.notify_email'));
        $this->assertSame('both', Config::get('delivery.public_mode'));
        foreach ([['default_capacity' => '99'], ['default_capacity' => 'two'], ['notify_email' => 'not-an-email'], ['public_mode' => 'wild']] as $bad) {
            $this->refused(fn () => DeliveryService::saveDefaults($this->admin, $bad));
        }
    }

    // ------------------------------------------------------------------------------------------------ one active appointment, reschedule, cancel, complete

    public function testOnlyOneActiveAppointmentPerProjectUnlessTheOwnerAllowsMore(): void
    {
        $p = $this->proj('One');
        $first = $this->book($p, 5);
        $this->refused(fn () => $this->book($p, 6), 'already has');
        DeliveryService::savePlan($this->admin, $p, ['preparation_days' => '0', 'booking_enabled' => '1', 'allow_multiple' => '1']);
        $this->assertSame(0, (int) DeliveryService::settingsRow((int) $p['id'])['allow_multiple'], 'an Administrator cannot allow it');
        DeliveryService::savePlan($this->owner, $p, ['preparation_days' => '0', 'booking_enabled' => '1', 'allow_multiple' => '1']);
        $this->book($p, 6);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM delivery_appointments WHERE project_id = ? AND status = 'confirmed'", [$p['id']])['c']);
        DeliveryService::cancel($first, $this->admin, true);
        DeliveryService::savePlan($this->owner, $p, ['preparation_days' => '0', 'booking_enabled' => '1']);
        $this->assertSame(1, $this->tally($this->day(6)));
    }

    public function testCancellingFreesTheDateAndIsEmailedAndLogged(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $c = $this->proj('C');
        $x = $this->book($a, 20);
        $this->book($b, 20, 'client_dashboard', $this->client2);
        $this->refused(fn () => $this->book($c, 20), 'fully booked');
        Mailer::$sent = [];
        DeliveryService::cancel($x, $this->admin, true, 'Client asked');
        $this->assertSame(1, $this->tally($this->day(20)));
        $this->assertSame(1, $this->tally($this->day(20), 'cancelled'));
        $this->book($c, 20);
        $this->assertContains('Your KIS Project Delivery Appointment Was Cancelled', array_column(Mailer::$sent, 'subject'));
        $actions = array_column(DeliveryService::history((int) $x['id']), 'action');
        $this->assertContains('cancelled', $actions);
        $this->assertContains('booked', $actions);
        $this->refused(fn () => DeliveryService::cancel(DeliveryService::appointment((int) $x['id']), $this->admin, true), 'confirmed');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'delivery.cancelled'")['c']);
    }

    public function testReschedulingChecksTheNewDateFirstAndOnlyThenFreesTheOldOne(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $c = $this->proj('C');
        $x = $this->book($a, 25);
        $this->book($b, 26, 'client_dashboard', $this->client2);
        $this->book($c, 26);
        $this->refused(fn () => DeliveryService::reschedule($x, $this->day(26), $this->admin, true), 'fully booked');
        $this->assertSame($this->day(25), DeliveryService::appointment((int) $x['id'])['appointment_date'], 'a refused move changes nothing');
        $this->assertSame(1, $this->tally($this->day(25)));
        $this->refused(fn () => DeliveryService::reschedule($x, $this->day(25), $this->admin, true), 'already');
        Mailer::$sent = [];
        $n = DeliveryService::reschedule($x, $this->day(27), $this->admin, true);
        $this->assertSame($this->day(27), $n['appointment_date']);
        $this->assertSame(1, (int) $n['reschedule_count']);
        $this->assertSame(0, $this->tally($this->day(25)));
        $this->assertSame(1, $this->tally($this->day(27)));
        $this->assertContains('Your KIS Project Delivery Appointment Was Rescheduled', array_column(Mailer::$sent, 'subject'));
    }

    public function testReschedulingCannotBypassPreparationOrBlocks(): void
    {
        $p = $this->proj('Prep', 10);
        $x = $this->book($p, 12);
        $this->refused(fn () => DeliveryService::reschedule($x, $this->day(5), $this->admin, true), 'estimated time');
        $this->refused(fn () => DeliveryService::reschedule($x, $this->day(5), $this->client, false), 'estimated time');
        DeliveryService::blockDate($this->owner, $this->day(13), 'Closed');
        $this->refused(fn () => DeliveryService::reschedule($x, $this->day(13), $this->client, false), 'not available');
        $this->assertSame($this->day(12), DeliveryService::appointment((int) $x['id'])['appointment_date']);
    }

    public function testMarkingCompletedAndInternalNotes(): void
    {
        $p = $this->proj('Done');
        $x = $this->book($p, 5);
        DeliveryService::addNote($x, $this->manager, 'Call the client first');
        DeliveryService::addNote($x, $this->admin, 'Second note');
        $this->assertStringContainsString('Call the client first', DeliveryService::appointment((int) $x['id'])['internal_notes']);
        $this->assertStringContainsString('Second note', DeliveryService::appointment((int) $x['id'])['internal_notes']);
        $this->refused(fn () => DeliveryService::addNote($x, $this->admin, '<b>x</b>'), 'note');
        try {
            DeliveryService::complete($x, $this->support);
            $this->fail('support cannot complete');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        Mailer::$sent = [];
        DeliveryService::complete($x, $this->manager);
        $this->assertSame('completed', DeliveryService::appointment((int) $x['id'])['status']);
        $this->assertContains('Your KIS Project Delivery Is Complete', array_column(Mailer::$sent, 'subject'));
        $this->refused(fn () => DeliveryService::complete(DeliveryService::appointment((int) $x['id']), $this->manager), 'confirmed');
    }

    // ------------------------------------------------------------------------------------------------ the client's own changes

    public function testAClientMovesTheAppointmentThemselvesOnlyBeforeTheCutOffAndNotMoreThanAllowed(): void
    {
        $p = $this->proj('Mine');
        $x = $this->book($p, 30);
        $this->assertTrue(DeliveryService::clientMay($x, 'reschedule')['self']);
        $x = DeliveryService::clientReschedule($x, $this->day(31), $this->client);
        $x = DeliveryService::clientReschedule($x, $this->day(32), $this->client);
        $this->assertSame(2, (int) $x['reschedule_count']);
        $this->refused(fn () => DeliveryService::clientReschedule($x, $this->day(33), $this->client), 'already moved');
        $this->assertTrue(DeliveryService::clientMay($x, 'cancel')['self'], 'cancelling is a different rule');
        $near = $this->proj('Near');
        $y = $this->book($near, 2);
        $this->assertFalse(DeliveryService::clientMay($y, 'reschedule')['self']);
        $this->refused(fn () => DeliveryService::clientReschedule($y, $this->day(10), $this->client), 'need our team');
        $this->refused(fn () => DeliveryService::clientCancel($y, $this->client), 'need our team');
        $this->assertSame($this->day(2), DeliveryService::appointment((int) $y['id'])['appointment_date']);
    }

    public function testAClientRequestReachesTheTeamAndCanBeClosedOrAnswered(): void
    {
        $near = $this->proj('Near');
        $y = $this->book($near, 2);
        Mailer::$sent = [];
        $this->refused(fn () => DeliveryService::requestChange($y, 'dance', 'x', $this->client));
        $this->refused(fn () => DeliveryService::requestChange($y, 'reschedule', '   ', $this->client));
        DeliveryService::requestChange($y, 'reschedule', 'Please move it to next week', $this->client);
        $y = DeliveryService::appointment((int) $y['id']);
        $this->assertSame('reschedule', $y['change_request_kind']);
        $this->assertSame('team@kis.test', Mailer::$sent[0]['to']);
        $this->assertStringContainsString('Change Requested', Mailer::$sent[0]['subject']);
        DeliveryService::dismissRequest($y, $this->admin);
        $this->assertNull(DeliveryService::appointment((int) $y['id'])['change_request_kind']);
        DeliveryService::requestChange($y, 'cancel', 'Not needed', $this->client);
        DeliveryService::reschedule(DeliveryService::appointment((int) $y['id']), $this->day(9), $this->admin, true);
        $this->assertNull(DeliveryService::appointment((int) $y['id'])['change_request_kind'], 'answering clears the request');
    }

    // ------------------------------------------------------------------------------------------------ emails, access, listing

    public function testTheConfirmationEmailsGoToTheClientAndTheTeam(): void
    {
        $p = $this->proj('Mail Project');
        Mailer::$sent = [];
        $a = $this->book($p, 40);
        $to = array_column(Mailer::$sent, 'subject', 'to');
        $this->assertSame('Your KIS Project Delivery Appointment Is Confirmed', $to['alpha@client.test']);
        $this->assertSame('New Project Delivery Appointment — ' . $p['code'], $to['team@kis.test']);
        foreach (Mailer::$sent as $m) {
            foreach ([$p['code'], 'Mail Project', $a['booking_reference'], DeliveryService::nice($this->day(40))] as $needle) {
                $this->assertStringContainsString($needle, $m['body']);
            }
        }
        $team = array_values(array_filter(Mailer::$sent, static fn (array $m): bool => $m['to'] === 'team@kis.test'))[0];
        $this->assertStringContainsString('Online Dashboard', $team['body']);
        $this->assertStringContainsString('Booked at', $team['body']);
        Mailer::$sent = [];
        $this->book($this->proj('Mail 2'), 41, 'manual', $this->admin);
        $this->assertStringContainsString('booked by the team', implode(' ', array_column(Mailer::$sent, 'body')));
        $this->assertStringContainsString('Manual / Offline Booking', implode(' ', array_column(Mailer::$sent, 'body')));
    }

    public function testStaffSeeWhatTheirPermissionsAllow(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $this->book($a, 5);
        $this->book($b, 5, 'client_dashboard', $this->client2);
        $count = fn (int $u): int => DeliveryService::search($u, [], 1, 50)['total'];
        $this->assertSame(2, $count($this->owner));
        $this->assertSame(2, $count($this->admin));
        $this->assertSame(1, $count($this->support), 'assigned projects only');
        foreach ([$this->client, $this->dev] as $u) {
            try {
                $count($u);
                $this->fail('no access');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertSame(1, DeliveryService::search($this->admin, ['project' => $a['code']], 1, 50)['total']);
        $this->assertSame(1, DeliveryService::search($this->admin, ['client' => 'bravo'], 1, 50)['total']);
        $this->assertSame(0, DeliveryService::search($this->admin, ['status' => 'cancelled'], 1, 50)['total']);
        $this->assertSame(2, DeliveryService::search($this->admin, ['from' => $this->day(5), 'to' => $this->day(5), 'source' => 'client_dashboard'], 1, 50)['total']);
        $this->assertSame(2, DeliveryService::stats($this->admin)['confirmed']);
        $this->assertSame(2, DeliveryService::stats($this->admin)['upcoming']);
    }

    public function testTheMigrationsEnforceTheirKeys(): void
    {
        $p = $this->proj('Keys');
        $a = $this->book($p, 5);
        $this->expectException(\PDOException::class);
        Database::run("INSERT INTO delivery_appointments (booking_reference, project_id, client_id, appointment_date, booking_source, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'manual', 'confirmed', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$a['booking_reference'], $p['id'], $p['client_id'], $this->day(9)]);
    }

    // ------------------------------------------------------------------------------------------------ concurrency

    /** @param list<callable():bool> $jobs each runs in its own process with its own database connection and returns true if it booked */
    private function parallel(array $jobs): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed for the concurrency test.');
        }
        Database::disconnect();
        $dir = sys_get_temp_dir() . '/vtx-par-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                Database::disconnect();
                $out = 'error';
                try {
                    $out = $job() ? 'ok' : 'no';
                } catch (ValidationException) {
                    $out = 'refused';
                } catch (\Throwable $e) {
                    $out = 'error: ' . $e->getMessage();
                }
                file_put_contents($dir . '/' . $i, $out);
                posix_kill(posix_getpid(), SIGKILL); // no PHPUnit shutdown in the child
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        Database::disconnect();
        $res = [];
        foreach (array_keys($jobs) as $i) {
            $res[] = (string) @file_get_contents($dir . '/' . $i);
            @unlink($dir . '/' . $i);
        }
        @rmdir($dir);

        return $res;
    }

    public function testSimultaneousBookingsOfOneDateNeverExceedItsCapacity(): void
    {
        $projects = [];
        for ($i = 1; $i <= 8; $i++) {
            $projects[] = $this->proj('Race ' . $i);
        }
        Mailer::$sent = [];
        Config::set('mail.driver', 'none');
        $date = $this->day(45);
        $jobs = array_map(fn (array $p): callable => fn (): bool => DeliveryService::book($p, $date, 'client_dashboard', $this->client) !== [], $projects);
        $res = $this->parallel($jobs);
        $this->assertSame([], array_filter($res, static fn (string $r): bool => str_starts_with($r, 'error')), 'no crashes or deadlocks: ' . implode(' | ', $res));
        $this->assertSame(2, count(array_filter($res, static fn (string $r): bool => $r === 'ok')), implode(',', $res));
        $this->assertSame(2, $this->tally($date), 'exactly the capacity was booked');
        $this->assertSame(6, count(array_filter($res, static fn (string $r): bool => $r === 'refused')));
    }

    public function testASimultaneousDoubleBookingOfOneProjectOnDifferentDatesAllowsOnlyOne(): void
    {
        $p = $this->proj('Twice');
        Config::set('mail.driver', 'none');
        $jobs = [];
        foreach ([50, 51, 52, 53] as $n) {
            $jobs[] = fn (): bool => DeliveryService::book($p, $this->day($n), 'client_dashboard', $this->client) !== [];
        }
        $res = $this->parallel($jobs);
        $this->assertSame(1, count(array_filter($res, static fn (string $r): bool => $r === 'ok')), implode(',', $res));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM delivery_appointments WHERE project_id = ? AND status = 'confirmed'", [$p['id']])['c']);
    }

    public function testRacingReschedulesAndBookingsKeepTheCapacity(): void
    {
        $a = $this->proj('A');
        $b = $this->proj('B', 0, $this->client2);
        $c = $this->proj('C');
        $d = $this->proj('D');
        $date = $this->day(60);
        $xa = $this->book($a, 55);
        $xb = $this->book($b, 56, 'client_dashboard', $this->client2);
        Config::set('mail.driver', 'none');
        $res = $this->parallel([
            fn (): bool => DeliveryService::reschedule($xa, $date, $this->admin, true) !== [],
            fn (): bool => DeliveryService::reschedule($xb, $date, $this->admin, true) !== [],
            fn (): bool => DeliveryService::book($c, $date, 'client_dashboard', $this->client) !== [],
            fn (): bool => DeliveryService::book($d, $date, 'manual', $this->admin) !== [],
        ]);
        $this->assertSame([], array_filter($res, static fn (string $r): bool => str_starts_with($r, 'error')), implode(' | ', $res));
        $this->assertSame(2, $this->tally($date), 'never more than the capacity: ' . implode(',', $res));
        $this->assertSame(2, count(array_filter($res, static fn (string $r): bool => $r === 'ok')));
    }
}
