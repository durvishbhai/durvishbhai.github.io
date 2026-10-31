<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * The extra answers collected by the public Contact and Project Requirements forms. The core of the request still becomes an ordinary CRM enquiry (same
 * reference numbers, stages, duplicate checks and permissions); the additional answers and any attachments are stored beside it and shown to the staff
 * who may open that enquiry. Attachments live in the private store and are never served by address.
 */
final class EnquiryExtras
{
    public const INQUIRY_TYPES = ['general' => 'General Inquiry', 'service' => 'Service Inquiry', 'project' => 'Project Discussion', 'partnership' => 'Partnership', 'careers' => 'Careers', 'support' => 'Support', 'other' => 'Other'];
    public const CATEGORIES = ['internal_tool' => 'Internal business management tool', 'customer_portal' => 'Customer-facing website / portal', 'ecommerce' => 'E-commerce / Online store', 'inventory' => 'Inventory / Stock management', 'crm' => 'CRM / Customer management', 'hrm' => 'HRM / Employee management', 'accounting' => 'Accounting / Finance dashboard', 'education' => 'School / Education management', 'clinic' => 'Hospital / Clinic management', 'booking' => 'Booking / Appointment system', 'analytics' => 'Reporting / Analytics dashboard', 'documents' => 'Document / File management', 'restaurant_pos' => 'Restaurant / POS system', 'real_estate' => 'Real estate / Property management', 'logistics' => 'Logistics / Delivery tracking', 'other' => 'Other (mention in special notes)'];
    public const TIMELINES = ['asap' => 'As soon as possible', 'one_month' => 'Within a month', 'one_three' => 'In 1–3 months', 'three_six' => 'In 3–6 months', 'flexible' => 'Flexible'];
    /** Choices offered by the step-by-step requirements form (wording only; the client may always add their own details). */
    public const OUTCOMES = ['Save time on manual work', 'Centralize data in one place', 'Improve team collaboration', 'Track sales and revenue', 'Manage customers better', 'Generate reports and insights', 'Reduce errors and duplication', 'Automate repetitive tasks', 'Provide self-service to clients', 'Compliance and audit trail', 'Replace existing manual / paper system', 'Other (mention in special notes)'];
    public const TECHNOLOGIES = ['Not sure: recommend the best fit', 'Website or CMS (for example WordPress)', 'Custom PHP and MySQL web application', 'JavaScript web application (React, Node.js)', 'Mobile app (Android or iOS)', 'No-code or low-code tool', 'Other (mention in special notes)'];
    public const FEATURES = ['Login and user accounts', 'Admin dashboard', 'Online payments', 'Invoices and receipts', 'Email, SMS or WhatsApp notifications', 'Reports and analytics', 'File uploads and documents', 'Search and filters', 'Multiple languages', 'Mobile-friendly design', 'Integrations with other tools (API)', 'Chat or support tickets', 'Booking or appointments', 'Content management (edit pages yourself)'];
    public const ROLES = ['Owner or admin', 'Staff or employees', 'Customers or clients', 'Vendors or partners', 'Public visitors (no login)'];
    public const PRIORITIES = ['All listed features are must-have', 'Core features first, extras later', 'MVP version first, then improve', 'Focus on the must-haves only'];
    public const ROLE_ACCESS = ['full' => 'Full Access', 'limited' => 'Limited Access', 'view' => 'View Only'];
    public const PERMISSION_SETS = ['Full system access', 'Manage users and content', 'Manage own department only', 'View and edit assigned records', 'Submit requests / forms only', 'Read-only access to reports'];
    public const USER_COUNTS = ['1 to 5', '6 to 20', '21 to 100', 'More than 100'];
    private const SERVICE_MAP = ['short-form-video-editing' => 'video_editing', 'youtube-long-form-video-editing' => 'video_editing', 'social-media-management' => 'social_media', 'thumbnail-design' => 'graphic_design',
        'poster-and-creative-design' => 'graphic_design', 'graphic-design' => 'graphic_design', 'content-writing' => 'content_writing', 'website-development' => 'website_development', 'ai-solutions' => 'ai_solutions', 'ai-automation' => 'ai_solutions'];

