<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Core\ValidationException;

/**
 * Separate, honest consent on every public form: (1) a privacy acknowledgement, (2) a confirmation that the person is of age (or acts for a business), (3) an optional
 * marketing consent that is shown, and unticked, only while marketing email is switched on. No date of birth is ever collected. What the person agreed to (the wording,
 * the policy version, the time) is kept as a record.
 */
final class ConsentRules
{
    public static function ageMessage(): string
    {
        return 'Please confirm that you are ' . ComplianceSettings::int('min_age') . ' or older, or that you are submitting this for a business or organisation you are authorised to represent.';
    }

    public static function requireAge(Request $request): void
    {
        if ($request->string('age_confirm') !== '1') {
            throw new ValidationException([self::ageMessage()]);
        }
    }

    /** The checks every public form makes: the bot check (when on) and the age confirmation. @throws ValidationException */
    public static function publicForm(Request $request): void
    {
        Turnstile::require($request);
        self::requireAge($request);
    }

    public static function privacyTicked(Request $request): bool
    {
        return $request->string('consent') === '1';
    }

    public static function marketingTicked(Request $request): bool
    {
        return ComplianceSettings::bool('marketing_enabled') && $request->string('marketing_consent') === '1';
    }

    /** The consent sentences as shown (plain text with the privacy link as HTML). @return array{privacy:string,age:string,marketing:string} */
    public static function texts(): array
    {
        $link = '<a href="/privacy-policy">Privacy Policy</a>';
        $fill = static fn (string $key): string => LegalDocuments::fill(htmlspecialchars(ComplianceSettings::get($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['privacy_link' => $link]);

        return ['privacy' => $fill('consent_privacy_text'), 'age' => $fill('consent_age_text'), 'marketing' => $fill('consent_marketing_text')];
    }

    /** Keeps the evidence of a submission's consent. Never throws: a failure here must not lose the person's submission. */
    public static function record(string $form, ?string $email, Request $request, ?int $userId = null): void
    {
        try {
            $t = self::texts();
            $v = LegalDocuments::live('privacy-policy');
            Database::run(
                'INSERT INTO consent_records (form_key, subject_email, user_id, privacy_version, text_hash, privacy_ack, age_confirmed, marketing_opt_in, ip_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$form, $email === null ? null : mb_strtolower(mb_substr($email, 0, 190)), $userId, $v === null ? null : (int) $v['version_no'], hash('sha256', $t['privacy'] . '|' . $t['age'] . '|' . $t['marketing']),
                    self::privacyTicked($request) ? 1 : 0, $request->string('age_confirm') === '1' ? 1 : 0, self::marketingTicked($request) ? 1 : 0, hash('sha256', (string) Config::get('app.key', 'consent') . '|' . $request->ip())],
            );
            if (self::marketingTicked($request) && $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Marketing::optIn($email, $form, true); // an explicit new tick is a new consent
            }
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Consent record failed: ' . $e->getMessage());
        }
    }
}
