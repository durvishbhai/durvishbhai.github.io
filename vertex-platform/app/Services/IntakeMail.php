<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * "We received your request" confirmations for people who submit a form (a website requirement, a contact message, an appointment request, a client's
 * new-project request, a job application). They only acknowledge receipt: nothing here says a request was accepted, approved, quoted or started.
 * Sent after the response, and a mail problem never affects the form.
 */
final class IntakeMail
{
    public static function enquiry(string $to, string $name, string $reference, string $source, string $service): void
    {
        if (!Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $portal = $source === 'client_portal';
        $what = $portal ? 'project request' : ($source === 'appointment_request' ? 'appointment request' : ($source === 'contact_form' ? 'message' : 'requirement'));
        $spec = [
            'subject' => 'We received your ' . $what . ' (' . $reference . ')', 'category' => 'Request received', 'tone' => 'info', 'heading' => 'We received your ' . $what, 'name' => $name,
            'paragraphs' => [
                'Thank you for contacting ' . (string) Config::get('app.name', 'Krazy Integrated Solutions') . '. Our team will read it and get back to you.',
                'This is only a confirmation that we received it. It is not a quotation, and no work has started or been agreed yet.',
            ],
            'details' => ['Reference' => $reference, 'Service' => $service, 'Received' => EmailTemplate::date(gmdate('Y-m-d H:i:s'), true)],
            'notes' => $portal ? [] : ['If you did not send this request, you can ignore this email.'],
        ];
        if ($portal) {
            try {
                $spec['button'] = ['label' => 'Open my dashboard', 'url' => AccountMail::link('/dashboard')];
            } catch (\Throwable) {
            }
        }
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }

    public static function career(string $to, string $name, string $reference, string $role): void
    {
        if (!Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $spec = [
            'subject' => 'We received your application (' . $reference . ')', 'category' => 'Careers', 'tone' => 'info', 'heading' => 'We received your application', 'name' => $name,
            'paragraphs' => ['Thank you for applying to ' . (string) Config::get('app.name', 'Krazy Integrated Solutions') . '. Our team will review your application. If it is a match, we will contact you.',
                'This email only confirms that your application arrived. It is not an offer or an interview invitation.'],
            'details' => ['Reference' => $reference, 'Role' => $role, 'Received' => EmailTemplate::date(gmdate('Y-m-d H:i:s'), true)],
            'notes' => ['If you did not apply, you can ignore this email.'],
        ];
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }

    public static function partner(string $to, string $name, string $reference): void
    {
        if (!Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $spec = [
            'subject' => 'We received your Referral Partner application (' . $reference . ')', 'category' => 'Referral partner', 'tone' => 'info', 'heading' => 'We received your application', 'name' => $name,
            'paragraphs' => ['Thank you for applying to become a Referral Partner. Our team will review it and email you the decision.',
                'This email only confirms that your application arrived. You are not a partner until it is approved.'],
            'details' => ['Reference' => $reference, 'Received' => EmailTemplate::date(gmdate('Y-m-d H:i:s'), true)],
            'notes' => ['If you did not apply, you can ignore this email.'],
        ];
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }
}
