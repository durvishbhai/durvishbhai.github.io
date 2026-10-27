<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\AppointmentConfig;
use Vertex\Services\AppTime;
use Vertex\Services\EnquiryExtras;

/** Appointments with the founder: the team's times and places, the booking form, its rules and the "not booked yet" wording. */
final class AppointmentTest extends HttpTestCase
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

    private function slot(string $from = '10:00', string $to = '11:00', int $mask = 127, int $active = 1): int
    {
        return AppointmentConfig::saveSlot($this->owner, null, ['start_time' => $from, 'end_time' => $to, 'days' => array_values(array_filter(range(1, 7), static fn (int $d): bool => ($mask & (1 << ($d - 1))) !== 0)), 'active' => $active ? '1' : '']);
    }

    /** Posts the booking form; the per-address limit (5 an hour) is cleared first so one test can send several. @return array<string,mixed> */
    private function send(\Vertex\Tests\Support\HttpClient $c, array $form): array
    {
        Database::run("DELETE FROM rate_limits WHERE bucket = 'public_form_ip'");

        return $c->post('/book-appointment', $form, '/book-appointment');
    }

    private function date(int $days): string
    {
        return AppTime::todayPlus($days);
    }

    /** @return array<string,mixed> */
    private function form(int $slot, array $over = []): array
    {
        return $over + ['contact_name' => 'Asha Fictional', 'email' => 'asha@web.test', 'phone' => '+91 90000 11111', 'whatsapp_same' => '1', 'occupation' => 'Shop owner', 'description' => 'I want to discuss a website for my shop.',
            'slot' => (string) $slot, 'meeting_date' => $this->date(3), 'meeting_type' => 'online', 'consent' => '1', 'website_url' => ''];
    }

    public function testTheOwnerAddsEditsHidesAndDeletesTimesAndPlaces(): void
    {
        $c = $this->loggedIn('owner@x.test');
        $this->assertStringContainsString('Appointments', $c->get('/admin/appointments')['body']);
        $c->post('/admin/appointments/slots', ['label' => 'Morning', 'start_time' => '10:00', 'end_time' => '11:30', 'days' => ['1', '2', '3'], 'sort_order' => '1', 'active' => '1'], '/admin/appointments');
        $s = Database::one('SELECT * FROM appointment_slots');
        $this->assertSame(7, (int) $s['weekdays'], 'Mon+Tue+Wed');
        $this->assertSame('Morning (10:00 AM - 11:30 AM)', AppointmentConfig::slotText($s));
        $this->assertSame('Mon, Tue, Wed', AppointmentConfig::daysText(7));
        $c->post('/admin/appointments/slots/' . $s['id'], ['label' => 'Morning', 'start_time' => '10:00', 'end_time' => '12:00', 'days' => ['1'], 'active' => ''], '/admin/appointments');
        $s = Database::one('SELECT * FROM appointment_slots');
        $this->assertSame('12:00:00', $s['end_time']);
        $this->assertSame(0, (int) $s['active'], 'hidden from visitors');
        $bad = $c->post('/admin/appointments/slots', ['start_time' => '12:00', 'end_time' => '11:00', 'days' => ['1']], '/admin/appointments');
        $this->assertSame(303, $bad['status']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM appointment_slots')['c'], 'end before start is refused');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM appointment_slots')['c']);
        $c->post('/admin/appointments/slots', ['start_time' => '14:00', 'end_time' => '15:00'], '/admin/appointments');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM appointment_slots')['c'], 'at least one weekday is required');

        $c->post('/admin/appointments/places', ['name' => 'KIS office', 'details' => 'Ground floor', 'active' => '1'], '/admin/appointments');
        $c->post('/admin/appointments/places', ['name' => 'KIS office', 'active' => '1'], '/admin/appointments');
        $c->post('/admin/appointments/places', ['name' => 'We will discuss it later', 'active' => '1'], '/admin/appointments');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM meeting_places')['c'], 'no duplicates and the permanent option is not a stored place');
        $p = Database::one('SELECT * FROM meeting_places');
        $c->post('/admin/appointments/places/' . $p['id'], ['name' => 'Client site', 'active' => '1'], '/admin/appointments');
        $this->assertSame('Client site', Database::one('SELECT name FROM meeting_places')['name']);
        $c->post('/admin/appointments/places/' . $p['id'] . '/delete', [], '/admin/appointments');
        $c->post('/admin/appointments/slots/' . $s['id'] . '/delete', [], '/admin/appointments');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM meeting_places')['c'] + (int) Database::one('SELECT COUNT(*) c FROM appointment_slots')['c']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action LIKE 'appointment_slot.%' OR action LIKE 'meeting_place.%'")['c']);
    }

    public function testOnlyThosePermittedCanEditTheTimesAndPlaces(): void
    {
        foreach (['mgr@x.test', 'sup@x.test', 'alpha@client.test'] as $email) {
            $c = $this->loggedIn($email);
            $this->assertSame(403, $c->get('/admin/appointments')['status'], $email);
            $this->assertSame(403, $c->post('/admin/appointments/slots', ['start_time' => '10:00', 'end_time' => '11:00', 'days' => ['1']], '/account')['status'], $email);
        }
        $this->assertSame(200, $this->loggedIn('admin@x.test')->get('/admin/appointments')['status']);
    }

    public function testTheFormOffersTheTeamsTimesAndPlacesAndAlwaysTheDiscussLaterOption(): void
    {
        $c = $this->client();
        $empty = $c->get('/book-appointment')['body'];
        $this->assertStringContainsString('No appointment times are open', $empty, 'nothing is offered until the team adds a time');
        $this->slot('10:00', '11:00', 31);
        $this->slot('15:00', '16:00', 127, 0); // hidden
        AppointmentConfig::savePlace($this->owner, null, ['name' => 'KIS office, Pune', 'active' => '1']);
        AppointmentConfig::savePlace($this->owner, null, ['name' => 'Hidden place', 'active' => '']);
        $page = $c->get('/book-appointment')['body'];
        foreach (['Full name', 'Email address', 'Phone number', 'WhatsApp number', 'same as my phone number', 'Company / organization', 'Your occupation', 'Purpose of the meeting', 'Date for the meeting', 'Available timings', 'Meeting type', 'Online', 'In-person', 'Place for the in-person meeting', 'Submit appointment request'] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }
        $this->assertStringContainsString('10:00 AM - 11:00 AM', $page);
        $this->assertStringContainsString('Mon, Tue, Wed, Thu, Fri', $page, 'the weekdays of a time are shown');
        $this->assertStringNotContainsString('3:00 PM', $page, 'a hidden time is not offered');
        $this->assertStringContainsString('KIS office, Pune', $page);
        $this->assertStringNotContainsString('Hidden place', $page);
        $this->assertStringContainsString('We will discuss it later', $page);
        $this->assertStringContainsString('not booked until our team', $page);
        $this->assertStringContainsString('Durvish Jawale', $page);
    }

    public function testAVisitorSendsARequestAndIsToldItIsNotBookedYet(): void
    {
        $slot = $this->slot();
        $c = $this->client();
        $r = $this->send($c, $this->form($slot));
        $this->assertSame(303, $r['status'], $r['body']);
        $this->assertSame('/enquiry/received', $r['location']);
        $page = $c->get('/enquiry/received')['body'];
        $this->assertStringContainsString('Appointment request received', $page);
        $this->assertStringContainsString('not booked yet', $page);
        $this->assertStringContainsString('request, not a confirmed appointment', $page);
        $this->assertStringContainsString('genuine', $page);
        $e = Database::one("SELECT * FROM enquiries WHERE email = 'asha@web.test'");
        $this->assertSame('appointment_request', $e['source']);
        $this->assertSame('I want to discuss a website for my shop.', $e['description']);
        $d = EnquiryExtras::details((int) $e['id']);
        $this->assertSame('Durvish Jawale (Founder & CEO)', $d['Meeting with']);
        $this->assertSame('Online', $d['Meeting type']);
        $this->assertSame('10:00 AM - 11:00 AM', $d['Meeting time']);
        $this->assertSame('Shop owner', $d['Occupation']);
        $this->assertSame('+91 90000 11111 (same as phone)', $d['WhatsApp number']);
        $this->assertArrayNotHasKey('Meeting place', $d);
        $this->assertNull($e['client_id'], 'a visitor is not a client');
    }

    public function testWhatsappIsRequiredOnlyWhenItDiffersFromThePhone(): void
    {
        $slot = $this->slot();
        $c = $this->client();
        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $this->send($c, $this->form($slot, ['whatsapp_same' => '', 'whatsapp' => '']));
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'a different WhatsApp number must be given');
        $this->send($c, $this->form($slot, ['whatsapp_same' => '', 'whatsapp' => '+91 98000 22222']));
        $d = EnquiryExtras::details((int) Database::one("SELECT id FROM enquiries WHERE email = 'asha@web.test'")['id']);
        $this->assertSame('+91 98000 22222', $d['WhatsApp number']);
    }

    public function testInPersonNeedsAPlaceAndTheDiscussLaterOptionIsAlwaysValid(): void
    {
        $slot = $this->slot();
        $pid = AppointmentConfig::savePlace($this->owner, null, ['name' => 'KIS office, Pune', 'active' => '1']);
        $hidden = AppointmentConfig::savePlace($this->owner, null, ['name' => 'Old office', 'active' => '']);
        $c = $this->client();
        $n = static fn (): int => (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $b = $n();
        $this->send($c, $this->form($slot, ['meeting_type' => 'in_person', 'place' => '']));
        $this->send($c, $this->form($slot, ['meeting_type' => 'in_person', 'place' => (string) $hidden]));
        $this->send($c, $this->form($slot, ['meeting_type' => 'in_person', 'place' => '999999']));
        $this->assertSame($b, $n(), 'no place, a hidden place or an unknown place is refused');
        $this->send($c, $this->form($slot, ['meeting_type' => 'in_person', 'place' => (string) $pid, 'email' => 'p1@web.test']));
        $this->send($c, $this->form($slot, ['meeting_type' => 'in_person', 'place' => AppointmentConfig::LATER, 'email' => 'p2@web.test']));
        $this->send($c, $this->form($slot, ['meeting_type' => 'online', 'place' => '999999', 'email' => 'p3@web.test']));
        $this->assertSame($b + 3, $n());
        $get = static fn (string $email): array => EnquiryExtras::details((int) Database::one('SELECT id FROM enquiries WHERE email = ?', [$email])['id']);
        $this->assertSame('KIS office, Pune', $get('p1@web.test')['Meeting place']);
        $this->assertSame('We will discuss it later', $get('p2@web.test')['Meeting place']);
        $this->assertSame('In person', $get('p2@web.test')['Meeting type']);
        $this->assertArrayNotHasKey('Meeting place', $get('p3@web.test'), 'online meetings have no place');
    }

    public function testTheDateAndTimeMustBeOnesThatCanReallyBeOffered(): void
    {
        $weekdays = $this->slot('10:00', '11:00', 31); // Mon-Fri
        $c = $this->client();
        $n = static fn (): int => (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $b = $n();
        $this->send($c, $this->form($weekdays, ['meeting_date' => AppTime::today()]));
        $this->send($c, $this->form($weekdays, ['meeting_date' => $this->date(-3)]));
        $this->send($c, $this->form($weekdays, ['meeting_date' => $this->date(200)]));
        $this->send($c, $this->form($weekdays, ['meeting_date' => 'not a date']));
        $this->send($c, $this->form(999999));
        $off = $this->slot('16:00', '17:00', 127, 0);
        $this->send($c, $this->form($off));
        $this->assertSame($b, $n(), 'today, the past, too far ahead, a bad date, an unknown time and a hidden time are all refused');
        // a Saturday for a Monday-Friday time
        $d = $this->date(2);
        while ((int) gmdate('N', strtotime($d)) !== 6) {
            $d = gmdate('Y-m-d', strtotime($d . ' +1 day'));
        }
        $this->send($c, $this->form($weekdays, ['meeting_date' => $d]));
        $this->assertSame($b, $n(), 'a time that does not run on that weekday is refused');
        $ok = $d;
        while ((int) gmdate('N', strtotime($ok)) !== 2) {
            $ok = gmdate('Y-m-d', strtotime($ok . ' +1 day'));
        }
        $this->send($c, $this->form($weekdays, ['meeting_date' => $ok]));
        $this->assertSame($b + 1, $n());
    }

    public function testASignedInClientIsPrefilledAndTheRequestIsLinkedToTheirFile(): void
    {
        $slot = $this->slot();
        $c = $this->loggedIn('alpha@client.test');
        $page = $c->get('/book-appointment')['body'];
        $this->assertStringContainsString('value="alpha@client.test"', $page, 'the client does not retype what we know');
        $this->assertStringContainsString('Book an appointment', $c->get('/dashboard')['body'], 'a menu entry for clients');
        $this->send($c, $this->form($slot, ['email' => 'alpha@client.test', 'contact_name' => 'Alpha Client']));
        $e = Database::one("SELECT client_id FROM enquiries WHERE source = 'appointment_request' AND email = 'alpha@client.test'");
        $this->assertSame((int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'], (int) $e['client_id']);
    }

    public function testTheRequirementsFormOffersExactlyTheRequestedProjectTypesAndOutcomes(): void
    {
        $page = $this->client()->get('/request-quote')['body'];
        $types = ['Internal business management tool', 'Customer-facing website / portal', 'E-commerce / Online store', 'Inventory / Stock management', 'CRM / Customer management', 'HRM / Employee management', 'Accounting / Finance dashboard', 'School / Education management', 'Hospital / Clinic management', 'Booking / Appointment system', 'Reporting / Analytics dashboard', 'Document / File management', 'Restaurant / POS system', 'Real estate / Property management', 'Logistics / Delivery tracking', 'Other (mention in special notes)'];
        $outcomes = ['Save time on manual work', 'Centralize data in one place', 'Improve team collaboration', 'Track sales and revenue', 'Manage customers better', 'Generate reports and insights', 'Reduce errors and duplication', 'Automate repetitive tasks', 'Provide self-service to clients', 'Compliance and audit trail', 'Replace existing manual / paper system', 'Other (mention in special notes)'];
        $this->assertSame(array_values($types), array_values(EnquiryExtras::CATEGORIES));
        $this->assertSame($outcomes, EnquiryExtras::OUTCOMES);
        foreach (array_merge($types, $outcomes) as $label) {
            $this->assertStringContainsString('>' . htmlspecialchars($label, ENT_QUOTES) . '</option>', $page, $label);
        }
        $this->assertStringContainsString('Select a project type', $page);
        $this->assertStringContainsString('Select an expected outcome', $page);
        $this->assertStringContainsString('Special notes', $page);
    }
}
