<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Create, edit, publish, order and remove the website's lists, and read back only what is published for visitors.
 *
 *  - `cms.edit` (policies: `legal.manage`) writes drafts; `cms.publish` publishes, unpublishes, archives, deletes. A draft is never exposed by any public query.
 *  - Editable text is stored as typed, escaped at display time and only lightly formatted by CmsText, so no editor can inject markup or script.
 *  - Policies keep every saved version, can be restored, and cannot be published while an [ADMIN TO COMPLETE] note remains in the text.
 */
final class SiteContent
{
    // ------------------------------------------------------------------------------------------------ reading (admin)

    /** @return list<array<string,mixed>> */
    public static function rows(string $entity, string $status = ''): array
    {
        $d = SiteEntities::def($entity);
        $w = $status !== '' && in_array($status, $d['statuses'], true) ? ' WHERE status = ' . Database::connection()->quote($status) : '';
        $order = $entity === 'policies' ? 'title' : ($entity === 'faqs' ? 'category, position, id' : ($entity === 'team' ? 'FIELD(department, \'leadership\',\'creative\',\'development\',\'operations\'), position, id' : 'position, id'));

        return Database::all("SELECT * FROM {$d['table']}{$w} ORDER BY {$order}");
    }

    /** @return array<string,mixed>|null */
    public static function find(string $entity, string $publicId): ?array
    {
        $d = SiteEntities::def($entity);

        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one("SELECT * FROM {$d['table']} WHERE public_id = ?", [$publicId]) : null;
    }

    /** Values for the edit form: column values by FORM field name (media as public ids, gallery as arrays, money in rupees). @param array<string,mixed> $row @return array<string,mixed> */
    public static function formValues(string $entity, array $row): array
    {
        $out = [];
        foreach (SiteEntities::def($entity)['fields'] as $name => $f) {
            $col = $f['col'] ?? $name;
            $v = $row[$col] ?? null;
            $out[$name] = match ($f['kind']) {
                'media' => $v === null ? '' : (string) (Database::one('SELECT public_id FROM cms_media WHERE id = ?', [$v])['public_id'] ?? ''),
                'gallery' => array_values((array) json_decode((string) $v, true)),
                'multi' => $v === null || $v === '' ? [] : explode(',', (string) $v),
                'money' => $v === null ? '' : number_format(((int) $v) / 100, 2, '.', ''),
                'bool' => (int) $v === 1,
                default => (string) ($v ?? ''),
            };
        }
        if (SiteEntities::def($entity)['slug'] ?? false) {
            $out['slug'] = (string) ($row['slug'] ?? '');
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ writing

    /** @param array<string,mixed> $in posted form @return array<string,mixed> the saved row */
    public static function save(int $actorId, string $entity, ?string $publicId, array $in): array
    {
        $d = SiteEntities::def($entity);
        Gate::authorize($d['edit'], $actorId);
        $existing = $publicId !== null ? (self::find($entity, $publicId) ?? throw new HttpException(404)) : null;
        if ($existing === null && !empty($d['noCreate'])) {
            throw new HttpException(404);
        }
        [$cols, $errors] = self::collect($entity, $d, $in, $existing);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($entity === 'policies') {
            return self::savePolicy($actorId, $existing, $cols);
        }
        if ($existing !== null && $existing['status'] === 'published' && !Gate::can($d['publish'], $actorId)) {
            throw new HttpException(403, 'This item is live. Changing it needs publish rights.');
        }

        return Database::transaction(function () use ($actorId, $entity, $d, $existing, $cols): array {
            if ($existing === null) {
                $cols['public_id'] = uuid_hex();
                $cols['created_by'] = $actorId;
                $cols['position'] = (int) Database::one("SELECT COALESCE(MAX(position), 0) + 10 AS p FROM {$d['table']}")['p'];
                $names = array_keys($cols);
                Database::run("INSERT INTO {$d['table']} (" . implode(', ', $names) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())', array_values($cols));
                $id = Database::insertId();
                Audit::record('site.created', 'site_' . $entity, $id, [], ['title' => (string) ($cols[$d['title']] ?? '')], [], $actorId);

                return Database::one("SELECT * FROM {$d['table']} WHERE id = ?", [$id]);
            }
            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($cols)));
            Database::run("UPDATE {$d['table']} SET {$set}, updated_at = UTC_TIMESTAMP() WHERE id = ?", [...array_values($cols), $existing['id']]);
            Audit::record('site.updated', 'site_' . $entity, (int) $existing['id'], [], ['title' => (string) ($cols[$d['title']] ?? $existing[$d['title']])], ['status' => $existing['status']], $actorId);

            return Database::one("SELECT * FROM {$d['table']} WHERE id = ?", [$existing['id']]);
        });
    }

