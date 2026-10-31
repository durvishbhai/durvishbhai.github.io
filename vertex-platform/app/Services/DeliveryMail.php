<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * Emails for delivery appointments, in the shared KIS look (EmailTemplate). They are sent after the response and a mail problem never affects a booking.
 * The client gets what concerns their own appointment; the team's notification address gets the internal copy.
 */
final class DeliveryMail
{
    /** The team's address: the one set for delivery, else the sender address of the mail settings. */
    public static function teamAddress(): string
    {
        foreach (['delivery.notify_email', 'mail.from_address'] as $k) {
            $v = trim((string) Config::get($k, ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $spec */
    private static function send(string $to, array $spec): void
    {
        if ($to === '' || !Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }

    private static function link(string $path): ?array
    {
        try {
            return ['label' => 'View my appointment', 'url' => AccountMail::link($path)];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $a @return array<string,string> */
    private static function facts(array $a, bool $internal = false): array
    {
        $rows = [
            'Client' => (string) $a['contact_name'],
            'Project' => (string) $a['project_title'],
            'Project ID' => (string) $a['project_code'],
            'Project start date' => !empty($a['project_start_date']) ? DeliveryService::nice((string) $a['project_start_date']) : '',
            'Delivery date' => DeliveryService::nice((string) $a['appointment_date']),
            'Booking reference' => (string) $a['booking_reference'],
            'Status' => DeliveryService::STATUSES[$a['status']] ?? (string) $a['status'],
        ];
        if ($internal) {
            $rows['Booking source'] = DeliveryService::sourceLabel((string) $a['booking_source']);
            $rows['Booked at'] = EmailTemplate::date(gmdate('Y-m-d H:i:s'), true);
        }

        return $rows;
    }

    /** Client + team when a date is booked (by the client, through the public link, or by the team). @param array<string,mixed> $a */
    public static function booked(array $a, ?string $byStaff): void
    {
        $button = self::link('/projects/' . $a['project_public_id'] . '/delivery');
        $spec = [
            'subject' => 'Your KIS Project Delivery Appointment Is Confirmed', 'category' => 'Delivery appointment', 'tone' => 'success', 'heading' => 'Your delivery appointment is confirmed', 'name' => (string) $a['contact_name'],
            'paragraphs' => $byStaff !== null
                ? ['Our team has booked your project delivery for the date below, as you asked.']
                : ['Thank you. Your project delivery appointment is confirmed for the date below.'],
            'details' => self::facts($a), 'notes' => ['If you need to change the date, sign in to your dashboard or reply to this email and our team will help.'],
        ];
        if ($button !== null) {
            $spec['button'] = $button;
        }
        self::send((string) $a['client_email'], $spec);
        self::send(self::teamAddress(), [
            'subject' => 'New Project Delivery Appointment — ' . $a['project_code'], 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => $byStaff !== null ? 'A delivery appointment was booked by the team' : 'A client booked a delivery appointment',
            'paragraphs' => $byStaff !== null ? ['Booked by ' . $byStaff . '.'] : [], 'details' => self::facts($a, true),
        ]);
    }

    /** @param array<string,mixed> $a */
    public static function rescheduled(array $a, string $oldDate, string $by): void
    {
        $details = self::facts($a) + ['Previous date' => DeliveryService::nice($oldDate)];
        $button = self::link('/projects/' . $a['project_public_id'] . '/delivery');
        $spec = [
            'subject' => 'Your KIS Project Delivery Appointment Was Rescheduled', 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => 'Your delivery appointment was rescheduled', 'name' => (string) $a['contact_name'],
            'paragraphs' => ['Your project delivery appointment now takes place on ' . DeliveryService::nice((string) $a['appointment_date']) . '.'], 'details' => $details,
        ];
        if ($button !== null) {
            $spec['button'] = $button;
        }
        self::send((string) $a['client_email'], $spec);
        self::send(self::teamAddress(), [
            'subject' => 'Delivery Appointment Rescheduled — ' . $a['project_code'], 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => 'A delivery appointment was rescheduled',
            'paragraphs' => ['Changed by ' . $by . '.'], 'details' => self::facts($a, true) + ['Previous date' => DeliveryService::nice($oldDate)],
        ]);
    }

    /** @param array<string,mixed> $a */
    public static function cancelled(array $a, string $by, string $reason): void
    {
        $paras = ['Your project delivery appointment for ' . DeliveryService::nice((string) $a['appointment_date']) . ' was cancelled.'];
        if (trim($reason) !== '') {
            $paras[] = 'Reason: ' . trim($reason);
        }
        self::send((string) $a['client_email'], [
            'subject' => 'Your KIS Project Delivery Appointment Was Cancelled', 'category' => 'Delivery appointment', 'tone' => 'warning', 'heading' => 'Your delivery appointment was cancelled', 'name' => (string) $a['contact_name'],
            'paragraphs' => $paras, 'details' => self::facts($a), 'notes' => ['You can book a new date from your dashboard, or contact our team.'],
        ]);
        self::send(self::teamAddress(), [
            'subject' => 'Delivery Appointment Cancelled — ' . $a['project_code'], 'category' => 'Delivery appointment', 'tone' => 'warning', 'heading' => 'A delivery appointment was cancelled',
            'paragraphs' => ['Cancelled by ' . $by . '. The date is free again.'], 'details' => self::facts($a, true),
        ]);
    }

    /** @param array<string,mixed> $a */
    public static function completed(array $a): void
    {
        self::send((string) $a['client_email'], [
            'subject' => 'Your KIS Project Delivery Is Complete', 'category' => 'Delivery appointment', 'tone' => 'success', 'heading' => 'Your delivery appointment is complete', 'name' => (string) $a['contact_name'],
            'paragraphs' => ['Our team has marked your project delivery appointment as completed. Thank you for working with us.'], 'details' => self::facts($a),
        ]);
    }

    /** A client asks the team to change an appointment. @param array<string,mixed> $a */
    public static function changeRequested(array $a): void
    {
        self::send(self::teamAddress(), [
            'subject' => 'Delivery Appointment Change Requested — ' . $a['project_code'], 'category' => 'Delivery appointment', 'tone' => 'warning', 'heading' => 'A client asked to change a delivery appointment',
            'paragraphs' => ['Request: ' . ucfirst((string) $a['change_request_kind']) . '. ' . (string) $a['change_request_note']], 'details' => self::facts($a, true),
        ]);
    }

    /** The team is told when a date is blocked, with how many confirmed appointments are already on it (they stay). */
    public static function dateBlocked(string $ymd, string $reason, int $confirmed, string $by): void
    {
        self::send(self::teamAddress(), [
            'subject' => 'Delivery Date Blocked — ' . local_date($ymd, 'j F Y'), 'category' => 'Delivery appointment', 'tone' => 'warning', 'heading' => 'A delivery date was marked fully booked',
            'paragraphs' => ['No new bookings can be made for this date. ' . ($confirmed > 0 ? $confirmed . ' confirmed appointment(s) already on it were left unchanged.' : 'No appointments were on it.')],
            'details' => ['Date' => DeliveryService::nice($ymd), 'Reason (internal)' => $reason, 'Blocked by' => $by],
        ]);
    }

    /** The client is told when the team changes when their project can be booked. @param array<string,mixed> $project */
    public static function availabilityChanged(array $project): void
    {
        $client = \Vertex\Core\Database::one('SELECT contact_name, email FROM clients WHERE id = ?', [$project['client_id']]);
        if ($client === null) {
            return;
        }
        $plan = DeliveryService::plan($project);
        $button = self::link('/projects/' . $project['public_id'] . '/delivery');
        $spec = [
            'subject' => 'Your KIS Delivery Booking Dates Were Updated', 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => 'Delivery booking dates updated', 'name' => (string) $client['contact_name'],
            'paragraphs' => [$plan['configured'] ? 'The estimated time for your project is ' . $plan['duration'] . '. Your delivery date must be after that time has passed, counted from your project start date.' : 'The dates for booking your project delivery were updated by our team.', 'An appointment you have already confirmed is not changed by this.'],
            'details' => ['Project' => (string) $project['title'], 'Project ID' => (string) $project['code'], 'Estimated time' => (string) $plan['duration'], 'Project start date (set by our team)' => $plan['start_fixed'] && $plan['start'] !== null ? DeliveryService::nice($plan['start']) : ''],
        ];
        if ($button !== null) {
            $spec['button'] = $button;
        }
        self::send((string) $client['email'], $spec);
    }

    /** The one-time code of the public booking page. */
    public static function otp(string $to, string $name, string $code, int $minutes): void
    {
        self::send($to, [
            'subject' => 'Your KIS delivery booking code: ' . $code, 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => 'Your verification code', 'name' => $name,
            'paragraphs' => ['Use this code to continue booking your project delivery appointment. It works once and expires in ' . $minutes . ' minutes.', 'Code: ' . $code],
            'notes' => ['If you did not ask for this, ignore this email. Nobody can book without the code.'],
        ]);
    }

    /** A direct booking link the team created for a client. */
    public static function bookingLink(string $to, string $name, string $projectTitle, string $url, string $until): void
    {
        self::send($to, [
            'subject' => 'Book your KIS project delivery appointment', 'category' => 'Delivery appointment', 'tone' => 'info', 'heading' => 'You can now book your delivery date', 'name' => $name,
            'paragraphs' => ['Use the button below to choose a delivery date for ' . $projectTitle . '. The link is private to you: please do not share it.'],
            'details' => ['Link valid until' => $until], 'button' => ['label' => 'Choose my delivery date', 'url' => $url],
        ]);
    }
}
