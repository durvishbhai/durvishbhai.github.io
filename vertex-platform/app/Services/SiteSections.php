<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * Editable text sections of the public pages. The shipped copy lives in SiteDefaults; an administrator's saved value for a field replaces it (an emptied
 * field stays empty, so a button or paragraph can be removed). Sections can be hidden and, on the home page, reordered.
 */
final class SiteSections
{
    /** @var array<string,array<string,array{enabled:bool,position:int,data:array<string,string>}>> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string,array{enabled:bool,position:int,data:array<string,string>}> */
    private static function load(string $page): array
    {
        if (isset(self::$cache[$page])) {
            return self::$cache[$page];
        }
        $saved = [];
        try {
            foreach (Database::all('SELECT section_key, enabled, position, data_json FROM site_sections WHERE page = ?', [$page]) as $r) {
                $saved[(string) $r['section_key']] = ['enabled' => (bool) $r['enabled'], 'position' => (int) $r['position'], 'data' => (array) json_decode((string) $r['data_json'], true)];
            }
        } catch (\Throwable) {
            // the table does not exist yet: the shipped copy is shown
        }
        $out = [];
        $i = 0;
        foreach (SiteDefaults::page($page)['sections'] as $key => $def) {
            $i += 10;
            $row = $saved[$key] ?? null;
            $data = $def['data'];
            foreach ($row['data'] ?? [] as $k => $v) {
                if (array_key_exists($k, $def['fields'])) {
                    $data[$k] = (string) $v;
                }
            }
            $out[$key] = ['enabled' => $row['enabled'] ?? true, 'position' => $row['position'] ?? $i, 'data' => $data];
        }
        uasort($out, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return self::$cache[$page] = $out;
    }

    /** @return array<string,string> the section's values with the shipped copy filling any gap */
    public static function get(string $page, string $key): array
    {
        return self::load($page)[$key]['data'] ?? [];
    }

    public static function enabled(string $page, string $key): bool
    {
        return (bool) (self::load($page)[$key]['enabled'] ?? false);
    }

    /** @return array<string,array{enabled:bool,position:int,data:array<string,string>}> in display order, hidden ones included */
    public static function all(string $page): array
    {
        return self::load($page);
    }

    /** @return list<string> keys of the sections to show, in order */
    public static function visible(string $page): array
    {
        return array_keys(array_filter(self::load($page), static fn (array $s): bool => $s['enabled']));
    }

    /**
     * @param array<string,mixed> $in posted values: s[<key>][enabled], s[<key>][position], s[<key>][f][<field>]
     */
    public static function save(int $actorId, string $page, array $in): void
    {
        Gate::authorize('cms.edit', $actorId);
        $def = SiteDefaults::page($page);
        $errors = [];
        $rows = [];
        foreach ($def['sections'] as $key => $s) {
            $posted = is_array($in[$key] ?? null) ? $in[$key] : [];
            $fields = is_array($posted['f'] ?? null) ? $posted['f'] : [];
            $data = [];
            foreach ($s['fields'] as $name => $f) {
                $v = trim((string) ($fields[$name] ?? ''));
                $label = $s['label'] . ' / ' . $f['label'];
                if (mb_strlen($v) > (int) $f['max']) {
                    $errors[] = "{$label} is too long (at most {$f['max']} characters).";
                    continue;
                }
                switch ($f['kind']) {
                    case 'url':
                        if ($v !== '' && CmsText::safeUrl($v) === null) {
                            $errors[] = "{$label} must start with /, https://, mailto: or tel:.";
                            continue 2;
                        }
                        break;
                    case 'media':
                        if ($v !== '' && CmsService::mediaByPublicId($v) === null) {
                            $errors[] = "{$label}: choose a picture from the media library.";
                            continue 2;
                        }
                        break;
                    case 'text':
                        if (preg_match('/[<>\r\n]/', $v)) {
                            $errors[] = "{$label} must be one line without < or >.";
                            continue 2;
                        }
                        break;
                }
                $data[$name] = $v;
            }
            $pos = (int) ($posted['position'] ?? 0);
            $rows[$key] = [!empty($posted['enabled']) ? 1 : 0, $pos >= 1 && $pos <= 999 ? $pos : 500, $data];
        }
        if ($errors !== []) {
            throw new ValidationException(array_slice($errors, 0, 8));
        }
        Database::transaction(function () use ($actorId, $page, $rows): void {
            foreach ($rows as $key => [$enabled, $pos, $data]) {
                Database::run('INSERT INTO site_sections (page, section_key, enabled, position, data_json, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), position = VALUES(position), data_json = VALUES(data_json), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                    [$page, $key, $enabled, $pos, json_encode($data, JSON_UNESCAPED_UNICODE), $actorId]);
            }
            Audit::record('site.sections_saved', 'site_page', null, [], ['page' => $page], [], $actorId);
        });
        self::flush();
    }

    /** Puts the shipped copy back for one page (the saved rows are removed). */
    public static function reset(int $actorId, string $page): void
    {
        Gate::authorize('cms.publish', $actorId);
        SiteDefaults::page($page);
        Database::run('DELETE FROM site_sections WHERE page = ?', [$page]);
        Audit::record('site.sections_reset', 'site_page', null, [], ['page' => $page], [], $actorId);
        self::flush();
    }
}