    /**
     * @param array<string,mixed> $d @param array<string,mixed> $in @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private static function collect(string $entity, array $d, array $in, ?array $existing): array
    {
        $cols = [];
        $errors = [];
        foreach ($d['fields'] as $name => $f) {
            $label = $f['label'];
            $col = $f['col'] ?? $name;
            $raw = $in[$name] ?? null;
            $v = is_array($raw) ? $raw : trim((string) $raw);
            switch ($f['kind']) {
                case 'bool':
                    $cols[$col] = !empty($raw) && $raw !== '0' ? 1 : 0;
                    continue 2;
                case 'gallery':
                    $ids = [];
                    foreach ((array) $v as $g) {
                        $g = (string) $g;
                        if (preg_match('/^[a-f0-9]{32}$/', $g) && CmsService::mediaByPublicId($g) !== null && !in_array($g, $ids, true)) {
                            $ids[] = $g;
                        }
                    }
                    $cols[$col] = $ids === [] ? null : json_encode(array_slice($ids, 0, 12));
                    continue 2;
                case 'multi':
                    $allowed = $f['options'] === 'services' ? array_column(Database::all('SELECT slug FROM site_services'), 'slug') : [];
                    $pick = array_values(array_unique(array_filter(array_map('strval', (array) $v), static fn (string $s): bool => in_array($s, $allowed, true) && ($existing === null || $s !== ($existing['slug'] ?? '')))));
                    $cols[$col] = $pick === [] ? null : implode(',', array_slice($pick, 0, 6));
                    continue 2;
            }
            $v = (string) $v;
            if ($v === '') {
                if (!empty($f['required'])) {
                    $errors[] = "{$label} is required.";
                }
                $cols[$col] = null;
                continue;
            }
            if (isset($f['max']) && mb_strlen($v) > (int) $f['max']) {
                $errors[] = "{$label} is too long (at most {$f['max']} characters).";
                continue;
            }
            switch ($f['kind']) {
                case 'text':
                    if (preg_match('/[<>\r\n]/', $v)) {
                        $errors[] = "{$label} must be one line without < or >.";
                        continue 2;
                    }
                    break;
                case 'lines':
                    if (count(array_filter(preg_split('/\R/', $v) ?: [])) > 60) {
                        $errors[] = "{$label}: at most 60 lines.";
                        continue 2;
                    }
                    break;
                case 'select':
                    if (!isset($f['options'][$v])) {
                        $errors[] = "{$label}: choose one of the listed options.";
                        continue 2;
                    }
                    break;
                case 'url':
                    if (!preg_match('#^https://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/\S*)?$#', $v) || preg_match('/[<>"\']/', $v)) {
                        $errors[] = "{$label} must start with https://";
                        continue 2;
                    }
                    break;
                case 'media':
                    $m = CmsService::mediaByPublicId($v);
                    if ($m === null) {
                        $errors[] = "{$label}: choose a picture from the media library.";
                        continue 2;
                    }
                    $v = (string) $m['id'];
                    break;
                case 'date':
                    try {
                        $v = (string) FieldRules::date($v, $label, false);
                    } catch (ValidationException $e) {
                        array_push($errors, ...$e->errors);
                        continue 2;
                    }
                    break;
                case 'money':
                    try {
                        $v = (string) FieldRules::moneyMinor($v, $label, false);
                    } catch (ValidationException $e) {
                        array_push($errors, ...$e->errors);
                        continue 2;
                    }
                    break;
            }
            $cols[$col] = $v;
        }
        if ($d['slug'] ?? false) {
            $base = trim((string) ($in['slug'] ?? ''));
            $slug = SiteText::slug($base !== '' ? $base : (string) ($cols[$d['title']] ?? ''));
            if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $slug)) {
                $errors[] = 'The address may use letters, digits and dashes (at least 2 characters).';
            } elseif (Database::one("SELECT 1 AS x FROM {$d['table']} WHERE slug = ? AND id <> ?", [$slug, $existing['id'] ?? 0]) !== null) {
                $errors[] = 'Another item already uses that address.';
            }
            $cols['slug'] = $slug;
        }
        if ($entity === 'services' && !empty($cols['show_price']) && ($cols['starting_price_minor'] ?? null) === null) {
            $errors[] = 'Enter the starting price, or untick "Show a starting price".';
        }

        return [$cols, $errors];
    }

    /** @param array<string,mixed> $row */
    public static function setStatus(int $actorId, string $entity, array $row, string $to): void
    {
        $d = SiteEntities::def($entity);
        Gate::authorize($d['publish'], $actorId);
        if ($entity === 'policies') {
            Gate::authorize('cms.publish', $actorId);
        }
        if (!in_array($to, $d['statuses'], true)) {
            throw new HttpException(422);
        }
        if ($to === 'published') {
            if ($entity === 'policies' && preg_match('/\[ADMIN TO COMPLETE/i', (string) $row['body'])) {
                throw new ValidationException(['This policy still contains an [ADMIN TO COMPLETE] note. Fill in the missing details and delete that line before publishing.']);
            }
            if ($entity === 'services' && trim((string) $row['summary']) === '') {
                throw new ValidationException(['Add a short description before publishing.']);
            }
        }
        Database::transaction(function () use ($actorId, $entity, $d, $row, $to): void {
            Database::run("UPDATE {$d['table']} SET status = ?, updated_at = UTC_TIMESTAMP()" . ($entity === 'policies' && $to === 'published' ? ', published_at = COALESCE(published_at, UTC_TIMESTAMP())' : '') . ' WHERE id = ?', [$to, $row['id']]);
            Audit::record('site.' . $to, 'site_' . $entity, (int) $row['id'], ['status' => $row['status']], ['status' => $to], ['title' => (string) $row[$d['title']]], $actorId);
        });
    }

