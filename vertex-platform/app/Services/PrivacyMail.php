<?php

declare(strict_types=1);

namespace Vertex\Services;

/** Branded emails for data deletion requests (shared KIS template, HTML + plain text). They never include more than the requester needs. */
final class PrivacyMail
{
    /** @param array<string,mixed> $spec */
    private static function send(string $to, array $spec): void
    {
        if (!Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }

    private static function link(string $path): ?string
    {
        try {
            return AccountMail::link($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function verify(string $to, string $name, string $reference, string $token): void
    {
        $spec = ['subject' => 'Confirm your data deletion request (' . $reference . ')', 'category' => 'Privacy', 'tone' => 'info', 'heading' => 'Please confirm your request', 'name' => $name,
            'paragraphs' => ['Someone asked us to delete the personal data held under this email address. To protect you, we only act on the request once you confirm that it is yours.'],
            'details' => ['Reference' => $reference],
            'notes' => ['The link works for 7 days. If you did not ask for this, ignore this email: nothing will be deleted.']];
        if (($u = self::link('/data-deletion/verify/' . $token)) !== null) {
            $spec['button'] = ['label' => 'Confirm my request', 'url' => $u];
        }
        self::send($to, $spec);
    }

    public static function received(string $to, string $name, string $reference, string $trackToken, int $days): void
    {
        $spec = ['subject' => 'We received your data deletion request (' . $reference . ')', 'category' => 'Privacy', 'tone' => 'success', 'heading' => 'Your request is with us', 'name' => $name,
            'paragraphs' => ['Thank you for confirming. Our team will review your request' . ($days > 0 ? ' and aim to answer within ' . $days . ' days' : '') . '. Some records, such as invoices, quotations, payment receipts and agreements, must be kept by law: we will tell you exactly what is deleted, what is anonymised and what is kept, and why.'],
            'details' => ['Reference' => $reference], 'notes' => ['Keep the status link private: anyone with it can see the status of this request.']];
        if (($u = self::link('/data-deletion/status/' . $trackToken)) !== null) {
            $spec['button'] = ['label' => 'Check the status', 'url' => $u];
        }
        self::send($to, $spec);
    }

    public static function statusLink(string $to, string $name, string $reference, string $trackToken): void
    {
        $spec = ['subject' => 'Your data deletion request status link (' . $reference . ')', 'category' => 'Privacy', 'tone' => 'info', 'heading' => 'Your private status link', 'name' => $name,
            'paragraphs' => ['You asked for the status of request ' . $reference . '. Use the button below. The link is private to you.'], 'details' => ['Reference' => $reference]];
        if (($u = self::link('/data-deletion/status/' . $trackToken)) !== null) {
            $spec['button'] = ['label' => 'Open my request', 'url' => $u];
        }
        self::send($to, $spec);
    }

    public static function statusChanged(string $to, string $name, string $reference, string $title, string $text, string $unused = ''): void
    {
        $spec = ['subject' => 'Your data deletion request: ' . $title . ' (' . $reference . ')', 'category' => 'Privacy', 'tone' => $title === 'Not approved' ? 'warning' : 'info', 'heading' => $title, 'name' => $name,
            'paragraphs' => [$text], 'details' => ['Reference' => $reference], 'notes' => ['To see the full status or reply, open the status page on our website and enter your reference and email address: we will send you a private link.']];
        if (($u = self::link('/data-deletion/status')) !== null) {
            $spec['button'] = ['label' => 'Find my request', 'url' => $u];
        }
        self::send($to, $spec);
    }

    /** @param array<string,mixed> $done */
    public static function completed(string $to, string $name, string $reference, array $done): void
    {
        $list = static function (array $m): string {
            $o = [];
            foreach ($m as $k => $v) {
                $o[] = '• ' . $k . (is_int($v) ? ' (' . $v . ')' : '');
            }

            return implode("\n", $o);
        };
        $retained = [];
        foreach ($done['retained'] as $r) {
            $retained[] = '• ' . $r['what'] . ($r['count'] ? ' (' . $r['count'] . ')' : '') . ': ' . $r['reason'];
        }
        $spec = ['subject' => 'Your data deletion request is complete (' . $reference . ')', 'category' => 'Privacy', 'tone' => 'success', 'heading' => 'Your request is complete', 'name' => $name,
            'paragraphs' => ['We have processed your request. This is what we did.',
                'Deleted:' . "\n" . ($done['deleted'] === [] ? '• nothing needed deleting' : $list($done['deleted'])),
                'Anonymised (kept without identifying you):' . "\n" . ($done['anonymised'] === [] ? '• nothing' : $list($done['anonymised'])),
                'Kept because the law or a contract requires it:' . "\n" . implode("\n", $retained)],
            'details' => ['Reference' => $reference], 'notes' => ['Your sign-in account no longer works. If you want to work with us again, you can create a new account.']];
        self::send($to, $spec);
    }
}
