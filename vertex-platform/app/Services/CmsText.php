<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * A deliberately small, safe text format for website content: paragraphs, "## heading" / "### heading", "- list items", **bold**, *italic* and
 * [text](address) links (https://, mailto:, tel: or a path starting with /). Everything is HTML-escaped FIRST and only then are these patterns turned into tags,
 * so no editor, however trusted, can inject a script, an attribute or a style. There is no raw-HTML escape hatch.
 */
final class CmsText
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function safeUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '' || preg_match('/[\s<>"\'`\\\\]/', $url)) {
            return null;
        }
        if (preg_match('#^(https?://[A-Za-z0-9][A-Za-z0-9.\-]*(:\d+)?(/[^\s]*)?|mailto:[^\s@]+@[^\s@]+\.[^\s@]+|tel:\+?[0-9\- ]{5,20}|/(?!/)[^\s]*|\#[A-Za-z0-9_\-]+)$#', $url)) {
            return $url;
        }

        return null;
    }

    private static function inline(string $escaped): string
    {
        $escaped = (string) preg_replace_callback('/\[([^\]\n]{1,200})\]\(([^)\s]{1,500})\)/', static function (array $m): string {
            $url = self::safeUrl($m[2]);
            if ($url === null) {
                return $m[1];
            }
            $external = str_starts_with($url, 'http');

            return '<a href="' . self::e($url) . '"' . ($external ? ' rel="noopener noreferrer" target="_blank"' : '') . '>' . $m[1] . '</a>';
        }, $escaped);
        $escaped = (string) preg_replace('/\*\*([^*\n]{1,300})\*\*/', '<strong>$1</strong>', $escaped);

        return (string) preg_replace('/(?<![*\w])\*([^*\n]{1,300})\*(?![*\w])/', '<em>$1</em>', $escaped);
    }

    public static function render(string $text): string
    {
        $out = [];
        $list = [];
        $flush = static function () use (&$list, &$out): void {
            if ($list !== []) {
                $out[] = '<ul>' . implode('', array_map(static fn (string $i): string => '<li>' . $i . '</li>', $list)) . '</ul>';
                $list = [];
            }
        };
        $para = [];
        $flushPara = static function () use (&$para, &$out): void {
            if ($para !== []) {
                $out[] = '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $flushPara();
                $flush();
                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m)) {
                $flushPara();
                $flush();
                $lvl = strlen($m[1]);
                $out[] = "<h{$lvl}>" . self::inline(self::e($m[2])) . "</h{$lvl}>";
            } elseif (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $flushPara();
                $list[] = self::inline(self::e($m[1]));
            } else {
                $flush();
                $para[] = self::inline(self::e($line));
            }
        }
        $flushPara();
        $flush();

        return implode("\n", $out);
    }
}
