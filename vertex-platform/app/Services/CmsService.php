<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Modules 13 and 34: the public website, edited from the admin area.
 *
 *  - Pages are lists of typed blocks (CmsBlocks), with search-engine fields, a draft -> published / scheduled / archived life cycle, a preview, and a full
 *    version history with restore. Every save that changes something creates a version; nothing is ever overwritten.
 *  - Changing the address of a live page leaves a redirect behind. Redirects can also be added by hand. /sitemap.xml and /robots.txt are generated.
 *  - Reusable blocks are edited once and shown on many pages. Header and footer menus are editable. Company facts come from Integrations & settings.
 *  - The media library accepts real images only (type, size and dimensions checked, JPEG/PNG/WebP re-encoded to drop hidden data), stored under random names
 *    in a folder where scripts cannot run.
 *  - `cms.edit` writes drafts, `cms.publish` makes things live (publish, schedule, restore on a live page, redirects, menus).
 */
final class CmsService
{
    public const MAX_BLOCKS = 40;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    // ------------------------------------------------------------------------------------------------ pages

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM cms_pages WHERE public_id = ?', [$publicId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT public_id, slug, title, status, publish_at, published_at, updated_at, current_version FROM cms_pages ORDER BY (status = \'archived\'), slug');
    }

    /** @return list<array<string,mixed>> */
    public static function blocksOf(array $page): array
    {
        return array_values((array) json_decode((string) $page['blocks_json'], true));
    }

    public static function normaliseSlug(string $slug): string
    {
        return trim(strtolower(trim($slug)), '/');
    }

    /** First path segments the application itself uses: a page may never take them over. @return array<string,true> */
    public static function reservedSegments(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $seg = ['assets' => true, 'uploads' => true, 'index.php' => true, 'sitemap.xml' => true, 'robots.txt' => true, 'favicon.ico' => true];
        $ref = new \ReflectionProperty(\Vertex\Core\Router::class, 'routes');
        foreach ($ref->getValue(\Vertex\Http\Kernel::router()) as $r) {
            $first = explode('/', trim((string) $r['pattern'], '/'))[0] ?? '';
            if ($first !== '' && !str_starts_with($first, '{')) {
                $seg[$first] = true;
            }
        }

        return $cache = $seg;
    }

    private static function validSlug(string $slug, ?int $exceptId): ?string
    {
        if ($slug === 'home') {
            return Database::one('SELECT 1 AS x FROM cms_pages WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) === null ? null : 'The home page already exists.';
        }
        if (!preg_match('#^[a-z0-9][a-z0-9-]{0,58}(/[a-z0-9][a-z0-9-]{0,58})?$#', $slug)) {
            return 'The address may use letters, digits and dashes, with at most one slash, for example "about" or "services/web-design".';
        }
        if (isset(self::reservedSegments()[explode('/', $slug)[0]])) {
            return 'That address is used by the platform itself. Choose another.';
        }
        if (Database::one('SELECT 1 AS x FROM cms_pages WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null) {
            return 'Another page already uses that address.';
        }

        return null;
    }

    public static function create(int $actorId, string $title, string $slug): array
    {
        Gate::authorize('cms.edit', $actorId);
        $title = (string) FieldRules::line($title, 'Page title', 2, 200);
        $slug = self::normaliseSlug($slug !== '' ? $slug : (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)));
        $slug = trim($slug, '-');
        if (($err = self::validSlug($slug, null)) !== null) {
            throw new ValidationException([$err]);
        }
        $pub = uuid_hex();

        return Database::transaction(function () use ($actorId, $title, $slug, $pub): array {
            Database::run("INSERT INTO cms_pages (public_id, slug, title, status, blocks_json, current_version, created_by, created_at, updated_at) VALUES (?, ?, ?, 'draft', '[]', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$pub, $slug, $title, $actorId]);
            $id = Database::insertId();
            self::snapshot($id, 1, $title, '[]', null, 'Created', $actorId);
            Audit::record('cms.page_created', 'cms_page', $id, [], ['slug' => $slug], [], $actorId);

            return ['public_id' => $pub, 'id' => $id];
        });
    }

    private static function snapshot(int $pageId, int $version, string $title, string $blocksJson, ?string $seoJson, string $note, int $actorId): void
    {
        Database::run('INSERT INTO cms_page_versions (page_id, version_no, title, blocks_json, seo_json, note, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$pageId, $version, $title, $blocksJson, $seoJson, mb_substr($note, 0, 255), $actorId]);
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private static function seoOf(array $page): array
    {
        return ['seo_title' => $page['seo_title'], 'seo_description' => $page['seo_description'], 'og_media_id' => $page['og_media_id'], 'noindex' => (int) $page['noindex'], 'canonical_url' => $page['canonical_url']];
    }

    /** Writes new content and a new version, in one transaction. @param list<array<string,mixed>>|null $blocks */
    private static function saveContent(int $actorId, array $page, ?string $title, ?array $blocks, ?array $seo, string $note, ?string $slug = null): void
    {
        Database::transaction(function () use ($actorId, $page, $title, $blocks, $seo, $note, $slug): void {
            $cur = Database::one('SELECT * FROM cms_pages WHERE id = ? FOR UPDATE', [$page['id']]);
            $newTitle = $title ?? $cur['title'];
            $newBlocks = $blocks === null ? (string) $cur['blocks_json'] : json_encode(array_values($blocks), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $seoNow = self::seoOf($cur);
            $newSeo = $seo ?? $seoNow;
            $newSlug = $slug ?? $cur['slug'];
            if ($newTitle === $cur['title'] && $newBlocks === $cur['blocks_json'] && $newSeo == $seoNow && $newSlug === $cur['slug']) {
                return;
            }
            if ($cur['status'] === 'published' && !Gate::can('cms.publish', $actorId)) {
                throw new HttpException(403, 'Changing a live page needs publish rights. Ask an administrator, or unpublish it first.');
            }
            if ($newSlug !== $cur['slug'] && in_array($cur['status'], ['published', 'scheduled'], true)) {
                Database::run('INSERT INTO cms_redirects (from_path, to_url, status_code, created_by, created_at) VALUES (?, ?, 301, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE to_url = VALUES(to_url)', ['/' . $cur['slug'], '/' . ($newSlug === 'home' ? '' : $newSlug), $actorId]);
            }
            $v = (int) $cur['current_version'] + 1;
            Database::run('UPDATE cms_pages SET slug = ?, title = ?, blocks_json = ?, seo_title = ?, seo_description = ?, og_media_id = ?, noindex = ?, canonical_url = ?, current_version = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$newSlug, $newTitle, $newBlocks, $newSeo['seo_title'], $newSeo['seo_description'], $newSeo['og_media_id'], $newSeo['noindex'], $newSeo['canonical_url'], $v, $cur['id']]);
            self::snapshot((int) $cur['id'], $v, $newTitle, $newBlocks, json_encode($newSeo, JSON_THROW_ON_ERROR), $note, $actorId);
            Audit::record('cms.page_changed', 'cms_page', (int) $cur['id'], [], ['version' => $v], ['note' => mb_substr($note, 0, 120), 'status' => $cur['status']], $actorId);
        });
    }

    /** @param array<string,mixed> $in title, slug, seo_title, seo_description, canonical_url, og_image, noindex */
    public static function updateDetails(int $actorId, array $page, array $in): void
    {
        Gate::authorize('cms.edit', $actorId);
        $title = (string) FieldRules::line($in['title'] ?? '', 'Page title', 2, 200);
        $slug = self::normaliseSlug((string) ($in['slug'] ?? $page['slug']));
        if ($slug !== $page['slug'] && ($err = self::validSlug($slug, (int) $page['id'])) !== null) {
            throw new ValidationException([$err]);
        }
        $seoTitle = FieldRules::line($in['seo_title'] ?? '', 'Search title', 2, 160, false);
        $seoDesc = FieldRules::line($in['seo_description'] ?? '', 'Search description', 10, 320, false);
        $canon = trim((string) ($in['canonical_url'] ?? ''));
        if ($canon !== '' && !preg_match('#^https://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/\S*)?$#', $canon)) {
            throw new ValidationException(['The canonical address must start with https://']);
        }
        $og = null;
        if (trim((string) ($in['og_image'] ?? '')) !== '') {
            $m = self::mediaByPublicId(trim((string) $in['og_image'])) ?? throw new ValidationException(['Choose the sharing picture from the media library.']);
            $og = (int) $m['id'];
        }
        self::saveContent($actorId, $page, $title, null, ['seo_title' => $seoTitle, 'seo_description' => $seoDesc, 'og_media_id' => $og, 'noindex' => !empty($in['noindex']) ? 1 : 0, 'canonical_url' => $canon === '' ? null : $canon], 'Details and search settings', $slug);
    }

    /** @param array<string,mixed> $in */
    public static function addBlock(int $actorId, array $page, string $type, array $in): void
    {
        Gate::authorize('cms.edit', $actorId);
        $blocks = self::blocksOf($page);
        if (count($blocks) >= self::MAX_BLOCKS) {
            throw new ValidationException(['A page can have at most ' . self::MAX_BLOCKS . ' blocks.']);
        }
        $blocks[] = CmsBlocks::clean($type, $in);
        self::saveContent($actorId, $page, null, $blocks, null, 'Added block: ' . $type);
    }

    /** @param array<string,mixed> $in */
    public static function updateBlock(int $actorId, array $page, int $index, array $in): void
    {
        Gate::authorize('cms.edit', $actorId);
        $blocks = self::blocksOf($page);
        if (!isset($blocks[$index])) {
            throw new HttpException(404);
        }
        $blocks[$index] = CmsBlocks::clean((string) $blocks[$index]['type'], $in);
        self::saveContent($actorId, $page, null, $blocks, null, 'Edited block ' . ($index + 1));
    }

    public static function moveBlock(int $actorId, array $page, int $index, string $dir): void
    {
        Gate::authorize('cms.edit', $actorId);
        $blocks = self::blocksOf($page);
        $j = $dir === 'up' ? $index - 1 : $index + 1;
        if (!isset($blocks[$index], $blocks[$j])) {
            return;
        }
        [$blocks[$index], $blocks[$j]] = [$blocks[$j], $blocks[$index]];
        self::saveContent($actorId, $page, null, $blocks, null, 'Moved block ' . ($index + 1) . ' ' . $dir);
    }

    public static function deleteBlock(int $actorId, array $page, int $index): void
    {
        Gate::authorize('cms.edit', $actorId);
        $blocks = self::blocksOf($page);
        if (!isset($blocks[$index])) {
            throw new HttpException(404);
        }
        array_splice($blocks, $index, 1);
        self::saveContent($actorId, $page, null, $blocks, null, 'Removed block ' . ($index + 1));
    }

    // ------------------------------------------------------------------------------------------------ life cycle and history

    /** @param 'draft'|'published'|'archived' $to */
    public static function setStatus(int $actorId, array $page, string $to): void
    {
        Gate::authorize('cms.publish', $actorId);
        if (!in_array($to, ['draft', 'published', 'archived'], true)) {
            throw new HttpException(422);
        }
        if ($to === 'published') {
            if (self::blocksOf($page) === []) {
                throw new ValidationException(['Add at least one block before publishing.']);
            }
            if (($page['seo_title'] ?? '') === '' && ($page['seo_description'] ?? '') === '' && !$page['noindex']) {
                // allowed: the defaults are used, but make the omission visible in the audit trail
            }
        }
        Database::transaction(function () use ($actorId, $page, $to): void {
            Database::run('UPDATE cms_pages SET status = ?, published_at = ?, publish_at = NULL, archived_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to, $to === 'published' ? gmdate('Y-m-d H:i:s') : ($page['published_at'] ?? null), $to === 'archived' ? gmdate('Y-m-d H:i:s') : null, $page['id']]);
            Audit::record('cms.page_' . $to, 'cms_page', (int) $page['id'], ['status' => $page['status']], ['status' => $to], ['slug' => $page['slug']], $actorId);
        });
    }

    /** @param string $localDateTime "YYYY-MM-DD HH:MM" in the business time zone */
    public static function schedule(int $actorId, array $page, string $localDateTime): void
    {
        Gate::authorize('cms.publish', $actorId);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i', trim(str_replace('T', ' ', $localDateTime)), AppTime::zone());
        if ($d === false) {
            throw new ValidationException(['Enter the date and time like 2026-12-01 09:30.']);
        }
        $utc = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ($utc <= gmdate('Y-m-d H:i:s')) {
            throw new ValidationException(['Choose a time in the future (or use Publish now).']);
        }
        if (self::blocksOf($page) === []) {
            throw new ValidationException(['Add at least one block before scheduling.']);
        }
        Database::run("UPDATE cms_pages SET status = 'scheduled', publish_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$utc, $page['id']]);
        Audit::record('cms.page_scheduled', 'cms_page', (int) $page['id'], ['status' => $page['status']], ['status' => 'scheduled'], ['publish_at' => $utc], $actorId);
    }

    /** Makes due scheduled pages live (scheduler; the public lookup also treats them as live). @return int pages published */
    public static function publishDue(): int
    {
        return Database::run("UPDATE cms_pages SET status = 'published', published_at = publish_at, updated_at = UTC_TIMESTAMP() WHERE status = 'scheduled' AND publish_at <= UTC_TIMESTAMP()")->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public static function versions(array $page): array
    {
        return Database::all('SELECT v.version_no, v.title, v.note, v.created_at, u.name AS by_name FROM cms_page_versions v LEFT JOIN users u ON u.id = v.changed_by WHERE v.page_id = ? ORDER BY v.version_no DESC LIMIT 100', [$page['id']]);
    }

    public static function restore(int $actorId, array $page, int $versionNo): void
    {
        Gate::authorize('cms.edit', $actorId);
        $v = Database::one('SELECT * FROM cms_page_versions WHERE page_id = ? AND version_no = ?', [$page['id'], $versionNo]) ?? throw new HttpException(404);
        $seo = $v['seo_json'] !== null ? (array) json_decode((string) $v['seo_json'], true) : self::seoOf($page);
        $seo += ['seo_title' => null, 'seo_description' => null, 'og_media_id' => null, 'noindex' => 0, 'canonical_url' => null];
        self::saveContent($actorId, $page, (string) $v['title'], (array) json_decode((string) $v['blocks_json'], true), $seo, 'Restored version ' . $versionNo);
    }

    // ------------------------------------------------------------------------------------------------ public side

    /** A page visitors may see: published, or scheduled and due. @return array<string,mixed>|null */
    public static function findLive(string $slug): ?array
    {
        return Database::one("SELECT * FROM cms_pages WHERE slug = ? AND archived_at IS NULL AND (status = 'published' OR (status = 'scheduled' AND publish_at <= UTC_TIMESTAMP()))", [$slug]);
    }

    public static function renderBlocks(array $page): string
    {
        $out = '';
        foreach (self::blocksOf($page) as $b) {
            $out .= CmsBlocks::html((array) $b);
        }

        return $out;
    }

    /** @return array{code:int,to:string}|null */
    public static function redirectFor(string $path): ?array
    {
        $r = Database::one('SELECT id, to_url, status_code FROM cms_redirects WHERE from_path = ?', [$path]);
        if ($r === null) {
            return null;
        }
        Database::run('UPDATE cms_redirects SET hits = hits + 1 WHERE id = ?', [$r['id']]);

        return ['code' => (int) $r['status_code'], 'to' => (string) $r['to_url']];
    }

    /** @return array<string,mixed> meta for the page layout */
    public static function meta(array $page): array
    {
        $site = (array) Config::get('site', []);
        $desc = (string) ($page['seo_description'] ?: ($site['default_seo_description'] ?? ''));
        $og = $page['og_media_id'] ? Database::one('SELECT * FROM cms_media WHERE id = ?', [$page['og_media_id']]) : null;
        $base = rtrim((string) Config::get('app.url'), '/');

        return ['description' => $desc, 'canonical' => $page['canonical_url'] ?: ($base !== '' ? $base . ($page['slug'] === 'home' ? '/' : '/' . $page['slug']) : ''), 'ogImage' => $og === null || $base === '' ? '' : $base . self::mediaUrl($og), 'ogTitle' => (string) ($page['seo_title'] ?: $page['title'])];
    }

    /** @return list<array{loc:string,lastmod:string}> */
    public static function sitemapEntries(): array
    {
        $base = rtrim((string) Config::get('app.url'), '/');
        $today = gmdate('Y-m-d');
        $out = [['loc' => $base . '/', 'lastmod' => $today]];
        $paths = ['/about', '/services', '/our-clients', '/our-partners', '/careers', '/contact', '/request-quote', '/faq', '/partners/apply', '/sitemap'];
        if (SiteHiring::isVisible()) {
            $paths[] = '/hiring';
        }
        if (SiteContent::hasPublished('team')) {
            $paths[] = '/our-team';
        }
        if (SiteContent::hasPublished('portfolio')) {
            $paths[] = '/portfolio';
        }
        foreach ($paths as $p) {
            $out[] = ['loc' => $base . $p, 'lastmod' => $today];
        }
        foreach ([['services', '/services/'], ['portfolio', '/portfolio/'], ['jobs', '/careers/']] as [$entity, $prefix]) {
            foreach (SiteContent::published($entity) as $r) {
                $out[] = ['loc' => $base . $prefix . $r['slug'], 'lastmod' => substr((string) $r['updated_at'], 0, 10)];
            }
        }
        foreach (SiteContent::published('policies') as $r) {
            $out[] = ['loc' => $base . '/' . $r['slug'], 'lastmod' => substr((string) $r['updated_at'], 0, 10)];
        }
        foreach (Database::all("SELECT slug, updated_at FROM cms_pages WHERE archived_at IS NULL AND noindex = 0 AND (status = 'published' OR (status = 'scheduled' AND publish_at <= UTC_TIMESTAMP())) ORDER BY slug") as $p) {
            if ($p['slug'] !== 'home') {
                $out[] = ['loc' => $base . '/' . $p['slug'], 'lastmod' => substr((string) $p['updated_at'], 0, 10)];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ menus and redirects

    /** @return list<array{label:string,url:string}> */
    public static function menu(string $location): array
    {
        try {
            $r = Database::one('SELECT items_json FROM cms_menus WHERE location = ?', [$location]);
        } catch (\Throwable) {
            return [];
        }

        return $r === null ? [] : array_values(array_filter((array) json_decode((string) $r['items_json'], true), static fn ($i): bool => is_array($i) && isset($i['label'], $i['url'])));
    }

    /** True when an administrator has saved this menu (an empty saved menu means "show nothing", an unsaved one means "use the built-in links"). */
    public static function menuSaved(string $location): bool
    {
        try {
            return Database::one('SELECT 1 AS x FROM cms_menus WHERE location = ?', [$location]) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public const MENU_LOCATIONS = ['header' => 'Header menu', 'footer_explore' => 'Footer: Explore', 'footer_opportunities' => 'Footer: Opportunities', 'footer_support' => 'Footer: Support', 'footer_legal' => 'Footer: Legal', 'footer' => 'Footer: extra links under the columns'];

    public static function menuText(string $location): string
    {
        $lines = [];
        foreach (self::menu($location) as $i) {
            $lines[] = $i['label'] . ' | ' . $i['url'];
            foreach ((array) ($i['children'] ?? []) as $c) {
                $lines[] = '- ' . $c['label'] . ' | ' . $c['url'];
            }
        }

        return implode("\n", $lines);
    }

    public static function saveMenu(int $actorId, string $location, string $text): void
    {
        Gate::authorize('cms.publish', $actorId);
        if (!isset(self::MENU_LOCATIONS[$location])) {
            throw new HttpException(404);
        }
        $items = [];
        $errors = [];
        $top = -1;
        foreach (preg_split('/\R/', trim($text)) ?: [] as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $child = str_starts_with(ltrim($line), '- ');
            $line = $child ? substr(ltrim($line), 2) : $line;
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $special = $url === '@services';
            if (mb_strlen($label) < 1 || mb_strlen($label) > 40 || preg_match('/[<>]/', $label) || (!$special && CmsText::safeUrl($url) === null)) {
                $errors[] = 'Line ' . ($n + 1) . ': write "Label | /address" with a label up to 40 characters and an address starting with /, https://, mailto: or tel: (use "- " before a link to put it under the one above).';
                continue;
            }
            if ($child) {
                if ($top < 0 || $special) {
                    $errors[] = 'Line ' . ($n + 1) . ': a "- " link must come after a main link.';
                    continue;
                }
                $items[$top]['children'][] = ['label' => $label, 'url' => $url];
                continue;
            }
            $items[] = ['label' => $label, 'url' => $special ? '/services' : $url] + ($special ? ['auto' => 'services'] : []);
            $top = count($items) - 1;
        }
        if (count($items) > 12) {
            $errors[] = 'A menu can have at most 12 main links.';
        }
        foreach ($items as $it) {
            if (count((array) ($it['children'] ?? [])) > 12) {
                $errors[] = 'A dropdown can have at most 12 links.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException(array_slice($errors, 0, 6));
        }
        Database::run('INSERT INTO cms_menus (location, items_json, updated_by, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE items_json = VALUES(items_json), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()', [$location, json_encode($items, JSON_UNESCAPED_UNICODE), $actorId]);
        Audit::record('cms.menu_saved', 'cms_menu', null, [], ['location' => $location, 'links' => count($items)], [], $actorId);
    }

    /** Puts the built-in links back for one menu. */
    public static function resetMenu(int $actorId, string $location): void
    {
        Gate::authorize('cms.publish', $actorId);
        if (!isset(self::MENU_LOCATIONS[$location])) {
            throw new HttpException(404);
        }
        Database::run('DELETE FROM cms_menus WHERE location = ?', [$location]);
        Audit::record('cms.menu_reset', 'cms_menu', null, [], ['location' => $location], [], $actorId);
    }

    /** @return list<array<string,mixed>> */
    public static function redirects(): array
    {
        return Database::all('SELECT * FROM cms_redirects ORDER BY id DESC LIMIT 300');
    }

    public static function addRedirect(int $actorId, string $from, string $to, string $code): void
    {
        Gate::authorize('cms.publish', $actorId);
        $from = '/' . trim($from, '/ ');
        $to = trim($to);
        if (!preg_match('#^/[A-Za-z0-9\-_/.]{1,188}$#', $from) || $from === '/' || str_contains($from, '..') || str_contains($from, '//')) {
            throw new ValidationException(['"From" must be a path such as /old-page (letters, digits, dashes).']);
        }
        if (CmsText::safeUrl($to) === null || !preg_match('#^(https://|/)#', $to) || $to === $from) {
            throw new ValidationException(['"To" must be a path (/new-page) or an https:// address, and different from "From".']);
        }
        if (isset(self::reservedSegments()[explode('/', trim($from, '/'))[0]])) {
            throw new ValidationException(['That path belongs to the platform itself and cannot be redirected.']);
        }
        $status = $code === '302' ? 302 : 301;
        Database::run('INSERT INTO cms_redirects (from_path, to_url, status_code, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE to_url = VALUES(to_url), status_code = VALUES(status_code)', [$from, $to, $status, $actorId]);
        Audit::record('cms.redirect_saved', 'cms_redirect', null, [], ['from' => $from, 'to' => $to], [], $actorId);
    }

    public static function deleteRedirect(int $actorId, int $id): void
    {
        Gate::authorize('cms.publish', $actorId);
        Database::run('DELETE FROM cms_redirects WHERE id = ?', [$id]);
        Audit::record('cms.redirect_deleted', 'cms_redirect', $id, [], [], [], $actorId);
    }

    // ------------------------------------------------------------------------------------------------ reusable blocks

    /** @return list<array<string,mixed>> */
    public static function reusable(): array
    {
        $rows = Database::all('SELECT * FROM cms_blocks ORDER BY name');
        foreach ($rows as &$r) {
            $r['data'] = (array) json_decode((string) $r['data_json'], true);
        }

        return $rows;
    }

    /** @param array<string,mixed> $in */
    public static function saveReusable(int $actorId, ?string $publicId, string $name, string $type, array $in): void
    {
        Gate::authorize('cms.edit', $actorId);
        $name = (string) FieldRules::line($name, 'Name', 3, 120);
        if ($type === 'reusable') {
            throw new ValidationException(['A reusable block cannot contain another reusable block.']);
        }
        $data = CmsBlocks::clean($type, $in);
        unset($data['type']);
        $existing = $publicId !== null ? Database::one('SELECT * FROM cms_blocks WHERE public_id = ?', [$publicId]) : null;
        if (Database::one('SELECT 1 AS x FROM cms_blocks WHERE name = ? AND id <> ?', [$name, $existing['id'] ?? 0]) !== null) {
            throw new ValidationException(['A reusable block with that name already exists.']);
        }
        if ($existing === null) {
            Database::run('INSERT INTO cms_blocks (public_id, name, block_type, data_json, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [uuid_hex(), $name, $type, json_encode($data, JSON_UNESCAPED_UNICODE), $actorId]);
        } else {
            Database::run('UPDATE cms_blocks SET name = ?, data_json = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, json_encode($data, JSON_UNESCAPED_UNICODE), $actorId, $existing['id']]);
        }
        Audit::record('cms.reusable_saved', 'cms_block', null, [], ['name' => $name, 'type' => $type], [], $actorId);
    }

    public static function deleteReusable(int $actorId, string $publicId): void
    {
        Gate::authorize('cms.edit', $actorId);
        if (Database::one('SELECT 1 AS x FROM cms_pages WHERE blocks_json LIKE ?', ['%"ref":"' . $publicId . '"%']) !== null) {
            throw new ValidationException(['This block is used on a page. Remove it from the page first.']);
        }
        Database::run('DELETE FROM cms_blocks WHERE public_id = ?', [$publicId]);
        Audit::record('cms.reusable_deleted', 'cms_block', null, [], [], ['public_id' => $publicId], $actorId);
    }

    // ------------------------------------------------------------------------------------------------ media library

    private static function publicDir(): string
    {
        return defined('VERTEX_PUBLIC') ? (string) VERTEX_PUBLIC : VERTEX_ROOT . '/public';
    }

    /** @return array<string,mixed>|null */
    public static function mediaByPublicId(string $id): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) ? Database::one('SELECT * FROM cms_media WHERE public_id = ?', [$id]) : null;
    }

    /** @param array<string,mixed> $m */
    public static function mediaUrl(array $m): string
    {
        return '/uploads/cms/' . substr((string) $m['created_at'], 0, 4) . '/' . $m['file_name'];
    }

    /** @return list<array<string,mixed>> */
    public static function media(): array
    {
        return Database::all('SELECT * FROM cms_media ORDER BY id DESC LIMIT 300');
    }

    /** @param array<string,mixed> $file one $_FILES entry */
    public static function upload(int $actorId, array $file, string $alt, bool $requireHttpUpload = true): array
    {
        Gate::authorize('cms.edit', $actorId);
        if (!RateLimiter::hit('cms_upload_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $alt = (string) (FieldRules::line($alt, 'Description of the picture', 3, 200, false) ?? '');
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || ($requireHttpUpload && !is_uploaded_file($tmp))) {
            throw new ValidationException(['Choose a picture to upload.']);
        }
        $size = (int) filesize($tmp);
        if ($size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new ValidationException(['The picture must be smaller than 5 MB.']);
        }
        $raw = (string) file_get_contents($tmp);
        $info = @getimagesizefromstring($raw);
        $mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = $info === false ? '' : (string) $info['mime'];
        if (!isset($mimes[$mime])) {
            throw new ValidationException(['Only JPEG, PNG, WebP or GIF pictures can be uploaded.']);
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1 || $w > 6000 || $h > 6000) {
            throw new ValidationException(['The picture is too large (at most 6000 x 6000 pixels).']);
        }
        $ext = $mimes[$mime];
        if (preg_match('/<\?(php|=)|<script/i', $raw)) {
            throw new ValidationException(['That file contains code and was refused.']);
        }
        if ($ext !== 'gif' && function_exists('imagecreatefromstring')) { // re-encode: drops metadata and anything hidden after the image data
            $img = @imagecreatefromstring($raw);
            if ($img === false) {
                throw new ValidationException(['The picture could not be read.']);
            }
            ob_start();
            match ($ext) {
                'jpg' => imagejpeg($img, null, 88),
                'png' => (function () use ($img): void { imagesavealpha($img, true); imagepng($img, null, 6); })(),
                default => imagewebp($img, null, 85),
            };
            $clean = (string) ob_get_clean();
            imagedestroy($img);
            if ($clean !== '') {
                $raw = $clean;
            }
        }
        $year = gmdate('Y');
        $dir = self::publicDir() . '/uploads/cms/' . $year;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException(['The uploads folder cannot be created on this server.']);
        }
        $guard = self::publicDir() . '/uploads/cms/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "# Pictures only: nothing in here may ever run as a script.\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\n");
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $raw) === false) {
            throw new ValidationException(['The picture could not be saved.']);
        }
        @chmod($dir . '/' . $name, 0644);
        $orig = mb_substr((string) preg_replace('/[^\w. -]/u', '_', (string) ($file['name'] ?? 'picture.' . $ext)), 0, 190);
        $pub = uuid_hex();
        Database::run('INSERT INTO cms_media (public_id, original_name, file_name, mime, size_bytes, width, height, alt_text, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$pub, $orig, $name, $mime, strlen($raw), $w, $h, $alt === '' ? null : $alt, $actorId]);
        Audit::record('cms.media_uploaded', 'cms_media', Database::insertId(), [], ['mime' => $mime, 'bytes' => strlen($raw)], [], $actorId);

        return ['public_id' => $pub, 'file_name' => $name];
    }

    public static function deleteMedia(int $actorId, string $publicId): void
    {
        Gate::authorize('cms.edit', $actorId);
        $m = self::mediaByPublicId($publicId) ?? throw new HttpException(404);
        $used = Database::one('SELECT 1 AS x FROM cms_pages WHERE blocks_json LIKE ? OR og_media_id = ?', ['%' . $publicId . '%', $m['id']]) ?? Database::one('SELECT 1 AS x FROM cms_blocks WHERE data_json LIKE ?', ['%' . $publicId . '%']) ?? Database::one('SELECT 1 AS x FROM cms_page_versions WHERE 0');
        if ($used !== null) {
            throw new ValidationException(['This picture is used on a page or block. Remove it there first.']);
        }
        Database::run('DELETE FROM cms_media WHERE id = ?', [$m['id']]);
        @unlink(self::publicDir() . self::mediaUrl($m));
        Audit::record('cms.media_deleted', 'cms_media', (int) $m['id'], [], [], [], $actorId);
    }
}
