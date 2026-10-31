<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\DeliveryPublic;
use Vertex\Services\DeliveryService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/** The delivery appointment pages end to end: client dashboard, team screens and permissions, and the public (no sign-in) booking flow. */
final class DeliveryHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    private int $owner;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function day(int $n): string
    {
        return AppTime::todayPlus($n);
    }

    /** @return array<string,mixed> */
    private function proj(string $title, int $prep = 0, ?int $clientUser = null): array
    {
        $p = $this->makeProject($clientUser ?? $this->client, $title);
        $row = ProjectPolicy::find($p['public_id']);
        DeliveryService::savePlan($this->owner, $row, ['preparation_days' => (string) $prep, 'booking_enabled' => '1']);
        if ($clientUser === null) {
            foreach ([$this->manager, $this->support] as $u) {
                ProjectAssignmentService::assign($this->admin, (int) $row['id'], $u, 'member');
            }
        }

        return $row;
    }

    private function page(array $p): string
    {
        return '/projects/' . $p['public_id'] . '/delivery';
    }

    private function tally(string $date): int
    {
        return (int) Database::one("SELECT COUNT(*) c FROM delivery_appointments WHERE appointment_date = ? AND status = 'confirmed'", [$date])['c'];
    }

    private function otpFromMail(): ?string
    {
        $log = '';
        foreach (glob(VERTEX_ROOT . '/storage/logs/mail-*.log') ?: [] as $f) {
            $log .= (string) file_get_contents($f);
        }

        return preg_match_all('/Code: (\d{6})/', $log, $m) ? end($m[1]) : null;
    }

    /** The page without per-request noise (CSRF tokens), for comparing two responses. */
    private function plain(string $html): string
    {
        return (string) preg_replace('/value="[a-f0-9]{32,}"/', 'value="T"', $html);
    }

    // ------------------------------------------------------------------------------------------------ client dashboard

    public function testAClientChoosesAStartDateThenADeliveryDateAfterTheEstimatedTime(): void
    {
        $p = $this->proj('Alpha site', 3); // estimated 3 days
        $today = AppTime::today();
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get($this->page($p));
        $this->assertSame(200, $page['status']);
        foreach (['Project delivery appointment', 'Step 1', 'Choose your project start date', 'Estimated time', '3 days', 'Delivery</a>'] as $needle) {
            $this->assertStringContainsString($needle, $page['body']);
        }
        $this->assertStringNotContainsString('Step 2', $page['body'], 'no calendar before a start date is chosen');
        $step2 = str_replace('&amp;', '&', $c->get($this->page($p) . '?start=' . $today)['body']);
        $this->assertStringContainsString('Step 2', $step2);
        $this->assertStringContainsString('date=' . $this->day(4) . '#review', $step2, 'start + 3 days + 1 is the first selectable day');
        $this->assertStringNotContainsString('date=' . $this->day(3) . '#review', $step2, 'the end of the estimate itself is not selectable');
        $this->assertStringNotContainsString('date=' . $this->day(2) . '#review', $step2);
        $later = str_replace('&amp;', '&', $c->get($this->page($p) . '?start=' . $this->day(10))['body']);
        $this->assertStringContainsString('date=' . $this->day(14) . '#review', $later, 'a later start moves the first delivery date');
        $this->assertStringNotContainsString('date=' . $this->day(13) . '#review', $later);
        $review = $c->get($this->page($p) . '?start=' . $today . '&date=' . $this->day(4))['body'];
        $this->assertStringContainsString('Review your booking', $review);
        $this->assertStringContainsString('Project start date', $review);
        $this->assertStringNotContainsString('<dt>Delivery date</dt>', $review, 'the review no longer repeats the delivery date');
        $this->assertStringContainsString('Confirm delivery appointment', $review);

        $c->post($this->page($p) . '/book', ['date' => $this->day(3), 'start_date' => $today], $this->page($p));
        $this->assertSame(0, $this->tally($this->day(3)), 'a hand-made request inside the estimated time is refused');
        $c->post($this->page($p) . '/book', ['date' => $this->day(13), 'start_date' => $this->day(10)], $this->page($p));
        $this->assertSame(0, $this->tally($this->day(13)), 'and so is one that ignores the chosen start date');
        $c->post($this->page($p) . '/book', ['date' => 'garbage', 'start_date' => $today], $this->page($p));
        $c->post($this->page($p) . '/book', ['date' => $this->day(14), 'start_date' => 'soon'], $this->page($p));
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM delivery_appointments')['c']);
        $r = $c->post($this->page($p) . '/book', ['date' => $this->day(14), 'start_date' => $this->day(10), 'client_notes' => 'Morning please'], $this->page($p));
        $this->assertSame(303, $r['status']);
        $a = Database::one('SELECT * FROM delivery_appointments');
        $this->assertSame($this->day(14), $a['appointment_date']);
        $this->assertSame($this->day(10), $a['project_start_date']);
        $this->assertSame('client_dashboard', $a['booking_source']);
        $after = $c->get($this->page($p))['body'];
        foreach (['Your Delivery Appointment Is Confirmed!', $a['booking_reference'], $p['code'], 'Confirmed'] as $needle) {
            $this->assertStringContainsString($needle, $after);
        }
        $mine = $c->get('/my/delivery');
        $this->assertSame(200, $mine['status']);
        $this->assertStringContainsString($a['booking_reference'], $mine['body']);
    }

    public function testAFullDateIsShownAsFullAndCannotBeTakenByAThirdClient(): void
    {
        $date = $this->day(5);
        DeliveryService::book($this->proj('B', 0, $this->client2), $date, 'client_dashboard', $this->client2);
        DeliveryService::book($this->proj('A2'), $date, 'client_dashboard', $this->client);
        $third = $this->proj('A3');
        $c = $this->loggedIn('alpha@client.test');
        $body = $c->get($this->page($third) . '?start=' . AppTime::today() . '&month=' . substr($date, 0, 7))['body'];
        $this->assertStringContainsString('Fully booked', $body);
        $this->assertStringNotContainsString('date=' . $date . '#review', str_replace('&amp;', '&', $body));
        $this->assertStringNotContainsString('User bravo@client.test', $body, 'no other client is named');
        $c->post($this->page($third) . '/book', ['date' => $date, 'start_date' => AppTime::today()], $this->page($third));
        $this->assertSame(2, $this->tally($date));
        $c->post($this->page($third) . '/book', ['date' => $this->day(6), 'start_date' => AppTime::today()], $this->page($third));
        $this->assertSame(1, $this->tally($this->day(6)));
    }

    public function testAClientCannotUseAnotherClientsProjectOrTheTeamScreens(): void
    {
        $p = $this->proj('Alpha only');
        $c2 = $this->loggedIn('bravo@client.test');
        $this->assertSame(404, $c2->get($this->page($p))['status']);
        $this->assertSame(404, $c2->post($this->page($p) . '/book', ['date' => $this->day(4)], '/account')['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM delivery_appointments')['c']);
        $c = $this->loggedIn('alpha@client.test');
        foreach (['/admin/delivery', '/admin/delivery/capacity'] as $url) {
            $this->assertSame(403, $c->get($url)['status'], $url);
        }
        foreach (['/plan', '/manual', '/link'] as $tail) {
            $this->assertSame(403, $c->post($this->page($p) . $tail, ['date' => $this->day(4), 'preparation_days' => '1'], $this->page($p))['status'], $tail);
        }
        $this->assertNull(Database::one('SELECT 1 FROM project_delivery_settings WHERE preparation_days = 1'));
    }

    public function testAClientMovesOrCancelsThemselvesAndAskedChangesGoToTheTeam(): void
    {
        $p = $this->proj('Moves');
        $c = $this->loggedIn('alpha@client.test');
        $c->post($this->page($p) . '/book', ['date' => $this->day(20)], $this->page($p));
        $ref = (string) Database::one('SELECT booking_reference r FROM delivery_appointments')['r'];
        $this->assertStringContainsString('Choose a different date', $c->get($this->page($p))['body']);
        $this->assertStringContainsString('Choose a new delivery date', $c->get($this->page($p) . '?mode=reschedule')['body']);
        $c->post($this->page($p) . '/reschedule', ['reference' => $ref, 'date' => $this->day(21)], $this->page($p));
        $this->assertSame($this->day(21), Database::one('SELECT appointment_date d FROM delivery_appointments')['d']);
        $c->post($this->page($p) . '/reschedule', ['reference' => 'KDA-NOPE', 'date' => $this->day(22)], $this->page($p));
        $this->assertSame($this->day(21), Database::one('SELECT appointment_date d FROM delivery_appointments')['d']);
        $c->post($this->page($p) . '/request-change', ['reference' => $ref, 'kind' => 'reschedule', 'note' => 'Next month please'], $this->page($p));
        $this->assertSame('reschedule', Database::one('SELECT change_request_kind k FROM delivery_appointments')['k']);
        $c->post($this->page($p) . '/cancel', ['reference' => $ref], $this->page($p));
        $this->assertSame('cancelled', Database::one('SELECT status s FROM delivery_appointments')['s']);
        $this->assertSame(0, $this->tally($this->day(21)));
        $c->post($this->page($p) . '/book', ['date' => $this->day(23)], $this->page($p));
        $this->assertSame(1, $this->tally($this->day(23)), 'a new date can be booked after cancelling');
    }

    // ------------------------------------------------------------------------------------------------ the team screens

    public function testTheTeamScreensAndWhoMayUseThem(): void
    {
        $p = $this->proj('Team');
        DeliveryService::book($p, $this->day(8), 'client_dashboard', $this->client);
        $id = (int) Database::one('SELECT id FROM delivery_appointments')['id'];
        $o = $this->loggedIn('owner@x.test');
        $index = $o->get('/admin/delivery');
        $this->assertSame(200, $index['status']);
        foreach (['Delivery appointments', 'Total confirmed', 'Create a manual / offline booking', 'All appointments', $p['code']] as $needle) {
            $this->assertStringContainsString($needle, $index['body']);
        }
        $this->assertStringContainsString(local_date($this->day(8), 'l, j F Y'), $o->get('/admin/delivery?date=' . $this->day(8))['body']);
        $this->assertSame(200, $o->get('/admin/delivery/capacity')['status']);
        $this->assertSame(200, $o->get('/admin/delivery/appointments/' . $id)['status']);
        $this->assertSame(200, $o->get($this->page($p))['status']);
        $this->assertSame(200, $o->get('/admin/delivery?status=confirmed&source=client_dashboard&project=' . $p['code'])['status']);
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get('/admin/delivery/capacity')['status']);

        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/admin/delivery')['status']);
        $this->assertSame(403, $m->get('/admin/delivery/capacity')['status'], 'capacity is for those who configure');
        $this->assertSame(403, $m->post('/admin/delivery/capacity/block', ['date' => $this->day(9), 'reason' => 'x'], '/admin/delivery')['status']);
        $this->assertSame(403, $m->post($this->page($p) . '/plan', ['preparation_days' => '1'], '/admin/delivery')['status']);

        $s = $this->loggedIn('sup@x.test');
        $this->assertSame(200, $s->get('/admin/delivery')['status'], 'support sees assigned projects');
        $this->assertSame(200, $s->get('/admin/delivery/appointments/' . $id)['status']);
        $this->assertSame(403, $s->post('/admin/delivery/appointments/' . $id . '/cancel', [], '/admin/delivery')['status']);
        $this->assertSame(403, $s->post('/admin/delivery/manual', ['project' => $p['public_id'], 'date' => $this->day(9)], '/admin/delivery')['status']);

        $other = $this->proj('Other', 0, $this->client2);
        DeliveryService::book($other, $this->day(9), 'client_dashboard', $this->client2);
        $oid = (int) Database::one("SELECT id FROM delivery_appointments WHERE project_id = ?", [$other['id']])['id'];
        $this->assertSame(404, $s->get('/admin/delivery/appointments/' . $oid)['status'], 'not assigned to that project');
        $this->assertSame(403, $this->loggedIn('dev@x.test')->get('/admin/delivery')['status']);
    }

    public function testTheTeamCanBookRescheduleCompleteAndControlCapacityOverHttp(): void
    {
        $p = $this->proj('Phone request', 20);
        $o = $this->loggedIn('owner@x.test');
        $o->post('/admin/delivery/manual', ['project' => $p['public_id'], 'date' => $this->day(5), 'source' => 'manual', 'internal_notes' => 'x'], '/admin/delivery');
        $this->assertSame(0, $this->tally($this->day(5)), 'the preparation time applies to manual bookings too');
        $o->post('/admin/delivery/manual', ['project' => $p['public_id'], 'date' => $this->day(5), 'source' => 'manual', 'override' => '1', 'internal_notes' => 'Phone call'], '/admin/delivery');
        $a = Database::one('SELECT * FROM delivery_appointments');
        $this->assertSame('manual', $a['booking_source']);
        $this->assertSame('Phone call', $a['internal_notes']);
        $this->assertSame(1, DeliveryService::day($this->day(5))['used'], 'manual bookings use the same capacity');
        $id = $a['id'];
        $o->post("/admin/delivery/appointments/{$id}/note", ['note' => 'Client confirmed on WhatsApp'], "/admin/delivery/appointments/{$id}");
        $this->assertStringContainsString('Client confirmed on WhatsApp', $o->get("/admin/delivery/appointments/{$id}")['body']);
        $o->post("/admin/delivery/appointments/{$id}/reschedule", ['date' => $this->day(6)], "/admin/delivery/appointments/{$id}");
        $this->assertSame(1, $this->tally($this->day(5)), 'moving inside the preparation time is refused without the override');
        $o->post("/admin/delivery/appointments/{$id}/reschedule", ['date' => $this->day(6), 'override' => '1'], "/admin/delivery/appointments/{$id}");
        $this->assertSame([0, 1], [$this->tally($this->day(5)), $this->tally($this->day(6))]);
        $o->post('/admin/delivery/capacity/block', ['date' => $this->day(7), 'reason' => 'Public holiday'], '/admin/delivery/capacity');
        $cap = $o->get('/admin/delivery/capacity')['body'];
        $this->assertStringContainsString('Public holiday', $cap);
        $this->assertTrue(DeliveryService::day($this->day(7))['blocked']);
        $o->post('/admin/delivery/capacity/unblock', ['date' => $this->day(7)], '/admin/delivery/capacity');
        $this->assertFalse(DeliveryService::day($this->day(7))['blocked']);
        $o->post('/admin/delivery/capacity/reserve', ['date' => $this->day(8), 'slots' => '1', 'reason' => 'Launch'], '/admin/delivery/capacity');
        $this->assertSame(1, DeliveryService::day($this->day(8))['reserved']);
        $rid = (int) Database::one('SELECT id FROM delivery_capacity_reservations')['id'];
        $o->post("/admin/delivery/capacity/reservations/{$rid}/release", [], '/admin/delivery/capacity');
        $this->assertSame(0, DeliveryService::day($this->day(8))['reserved']);
        $o->post('/admin/delivery/capacity/date', ['date' => $this->day(9), 'capacity' => '5'], '/admin/delivery/capacity');
        $this->assertSame(5, DeliveryService::day($this->day(9))['cap']);
        $o->post('/admin/delivery/capacity/defaults', ['default_capacity' => '3', 'public_mode' => 'otp'], '/admin/delivery/capacity');
        \Vertex\Services\Settings::reload();
        $this->assertSame(3, DeliveryService::defaultCapacity());
        $o->post("/admin/delivery/appointments/{$id}/complete", [], "/admin/delivery/appointments/{$id}");
        $this->assertSame('completed', Database::one('SELECT status s FROM delivery_appointments')['s']);
        $this->assertGreaterThan(5, (int) Database::one('SELECT COUNT(*) c FROM delivery_appointment_logs')['c']);
    }

    public function testTheProjectPageLetsTheOwnerSetThePreparationTimeAndCreateALink(): void
    {
        $p = $this->makeProject($this->client, 'Fresh');
        $o = $this->loggedIn('owner@x.test');
        $this->assertStringContainsString('Delivery settings for this project', $o->get($this->page($p))['body']);
        $o->post($this->page($p) . '/plan', ['preparation_days' => '2', 'preparation_unit' => 'months', 'booking_enabled' => '1'], $this->page($p));
        $s = Database::one('SELECT * FROM project_delivery_settings');
        $this->assertSame([2, 'months'], [(int) $s['preparation_days'], $s['preparation_unit']]);
        $this->assertStringContainsString('2 months', $o->get($this->page($p))['body']);
        $o->post($this->page($p) . '/plan', ['preparation_days' => 'soon'], $this->page($p));
        $this->assertSame(2, (int) Database::one('SELECT preparation_days p FROM project_delivery_settings')['p']);
        $o->post($this->page($p) . '/link', [], $this->page($p));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM delivery_booking_tokens')['c']);
        $shown = $o->get($this->page($p))['body'];
        $this->assertMatchesRegularExpression('#/delivery-appointment/link/[a-f0-9]{48}#', $shown, 'the link is shown once');
        $this->assertDoesNotMatchRegularExpression('#/delivery-appointment/link/[a-f0-9]{48}#', $o->get($this->page($p))['body'], 'and not again');
        $this->assertNull(Database::one("SELECT 1 FROM delivery_booking_tokens WHERE token_hash REGEXP '^[a-f0-9]{48}$'"), 'only a hash is stored');
    }

    // ------------------------------------------------------------------------------------------------ public booking

    public function testThePublicFlowNeverRevealsWhichProjectsOrNamesExist(): void
    {
        $p = $this->proj('Public one', 0);
        $name = 'User alpha@client.test';
        $guest = $this->client();
        $wrong = $this->client();
        $this->assertSame(200, $guest->get('/delivery-appointment')['status']);
        $good = $guest->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => $name], '/delivery-appointment');
        $bad = $wrong->post('/delivery-appointment', ['project_id' => 'KIS-0000', 'client_name' => 'Nobody At All'], '/delivery-appointment');
        $badName = $this->client()->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => 'Somebody Else'], '/delivery-appointment');
        foreach ([$good, $bad, $badName] as $r) {
            $this->assertSame(303, $r['status']);
            $this->assertSame('/delivery-appointment/verify', $r['location']);
        }
        $this->assertSame($this->plain($guest->get('/delivery-appointment/verify')['body']), $this->plain($wrong->get('/delivery-appointment/verify')['body']), 'the next page is identical');
        $this->assertNotNull($this->otpFromMail(), 'only the real client got a code');
        $this->assertSame(1, $this->mailCount());
        $this->assertStringNotContainsString($p['title'], $guest->get('/delivery-appointment/verify')['body']);
        $this->assertSame(303, $guest->get('/delivery-appointment/book')['status'], 'nothing without the code');
        $this->assertSame('/delivery-appointment', $guest->get('/delivery-appointment/book')['location']);
    }

    public function testOnlyTheEmailedCodeOpensTheBookingAndItWorksOnce(): void
    {
        $p = $this->proj('Public two', 0);
        $name = 'user ALPHA client test';
        $c = $this->client();
        $c->post('/delivery-appointment', ['project_id' => strtolower($p['code']), 'client_name' => $name], '/delivery-appointment');
        $code = $this->otpFromMail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $wrong = $code === '000000' ? '111111' : '000000';
        $r = $c->post('/delivery-appointment/verify', ['code' => $wrong], '/delivery-appointment/verify');
        $this->assertSame('/delivery-appointment/verify', $r['location']);
        $this->assertSame(303, $c->get('/delivery-appointment/book')['status']);
        $ok = $c->post('/delivery-appointment/verify', ['code' => $code], '/delivery-appointment/verify');
        $this->assertSame('/delivery-appointment/book', $ok['location']);
        $page = $c->get('/delivery-appointment/book');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString($p['code'], $page['body']);
        $this->assertStringContainsString('Public two', $page['body']);
        $this->assertStringContainsString('Step 1', $page['body'], 'the start date comes first');
        $this->assertStringContainsString('Step 2', $c->get('/delivery-appointment/book?start=' . $this->day(2))['body']);
        $this->assertStringContainsString('no-store', implode(' ', (array) ($c->lastHeaders['cache-control'] ?? '')));
        $b = $c->post('/delivery-appointment/book', ['date' => $this->day(4), 'start_date' => $this->day(2), 'client_notes' => 'Via link'], '/delivery-appointment/book');
        $this->assertSame(303, $b['status']);
        $a = Database::one('SELECT * FROM delivery_appointments');
        $this->assertSame('public_link', $a['booking_source']);
        $this->assertSame($this->day(4), $a['appointment_date']);
        $this->assertSame($this->day(2), $a['project_start_date']);
        $this->assertStringContainsString($a['booking_reference'], $c->get('/delivery-appointment/book')['body']);
        // the code cannot be used again from another browser
        $again = $this->client();
        $again->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => 'User alpha@client.test'], '/delivery-appointment');
        $again->post('/delivery-appointment/verify', ['code' => $code], '/delivery-appointment/verify');
        $this->assertSame(303, $again->get('/delivery-appointment/book')['status']);
    }

    public function testTooManyWrongCodesLockTheCodeEvenForTheRightOne(): void
    {
        $p = $this->proj('Public three', 0);
        $c = $this->client();
        $c->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => 'User alpha@client.test'], '/delivery-appointment');
        $code = (string) $this->otpFromMail();
        $wrong = $code === '123456' ? '654321' : '123456';
        for ($i = 0; $i < 5; $i++) {
            $c->post('/delivery-appointment/verify', ['code' => $wrong], '/delivery-appointment/verify');
        }
        $c->post('/delivery-appointment/verify', ['code' => $code], '/delivery-appointment/verify');
        $this->assertSame(303, $c->get('/delivery-appointment/book')['status'], 'locked after five wrong tries');
    }

    public function testAPrivateLinkOpensOnlyItsOwnProjectAndCanBeSwitchedOff(): void
    {
        $p = $this->proj('Linked', 0);
        $other = $this->proj('Not linked', 0, $this->client2);
        DeliveryService::saveDefaults($this->owner, ['public_mode' => 'token']);
        $token = DeliveryPublic::createToken($this->owner, $p);
        $c = $this->client();
        $this->assertStringContainsString('private booking link', $c->get('/delivery-appointment')['body']);
        $c->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => 'User alpha@client.test'], '/login');
        $this->assertSame(0, $this->mailCount(), 'no code is sent in link-only mode');
        $r = $c->get('/delivery-appointment/link/' . $token);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/delivery-appointment/book', $r['location']);
        $page = $c->get('/delivery-appointment/book')['body'];
        $this->assertStringContainsString('Linked', $page);
        $this->assertStringNotContainsString('Not linked', $page);
        $c->post('/delivery-appointment/book', ['date' => $this->day(3)], '/delivery-appointment/book');
        $this->assertSame((int) $p['id'], (int) Database::one('SELECT project_id FROM delivery_appointments')['project_id']);
        $this->assertSame((int) $p['id'] === 0 ? 0 : 0, (int) Database::one('SELECT COUNT(*) c FROM delivery_appointments WHERE project_id = ?', [$other['id']])['c']);
        DeliveryPublic::revokeTokens($this->owner, $p);
        $bad = $this->client()->get('/delivery-appointment/link/' . $token);
        $this->assertSame(200, $bad['status']);
        $this->assertStringContainsString('not valid', $bad['body']);
        $this->assertStringContainsString('not valid', $this->client()->get('/delivery-appointment/link/' . str_repeat('a', 48))['body']);
        $this->assertStringContainsString('not valid', $this->client()->get('/delivery-appointment/link/short')['body']);
    }

    public function testThePublicPageCanBeSwitchedOff(): void
    {
        $p = $this->proj('Off', 0);
        DeliveryService::saveDefaults($this->owner, ['public_mode' => 'off']);
        $c = $this->client();
        $this->assertStringContainsString('not available right now', $c->get('/delivery-appointment')['body']);
        $c->post('/delivery-appointment', ['project_id' => $p['code'], 'client_name' => 'User alpha@client.test'], '/login');
        $this->assertSame(0, $this->mailCount());
        $this->assertSame(303, $c->get('/delivery-appointment/book')['status']);
    }
}
