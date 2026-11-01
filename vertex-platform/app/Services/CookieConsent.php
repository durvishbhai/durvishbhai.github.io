<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;

/**
 * Cookie consent. Four categories: strictly necessary (always on), preferences, analytics, marketing. Optional categories are OFF until the visitor switches them on.
 * The choice lives in a first-party cookie `kis_consent` = v<version>.<unix time>.<three 0/1 flags for preferences, analytics, marketing>.<random id of this browser>; every choice is also logged
 * (append only) so it can be shown later. A choice stops counting when the consent version changes or after the configured number of days.
 */
final class CookieConsent
{
    public const COOKIE = 'kis_consent';
    public const CATEGORIES = ['necessary' => 'Strictly necessary', 'preferences' => 'Preferences', 'analytics' => 'Analytics', 'marketing' => 'Marketing'];

    public static function version(): int
    {
        return max(1, ComplianceSettings::int('cookie_version')) + CookieRegister::versionBump();
    }

    /** The visitor's valid choice, or null when they have not chosen (or must choose again). @return array{preferences:bool,analytics:bool,marketing:bool,at:int,id:string}|null */
    public static function current(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);

        return self::parse($raw === null ? '' : $raw);
    }

    /** @return array{preferences:bool,analytics:bool,marketing:bool,at:int,id:string}|null */
    public static function parse(string $raw): ?array
    {
        if (!preg_match('/^v(\d{1,6})\.(\d{9,11})\.([01])([01])([01])\.([a-f0-9]{32})$/', $raw, $m)) {
            return null;
        }
        $maxAge = max(1, ComplianceSettings::int('cookie_valid_days')) * 86400;
        if ((int) $m[1] !== self::version() || (int) $m[2] + $maxAge < time() || (int) $m[2] > time() + 300) {
            return null;
        }

        return ['preferences' => $m[3] === '1', 'analytics' => $m[4] === '1', 'marketing' => $m[5] === '1', 'at' => (int) $m[2], 'id' => $m[6]];
    }

    /** The Set-Cookie header for a choice. */
    public static function header(bool $prefs, bool $analytics, bool $marketing, bool $secure, string $id): string
    {
        $value = 'v' . self::version() . '.' . time() . '.' . ($prefs ? '1' : '0') . ($analytics ? '1' : '0') . ($marketing ? '1' : '0') . '.' . $id;
        $days = max(1, ComplianceSettings::int('cookie_valid_days'));

        return self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . ($days * 86400) . '; SameSite=Lax' . ($secure ? '; Secure' : '');
    }

    /** Records a choice. @return array{action:string,id:string} */
    public static function record(Request $request, bool $prefs, bool $analytics, bool $marketing, string $action): array
    {
        $prev = self::current($request);
        if (!in_array($action, ['accept_all', 'reject_all', 'custom', 'withdraw'], true)) {
            $action = 'custom';
        }
        if ($action === 'reject_all' && $prev !== null && ($prev['preferences'] || $prev['analytics'] || $prev['marketing'])) {
            $action = 'withdraw'; // switching optional categories off after they were on is a withdrawal
        }
        $vid = $prev !== null ? $prev['id'] : bin2hex(random_bytes(16));
        $salt = (string) Config::get('app.key', 'cookie');
        Database::run(
            'INSERT INTO cookie_consents (visitor_id, consent_version, action, preferences, analytics, marketing, ip_hash, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$vid, self::version(), $action, $prefs ? 1 : 0, $analytics ? 1 : 0, $marketing ? 1 : 0, hash('sha256', $salt . '|' . $request->ip()), mb_substr($request->userAgent(), 0, 160)],
        );

        return ['action' => $action, 'id' => $vid];
    }

    /** The cookies and storage the website really uses, by category (the integrations register adds its own). @return list<array<string,string>> */
    public static function inventory(): array
    {
        $rows = [
            ['name' => (string) Config::get('session.name', 'vertex_sid'), 'category' => 'necessary', 'purpose' => 'Keeps you signed in and protects forms against forgery (a session cookie, removed when you sign out or close the browser).', 'duration' => 'Session', 'provider' => 'This website (first party)', 'type' => 'Cookie'],
            ['name' => self::COOKIE, 'category' => 'necessary', 'purpose' => 'Remembers your cookie choices so we do not ask on every page.', 'duration' => max(1, ComplianceSettings::int('cookie_valid_days')) . ' days', 'provider' => 'This website (first party)', 'type' => 'Cookie'],
            ['name' => 'kis-theme', 'category' => 'preferences', 'purpose' => 'Remembers your light or dark theme. If you have not allowed preferences it is kept only until you close the tab.', 'duration' => 'Until you clear it', 'provider' => 'This website (first party)', 'type' => 'Browser storage'],
        ];
        foreach (CookieRegister::rows() as $r) {
            $rows[] = $r;
        }

        return $rows;
    }
}
