<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/** Small helpers shared by every public website view: the company name, {tokens} in editable copy, safe formatting, links and the icon set. */
final class SiteText
{
    public static function company(): string
    {
        $n = trim((string) Config::get('site.company_name', ''));

        return $n !== '' ? $n : (string) Config::get('app.name', 'Our company');
    }

    /** Initials for the brand abbreviation (Krazy Integrated Solutions -> KIS). */
    public static function abbreviation(): string
    {
        $w = preg_split('/\s+/', self::company()) ?: [];

        return strtoupper(implode('', array_map(static fn (string $x): string => mb_substr($x, 0, 1), array_slice($w, 0, 4))));
    }

    /** Replaces {company} and {abbr} in editable copy. Everything else is left exactly as written. */
    public static function tokens(string $s): string
    {
        return strtr($s, ['{company}' => self::company(), '{abbr}' => self::abbreviation()]);
    }

    /** Plain text, escaped, with tokens replaced. */
    public static function line(?string $s): string
    {
        return htmlspecialchars(self::tokens((string) $s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Paragraphs, lists, **bold**, *italic* and [links](https://...) from the same safe formatter the CMS uses (no raw HTML is possible). */
    public static function rich(?string $s): string
    {
        return CmsText::render(self::tokens((string) $s));
    }

    /** @return list<list<string>> "A | B | C" lines split into trimmed columns, tokens replaced */
    public static function rows(?string $lines): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim((string) $lines)) ?: [] as $l) {
            if (trim($l) !== '') {
                $out[] = array_map(static fn (string $c): string => self::tokens(trim($c)), explode('|', $l));
            }
        }

        return $out;
    }

    /** @return list<string> one item per non-empty line */
    public static function items(?string $lines): array
    {
        return array_values(array_filter(array_map(static fn (string $l): string => self::tokens(trim($l)), preg_split('/\R/', trim((string) $lines)) ?: []), static fn (string $l): bool => $l !== ''));
    }

    /** A safe href: /path, https://, mailto:, tel:. Anything else becomes "#". */
    public static function href(?string $url): string
    {
        $u = CmsText::safeUrl((string) $url);

        return $u === null ? '#' : $u;
    }

    public static function slug(string $s): string
    {
        $s = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $s));

        return trim(mb_substr($s, 0, 70), '-');
    }

    /** @return string inline SVG for a named icon (static markup, never built from input) */
    public static function icon(string $name): string
    {
        $p = [
            'video' => '<rect x="3" y="5" width="14" height="14" rx="2"/><path d="m17 10 4-2.5v9L17 14"/>',
            'short' => '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="m11 10 3 2-3 2z"/>',
            'youtube' => '<rect x="2.5" y="5" width="19" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
            'social' => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
            'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-8 8"/>',
            'poster' => '<rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M8.5 7h7M8.5 11h7M8.5 15h4"/>',
            'design' => '<path d="m4 20 1-4L16.5 4.5a2 2 0 0 1 3 3L8 19z"/><path d="m14 7 3 3"/>',
            'write' => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 12h7M9 16h7"/>',
            'web' => '<rect x="2.5" y="4" width="19" height="16" rx="2"/><path d="M2.5 9h19M7 6.5h.01M10 6.5h.01"/><path d="m10 13-2 2 2 2m4-4 2 2-2 2"/>',
            'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/><circle cx="12" cy="12" r="2"/>',
            'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
            'check' => '<path d="m5 12 4.5 4.5L19 7"/>',
            'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'pin' => '<path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14a6 6 0 0 1 3 6"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
            'shield' => '<path d="M12 3 4.5 6v6c0 4.5 3.2 7.8 7.5 9 4.3-1.2 7.5-4.5 7.5-9V6z"/><path d="m9 12 2.2 2.2L15 10"/>',
            'chat' => '<path d="M4 5h16v11H9l-5 4z"/>',
            'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
            'loop' => '<path d="M4 12a8 8 0 0 1 14-5.3L20 9M20 12a8 8 0 0 1-14 5.3L4 15"/><path d="M20 4v5h-5M4 20v-5h5"/>',
        ];
        $body = $p[$name] ?? $p['spark'];

        return '<svg class="icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /** @return array<string,string> icon key => label, for the admin picker */
    public static function iconChoices(): array
    {
        return ['short' => 'Short video', 'youtube' => 'Long video', 'video' => 'Video', 'social' => 'Social media', 'image' => 'Image / thumbnail', 'poster' => 'Poster', 'design' => 'Design', 'write' => 'Writing', 'web' => 'Website', 'spark' => 'AI / spark', 'gear' => 'Automation', 'layers' => 'Layers', 'chat' => 'Support / chat', 'target' => 'Strategy', 'users' => 'People'];
    }
}
