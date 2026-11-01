<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** Cookies and storage added by optional integrations the Owner has switched on (filled in by the integrations register). */
final class CookieRegister
{
    /** @return list<array<string,string>> */
    public static function rows(): array
    {
        try {
            $out = [];
            foreach (Database::all("SELECT name, cookie_category, cookie_details FROM compliance_integrations WHERE is_active = 1 AND cookie_category IN ('preferences','analytics','marketing')") as $r) {
                foreach (preg_split('/\R/', (string) $r['cookie_details'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                    $p = array_map('trim', explode('|', $line));
                    $out[] = ['name' => $p[0] ?? '', 'category' => (string) $r['cookie_category'], 'purpose' => $p[1] ?? '', 'duration' => $p[2] ?? '', 'provider' => (string) $r['name'], 'type' => 'Cookie'];
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Enabling an optional integration asks everyone again: the consent version goes up by one per active optional integration. */
    public static function versionBump(): int
    {
        try {
            return (int) Database::one("SELECT COALESCE(SUM(consent_epoch), 0) AS n FROM compliance_integrations WHERE is_active = 1 AND cookie_category IN ('preferences','analytics','marketing')")['n'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Active integrations that load a script after consent. @return list<array{key:string,category:string,script_url:string}> */
    public static function scripts(): array
    {
        try {
            return array_map(static fn (array $r): array => ['key' => (string) $r['id'], 'category' => (string) $r['cookie_category'], 'script_url' => (string) $r['script_url']],
                Database::all("SELECT id, cookie_category, script_url FROM compliance_integrations WHERE is_active = 1 AND script_url <> '' AND cookie_category IN ('preferences','analytics','marketing')"));
        } catch (\Throwable) {
            return [];
        }
    }
}