    /** @param array<string,mixed> $row */
    public static function delete(int $actorId, string $entity, array $row): void
    {
        $d = SiteEntities::def($entity);
        Gate::authorize($d['publish'], $actorId);
        if (!empty($d['noDelete'])) {
            throw new HttpException(404);
        }
        Database::transaction(function () use ($actorId, $entity, $d, $row): void {
            Database::run("DELETE FROM {$d['table']} WHERE id = ?", [$row['id']]);
            Audit::record('site.deleted', 'site_' . $entity, (int) $row['id'], ['status' => $row['status']], [], ['title' => (string) $row[$d['title']]], $actorId);
        });
    }

    /** @param array<string,mixed> $row */
    public static function move(int $actorId, string $entity, array $row, string $dir): void
    {
        $d = SiteEntities::def($entity);
        Gate::authorize($d['edit'], $actorId);
        $ids = array_map('intval', array_column(Database::all("SELECT id FROM {$d['table']} ORDER BY position, id"), 'id'));
        $i = array_search((int) $row['id'], $ids, true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i === false || !isset($ids[$j])) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        Database::transaction(function () use ($d, $ids): void {
            foreach ($ids as $n => $id) {
                Database::run("UPDATE {$d['table']} SET position = ? WHERE id = ?", [($n + 1) * 10, $id]);
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ policies

    /** @param array<string,mixed>|null $existing @param array<string,mixed> $cols @return array<string,mixed> */
    private static function savePolicy(int $actorId, ?array $existing, array $cols): array
    {
        $p = $existing ?? throw new HttpException(404);
        if ($cols['title'] === $p['title'] && $cols['body'] === $p['body'] && ($cols['effective_date'] ?? null) === $p['effective_date']) {
            return $p;
        }

        return Database::transaction(function () use ($actorId, $p, $cols): array {
            $v = (int) $p['version_no'] + 1;
            Database::run('UPDATE site_policies SET title = ?, body = ?, effective_date = ?, version_no = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$cols['title'], $cols['body'], $cols['effective_date'] ?? null, $v, $actorId, $p['id']]);
            self::policyVersion((int) $p['id'], $v, (string) $cols['title'], (string) $cols['body'], $cols['effective_date'] ?? null, 'Edited', $actorId);
            Audit::record('site.policy_updated', 'site_policies', (int) $p['id'], [], ['version' => $v], ['status' => $p['status']], $actorId);

            return Database::one('SELECT * FROM site_policies WHERE id = ?', [$p['id']]);
        });
    }

    private static function policyVersion(int $id, int $no, string $title, string $body, ?string $effective, string $note, ?int $actor): void
    {
        Database::run('INSERT INTO site_policy_versions (policy_id, version_no, title, body, effective_date, note, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$id, $no, $title, $body, $effective, $note, $actor]);
    }

    /** @return list<array<string,mixed>> */
    public static function policyVersions(array $policy): array
    {
        return Database::all('SELECT v.version_no, v.title, v.effective_date, v.note, v.created_at, u.name AS by_name FROM site_policy_versions v LEFT JOIN users u ON u.id = v.changed_by WHERE v.policy_id = ? ORDER BY v.version_no DESC LIMIT 100', [$policy['id']]);
    }

    public static function restorePolicy(int $actorId, array $policy, int $versionNo): void
    {
        Gate::authorize('legal.manage', $actorId);
        $v = Database::one('SELECT * FROM site_policy_versions WHERE policy_id = ? AND version_no = ?', [$policy['id'], $versionNo]) ?? throw new HttpException(404);
        Database::transaction(function () use ($actorId, $policy, $v): void {
            $n = (int) $policy['version_no'] + 1;
            Database::run('UPDATE site_policies SET title = ?, body = ?, effective_date = ?, version_no = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$v['title'], $v['body'], $v['effective_date'], $n, $actorId, $policy['id']]);
            self::policyVersion((int) $policy['id'], $n, (string) $v['title'], (string) $v['body'], $v['effective_date'], 'Restored version ' . $v['version_no'], $actorId);
            Audit::record('site.policy_restored', 'site_policies', (int) $policy['id'], [], ['version' => $n, 'from' => (int) $v['version_no']], [], $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ public side (only ever published rows)

    /** @return list<array<string,mixed>> */
    public static function published(string $entity): array
    {
        $d = SiteEntities::def($entity);
        $extra = $entity === 'jobs' ? ' AND (deadline IS NULL OR deadline >= ' . Database::connection()->quote(AppTime::today()) . ')' : '';
        $order = $entity === 'faqs' ? 'category, position, id' : ($entity === 'team' ? "FIELD(department, 'leadership','creative','development','operations'), position, id" : ($entity === 'policies' ? 'title' : 'position, id'));
        try {
            return Database::all("SELECT * FROM {$d['table']} WHERE status = 'published'{$extra} ORDER BY {$order}");
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public static function publishedBySlug(string $entity, string $slug): ?array
    {
        $d = SiteEntities::def($entity);
        $extra = $entity === 'jobs' ? ' AND (deadline IS NULL OR deadline >= ' . Database::connection()->quote(AppTime::today()) . ')' : '';
        $col = $entity === 'policies' ? 'slug' : 'slug';

        return preg_match('/^[a-z0-9-]{1,80}$/', $slug) ? Database::one("SELECT * FROM {$d['table']} WHERE {$col} = ? AND status = 'published'{$extra}", [$slug]) : null;
    }

    public static function hasPublished(string $entity): bool
    {
        return self::published($entity) !== [];
    }

    /** @return array<string,array{total:int,published:int}> */
    public static function counts(): array
    {
        $out = [];
        foreach (SiteEntities::defs() as $k => $d) {
            $r = Database::one("SELECT COUNT(*) AS t, COALESCE(SUM(status = 'published'), 0) AS p FROM {$d['table']}");
            $out[$k] = ['total' => (int) $r['t'], 'published' => (int) $r['p']];
        }

        return $out;
    }

    /** URL of the first picture of a media field, or '' @param array<string,mixed> $row */
    public static function imageUrl(array $row, string $col): string
    {
        if (empty($row[$col])) {
            return '';
        }
        $m = Database::one('SELECT * FROM cms_media WHERE id = ?', [$row[$col]]);

        return $m === null ? '' : CmsService::mediaUrl($m);
    }

    /** @return array{url:string,alt:string,w:int,h:int}|null */
    public static function image(array $row, string $col): ?array
    {
        if (empty($row[$col])) {
            return null;
        }
        $m = Database::one('SELECT * FROM cms_media WHERE id = ?', [$row[$col]]);

        return $m === null ? null : ['url' => CmsService::mediaUrl($m), 'alt' => (string) $m['alt_text'], 'w' => (int) $m['width'], 'h' => (int) $m['height']];
    }

    /** @return list<array{url:string,alt:string,w:int,h:int}> */
    public static function gallery(array $row): array
    {
        $out = [];
        foreach ((array) json_decode((string) ($row['gallery_json'] ?? ''), true) as $id) {
            $m = CmsService::mediaByPublicId((string) $id);
            if ($m !== null) {
                $out[] = ['url' => CmsService::mediaUrl($m), 'alt' => (string) $m['alt_text'], 'w' => (int) $m['width'], 'h' => (int) $m['height']];
            }
        }

        return $out;
    }
}
