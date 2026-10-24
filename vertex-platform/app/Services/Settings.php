<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * Module 35: the Integration Center's settings store. A fixed, reviewed list of settings (company facts, email, AI, Razorpay, feature switches) can be changed in
 * the admin area instead of editing .env. Values live in `app_settings`; secrets are sealed with SecretBox (key from APP_KEY, which stays in .env) and are
 * write-only: they are never shown again, never put in the audit log and never sent to the browser.
 *
 * Precedence: a value saved here wins over .env and over the built-in default; "Use the default again" removes it. Bootstrap calls apply() once per request
 * (one small query, ignored when the table does not exist yet, so a half-installed site still starts).
 */
final class Settings
{
    private static bool $applied = false;

    /** @return array<string,array{group:string,label:string,type:string,options?:array<string,string>,help?:string,secret?:bool}> key = config path */
    public static function definitions(): array
    {
        $yn = ['1' => 'Yes', '0' => 'No'];

        return [
            'app.name' => ['group' => 'general', 'label' => 'Business name shown in the portal and emails', 'type' => 'text'],
            'mail.driver' => ['group' => 'email', 'label' => 'Email sending', 'type' => 'select', 'options' => ['none' => 'Off', 'smtp' => 'SMTP (send real emails)', 'log' => 'Log only (development)'], 'help' => 'Invitations, password resets and reminders need SMTP.'],
            'mail.host' => ['group' => 'email', 'label' => 'SMTP host', 'type' => 'text', 'help' => 'Hostinger: smtp.hostinger.com'],
            'mail.port' => ['group' => 'email', 'label' => 'SMTP port', 'type' => 'int'],
            'mail.encryption' => ['group' => 'email', 'label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None']],
            'mail.username' => ['group' => 'email', 'label' => 'SMTP username', 'type' => 'text'],
            'mail.password' => ['group' => 'email', 'label' => 'SMTP password', 'type' => 'secret'],
            'mail.from_address' => ['group' => 'email', 'label' => 'From address', 'type' => 'email'],
            'mail.from_name' => ['group' => 'email', 'label' => 'From name', 'type' => 'text'],
            'renewals.client_email' => ['group' => 'notifications', 'label' => 'Email clients their domain/hosting expiry reminders', 'type' => 'bool'],
            'notifications.email_clients' => ['group' => 'notifications', 'label' => 'Email clients and referral partners a copy of their portal notifications (payments, receipts, invoices, project updates)', 'type' => 'bool'],
            'notifications.email_staff' => ['group' => 'notifications', 'label' => 'Email team members a copy of the notices addressed to them (approvals, new enquiries, replies)', 'type' => 'bool'],
            'notifications.login_alert' => ['group' => 'notifications', 'label' => 'Email a person when their account signs in ("was this you?")', 'type' => 'bool'],
            'uploads.max_mb' => ['group' => 'storage', 'label' => 'Largest deliverable upload (MB)', 'type' => 'int', 'help' => 'PHP\'s own upload limit (hPanel -> PHP settings) must be at least this large.'],
            'site.company_name' => ['group' => 'website', 'label' => 'Company name (website)', 'type' => 'text'],
            'site.tagline' => ['group' => 'website', 'label' => 'Tagline', 'type' => 'text'],
            'site.email' => ['group' => 'website', 'label' => 'Public email address', 'type' => 'email'],
            'site.sales_email' => ['group' => 'website', 'label' => 'Sales email address', 'type' => 'email'],
            'site.support_email' => ['group' => 'website', 'label' => 'Support email address', 'type' => 'email'],
            'site.phone' => ['group' => 'website', 'label' => 'Public phone number', 'type' => 'text'],
            'site.whatsapp' => ['group' => 'website', 'label' => 'WhatsApp number (digits, with country code)', 'type' => 'text'],
            'site.address' => ['group' => 'website', 'label' => 'Business address', 'type' => 'textarea'],
            'site.show_address' => ['group' => 'website', 'label' => 'Show the address on the public website', 'type' => 'bool', 'help' => 'Off by default. Nothing is invented: an empty value is simply not shown.'],
            'site.facebook' => ['group' => 'website', 'label' => 'Facebook page address', 'type' => 'url'],
            'site.instagram' => ['group' => 'website', 'label' => 'Instagram address', 'type' => 'url'],
            'site.linkedin' => ['group' => 'website', 'label' => 'LinkedIn address', 'type' => 'url'],
            'site.twitter' => ['group' => 'website', 'label' => 'X / Twitter address', 'type' => 'url'],
            'site.youtube' => ['group' => 'website', 'label' => 'YouTube address', 'type' => 'url'],
            'site.footer_description' => ['group' => 'website', 'label' => 'Short description in the footer', 'type' => 'textarea'],
            'site.footer_text' => ['group' => 'website', 'label' => 'Footer text', 'type' => 'textarea'],
            'site.copyright' => ['group' => 'website', 'label' => 'Copyright line (leave empty for the automatic one)', 'type' => 'text'],
            'site.company_details' => ['group' => 'website', 'label' => 'Company or registration details (only if approved for publication)', 'type' => 'textarea'],
            'site.default_seo_description' => ['group' => 'website', 'label' => 'Default search-engine description', 'type' => 'textarea'],
            'ai.enabled' => ['group' => 'ai', 'label' => 'Turn AI assistance on', 'type' => 'bool'],
            'ai.provider' => ['group' => 'ai', 'label' => 'Provider', 'type' => 'select', 'options' => ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI-compatible service']],
            'ai.base_url' => ['group' => 'ai', 'label' => 'API address (leave empty for the provider\'s own)', 'type' => 'url'],
            'ai.model' => ['group' => 'ai', 'label' => 'Model name', 'type' => 'text'],
            'ai.api_key' => ['group' => 'ai', 'label' => 'API key', 'type' => 'secret'],
            'ai.monthly_cap' => ['group' => 'ai', 'label' => 'Most AI requests per month (whole company)', 'type' => 'int'],
            'google.enabled' => ['group' => 'google', 'label' => 'Let clients sign in and sign up with Google', 'type' => 'bool'],
            'google.client_id' => ['group' => 'google', 'label' => 'Google client ID', 'type' => 'text', 'help' => 'From the Google Cloud Console → APIs & Services → Credentials → OAuth client ID (type: Web application). Ends with .apps.googleusercontent.com.'],
            'google.client_secret' => ['group' => 'google', 'label' => 'Google client secret', 'type' => 'secret'],
            'razorpay.enabled' => ['group' => 'razorpay', 'label' => 'Accept online payments through Razorpay', 'type' => 'bool'],
            'razorpay.key_id' => ['group' => 'razorpay', 'label' => 'Key ID', 'type' => 'text', 'help' => 'From the Razorpay Dashboard → Account & Settings → API Keys. Starts with rzp_test_ (test mode, no real money) or rzp_live_ (live).'],
            'razorpay.key_secret' => ['group' => 'razorpay', 'label' => 'Key Secret', 'type' => 'secret'],
            'razorpay.webhook_secret' => ['group' => 'razorpay', 'label' => 'Webhook secret', 'type' => 'secret', 'help' => 'The secret you type when you add the webhook in the Razorpay Dashboard.'],
            'razorpay.qr_mode' => ['group' => 'razorpay', 'label' => 'How payment requests are paid', 'type' => 'select', 'options' => ['auto' => 'Automatic (Razorpay UPI QR, else a payment link for the exact amount)', 'link' => 'Payment link for the exact amount only', 'checkout' => 'Razorpay checkout window on the client\'s page (card, UPI, net banking, wallets)'], 'help' => 'Whichever you pick, the amount is exactly what the team entered. The team can also choose per request.'],
            'features.cms' => ['group' => 'features', 'label' => 'Public website pages (CMS)', 'type' => 'bool'],
            'features.public_forms' => ['group' => 'features', 'label' => 'Public form builder pages', 'type' => 'bool'],
            'features.ai' => ['group' => 'features', 'label' => 'AI assistance menu', 'type' => 'bool'],
            'auth.registration_mode' => ['group' => 'features', 'label' => 'Client self-registration', 'type' => 'select', 'options' => Registration::MODES, 'help' => 'Adds a "Sign up" tab to the website and sign-in page. Until you choose, new accounts wait for your approval. With approval, new accounts appear under Users as pending and you approve them there.'],
        ];
    }

