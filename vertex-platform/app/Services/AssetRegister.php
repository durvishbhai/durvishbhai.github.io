<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Register of the images, icons and fonts the website ships with, and where each came from. The system can list WHAT is there; only the Owner knows WHERE it came from
 * and under what licence, so every entry starts as "unverified" with empty source/licence fields. Nothing is guessed: an entry becomes "confirmed" only when the Owner
 * records a source and a licence (or evidence of ownership). Pictures uploaded through the website editor are the uploader's responsibility and are counted, not listed.
 */
final class AssetRegister
{
    public const KINDS = ['image' => 'Image', 'icon' => 'Icon', 'font' => 'Font', 'script' => 'Script', 'stylesheet' => 'Stylesheet', 'other' => 'Other'];
    public const STATUSES = ['unverified' => 'Licence not confirmed', 'confirmed' => 'Confirmed', 'replace' => 'Replace this asset', 'not_needed' => 'No licence needed (own work / system)'];

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/public/assets';
    }

    /** Adds a row for every image/icon/font file found that is not registered yet. Never changes a row the Owner has edited. */
    public static function sync(): int
    {
        $added = 0;
        $root = self::root();
        foreach (['img' => 'image', 'fonts' => 'font'] as $dir => $kind) {
            foreach (glob($root . '/' . $dir . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $base = basename($file);
                $rel = 'assets/' . $dir . '/' . $base;
                $k = $kind === 'image' && preg_match('/icon|favicon|pwa|badge|mark/i', $base) ? 'icon' : $kind;
                $n = Database::run(
                    'INSERT IGNORE INTO asset_register (asset_key, name, kind, location, status, created_at, updated_at) VALUES (?, ?, ?, ?, \'unverified\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [$rel, $base, $k, '/' . $rel],
                )->rowCount();
                $added += $n;
            }
        }
        if (Database::run("INSERT IGNORE INTO asset_register (asset_key, name, kind, location, source, licence, status, notes, created_at, updated_at) VALUES ('system:font-stack', 'System font stack', 'font', 'public/assets/css/app.css', 'Fonts already installed on the visitor\\'s device', 'None needed: no font file is shipped or downloaded', 'not_needed', 'The CSS names Inter, Segoe UI, system-ui and others, but does not include or download any font file. If Inter is not installed on the visitor\\'s device the next one is used.', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->rowCount() > 0) {
            $added++;
        }

        return $added;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        self::sync();

        return Database::all('SELECT * FROM asset_register ORDER BY FIELD(status, \'replace\', \'unverified\', \'confirmed\', \'not_needed\'), kind, name');
    }

    /** @return array{unverified:int,confirmed:int,replace:int,not_needed:int,uploads:int} */
    public static function summary(): array
    {
        self::sync();
        $c = ['unverified' => 0, 'confirmed' => 0, 'replace' => 0, 'not_needed' => 0];
        foreach (Database::all('SELECT status, COUNT(*) c FROM asset_register GROUP BY status') as $r) {
            $c[$r['status']] = (int) $r['c'];
        }
        try {
            $c['uploads'] = (int) Database::one('SELECT COUNT(*) c FROM cms_media')['c'];
        } catch (\Throwable) {
            $c['uploads'] = 0;
        }

        return $c;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM asset_register WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $in @return int */
    public static function save(int $actorId, ?int $id, array $in): int
    {
        Gate::authorize('compliance.manage', $actorId);
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $kind = (string) ($in['kind'] ?? 'other');
        $status = (string) ($in['status'] ?? 'unverified');
        $f = static fn (string $k, int $max): ?string => ($v = mb_substr(trim((string) ($in[$k] ?? '')), 0, $max)) === '' ? null : $v;
        $source = $f('source', 255);
        $licence = $f('licence', 190);
        $evidence = $f('evidence', 255);
        if ($name === '' || mb_strlen($name) > 190) {
            $errors[] = 'Give the asset a name (up to 190 characters).';
        }
        if (!isset(self::KINDS[$kind])) {
            $errors[] = 'Choose what kind of asset it is.';
        }
        if (!isset(self::STATUSES[$status])) {
            $errors[] = 'Choose a status.';
        }
        if ($status === 'confirmed' && ($source === null || ($licence === null && $evidence === null))) {
            $errors[] = 'To mark an asset Confirmed, record where it came from and either its licence or your evidence of ownership.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $old = $id === null ? null : (self::find($id) ?? throw new HttpException(404));
        $newId = Database::transaction(function () use ($actorId, $id, $name, $kind, $status, $source, $licence, $evidence, $in, $old): int {
            $loc = mb_substr(trim((string) ($in['location'] ?? '')), 0, 255);
            $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 4000);
            if ($id === null) {
                Database::run('INSERT INTO asset_register (name, kind, location, source, licence, evidence, status, notes, reviewed_by, reviewed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [$name, $kind, $loc === '' ? null : $loc, $source, $licence, $evidence, $status, $notes === '' ? null : $notes, $actorId]);
                $id = (int) Database::insertId();
            } else {
                Database::run('UPDATE asset_register SET name = ?, kind = ?, location = ?, source = ?, licence = ?, evidence = ?, status = ?, notes = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [$name, $kind, $loc === '' ? null : $loc, $source, $licence, $evidence, $status, $notes === '' ? null : $notes, $actorId, $id]);
            }
            Audit::record('compliance.asset_saved', 'asset_register', $id, ['status' => $old['status'] ?? null], ['status' => $status], ['name' => $name], $actorId);

            return $id;
        });

        return $newId;
    }
}
