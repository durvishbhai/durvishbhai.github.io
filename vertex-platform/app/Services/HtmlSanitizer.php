<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * Turns editor HTML into a small, safe subset. It is a WHITELIST: only the tags and attributes below survive; scripts, styles, frames, forms, event handlers,
 * inline styles, classes and unknown tags are dropped. Links may only be https, http (kept as given), mailto, tel, a path on this site or a #anchor, and always
 * get rel="noopener noreferrer". Headings get stable ids (used for the table of contents and for the required-sections check).
 */
final class HtmlSanitizer
{
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'em' => [], 'u' => [], 'sup' => [], 'sub' => [], 'code' => [], 'blockquote' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'a' => ['href', 'title'], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['scope'], 'td' => [],
    ];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'div' => 'p', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'strike' => 'u', 's' => 'u'];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template', 'canvas', 'audio', 'video', 'img', 'picture', 'source'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="vx-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('vx-root');
        if ($root === null) {
            return '';
        }
        self::walk($root, $doc);
        $used = [];
        foreach (['h2', 'h3', 'h4'] as $tag) {
            foreach (iterator_to_array($root->getElementsByTagName($tag)) as $h) {
                $id = (string) $h->getAttribute('id');
                if (!preg_match('/^[a-z0-9][a-z0-9-]{0,58}$/', $id)) {
                    $id = self::slug($h->textContent);
                }
                $base = $id;
                for ($i = 2; isset($used[$id]); $i++) {
                    $id = $base . '-' . $i;
                }
                $used[$id] = true;
                $h->setAttribute('id', $id);
            }
        }
        $out = '';
        foreach ($root->childNodes as $n) {
            $out .= $doc->saveHTML($n);
        }
        $out = (string) preg_replace('~<p>(\s|&nbsp;|<br>)*</p>~i', '', $out);

        return trim($out);
    }

    public static function slug(string $text): string
    {
        $s = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($text)));
        $s = trim($s, '-');

        return $s === '' ? 'section' : substr($s, 0, 58);
    }

    /** @return list<string> heading ids present (h2/h3) */
    public static function headingIds(string $html): array
    {
        return preg_match_all('/<h[23] id="([a-z0-9-]+)"/', $html, $m) ? $m[1] : [];
    }

    private static function walk(\DOMNode $node, \DOMDocument $doc): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (in_array($name, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            $name = self::RENAME[$name] ?? $name;
            if (!isset(self::TAGS[$name])) {
                self::walk($child, $doc); // unknown wrapper (span, font, section ...): keep its content, drop the tag
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            if ($name !== strtolower($child->nodeName)) {
                $new = $doc->createElement($name);
                foreach ($child->attributes as $a) {
                    $new->setAttribute($a->nodeName, $a->nodeValue);
                }
                while ($child->firstChild !== null) {
                    $new->appendChild($child->firstChild);
                }
                $node->replaceChild($new, $child);
                $child = $new;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $an = strtolower($attr->nodeName);
                if (!in_array($an, self::TAGS[$name], true)) {
                    $child->removeAttribute($attr->nodeName);
                }
            }
            if ($name === 'a') {
                $href = trim((string) $child->getAttribute('href'));
                if (!self::safeHref($href)) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }
            if ($name === 'th' && !in_array($child->getAttribute('scope'), ['row', 'col'], true)) {
                $child->removeAttribute('scope');
            }
            self::walk($child, $doc);
        }
    }

    private static function safeHref(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x1f\s]/', $href)) {
            return false;
        }

        return (bool) preg_match('~^(https?://[^\s]+|mailto:[^\s@]+@[^\s@]+|tel:\+?[0-9 ()-]+|/[^/\\\\][^\s]*|/|#[a-z0-9_-]+)$~i', $href);
    }
}
