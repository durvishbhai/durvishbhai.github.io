<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The Owner's register of third-party services. It is INFORMATION for the public disclosure page, the cookie list and the privacy policy: it holds no secrets. The real
 * technical switches and credentials stay in Integrations & settings (the register links to them) and the register warns when the two disagree, so the website never
 * says a service is in use when it is switched off, or hides one that is on.
 */
final class Integrations
{
    public const CATEGORIES = ['hosting' => 'Hosting', 'payment' => 'Payments', 'email' => 'Email delivery', 'security' => 'Security', 'authentication' => 'Sign-in', 'analytics' => 'Analytics', 'marketing' => 'Marketing', 'ai' => 'AI', 'notifications' => 'Notifications', 'other' => 'Other'];
    public const STATES = ['active' => 'In use today', 'planned' => 'Possible in future (not in use)', 'disabled' => 'Stopped'];
    public const COOKIE_CATEGORIES = ['none' => 'Sets no cookies', 'necessary' => 'Strictly necessary', 'preferences' => 'Preferences', 'analytics' => 'Analytics', 'marketing' => 'Marketing'];

    /** Whether the technical switch for an integration is on; null = there is no switch to compare (informational). */
    public static function detect(string $slug): ?bool
    {
        return match ($slug) {
            'razorpay' => (bool) Config::get('razorpay.enabled', false),
            'gmail-smtp' => Config::get('mail.driver') === 'smtp',
            'google-signin' => (bool) Config::get('google.enabled', false),
            'web-push' => (bool) Config::get('notifications.push', false),
            'cloudflare-turnstile' => (bool) Config::get('turnstile.enabled', false),
            'gemini-api' => false, // no Gemini provider exists in the platform
            default => null,
        };
    }

    public static function ensureSeeded(): void
    {
        try {
            if (Database::one('SELECT 1 AS x FROM compliance_integrations LIMIT 1') !== null) {
                return;
            }
            $rows = [
                ['hostinger', 'Hostinger', 'hosting', 'active', 1, 'Hosts this website, its database and its files.', 'Everything stored on the platform (accounts, enquiries, project records and uploaded files) is stored on servers operated by Hostinger.', 'https://www.hostinger.com', 'https://www.hostinger.com/privacy-policy', null],
                ['razorpay', 'Razorpay', 'payment', 'active', 1, 'Processes online payments (for example the advance payment) when you pay by card, UPI, net banking or wallet.', 'Payer name, email and phone where you give them, the amount and the payment reference. Card and UPI details are entered in Razorpay\'s own payment window and are not stored by us.', 'https://razorpay.com', 'https://razorpay.com/privacy/', '/admin/settings#razorpay'],
                ['gmail-smtp', 'Gmail SMTP (Google)', 'email', 'active', 1, 'Delivers our emails: quotations, receipts, security alerts, policy notices and replies.', 'Your email address, your name and the content of the message sent to you.', 'https://workspace.google.com/', 'https://policies.google.com/privacy', '/admin/settings#email'],
                ['google-signin', 'Google sign-in', 'authentication', Config::get('google.enabled') ? 'active' : 'planned', Config::get('google.enabled') ? 1 : 0, 'Lets clients sign in or sign up with their Google account instead of a password.', 'Your Google account name, email address and verified-email status, shared by Google when you choose to sign in with it.', 'https://accounts.google.com/', 'https://policies.google.com/privacy', '/admin/settings#google'],
                ['web-push', 'Browser and phone notifications', 'notifications', Config::get('notifications.push') ? 'active' : 'planned', Config::get('notifications.push') ? 1 : 0, 'Sends notifications to devices where a signed-in user allowed them.', 'A technical address for your browser or phone, provided by its push service (for example Google, Apple or Mozilla), and the notification text.', null, null, '/admin/settings#notifications'],
                ['cloudflare-turnstile', 'Cloudflare Turnstile', 'security', 'planned', 1, 'A bot check that may be added to public forms. It is not in use today.', 'Technical information about your browser needed to tell people from bots, if it is switched on.', 'https://www.cloudflare.com/products/turnstile/', 'https://www.cloudflare.com/privacypolicy/', null],
                ['gemini-api', 'Gemini API (Google)', 'ai', 'planned', 1, 'A possible future AI integration. It is NOT used today and no data is sent to it.', 'Nothing today. If it is introduced, this page will say what is sent and why before it is switched on.', 'https://ai.google.dev/', 'https://policies.google.com/privacy', null],
            ];
            $n = 0;
            foreach ($rows as [$slug, $name, $cat, $state, $show, $purpose, $data, $url, $priv, $link]) {
                Database::run(
                    "INSERT INTO compliance_integrations (slug, name, category, state, show_public, is_active, purpose, data_shared, provider_url, privacy_url, settings_link, cookie_category, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'none', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [$slug, $name, $cat, $state, $show, $state === 'active' ? 1 : 0, $purpose, $data, $url, $priv, $link, ++$n * 10],
                );
            }
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Integrations could not be seeded: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        self::ensureSeeded();
        $rows = Database::all('SELECT * FROM compliance_integrations ORDER BY FIELD(state, \'active\', \'planned\', \'disabled\'), sort_order, id');
        foreach ($rows as &$r) {
            $r['detected'] = self::detect((string) $r['slug']);
            $r['warning'] = '';
            if ($r['detected'] === true && $r['state'] !== 'active') {
                $r['warning'] = 'The technical switch is ON but this is not disclosed as in use.';
            } elseif ($r['detected'] === false && $r['state'] === 'active') {
                $r['warning'] = 'Disclosed as in use, but the technical switch is OFF.';
            }
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM compliance_integrations WHERE id = ?', [$id]);
    }

    /** @return list<string> */
    public static function warnings(): array
    {
        $out = [];
        foreach (self::all() as $r) {
            if ($r['warning'] !== '') {
                $out[] = $r['name'] . ': ' . $r['warning'];
            }
        }

        return $out;
    }

    private static function url(string $v, string $label, array &$errors): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > 255 || !preg_match('~^https://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/[^\s<>"]*)?$~', $v)) {
            $errors[] = $label . ' must be an address starting with https://';

            return null;
        }

        return $v;
    }

