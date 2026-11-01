<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * Readiness for marketing email. NOTHING marketing-related is sent today and the switch is off (Compliance & legal → Marketing communication).
 * If it is ever switched on: only people who ticked the separate marketing box are emailed, every marketing email carries a working unsubscribe link, and an
 * unsubscribe is honoured at once. Security and transactional emails (sign-in alerts, receipts, quotations, policy notices, deletion updates) NEVER get an unsubscribe
 * link and are not affected by it: they go through Mailer::sendTemplate(), which has nothing to do with this class.
 */
final class Marketing
{
    public static function enabled(): bool
    {
        return ComplianceSettings::bool('marketing_enabled');
    }

    /**
     * @param bool $fresh true only for a new, explicit tick of the marketing box on a form: that is a new consent and it replaces an earlier unsubscribe.
     *                    false (an import, a sync, an old record) can never undo an unsubscribe.
     */
    public static function optIn(string $email, string $source, bool $fresh = false): void
    {
        $e = mb_strtolower(trim($email));
        if ($fresh) {
            Database::run(
                'INSERT INTO email_preferences (email, marketing_opt_in, opted_in_at, source, updated_at) VALUES (?, 1, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE marketing_opt_in = 1, opted_in_at = UTC_TIMESTAMP(), opted_out_at = NULL, source = VALUES(source), updated_at = UTC_TIMESTAMP()',
                [$e, $source],
            );

            return;
        }
        Database::run(
            'INSERT INTO email_preferences (email, marketing_opt_in, opted_in_at, source, updated_at) VALUES (?, 1, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE marketing_opt_in = IF(opted_out_at IS NOT NULL, marketing_opt_in, 1), opted_in_at = IF(marketing_opt_in = 1, COALESCE(opted_in_at, UTC_TIMESTAMP()), opted_in_at), updated_at = UTC_TIMESTAMP()',
            [$e, $source],
        );
    }

    public static function mayEmail(string $email): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $r = Database::one('SELECT marketing_opt_in FROM email_preferences WHERE email = ?', [mb_strtolower(trim($email))]);

        return $r !== null && (int) $r['marketing_opt_in'] === 1;
    }

    private static function sign(string $email): string
    {
        return hash_hmac('sha256', 'unsub|' . mb_strtolower($email), (string) Config::get('app.key', 'unsub'));
    }

    /** A personal link that works without signing in. */
    public static function unsubscribePath(string $email): string
    {
        $e = mb_strtolower(trim($email));

        return '/unsubscribe/' . rtrim(strtr(base64_encode($e), '+/', '-_'), '=') . '.' . self::sign($e);
    }

    /** @return string|null the email when the token is genuine */
    public static function emailFromToken(string $token): ?string
    {
        if (!preg_match('/^([A-Za-z0-9_-]{4,300})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        $e = base64_decode(strtr($m[1], '-_', '+/'), true);

        return $e !== false && filter_var($e, FILTER_VALIDATE_EMAIL) && hash_equals(self::sign($e), $m[2]) ? mb_strtolower($e) : null;
    }

    public static function unsubscribe(string $email): void
    {
        $e = mb_strtolower(trim($email));
        Database::run('INSERT INTO email_preferences (email, marketing_opt_in, opted_out_at, source, updated_at) VALUES (?, 0, UTC_TIMESTAMP(), \'unsubscribe\', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE marketing_opt_in = 0, opted_out_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()', [$e]);
        Audit::record('marketing.unsubscribed', 'email_preference', null, [], [], ['email_hash' => hash('sha256', $e)], null);
    }

    /**
     * The only way a marketing email may leave: opted-in recipients only, with the unsubscribe link in the body. Returns false (and sends nothing) otherwise.
     *
     * @param array<string,mixed> $spec an EmailTemplate description
     */
    public static function send(string $to, array $spec): bool
    {
        if (!self::mayEmail($to)) {
            return false;
        }
        try {
            $url = AccountMail::link(self::unsubscribePath($to));
        } catch (\Throwable) {
            return false; // no way to build a working link: do not send
        }
        $spec['category'] = $spec['category'] ?? 'News';
        $spec['notes'] = array_merge((array) ($spec['notes'] ?? []), ['You are receiving this because you chose to hear from us. Unsubscribe any time: ' . $url]);

        return Mailer::sendTemplate($to, $spec);
    }
}
