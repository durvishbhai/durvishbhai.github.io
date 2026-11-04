<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * The Owner's compliance settings: business identity, cookie banner wording, consent wording, age rule, deletion handling, accessibility and security statements,
 * marketing switch. A fixed, reviewed list (no secrets): each value is plain text, validated by type, and every change is audited. Facts that only the Owner knows
 * (proprietor's name, representative, registration numbers, grievance contact) have NO default: they stay empty and show as marked placeholders until filled in.
 */
final class ComplianceSettings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public const GROUPS = [
        'business' => 'Business identity',
        'disclosure' => 'Public disclosure approvals',
        'contact' => 'Legal contact & grievance',
        'cookies' => 'Cookie banner',
        'consent' => 'Form consent & age',
        'deletion' => 'Data deletion & retention',
        'statements' => 'Accessibility & security statements',
        'marketing' => 'Marketing communication',
        'acceptance' => 'Policy acceptance',
    ];

    /** @return array<string,array{group:string,label:string,type:string,default:string,help?:string}> */
    public static function fields(): array
    {
        return [
            'brand_name' => ['group' => 'business', 'label' => 'Brand shown to the public', 'type' => 'text', 'default' => 'Krazy Integrated Solutions'],
            'abbreviation' => ['group' => 'business', 'label' => 'Abbreviation', 'type' => 'text', 'default' => 'KIS'],
            'legal_enterprise_name' => ['group' => 'business', 'label' => 'Legal enterprise name', 'type' => 'text', 'default' => 'Swamini Mahila Gruh Udyog'],
            'legal_structure' => ['group' => 'business', 'label' => 'Legal structure', 'type' => 'text', 'default' => 'Sole Proprietorship', 'help' => 'Do not describe the business as a company, LLP or partnership.'],
            'business_units' => ['group' => 'business', 'label' => 'Business units (separate with ;)', 'type' => 'text', 'default' => 'Krazy Integrated Solutions; The Young Books'],
            'proprietor_name' => ['group' => 'business', 'label' => 'Legal proprietor: full name', 'type' => 'text', 'default' => '', 'help' => 'The person who legally owns Swamini Mahila Gruh Udyog. Required before the legal pages can be published.'],
            'operator_name' => ['group' => 'business', 'label' => 'Operational representative: full name', 'type' => 'text', 'default' => '', 'help' => 'Who manages and operates KIS day to day on the proprietor\'s authority.'],
            'operator_role' => ['group' => 'business', 'label' => 'Operational representative: role', 'type' => 'text', 'default' => 'Authorised operational representative'],
            'udyam_note' => ['group' => 'business', 'label' => 'Udyam statement', 'type' => 'text', 'default' => 'Registered under Swamini Mahila Gruh Udyog, with Krazy Integrated Solutions and The Young Books listed as business units.'],
            'udyam_number' => ['group' => 'business', 'label' => 'Udyam Registration Number (URN)', 'type' => 'text', 'default' => '', 'help' => 'Only the Owner can edit this. It is internal information and is NOT shown to visitors until you approve it under Public disclosure approvals.'],
            'shop_act_note' => ['group' => 'business', 'label' => 'Shop Act statement', 'type' => 'text', 'default' => 'Registered under Swamini Mahila Gruh Udyog.'],
            'shop_act_number' => ['group' => 'business', 'label' => 'Shop Act licence / registration number', 'type' => 'text', 'default' => '', 'help' => 'Only the Owner can edit this. It is internal information and is NOT shown to visitors until you approve it under Public disclosure approvals.'],
            'gst_status' => ['group' => 'business', 'label' => 'GST status', 'type' => 'text', 'default' => 'Not registered for GST'],
            'gstin' => ['group' => 'business', 'label' => 'GSTIN (only once registered)', 'type' => 'text', 'default' => ''],
            'governing_law' => ['group' => 'business', 'label' => 'Governing law (proposed)', 'type' => 'text', 'default' => '', 'help' => 'Proposed: India. Requires Legal Review before the Terms are published.'],
            'jurisdiction' => ['group' => 'business', 'label' => 'Courts / place for disputes (proposed)', 'type' => 'text', 'default' => '', 'help' => 'Proposed: Pune, Maharashtra. Requires Legal Review: the Terms cannot be published until a lawyer has confirmed this.'],
            'show_address' => ['group' => 'business', 'label' => 'Publish the business address', 'type' => 'bool', 'default' => '0', 'help' => 'Off by default. The address is never shown unless you switch this on AND fill it in.'],
            'public_address' => ['group' => 'business', 'label' => 'Business address (shown only if the switch above is on)', 'type' => 'textarea', 'default' => ''],

            'contact_email' => ['group' => 'contact', 'label' => 'Official business email', 'type' => 'email', 'default' => '', 'help' => 'Empty = the public email set under Integrations & settings → Website.'],
            'whatsapp' => ['group' => 'contact', 'label' => 'Official WhatsApp number (with country code)', 'type' => 'text', 'default' => '', 'help' => 'Empty = the WhatsApp number set under Integrations & settings → Website.'],
            'grievance_name' => ['group' => 'contact', 'label' => 'Grievance / privacy contact: name or title', 'type' => 'text', 'default' => ''],
            'grievance_email' => ['group' => 'contact', 'label' => 'Grievance / privacy contact: email', 'type' => 'email', 'default' => ''],
            'grievance_days' => ['group' => 'contact', 'label' => 'We aim to acknowledge grievances within (days)', 'type' => 'int', 'default' => '', 'help' => 'Leave empty if you do not want to state a target.'],

            'approve_proprietor' => ['group' => 'disclosure', 'label' => 'Show the legal proprietor\'s name publicly', 'type' => 'bool', 'default' => '0', 'help' => 'Off = the name stays internal and legal documents that need it cannot be published.'],
            'approve_operator' => ['group' => 'disclosure', 'label' => 'Show the operational representative\'s name publicly', 'type' => 'bool', 'default' => '0'],
            'approve_udyam' => ['group' => 'disclosure', 'label' => 'Show the Udyam Registration Number (URN) publicly', 'type' => 'bool', 'default' => '0'],
            'approve_shop_act' => ['group' => 'disclosure', 'label' => 'Show the Shop Act number publicly', 'type' => 'bool', 'default' => '0'],
            'approve_email' => ['group' => 'disclosure', 'label' => 'Show the official business email publicly', 'type' => 'bool', 'default' => '0', 'help' => 'Off = the public site email from Integrations & settings is used instead (as before).'],
            'approve_whatsapp' => ['group' => 'disclosure', 'label' => 'Show the official WhatsApp number publicly', 'type' => 'bool', 'default' => '0', 'help' => 'Off = the public site WhatsApp number from Integrations & settings is used instead (as before).'],
            'approve_grievance' => ['group' => 'disclosure', 'label' => 'Show the grievance contact publicly', 'type' => 'bool', 'default' => '0'],

            'cookie_banner_title' => ['group' => 'cookies', 'label' => 'Banner heading', 'type' => 'text', 'default' => 'Your cookie choices'],
            'cookie_banner_text' => ['group' => 'cookies', 'label' => 'Banner text', 'type' => 'textarea', 'default' => 'We use cookies that are strictly necessary to run this website (sign-in, security and your saved choices). With your permission we may also use optional cookies for preferences, analytics and marketing. We do not use any optional cookies today unless you see them listed under Cookie Settings. You can change your mind at any time.'],
            'cookie_desc_necessary' => ['group' => 'cookies', 'label' => 'Strictly necessary: description', 'type' => 'textarea', 'default' => 'Needed for the website to work: keeping you signed in, protecting forms against forgery and remembering your cookie choices. They cannot be switched off.'],
            'cookie_desc_preferences' => ['group' => 'cookies', 'label' => 'Preferences: description', 'type' => 'textarea', 'default' => 'Remember choices such as your colour theme where this is not essential to the service.'],
            'cookie_desc_analytics' => ['group' => 'cookies', 'label' => 'Analytics: description', 'type' => 'textarea', 'default' => 'Help us understand how the website is used. No analytics service is active at the moment.'],
            'cookie_desc_marketing' => ['group' => 'cookies', 'label' => 'Marketing: description', 'type' => 'textarea', 'default' => 'Used to show relevant advertising and measure campaigns. No marketing or advertising cookie is active at the moment.'],
            'cookie_version' => ['group' => 'cookies', 'label' => 'Consent version (raise it to ask every visitor again)', 'type' => 'int', 'default' => '1', 'help' => 'Visitors are asked again when this changes, when the banner text is materially changed, or when you enable a new optional integration.'],
            'cookie_valid_days' => ['group' => 'cookies', 'label' => 'Ask again after (days)', 'type' => 'int', 'default' => '180'],

            'consent_privacy_text' => ['group' => 'consent', 'label' => 'Privacy acknowledgement (required on forms)', 'type' => 'textarea', 'default' => 'I have read the {{privacy_link}} and I agree that {{brand}} may use the details I enter here to reply to my enquiry and, if we go ahead, to deliver the services I ask for.'],
            'consent_age_text' => ['group' => 'consent', 'label' => 'Age confirmation (required on forms)', 'type' => 'textarea', 'default' => 'I am {{min_age}} years old or older, or I am submitting this on behalf of a business or organisation that I am authorised to represent.'],
            'consent_marketing_text' => ['group' => 'consent', 'label' => 'Optional marketing consent (shown only when marketing is switched on)', 'type' => 'textarea', 'default' => 'Yes, I would also like to receive news and offers from {{brand}} by email. I can unsubscribe at any time.'],
            'min_age' => ['group' => 'consent', 'label' => 'Minimum age', 'type' => 'int', 'default' => '18'],
            'age_policy_text' => ['group' => 'consent', 'label' => 'Age statement used in the policies', 'type' => 'textarea', 'default' => 'Our services are for people aged 18 or over and for businesses and organisations acting through an authorised representative. We do not knowingly collect personal data from anyone under 18. If you believe a child has sent us information, contact us and we will delete it.'],

            'deletion_intro' => ['group' => 'deletion', 'label' => 'Text at the top of the deletion request page', 'type' => 'textarea', 'default' => 'You can ask us to delete the personal data we hold about you. We first confirm it is really you. Some records, such as invoices, quotations, payment receipts and agreements, must be kept for legal and accounting reasons: we tell you exactly what is deleted, what is anonymised and what must be kept, and why.'],
            'deletion_response_days' => ['group' => 'deletion', 'label' => 'We aim to answer deletion requests within (days)', 'type' => 'int', 'default' => '30'],
            'retention_years' => ['group' => 'deletion', 'label' => 'Years financial and project records are kept (optional)', 'type' => 'int', 'default' => '', 'help' => 'Leave EMPTY unless your accountant or lawyer has confirmed a number. Nothing is invented: when empty, the texts say "the periods required by applicable law".'],
            'retention_summary' => ['group' => 'deletion', 'label' => 'Retention summary shown on the deletion page', 'type' => 'textarea', 'default' => 'Invoices, quotations, payment receipts, signed agreements and the project records needed to support them are kept for the period required by Indian tax, accounting and contract law. Enquiries that did not become orders, optional profile details, notification history and uploaded reference files are deleted or anonymised when you ask.'],

            'accessibility_conformance' => ['group' => 'statements', 'label' => 'Accessibility: conformance statement', 'type' => 'textarea', 'default' => 'We aim to meet WCAG 2.2 level AA. We test with a keyboard, with automated checks and by reviewing colour contrast. We have not obtained an independent accessibility audit, and we do not claim certification.'],
            'accessibility_known_issues' => ['group' => 'statements', 'label' => 'Accessibility: known limitations', 'type' => 'textarea', 'default' => ''],
            'accessibility_reviewed' => ['group' => 'statements', 'label' => 'Accessibility: last reviewed', 'type' => 'date', 'default' => ''],
            'security_statement' => ['group' => 'statements', 'label' => 'Security disclosure text', 'type' => 'textarea', 'default' => 'We protect the site with HTTPS, protected sign-in, rate limiting, request-forgery protection, strict content security rules and access controls. No internet service can promise perfect security. To report a vulnerability, write to the contact below.'],

            'marketing_enabled' => ['group' => 'marketing', 'label' => 'We send marketing emails', 'type' => 'bool', 'default' => '0', 'help' => 'Off. Nothing marketing-related is sent today. Switching this on shows the optional marketing consent on forms and adds an unsubscribe link to marketing emails only (never to security or transactional emails).'],

            'acceptance_days' => ['group' => 'acceptance', 'label' => 'Default days clients have to accept an updated policy', 'type' => 'int', 'default' => '14'],
            'restriction_enabled' => ['group' => 'acceptance', 'label' => 'Restrict an account that has not accepted by the deadline', 'type' => 'bool', 'default' => '1', 'help' => 'Records are never deleted. A restricted account keeps read access to its invoices, receipts and agreements and can contact support.'],
        ];
    }

    public static function get(string $key): string
    {
        $f = self::fields()[$key] ?? throw new \InvalidArgumentException("Unknown compliance setting {$key}");
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::all('SELECT setting_key, value_text FROM compliance_settings') as $r) {
                    self::$cache[(string) $r['setting_key']] = (string) $r['value_text'];
                }
            } catch (\Throwable) {
                // no table yet (half-installed site): defaults apply
            }
        }

        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $f['default'];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /** Whether the Owner has approved a fact for public display (nothing is public until it is). @param string $fact proprietor|operator|udyam|shop_act|email|whatsapp|grievance */
    public static function approved(string $fact): bool
    {
        return self::bool('approve_' . $fact);
    }

    /** A fact for a PUBLIC page: its value only when the Owner approved it, else an empty string. */
    public static function publicValue(string $key, string $fact): string
    {
        return self::approved($fact) ? trim(self::get($key)) : '';
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** The official email: the Owner's legal contact address, else the public site email. */
    public static function email(): string
    {
        $v = self::approved('email') ? trim(self::get('contact_email')) : '';

        return $v !== '' ? $v : trim((string) Config::get('site.email', ''));
    }

    public static function whatsapp(): string
    {
        $v = self::approved('whatsapp') ? trim(self::get('whatsapp')) : '';

        return $v !== '' ? $v : trim((string) Config::get('site.whatsapp', ''));
    }

    /** "Krazy Integrated Solutions is a business unit operated under Swamini Mahila Gruh Udyog, a sole proprietorship." */
    public static function disclosure(): string
    {
        $structure = mb_strtolower(trim(self::get('legal_structure')));
        $structure = $structure === 'sole proprietorship' ? 'a sole proprietorship' : ($structure === '' ? '' : 'a ' . $structure);

        return trim(self::get('brand_name') . ' is a business unit operated under ' . self::get('legal_enterprise_name') . ($structure !== '' ? ', ' . $structure : '') . '.');
    }

    /** @return array<string,array{key:string,label:string,type:string,value:string,default:string,help:string}> fields of a group */
    public static function group(string $group): array
    {
        $out = [];
        foreach (self::fields() as $k => $f) {
            if ($f['group'] === $group) {
                $out[$k] = ['key' => $k, 'label' => $f['label'], 'type' => $f['type'], 'value' => self::get($k), 'default' => $f['default'], 'help' => $f['help'] ?? ''];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $in posted values by key */
    public static function save(int $actorId, string $group, array $in): void
    {
        Gate::authorize('compliance.manage', $actorId);
        if (!isset(self::GROUPS[$group])) {
            throw new ValidationException(['Unknown settings group.']);
        }
        $errors = [];
        $writes = [];
        foreach (self::fields() as $k => $f) {
            if ($f['group'] !== $group) {
                continue;
            }
            $raw = $in[$k] ?? null;
            if ($f['type'] === 'bool') {
                $writes[$k] = !empty($raw) && $raw !== '0' ? '1' : '0';
                continue;
            }
            if ($raw === null) {
                continue;
            }
            $v = trim((string) $raw);
            if (preg_match('/[\0]/', $v) || mb_strlen($v) > ($f['type'] === 'textarea' ? 4000 : 300) || preg_match('/[<>]/', $v)) {
                $errors[] = $f['label'] . ': is too long or contains < or >.';
                continue;
            }
            if ($v !== '') {
                if ($f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $f['label'] . ': must be an email address.';
                    continue;
                }
                if ($f['type'] === 'int' && (!ctype_digit($v) || (int) $v > 3650)) {
                    $errors[] = $f['label'] . ': must be a whole number.';
                    continue;
                }
                if ($f['type'] === 'date' && !DeliveryService::validDate($v)) {
                    $errors[] = $f['label'] . ': must be a date like 2026-12-31.';
                    continue;
                }
            }
            if ($k === 'min_age' && $v !== '' && ((int) $v < 13 || (int) $v > 21)) {
                $errors[] = 'Minimum age must be between 13 and 21.';
                continue;
            }
            $writes[$k] = $v;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        Database::transaction(function () use ($actorId, $group, $writes): void {
            $changed = [];
            self::flush(); // compare with what is stored now, never with a cached copy
            foreach ($writes as $k => $v) {
                if (self::get($k) === $v) {
                    continue;
                }
                Database::run('INSERT INTO compliance_settings (setting_key, value_text, updated_by, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()', [$k, $v, $actorId]);
                $changed[] = $k;
            }
            if ($changed !== []) {
                Audit::record('compliance.settings_changed', 'compliance_settings', null, [], [], ['group' => $group, 'keys' => $changed], $actorId);
            }
        });
        self::flush();
    }

    /** Placeholder facts still missing (the legal pages cannot be published until they are filled in). @return array<string,string> key => label */
    public static function missingFacts(): array
    {
        $need = ['proprietor_name' => 'Legal proprietor: full name', 'operator_name' => 'Operational representative: full name'];
        $miss = [];
        foreach ($need as $k => $label) {
            if (trim(self::get($k)) === '') {
                $miss[$k] = $label;
            }
        }
        if (self::email() === '') {
            $miss['contact_email'] = 'Official business email';
        }
        if (self::whatsapp() === '') {
            $miss['whatsapp'] = 'Official WhatsApp number';
        }

        return $miss;
    }
}
