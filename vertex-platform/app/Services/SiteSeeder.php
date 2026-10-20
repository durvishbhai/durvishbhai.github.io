<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Loads the starting content (the services and FAQs the business supplied, and DRAFT policies) into the database. It only ever adds what is missing, so it
 * can be run again safely, and it never touches anything an administrator has edited or deleted: once run, a marker stops it from re-adding removed items.
 * Policies stay drafts: they cannot be published until an administrator has completed them.
 */
final class SiteSeeder
{
    private const MARKER = ['page' => '_meta', 'key' => 'seeded'];

    public static function done(): bool
    {
        try {
            return Database::one('SELECT 1 AS x FROM site_sections WHERE page = ? AND section_key = ?', [self::MARKER['page'], self::MARKER['key']]) !== null;
        } catch (\Throwable) {
            return true; // no tables yet: nothing to do
        }
    }

    /** @return array{services:int,faqs:int,policies:int,team:int} how many items were added */
    public static function run(int $actorId, bool $force = false): array
    {
        Gate::authorize('cms.publish', $actorId);
        $added = ['services' => 0, 'faqs' => 0, 'policies' => 0, 'team' => 0];
        Database::transaction(function () use ($actorId, $force, &$added): void {
            $first = !self::done();
            $pos = 0;
            foreach (SiteDefaults::services() as $s) {
                $pos += 10;
                if ((!$first && !$force) || Database::one('SELECT 1 AS x FROM site_services WHERE slug = ?', [$s['slug']]) !== null) {
                    continue;
                }
                Database::run("INSERT INTO site_services (public_id, slug, name, icon, summary, hero_heading, intro, description, deliverables, use_cases, status, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, 'published', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [uuid_hex(), $s['slug'], $s['name'], $s['icon'], $s['summary'], $s['description'], $s['deliverables'], $s['use_cases'], $pos, $actorId]);
                $added['services']++;
            }
            if (($first || $force) && (int) Database::one('SELECT COUNT(*) AS c FROM site_faqs')['c'] === 0) {
                $pos = 0;
                foreach (SiteDefaults::faqs() as $f) {
                    $pos += 10;
                    Database::run("INSERT INTO site_faqs (public_id, category, question, answer, status, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'published', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $f['category'], $f['question'], $f['answer'], $pos, $actorId]);
                    $added['faqs']++;
                }
            }
            // The founder's profile, supplied by the business. Added on first load, or later only while the team list is empty (never brings back a profile that was deleted when others exist).
            if (($first || $force) && (int) Database::one('SELECT COUNT(*) AS c FROM site_team')['c'] === 0) {
                $m = SiteDefaults::founder();
                Database::run("INSERT INTO site_team (public_id, full_name, designation, department, bio, skills, status, position, created_by, created_at, updated_at) VALUES (?, ?, ?, 'leadership', ?, ?, 'published', 10, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [uuid_hex(), $m['full_name'], $m['designation'], $m['bio'], $m['skills'], $actorId]);
                $added['team']++;
            }
            foreach (SiteDefaults::policies() as $p) {
                if ((!$first && !$force) || Database::one('SELECT 1 AS x FROM site_policies WHERE slug = ?', [$p['slug']]) !== null) {
                    continue;
                }
                Database::run("INSERT INTO site_policies (public_id, slug, title, body, status, version_no, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), $p['slug'], $p['title'], $p['body'], $actorId]);
                $id = Database::insertId();
                Database::run("INSERT INTO site_policy_versions (policy_id, version_no, title, body, effective_date, note, changed_by, created_at) VALUES (?, 1, ?, ?, NULL, 'Starting draft', ?, UTC_TIMESTAMP())", [$id, $p['title'], $p['body'], $actorId]);
                $added['policies']++;
            }
            if ($first) {
                Database::run("INSERT INTO site_sections (page, section_key, enabled, position, data_json, updated_by, updated_at) VALUES (?, ?, 1, 0, '{}', ?, UTC_TIMESTAMP())", [self::MARKER['page'], self::MARKER['key'], $actorId]);
            }
            Audit::record('site.seeded', 'site', null, [], $added, [], $actorId);
        });
        SiteSections::flush();

        return $added;
    }
}
