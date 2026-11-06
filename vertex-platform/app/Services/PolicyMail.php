<?php

declare(strict_types=1);

namespace Vertex\Services;

/** Branded emails about policy updates and acceptance (shared KIS template; responsive HTML + plain text). These are service messages, never marketing. */
final class PolicyMail
{
    /** @param array<string,mixed> $spec */
    private static function send(string $to, array $spec): void
    {
        if (!Mailer::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        EmailChannel::afterResponse(static fn () => Mailer::sendTemplate($to, $spec));
    }

    private static function button(string $label): ?array
    {
        try {
            return ['label' => $label, 'url' => AccountMail::link('/policy-acceptance')];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $v the published version (with doc_title) */
    public static function updated(string $to, string $name, array $v): void
    {
        $spec = [
            'subject' => 'We updated our ' . $v['doc_title'] . ': please review it', 'category' => 'Policy update', 'tone' => 'info', 'heading' => 'We updated our ' . $v['doc_title'], 'name' => $name,
            'paragraphs' => ['We have published a new version of our ' . $v['doc_title'] . '. Because the change is material, we ask you to review it and confirm that you accept it.',
                trim((string) $v['change_summary']) !== '' ? 'What changed: ' . trim((string) $v['change_summary']) : 'You can read the full text before you decide.',
                'The updated terms apply to future services. Work and orders that are already completed stay under the terms they were made under, and your invoices, receipts and project records are not affected.'],
            'details' => ['Document' => (string) $v['doc_title'], 'Version' => (string) $v['version_no'], 'Effective' => !empty($v['effective_date']) ? EmailTemplate::date((string) $v['effective_date']) : '', 'Please accept by' => !empty($v['accept_by']) ? EmailTemplate::date((string) $v['accept_by']) : ''],
            'notes' => ['If you do not accept by the date above, access to your client dashboard is restricted until you do. Nothing is deleted, and you can still read your invoices, receipts and agreements.'],
        ];
        if (($b = self::button('Review and accept')) !== null) {
            $spec['button'] = $b;
        }
        self::send($to, $spec);
    }

    /** @param array<string,mixed> $v */
    public static function reminder(string $to, string $name, array $v): void
    {
        $spec = [
            'subject' => 'Reminder: please accept our updated ' . $v['doc_title'], 'category' => 'Policy update', 'tone' => 'warning', 'heading' => 'Acceptance still needed', 'name' => $name,
            'paragraphs' => ['We have not yet received your acceptance of the updated ' . $v['doc_title'] . '.'],
            'details' => ['Document' => (string) $v['doc_title'], 'Version' => (string) $v['version_no'], 'Please accept by' => !empty($v['accept_by']) ? EmailTemplate::date((string) $v['accept_by']) : ''],
        ];
        if (($b = self::button('Review and accept')) !== null) {
            $spec['button'] = $b;
        }
        self::send($to, $spec);
    }

    public static function restricted(string $to, string $name, bool $declined): void
    {
        $spec = [
            'subject' => 'Your KIS account is restricted until you accept the updated terms', 'category' => 'Account', 'tone' => 'warning', 'heading' => 'Account access restricted', 'name' => $name,
            'paragraphs' => [$declined ? 'You chose not to accept our updated terms, so normal access to your client dashboard is restricted.' : 'The deadline to accept our updated terms has passed, so normal access to your client dashboard is restricted.',
                'Nothing has been deleted. You can still read the legal documents, open your invoices, receipts, quotations and agreements, change your password and contact us. Accepting the updated terms restores access straight away.'],
        ];
        if (($b = self::button('Review and accept')) !== null) {
            $spec['button'] = $b;
        }
        self::send($to, $spec);
    }

    public static function reactivated(string $to, string $name): void
    {
        $spec = ['subject' => 'Your KIS account access is restored', 'category' => 'Account', 'tone' => 'success', 'heading' => 'Account access restored', 'name' => $name,
            'paragraphs' => ['Thank you for accepting the updated terms. Your client dashboard is fully available again.']];
        try {
            $spec['button'] = ['label' => 'Open my dashboard', 'url' => AccountMail::link('/dashboard')];
        } catch (\Throwable) {
        }
        self::send($to, $spec);
    }
}
