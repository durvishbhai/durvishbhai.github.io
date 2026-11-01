<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * What KIS offers, and how available each service is: active (taking projects), suspended (temporarily not taking projects), coming soon, or discontinued (hidden).
 * The Owner controls it (`compliance.manage`). Only ACTIVE services can be chosen in the public forms; suspended and coming-soon ones are shown with a clear label;
 * discontinued ones are not advertised at all.
 *
 * reconcile() brings an existing website in line with the real service list given by the business: it adds the offered services that are missing, marks the video-editing
 * services as suspended, social media management as discontinued, and the two other services the old starting content advertised (thumbnail design, content writing) as discontinued. Nothing is deleted and
 * services the Owner added are never touched.
 */
final class ServiceAvailability
{
    public const LABELS = ['active' => 'Active: taking projects', 'suspended' => 'Temporarily suspended', 'coming_soon' => 'Coming soon', 'discontinued' => 'Permanently discontinued'];
    public const PUBLIC_LABELS = ['suspended' => 'Not accepting projects at the moment', 'coming_soon' => 'Coming soon', 'discontinued' => 'No longer offered'];

    /** @return list<array{slug:string,name:string,icon:string,summary:string}> */
    public static function offered(): array
    {
        return [
            ['slug' => 'website-development', 'name' => 'Website Development', 'icon' => 'web', 'summary' => 'Build responsive websites and web-based experiences aligned with business requirements.'],
            ['slug' => 'web-application-development', 'name' => 'Web Application Development', 'icon' => 'gear', 'summary' => 'Design and build web applications for the workflows and users of your business.'],
            ['slug' => 'custom-management-systems-and-software', 'name' => 'Custom Management Systems and Software', 'icon' => 'gear', 'summary' => 'Build management systems and custom software around the way your business actually works.'],
            ['slug' => 'ios-application-and-software-development', 'name' => 'iOS Application and Software Development', 'icon' => 'web', 'summary' => 'Build applications and software for Apple devices.'],
            ['slug' => 'android-application-development', 'name' => 'Android Application Development', 'icon' => 'web', 'summary' => 'Build Android applications for your customers or your team.'],
            ['slug' => 'ai-solutions', 'name' => 'AI Solutions', 'icon' => 'spark', 'summary' => 'Explore practical AI-assisted tools and solutions for business workflows.'],
            ['slug' => 'ai-automation', 'name' => 'AI Automation', 'icon' => 'gear', 'summary' => 'Identify repetitive processes that can be improved through suitable automation.'],
            ['slug' => 'logo-creation', 'name' => 'Logo Creation', 'icon' => 'design', 'summary' => 'Create a logo that represents your business.'],
            ['slug' => 't-shirt-designing', 'name' => 'T-shirt Designing', 'icon' => 'design', 'summary' => 'Design artwork for T-shirts and similar merchandise.'],
            ['slug' => 'graphic-design', 'name' => 'Graphic Design', 'icon' => 'design', 'summary' => 'Develop visual assets, including posters and promotional creatives, that support your brand and communication.'],
        ];
    }

    private const SUSPENDED = ['short-form-video-editing', 'youtube-long-form-video-editing'];
    /** Services the old starting content advertised that KIS does not offer (social media management is discontinued; the others are not on the service list). */
    private const DISCONTINUED = ['social-media-management', 'thumbnail-design', 'content-writing'];

    /** Run once; safe to call again. */
    public static function reconcile(): void
    {
        try {
            if (Database::one("SELECT 1 AS x FROM compliance_settings WHERE setting_key = 'services_reconciled'") !== null) {
                return;
            }
            Database::transaction(static function (): void {
                $pos = (int) Database::one('SELECT COALESCE(MAX(position), 0) AS m FROM site_services')['m'];
                                foreach (self::offered() as $s) {
                    if (Database::one('SELECT 1 AS x FROM site_services WHERE slug = ?', [$s['slug']]) === null) {
                        Database::run(
                            "INSERT INTO site_services (public_id, slug, name, icon, summary, description, deliverables, use_cases, status, availability, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                            [bin2hex(random_bytes(16)), $s['slug'], $s['name'], $s['icon'], $s['summary'], $s['summary'] . "\n\nThe exact scope, deliverables, timeline and price of every engagement are agreed with you in writing before work begins.", 'The deliverables are agreed in writing with each client.', 'Described when we discuss your requirements.', ++$pos],
                        );
                    }
                }
                // Only the services the old starting content advertised are touched. Anything the Owner added themselves is never hidden by this.
                foreach (self::SUSPENDED as $slug) {
                    Database::run("UPDATE site_services SET availability = 'suspended', availability_note = 'Not currently accepting projects. We plan to resume this service.' WHERE slug = ?", [$slug]);
                }
                foreach (self::DISCONTINUED as $slug) {
                    Database::run("UPDATE site_services SET availability = 'discontinued', availability_note = ? WHERE slug = ?", [$slug === 'social-media-management' ? 'Discontinued.' : 'Not on the current service list: review before offering it again.', $slug]);
                }
                Database::run("INSERT INTO compliance_settings (setting_key, value_text, updated_at) VALUES ('services_reconciled', '1', UTC_TIMESTAMP())");
            });
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Services could not be reconciled: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        self::reconcile();

        return Database::all('SELECT public_id, slug, name, summary, status, availability, availability_note, position, updated_at FROM site_services ORDER BY FIELD(availability, \'active\', \'coming_soon\', \'suspended\', \'discontinued\'), position, id');
    }

    public static function set(int $actorId, string $publicId, string $availability, string $note): void
    {
        Gate::authorize('compliance.manage', $actorId);
        if (!isset(self::LABELS[$availability])) {
            throw new ValidationException(['Choose one of the availability options.']);
        }
        $note = trim($note);
        if (mb_strlen($note) > 300 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['The note is too long or contains < or >.']);
        }
        $s = Database::one('SELECT id, name, availability FROM site_services WHERE public_id = ?', [$publicId]) ?? throw new HttpException(404);
        Database::transaction(function () use ($actorId, $s, $availability, $note): void {
            Database::run('UPDATE site_services SET availability = ?, availability_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$availability, $note === '' ? null : $note, $s['id']]);
            Audit::record('compliance.service_availability', 'site_service', (int) $s['id'], ['availability' => $s['availability']], ['availability' => $availability], ['name' => $s['name']], $actorId);
        });
    }

    /** The label shown next to a service that is not taking projects, or '' when it is active. @param array<string,mixed> $s */
    public static function badge(array $s): string
    {
        return self::PUBLIC_LABELS[(string) ($s['availability'] ?? 'active')] ?? '';
    }
}