    /** @param array<string,mixed> $in @return int the integration id */
    public static function save(int $actorId, ?int $id, array $in): int
    {
        Gate::authorize('compliance.manage', $actorId);
        self::ensureSeeded(); // the starting rows must exist before a first custom row could stop them from ever being added
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[<>]/', $name)) {
            $errors[] = 'Give the service a name of 2 to 120 characters.';
        }
        $cat = (string) ($in['category'] ?? 'other');
        $state = (string) ($in['state'] ?? 'planned');
        $cookieCat = (string) ($in['cookie_category'] ?? 'none');
        if (!isset(self::CATEGORIES[$cat]) || !isset(self::STATES[$state]) || !isset(self::COOKIE_CATEGORIES[$cookieCat])) {
            $errors[] = 'Choose a category, a state and a cookie category from the lists.';
        }
        $text = static function (string $k, int $max) use ($in, &$errors): ?string {
            $v = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($v) > $max || preg_match('/[<>]/', $v)) {
                $errors[] = $k . ' is too long or contains < or >.';

                return null;
            }

            return $v === '' ? null : $v;
        };
        $purpose = $text('purpose', 2000);
        $data = $text('data_shared', 2000);
        $loc = $text('location_note', 255);
        $cookies = $text('cookie_details', 2000);
        $provider = self::url((string) ($in['provider_url'] ?? ''), 'Provider address', $errors);
        $privacy = self::url((string) ($in['privacy_url'] ?? ''), 'Privacy policy address', $errors);
        $script = self::url((string) ($in['script_url'] ?? ''), 'Script address', $errors);
        $link = trim((string) ($in['settings_link'] ?? ''));
        if ($link !== '' && !preg_match('~^/admin/[A-Za-z0-9/_#-]{1,100}$~', $link)) {
            $errors[] = 'The settings link must be a page inside the Administration area.';
            $link = '';
        }
        if ($script !== null && (preg_match('~^https://(\d{1,3}\.){3}\d{1,3}~', $script) || in_array($cookieCat, ['none', 'necessary'], true))) {
            $errors[] = 'A script can only be loaded for an OPTIONAL cookie category (preferences, analytics or marketing), from a named host.';
        }
        if ($cookies !== null) {
            foreach (preg_split('/\R/', $cookies) ?: [] as $line) {
                if (trim($line) !== '' && count(explode('|', $line)) !== 3) {
                    $errors[] = 'Each cookie line must look like: name | purpose | how long it lasts.';
                    break;
                }
            }
        }
        $old = $id === null ? null : (self::find($id) ?? throw new HttpException(404));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $optional = in_array($cookieCat, ['preferences', 'analytics', 'marketing'], true);
        $show = !empty($in['show_public']) && $in['show_public'] !== '0' ? 1 : 0;
        if ($cookieCat !== 'none' || $state === 'active' && $optional) {
            $show = 1; // a service that stores something on the visitor's device is always disclosed
        }
        $bump = false;
        if ($optional && $state === 'active' && ($old === null || $old['state'] !== 'active' || (string) $old['script_url'] !== (string) $script || (string) $old['cookie_details'] !== (string) $cookies || $old['cookie_category'] !== $cookieCat)) {
            $bump = true; // visitors are asked again: a new optional service now needs their consent
        }
        $slug = $old['slug'] ?? self::slugFor($name);
        $params = [$name, $cat, $state, $show, $state === 'active' ? 1 : 0, $purpose, $data, $loc, $provider, $privacy, $link === '' ? null : $link, $cookieCat, $cookies, $script, 1, $actorId];

        return (int) Database::transaction(function () use ($actorId, $id, $slug, $params, $old, $state, $bump): int {
            if ($bump) { // a counter that only ever goes up, so an old "accept all" can never switch on a service that was added later
                Database::run("INSERT INTO compliance_settings (setting_key, value_text, updated_by, updated_at) VALUES ('cookie_epoch', '1', ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text = CAST(value_text AS UNSIGNED) + 1, updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()", [$actorId]);
            }
            if ($id === null) {
                Database::run('INSERT INTO compliance_integrations (name, category, state, show_public, is_active, purpose, data_shared, location_note, provider_url, privacy_url, settings_link, cookie_category, cookie_details, script_url, consent_epoch, updated_by, slug, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 900, UTC_TIMESTAMP(), UTC_TIMESTAMP())', array_merge($params, [$slug]));
                $id = Database::insertId();
            } else {
                Database::run('UPDATE compliance_integrations SET name = ?, category = ?, state = ?, show_public = ?, is_active = ?, purpose = ?, data_shared = ?, location_note = ?, provider_url = ?, privacy_url = ?, settings_link = ?, cookie_category = ?, cookie_details = ?, script_url = ?, consent_epoch = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', array_merge($params, [$id]));
            }
            Audit::record('compliance.integration_saved', 'compliance_integration', $id, $old === null ? [] : ['state' => $old['state']], ['state' => $state, 'asks_again' => $bump], ['slug' => $slug], $actorId);

            return $id;
        });
    }

    private static function slugFor(string $name): string
    {
        $base = HtmlSanitizer::slug($name);
        $slug = $base;
        for ($i = 2; Database::one('SELECT 1 AS x FROM compliance_integrations WHERE slug = ?', [$slug]) !== null; $i++) {
            $slug = substr($base, 0, 55) . '-' . $i;
        }

        return $slug;
    }

    public static function markReviewed(int $actorId, int $id): void
    {
        Gate::authorize('compliance.manage', $actorId);
        $r = self::find($id) ?? throw new HttpException(404);
        Database::run('UPDATE compliance_integrations SET last_reviewed = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [AppTime::today(), $actorId, $id]);
        Audit::record('compliance.integration_reviewed', 'compliance_integration', $id, [], [], ['slug' => $r['slug']], $actorId);
    }

    /** What the public page shows. @return array{active:list<array<string,mixed>>,planned:list<array<string,mixed>>,optional_cookies:bool} */
    public static function publicView(): array
    {
        self::ensureSeeded();
        $rows = Database::all("SELECT * FROM compliance_integrations WHERE show_public = 1 AND state IN ('active','planned') ORDER BY sort_order, id");
        $active = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'active'));
        $planned = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'planned'));
        $optional = false;
        foreach ($active as $r) {
            $optional = $optional || in_array($r['cookie_category'], ['preferences', 'analytics', 'marketing'], true);
        }

        return ['active' => $active, 'planned' => $planned, 'optional_cookies' => $optional];
    }

    /** Hosts the Content Security Policy must allow because the Owner enabled an optional script. @return list<string> origins like https://host */
    public static function scriptOrigins(): array
    {
        $out = [];
        foreach (CookieRegister::scripts() as $s) {
            $p = parse_url($s['script_url']);
            if (!empty($p['host'])) {
                $out[] = 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            }
        }

        return array_values(array_unique($out));
    }
}