    /** @return array<string,string> */
    public static function groups(): array
    {
        return ['general' => 'General', 'website' => 'Website and company facts', 'email' => 'Email', 'notifications' => 'Notifications', 'storage' => 'Storage', 'ai' => 'AI assistance', 'razorpay' => 'Razorpay payments', 'google' => 'Google sign-in', 'features' => 'Feature switches'];
    }

    private static function isSecret(string $key): bool
    {
        return (self::definitions()[$key]['type'] ?? '') === 'secret';
    }

    /** Copies saved values into Config. Safe to call early: never throws. */
    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;
        try {
            $defs = self::definitions();
            foreach (Database::all('SELECT setting_key, value_text, is_secret FROM app_settings') as $r) {
                $key = (string) $r['setting_key'];
                if (!isset($defs[$key])) {
                    continue;
                }
                $v = $r['value_text'];
                if ((int) $r['is_secret'] === 1) {
                    $v = $v === null ? null : SecretBox::open((string) $v);
                    if ($v === null) {
                        continue; // sealed with another key or damaged: behave as if unset
                    }
                }
                Config::set($key, self::cast($defs[$key]['type'], (string) $v));
            }
            if (Config::get('uploads.max_mb') !== null) { // one number in the screen, bytes in the code
                Config::set('uploads.max_bytes.deliverable', max(1, (int) Config::get('uploads.max_mb')) * 1048576);
            }
        } catch (\Throwable) {
            // no database, no table yet, or no APP_KEY: the .env values stay in force
        }
    }

    public static function reload(): void
    {
        self::$applied = false;
        self::apply();
    }

    private static function cast(string $type, string $v): mixed
    {
        return match ($type) {
            'bool' => $v === '1',
            'int' => (int) $v,
            default => $v,
        };
    }

    public static function feature(string $name): bool
    {
        return (bool) Config::get('features.' . $name, true);
    }

    /**
     * What the admin screen shows. Secrets are reported only as set / not set.
     *
     * @return array<string,list<array{key:string,label:string,type:string,options:array<string,string>,help:string,value:mixed,secret_set:bool,source:string}>> by group
     */
    public static function forScreen(): array
    {
        $saved = [];
        foreach (Database::all('SELECT setting_key FROM app_settings') as $r) {
            $saved[(string) $r['setting_key']] = true;
        }
        $out = [];
        foreach (self::definitions() as $key => $d) {
            $secret = $d['type'] === 'secret';
            $v = $key === 'uploads.max_mb' ? (Config::get('uploads.max_mb') ?? intdiv((int) Config::get('uploads.max_bytes.deliverable', 52428800), 1048576)) : ($key === 'auth.registration_mode' ? Registration::configured() : Config::get($key));
            $out[$d['group']][] = ['key' => $key, 'label' => $d['label'], 'type' => $d['type'], 'options' => $d['options'] ?? [], 'help' => $d['help'] ?? '',
                'value' => $secret ? null : $v, 'secret_set' => $secret && (string) $v !== '', 'source' => isset($saved[$key]) ? 'saved here' : 'default / .env'];
        }

        return $out;
    }

    /** @param array<string,mixed> $in posted values by key with '.' replaced by '__' @param list<string> $clear keys to return to their default */
    public static function save(int $actorId, string $group, array $in, array $clear = []): void
    {
        Gate::authorize('settings.manage', $actorId);
        if (!isset(self::groups()[$group])) {
            throw new ValidationException(['Unknown settings group.']);
        }
        $defs = array_filter(self::definitions(), static fn (array $d): bool => $d['group'] === $group);
        $errors = [];
        $writes = [];
        foreach ($defs as $key => $d) {
            $field = str_replace('.', '__', $key);
            if (in_array($key, $clear, true)) {
                $writes[$key] = null;
                continue;
            }
            $raw = $in[$field] ?? null;
            if ($d['type'] === 'bool') {
                $writes[$key] = !empty($raw) && $raw !== '0' ? '1' : '0';
                continue;
            }
            if ($raw === null) {
                continue;
            }
            $v = trim((string) $raw);
            if ($d['type'] === 'secret') {
                if ($v !== '') {
                    if (mb_strlen($v) > 500 || preg_match('/[\r\n\0]/', $v)) {
                        $errors[] = $d['label'] . ' is not valid.';
                    } else {
                        $writes[$key] = $v;
                    }
                }
                continue; // empty = keep what is saved
            }
            $error = self::validate($d, $v);
            if ($error !== null) {
                $errors[] = $d['label'] . ': ' . $error;
                continue;
            }
            $writes[$key] = $v;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::transaction(function () use ($actorId, $group, $writes): void {
            $changed = [];
            foreach ($writes as $key => $v) {
                if ($v === null) {
                    Database::run('DELETE FROM app_settings WHERE setting_key = ?', [$key]);
                    $changed[] = $key . ' (reset)';
                    continue;
                }
                $secret = self::isSecret($key);
                $stored = $secret ? SecretBox::seal((string) $v) : (string) $v;
                $old = Database::one('SELECT value_text FROM app_settings WHERE setting_key = ?', [$key]);
                if (!$secret && $old !== null && $old['value_text'] === $stored) {
                    continue;
                }
                Database::run('INSERT INTO app_settings (setting_key, value_text, is_secret, updated_by, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                    [$key, $stored, $secret ? 1 : 0, $actorId]);
                $changed[] = $key . ($secret ? ' (secret replaced)' : '');
            }
            if ($changed !== []) {
                Audit::record('settings.changed', 'settings', null, [], [], ['group' => $group, 'keys' => $changed], $actorId); // names only: never the values
            }
        });
        self::reload();
    }

    /** @param array<string,mixed> $d */
    private static function validate(array $d, string $v): ?string
    {
        if ($v === '') {
            return $d['type'] === 'int' ? 'enter a number.' : null;
        }
        if (preg_match('/[\0\r]/', $v) || ($d['type'] !== 'textarea' && str_contains($v, "\n")) || mb_strlen($v) > 2000) {
            return 'contains characters that are not allowed or is too long.';
        }
        switch ($d['type']) {
            case 'int':
                return ctype_digit($v) && (int) $v <= 1_000_000 ? null : 'must be a whole number.';
            case 'email':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'must be an email address.';
            case 'url':
                return preg_match('#^https://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/\S*)?$#', $v) ? null : 'must start with https://';
            case 'select':
                return isset($d['options'][$v]) ? null : 'choose one of the listed options.';
            case 'text':
                return mb_strlen($v) <= 300 && !preg_match('/[<>]/', $v) ? null : 'is too long or contains < or >.';
            case 'textarea':
                return !preg_match('/[<>]/', $v) ? null : 'must not contain < or >.';
        }

        return null;
    }
}
