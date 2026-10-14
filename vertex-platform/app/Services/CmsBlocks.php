<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/** The building blocks of a website page: their fields, validation and HTML. Output is built from escaped values only (see CmsText). */
final class CmsBlocks
{
    /** @return array<string,array{label:string,help:string,fields:array<string,array{label:string,kind:string,required?:bool,max?:int,help?:string}>}> */
    public static function types(): array
    {
        return [
            'hero' => ['label' => 'Hero (big opening banner)', 'help' => 'The first thing visitors see.', 'fields' => [
                'heading' => ['label' => 'Heading', 'kind' => 'text', 'required' => true, 'max' => 160], 'subheading' => ['label' => 'Sub-heading', 'kind' => 'textarea', 'max' => 400],
                'button_label' => ['label' => 'Button text', 'kind' => 'text', 'max' => 40], 'button_url' => ['label' => 'Button address', 'kind' => 'url', 'max' => 300], 'image' => ['label' => 'Picture', 'kind' => 'media']]],
            'text' => ['label' => 'Text', 'help' => 'Paragraphs, ## headings, - lists, **bold**, *italic*, [links](https://...).', 'fields' => [
                'heading' => ['label' => 'Heading (optional)', 'kind' => 'text', 'max' => 160], 'body' => ['label' => 'Text', 'kind' => 'textarea', 'required' => true, 'max' => 20000]]],
            'features' => ['label' => 'Feature list', 'help' => 'One feature per line: Title | Short text', 'fields' => [
                'heading' => ['label' => 'Heading (optional)', 'kind' => 'text', 'max' => 160], 'items' => ['label' => 'Features', 'kind' => 'lines', 'required' => true, 'max' => 12000, 'help' => 'Title | Short text']]],
            'services' => ['label' => 'Services', 'help' => 'One service per line: Title | Short text | /page-address (address optional)', 'fields' => [
                'heading' => ['label' => 'Heading (optional)', 'kind' => 'text', 'max' => 160], 'items' => ['label' => 'Services', 'kind' => 'lines', 'required' => true, 'max' => 12000, 'help' => 'Title | Short text | /address']]],
            'portfolio' => ['label' => 'Portfolio', 'help' => 'One project per line: Title | Short text | https://link (link optional)', 'fields' => [
                'heading' => ['label' => 'Heading (optional)', 'kind' => 'text', 'max' => 160], 'items' => ['label' => 'Projects', 'kind' => 'lines', 'required' => true, 'max' => 12000, 'help' => 'Title | Short text | https://link']]],
            'cta' => ['label' => 'Call to action', 'help' => 'A line of text with one button.', 'fields' => [
                'heading' => ['label' => 'Heading', 'kind' => 'text', 'required' => true, 'max' => 160], 'text' => ['label' => 'Text', 'kind' => 'textarea', 'max' => 500],
                'button_label' => ['label' => 'Button text', 'kind' => 'text', 'required' => true, 'max' => 40], 'button_url' => ['label' => 'Button address', 'kind' => 'url', 'required' => true, 'max' => 300]]],
            'faq' => ['label' => 'Questions and answers', 'help' => 'One per line: Question | Answer', 'fields' => [
                'heading' => ['label' => 'Heading (optional)', 'kind' => 'text', 'max' => 160], 'items' => ['label' => 'Questions', 'kind' => 'lines', 'required' => true, 'max' => 12000, 'help' => 'Question | Answer']]],
            'image' => ['label' => 'Picture', 'help' => 'One picture from the media library.', 'fields' => [
                'image' => ['label' => 'Picture', 'kind' => 'media', 'required' => true], 'caption' => ['label' => 'Caption (optional)', 'kind' => 'text', 'max' => 200]]],
            'contact' => ['label' => 'Contact details', 'help' => 'Shows the company email, phone, address and links from Integrations & settings.', 'fields' => [
                'heading' => ['label' => 'Heading', 'kind' => 'text', 'max' => 160]]],
            'form' => ['label' => 'Link to a form', 'help' => 'A box that sends visitors to one of your forms (Forms menu).', 'fields' => [
                'heading' => ['label' => 'Heading', 'kind' => 'text', 'required' => true, 'max' => 160], 'text' => ['label' => 'Text', 'kind' => 'textarea', 'max' => 500],
                'form_slug' => ['label' => 'Form address (the part after /f/)', 'kind' => 'text', 'required' => true, 'max' => 60], 'button_label' => ['label' => 'Button text', 'kind' => 'text', 'max' => 40]]],
            'reusable' => ['label' => 'Reusable block', 'help' => 'Shows a block from the reusable list; editing it there changes every page that uses it.', 'fields' => [
                'ref' => ['label' => 'Reusable block', 'kind' => 'reusable', 'required' => true]]],
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> clean data, or throws */
    public static function clean(string $type, array $in): array
    {
        $def = self::types()[$type] ?? throw new ValidationException(['Choose a block type.']);
        $errors = [];
        $out = ['type' => $type];
        foreach ($def['fields'] as $name => $f) {
            $v = trim((string) ($in[$name] ?? ''));
            $label = $f['label'];
            if ($v === '') {
                if (!empty($f['required'])) {
                    $errors[] = "{$label} is required.";
                }
                $out[$name] = '';
                continue;
            }
            if (mb_strlen($v) > ($f['max'] ?? 300) && $f['kind'] !== 'media' && $f['kind'] !== 'reusable') {
                $errors[] = "{$label} is too long.";
                continue;
            }
            switch ($f['kind']) {
                case 'url':
                    if (CmsText::safeUrl($v) === null) {
                        $errors[] = "{$label} must start with https://, mailto:, tel: or / .";
                        continue 2;
                    }
                    break;
                case 'media':
                    if (!preg_match('/^[a-f0-9]{32}$/', $v) || Database::one('SELECT 1 AS x FROM cms_media WHERE public_id = ?', [$v]) === null) {
                        $errors[] = "{$label}: choose a picture from the media library.";
                        continue 2;
                    }
                    break;
                case 'reusable':
                    if (!preg_match('/^[a-f0-9]{32}$/', $v) || Database::one('SELECT 1 AS x FROM cms_blocks WHERE public_id = ?', [$v]) === null) {
                        $errors[] = "{$label}: choose a reusable block.";
                        continue 2;
                    }
                    break;
                case 'lines':
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $v) ?: []), static fn (string $l): bool => $l !== ''));
                    if (count($lines) > 40) {
                        $errors[] = "{$label}: at most 40 lines.";
                        continue 2;
                    }
                    $v = implode("\n", $lines);
                    break;
                default:
                    if ($f['kind'] === 'text' && str_contains($v, "\n")) {
                        $errors[] = "{$label} must be on one line.";
                        continue 2;
                    }
            }
            if ($f['kind'] !== 'textarea' && $f['kind'] !== 'lines' && preg_match('/[<>]/', $v)) {
                $errors[] = "{$label} must not contain < or >.";
                continue;
            }
            $out[$name] = $v;
        }
        if ($type === 'form' && ($out['form_slug'] ?? '') !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{2,59}$/', (string) $out['form_slug'])) {
            $errors[] = 'The form address is not valid.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    /** One-line summary for the editor list. @param array<string,mixed> $b */
    public static function summary(array $b): string
    {
        $t = self::types()[$b['type']]['label'] ?? $b['type'];
        $s = (string) ($b['heading'] ?? $b['caption'] ?? $b['ref'] ?? mb_substr((string) ($b['body'] ?? ''), 0, 60));

        return $t . ($s !== '' ? ': ' . mb_substr($s, 0, 70) : '');
    }

    private static function e(string $s): string
    {
        return CmsText::e($s);
    }

    private static function img(string $mediaId, string $class = ''): string
    {
        $m = CmsService::mediaByPublicId($mediaId);
        if ($m === null) {
            return '';
        }

        return '<img' . ($class !== '' ? ' class="' . $class . '"' : '') . ' src="' . self::e(CmsService::mediaUrl($m)) . '" alt="' . self::e((string) $m['alt_text']) . '" loading="lazy"' . ($m['width'] ? ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"' : '') . '>';
    }

    /** @return list<list<string>> */
    private static function rows(string $lines): array
    {
        return array_map(static fn (string $l): array => array_map('trim', explode('|', $l)), array_values(array_filter(array_map('trim', preg_split('/\R/', $lines) ?: []), static fn (string $l): bool => $l !== '')));
    }

    private static function button(string $label, string $url): string
    {
        $u = CmsText::safeUrl($url);

        return $label !== '' && $u !== null ? '<p><a class="btn btn-primary" href="' . self::e($u) . '"' . (str_starts_with($u, 'http') ? ' rel="noopener noreferrer"' : '') . '>' . self::e($label) . '</a></p>' : '';
    }

    /** @param array<string,mixed> $b @param int $depth guards reusable blocks that point at each other */
    public static function html(array $b, int $depth = 0): string
    {
        $h = fn (string $k): string => (string) ($b[$k] ?? '');
        $heading = static fn (string $t, string $tag = 'h2'): string => $t === '' ? '' : "<{$tag}>" . self::e($t) . "</{$tag}>";
        switch ($b['type'] ?? '') {
            case 'hero':
                return '<section class="cms-block cms-hero"><div class="container">' . '<div class="cms-hero-text">' . $heading($h('heading'), 'h1') . ($h('subheading') !== '' ? '<p class="lead">' . self::e($h('subheading')) . '</p>' : '') . self::button($h('button_label'), $h('button_url')) . '</div>'
                    . ($h('image') !== '' ? '<div class="cms-hero-img">' . self::img($h('image')) . '</div>' : '') . '</div></section>';
            case 'text':
                return '<section class="cms-block cms-text"><div class="container narrow">' . $heading($h('heading')) . CmsText::render($h('body')) . '</div></section>';
            case 'features':
            case 'services':
            case 'portfolio':
                $cards = '';
                foreach (self::rows($h('items')) as $r) {
                    $link = $b['type'] === 'portfolio' ? ($r[2] ?? '') : ($r[2] ?? '');
                    $u = $link !== '' ? CmsText::safeUrl($link) : null;
                    $cards .= '<div class="cms-card"><h3>' . self::e($r[0] ?? '') . '</h3>' . (($r[1] ?? '') !== '' ? '<p>' . self::e($r[1]) . '</p>' : '') . ($u !== null ? '<p><a href="' . self::e($u) . '"' . (str_starts_with($u, 'http') ? ' rel="noopener noreferrer" target="_blank"' : '') . '>Learn more</a></p>' : '') . '</div>';
                }

                return '<section class="cms-block cms-grid"><div class="container">' . $heading($h('heading')) . '<div class="cms-cards">' . $cards . '</div></div></section>';
            case 'cta':
                return '<section class="cms-block cms-cta"><div class="container narrow">' . $heading($h('heading')) . ($h('text') !== '' ? '<p>' . self::e($h('text')) . '</p>' : '') . self::button($h('button_label'), $h('button_url')) . '</div></section>';
            case 'faq':
                $items = '';
                foreach (self::rows($h('items')) as $r) {
                    $items .= '<details class="cms-faq"><summary>' . self::e($r[0] ?? '') . '</summary><p>' . self::e($r[1] ?? '') . '</p></details>';
                }

                return '<section class="cms-block"><div class="container narrow">' . $heading($h('heading')) . $items . '</div></section>';
            case 'image':
                return '<section class="cms-block"><div class="container narrow"><figure>' . self::img($h('image')) . ($h('caption') !== '' ? '<figcaption>' . self::e($h('caption')) . '</figcaption>' : '') . '</figure></div></section>';
            case 'contact':
                $c = (array) \Vertex\Core\Config::get('site', []);
                $li = '';
                foreach ([['Email', $c['email'] ?? '', 'mailto:'], ['Phone', $c['phone'] ?? '', 'tel:'], ['WhatsApp', $c['whatsapp'] ?? '', 'https://wa.me/']] as [$l, $v, $pre]) {
                    if ($v !== '') {
                        $href = $pre === 'tel:' ? 'tel:' . preg_replace('/[^0-9+]/', '', (string) $v) : ($pre === 'https://wa.me/' ? 'https://wa.me/' . preg_replace('/\D/', '', (string) $v) : $pre . $v);
                        $li .= '<li><strong>' . $l . ':</strong> <a href="' . self::e($href) . '">' . self::e((string) $v) . '</a></li>';
                    }
                }
                if (($c['address'] ?? '') !== '') {
                    $li .= '<li><strong>Address:</strong> ' . nl2br(self::e((string) $c['address'])) . '</li>';
                }
                foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 'youtube' => 'YouTube'] as $k => $l) {
                    if (($c[$k] ?? '') !== '' && CmsText::safeUrl((string) $c[$k]) !== null) {
                        $li .= '<li><a href="' . self::e((string) $c[$k]) . '" rel="noopener noreferrer" target="_blank">' . $l . '</a></li>';
                    }
                }

                return '<section class="cms-block"><div class="container narrow">' . $heading($h('heading') ?: 'Contact us') . '<ul class="plain">' . $li . '</ul></div></section>';
            case 'form':
                return '<section class="cms-block cms-cta"><div class="container narrow">' . $heading($h('heading')) . ($h('text') !== '' ? '<p>' . self::e($h('text')) . '</p>' : '') . '<p><a class="btn btn-primary" href="/f/' . self::e($h('form_slug')) . '">' . self::e($h('button_label') ?: 'Open the form') . '</a></p></div></section>';
            case 'reusable':
                if ($depth > 0) {
                    return '';
                }
                $r = Database::one('SELECT block_type, data_json FROM cms_blocks WHERE public_id = ?', [$h('ref')]);
                if ($r === null) {
                    return '';
                }

                return self::html(['type' => $r['block_type']] + (array) json_decode((string) $r['data_json'], true), $depth + 1);
        }

        return '';
    }
}
