<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;

/**
 * The public Hiring page and its switch. Three states, set in Administration -> Website -> Hiring:
 *   open    the page shows the roles and the application form
 *   closed  the page stays visible but says hiring is closed and takes no applications
 *   hidden  the page does not exist for visitors (404) and is not linked or in the sitemap
 * The state lives in site_sections (page "_hiring"), so it survives "reset wording". With nothing saved the page is open, because its roles were supplied by the business.
 */
final class SiteHiring
{
    public const STATES = ['open' => 'Open: show the roles and accept applications', 'closed' => 'Closed: show "hiring closed" and accept nothing', 'hidden' => 'Hidden: the page does not exist for visitors'];

    private static ?string $cache = null;

    public static function state(): string
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $state = 'open';
        try {
            $r = Database::one('SELECT data_json FROM site_sections WHERE page = ? AND section_key = ?', ['_hiring', 'state']);
            $v = (string) (((array) json_decode((string) ($r['data_json'] ?? '{}'), true))['state'] ?? '');
            if (isset(self::STATES[$v])) {
                $state = $v;
            }
        } catch (\Throwable) {
            // no table yet: the default applies
        }

        return self::$cache = $state;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function isOpen(): bool
    {
        return self::state() === 'open';
    }

    /** Linked, listed and reachable by visitors (open or closed, not hidden). */
    public static function isVisible(): bool
    {
        return self::state() !== 'hidden';
    }

    public static function set(int $actorId, string $state): void
    {
        Gate::authorize('cms.publish', $actorId);
        if (!isset(self::STATES[$state])) {
            throw new HttpException(422);
        }
        $before = self::state();
        Database::transaction(function () use ($actorId, $state, $before): void {
            Database::run("INSERT INTO site_sections (page, section_key, enabled, position, data_json, updated_by, updated_at) VALUES ('_hiring', 'state', 1, 0, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()", [json_encode(['state' => $state]), $actorId]);
            Audit::record('site.hiring_state', 'site_hiring', null, ['state' => $before], ['state' => $state], [], $actorId);
        });
        self::flush();
    }

    /** @return list<array{title:string,summary:string,family:string}> the roles shown on the page and offered in the form */
    public static function roles(): array
    {
        $out = [];
        foreach (SiteText::rows(SiteSections::get('hiring', 'roles')['items'] ?? '') as $r) {
            $title = trim((string) ($r[0] ?? ''));
            if ($title === '' || mb_strlen($title) > 100) {
                continue;
            }
            $out[] = ['title' => $title, 'summary' => trim((string) ($r[1] ?? '')), 'family' => stripos($title, 'video') !== false || stripos($title, 'edit') !== false ? 'video' : 'dev'];
        }

        return $out;
    }

    /** @return list<string> */
    public static function roleTitles(): array
    {
        return array_column(self::roles(), 'title');
    }
}