    /** @return array<string,string> slug => name of the services a visitor can ask about */
    public static function serviceChoices(): array
    {
        $out = [];
        foreach (SiteContent::published('services') as $s) {
            $out[(string) $s['slug']] = (string) $s['name'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in posted fields
     * @return array{base:array<string,string>,details:array<string,mixed>}
     */
    public static function contact(array $in): array
    {
        $errors = [];
        $type = null;
        $subject = null;
        try {
            $type = FieldRules::choice($in['inquiry_type'] ?? '', self::INQUIRY_TYPES, 'inquiry type', true);
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        try {
            $subject = FieldRules::line($in['subject'] ?? '', 'Subject', 3, 160);
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['base' => [], 'details' => ['form' => 'contact', 'Inquiry type' => self::INQUIRY_TYPES[$type], 'Subject' => $subject]];
    }

    /**
     * The extra answers of the appointment form: occupation, WhatsApp number, the chosen time and date, and where to meet. The time must be one the team offers,
     * on a weekday it runs, within the booking window; an in-person place must be one the team offers or the permanent "We will discuss it later".
     *
     * @param array<string,mixed> $in
     * @return array{base:array<string,string>,details:array<string,mixed>}
     */
    public static function appointment(array $in): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $occupation = $try(fn () => FieldRules::line($in['occupation'] ?? '', 'Your occupation', 2, 120));
        $phone = trim((string) ($in['phone'] ?? ''));
        $same = !empty($in['whatsapp_same']);
        $whatsapp = $same ? $phone : $try(fn () => FieldRules::phone($in['whatsapp'] ?? '', true));
        $slot = AppointmentConfig::slot((int) ($in['slot'] ?? 0), true);
        if ($slot === null) {
            $errors[] = 'Please choose one of the available times.';
        }
        $start = trim((string) ($in['start_time'] ?? ''));
        $end = trim((string) ($in['end_time'] ?? ''));
        $date = $try(fn () => FieldRules::date($in['meeting_date'] ?? '', 'Date for the meeting', true));
        if ($date !== null) {
            $min = AppTime::todayPlus((int) Config::get('appointments.min_days_ahead', 1));
            $max = AppTime::todayPlus((int) Config::get('appointments.max_days_ahead', 60));
            if ($date < $min || $date > $max) {
                $errors[] = 'Choose a date between ' . local_date($min) . ' and ' . local_date($max) . '.';
            } elseif ($slot !== null && !AppointmentConfig::runsOn($slot, $date)) {
                $errors[] = 'That time is not available on ' . gmdate('l', (int) strtotime($date . ' 12:00:00 UTC')) . 's. It runs on: ' . $slot['days'] . '.';
            } elseif ($slot !== null) {
                $try(function () use ($slot, $date, $start, $end): void {
                    AppointmentConfig::checkRange($slot, $date, $start, $end);
                });
            }
        }
        $type = (string) ($in['meeting_type'] ?? '');
        $placeName = '';
        if (!in_array($type, ['online', 'in_person'], true)) {
            $errors[] = 'Choose Online or In-person.';
        } elseif ($type === 'in_person') {
            $choice = (string) ($in['place'] ?? '');
            if ($choice === AppointmentConfig::LATER) {
                $placeName = AppointmentConfig::LATER_LABEL;
            } elseif (ctype_digit($choice) && ($pl = AppointmentConfig::place((int) $choice, true)) !== null) {
                $placeName = (string) $pl['name'];
            } else {
                $errors[] = 'Choose a place for the in-person meeting.';
            }
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if ($errors === [] && $email !== '' && AppointmentConfig::openRequests($email) >= AppointmentConfig::MAX_OPEN_REQUESTS) {
            $errors[] = 'This email address already has ' . AppointmentConfig::MAX_OPEN_REQUESTS . ' appointment requests waiting for our team. Please wait for us to contact you.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $when = gmdate('D d M Y', (int) strtotime($date . ' 12:00:00 UTC'));
        $range = AppointmentConfig::clock($start) . ' - ' . AppointmentConfig::clock($end);
        $kind = $type === 'online' ? 'Online' : 'In person: ' . $placeName;
        $summary = mb_substr($when . ', ' . $range . ', ' . $kind, 0, 120);

        $details = ['form' => 'appointment', 'Meeting with' => AppointmentConfig::host(), 'Meeting date' => $when, 'Meeting time' => $range,
            'Meeting type' => $type === 'online' ? 'Online' : 'In person', 'Meeting place' => $type === 'online' ? null : $placeName, 'Occupation' => $occupation, 'WhatsApp number' => $whatsapp . ($same ? ' (same as phone)' : '')];

        return ['base' => ['preferred_slot' => $summary], 'details' => array_filter($details, static fn ($v): bool => $v !== null && $v !== '') + ['_booking' => ['slot' => (int) $slot['id'], 'date' => $date, 'start' => $start, 'end' => $end]]];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{base:array<string,string>,details:array<string,mixed>}
     */
    public static function requirements(array $in): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $project = $try(fn () => FieldRules::line($in['project_name'] ?? '', 'Project name', 2, 160));
        $cat = $try(fn () => FieldRules::choice($in['project_category'] ?? '', self::CATEGORIES, 'project category', true));
        $problem = $try(fn () => FieldRules::text($in['problem_statement'] ?? '', 'Problem statement', 20, 3000));
        $outcome = $try(fn () => FieldRules::text($in['expected_outcome'] ?? '', 'Expected outcome', 10, 2000));
        $outcomeNote = $try(fn () => FieldRules::text($in['outcome_note'] ?? '', 'More about the outcome', 2, 1500, false));
        if ($outcome !== null && $outcomeNote !== null && $outcomeNote !== '') {
            $outcome .= ' (' . $outcomeNote . ')';
        }
        $st = self::structure($in);
        $featureNames = array_column($st['features'], 'name');
        $featuresExtra = $try(fn () => FieldRules::text($in['features'] ?? '', 'Other feature requirements', 3, 3000, false));
        $features = implode('; ', $featureNames) . ($featuresExtra !== null && $featuresExtra !== '' ? ($featureNames !== [] ? "\nOther: " : '') . $featuresExtra : '');
        $features = $features === '' ? null : $features;
        $rolesText = implode('; ', array_map(static fn (array $r): string => $r['name'] . ($r['access'] !== '' ? ' (' . $r['access'] . ($r['permissions'] !== '' ? ': ' . $r['permissions'] : '') . ')' : ''), $st['roles']));
        $userCount = $st['user_count'] === '' ? null : $st['user_count'];
        $roleNotes = $try(fn () => FieldRules::text($in['role_notes'] ?? '', 'Notes about who uses it', 2, 1000, false));
        $tech = $try(fn () => FieldRules::line($in['preferred_tech'] ?? '', 'Preferred technology', 2, 200, false));
        $timeline = $try(fn () => FieldRules::choice($in['timeline'] ?? '', self::TIMELINES, 'timeline', false));
        $notes = $try(fn () => FieldRules::text($in['notes'] ?? '', 'Additional notes', 2, 2000, false));
        $allowed = self::serviceChoices() + (array) \Vertex\Core\Config::get('workflow.services');
        $services = [];
        foreach ((array) ($in['services'] ?? []) as $s) {
            if (is_string($s) && isset($allowed[$s]) && !in_array($s, $services, true)) {
                $services[] = $s;
            }
        }
        if ($services === []) {
            $errors[] = 'Choose at least one service you need.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $first = $services[0];
        $workflow = (array) \Vertex\Core\Config::get('workflow.services');
        $service = isset($workflow[$first]) ? $first : (self::SERVICE_MAP[$first] ?? 'other');
        $names = array_map(static fn (string $s): string => (string) $allowed[$s], $services);
        $details = ['form' => 'requirements', 'Project name' => $project, 'Project category' => self::CATEGORIES[$cat], 'Required services' => implode(', ', $names), 'Problem statement' => $problem, 'Expected outcome' => $outcome,
            'Feature requirements' => $features, 'Feature priority' => $st['priority'] === '' ? null : $st['priority'], 'Who will use it' => $rolesText === '' ? null : $rolesText, 'Expected number of users' => $userCount, 'Notes about who uses it' => $roleNotes,
            'Preferred technology' => $tech, 'Desired timeline' => $timeline === null ? null : self::TIMELINES[$timeline], 'Additional notes' => $notes];
        $struct = ['features' => $st['features'], 'roles' => $st['roles'], 'priority' => $st['priority'], 'user_count' => $st['user_count'], 'extra_features' => (string) ($featuresExtra ?? '')];

        return ['base' => ['service' => $service, 'description' => "Project: {$project}\n\nProblem: {$problem}\n\nExpected outcome: {$outcome}"], 'details' => array_filter($details, static fn ($v): bool => $v !== null && $v !== '') + ['_structured' => $struct]];
    }

    /**
     * The step-by-step form's answers in a tidy, safe shape (lenient: it never throws, it only drops what is not allowed). Used to build the requirements
     * document and to store the structured lists beside the enquiry.
     *
     * @param array<string,mixed> $in
     * @return array{features:list<array{name:string,type:string}>,roles:list<array{name:string,access:string,permissions:string}>,priority:string,user_count:string}
     */
    public static function structure(array $in): array
    {
        $clean = static fn (mixed $v, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x1F<>]+/u', ' ', is_string($v) ? $v : '') ?? ''), 0, $max);
        $features = [];
        foreach ((array) ($in['feature_list'] ?? []) as $x) {
            if (is_string($x) && in_array($x, self::FEATURES, true) && !in_array($x, array_column($features, 'name'), true)) {
                $features[] = ['name' => $x, 'type' => 'Standard'];
            }
        }
        foreach (array_slice((array) ($in['custom_features'] ?? []), 0, 12) as $x) {
            $n = $clean($x, 120);
            if (mb_strlen($n) >= 2 && !in_array($n, array_column($features, 'name'), true)) {
                $features[] = ['name' => $n, 'type' => 'Custom'];
            }
        }
        $roles = [];
        $names = (array) ($in['role_name'] ?? []);
        $access = (array) ($in['role_access'] ?? []);
        $perms = (array) ($in['role_permissions'] ?? []);
        foreach (array_slice($names, 0, 8, true) as $i => $n) {
            $n = $clean($n, 60);
            if (mb_strlen($n) < 2) {
                continue;
            }
            $a = (string) ($access[$i] ?? '');
            $p = (string) ($perms[$i] ?? '');
            $roles[] = ['name' => $n, 'access' => self::ROLE_ACCESS[$a] ?? '', 'permissions' => in_array($p, self::PERMISSION_SETS, true) ? $p : ''];
        }
        foreach ((array) ($in['roles'] ?? []) as $x) { // the earlier simple tick-list
            if (is_string($x) && in_array($x, self::ROLES, true) && !in_array($x, array_column($roles, 'name'), true)) {
                $roles[] = ['name' => $x, 'access' => '', 'permissions' => ''];
            }
        }
        $priority = isset($in['feature_priority']) && in_array($in['feature_priority'], self::PRIORITIES, true) ? (string) $in['feature_priority'] : '';
        $count = isset($in['user_count']) && in_array($in['user_count'], self::USER_COUNTS, true) ? (string) $in['user_count'] : '';

        return ['features' => $features, 'roles' => $roles, 'priority' => $priority, 'user_count' => $count];
    }

    /**
     * Stores the extra answers and the accepted attachments. Call inside the transaction that created the enquiry.
     *
     * @param array<string,mixed> $details
     * @param list<array<string,mixed>> $files results of FileStore::accept
     */
    public static function save(int $enquiryId, array $details, array $files): void
    {
        Database::run('INSERT INTO enquiry_details (enquiry_id, details_json, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$enquiryId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
        foreach ($files as $meta) {
            $fid = FileStore::insert($meta, null);
            Database::run('INSERT INTO enquiry_files (enquiry_id, file_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$enquiryId, $fid]);
        }
    }

    /** @return array<string,mixed>|null */
    public static function details(int $enquiryId): ?array
    {
        try {
            $r = Database::one('SELECT details_json FROM enquiry_details WHERE enquiry_id = ?', [$enquiryId]);
        } catch (\Throwable) {
            return null;
        }

        return $r === null ? null : (array) json_decode((string) $r['details_json'], true);
    }

    /** @return list<array<string,mixed>> */
    public static function files(int $enquiryId): array
    {
        try {
            return Database::all('SELECT f.public_id, f.original_name, f.size_bytes FROM enquiry_files ef JOIN stored_files f ON f.id = ef.file_id WHERE ef.enquiry_id = ? ORDER BY ef.id', [$enquiryId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null the stored file when it belongs to this enquiry */
    public static function file(int $enquiryId, string $filePublicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $filePublicId) ? Database::one('SELECT f.* FROM enquiry_files ef JOIN stored_files f ON f.id = ef.file_id WHERE ef.enquiry_id = ? AND f.public_id = ?', [$enquiryId, $filePublicId]) : null;
    }
}
