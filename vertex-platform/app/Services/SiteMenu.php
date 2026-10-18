<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * The public header and footer links. An administrator's saved menu wins; otherwise the built-in links are used and anything that would lead to an
 * unpublished page (policies) or an empty section (team, portfolio) is left out, so a visitor never meets a dead link.
 */
final class SiteMenu
{
    /** @return list<array{label:string,url:string,children:list<array{label:string,url:string}>}> */
    public static function header(): array
    {
        $items = CmsService::menuSaved('header') ? CmsService::menu('header') : self::defaultHeader();
        $out = [];
        foreach ($items as $i) {
            $children = (array) ($i['children'] ?? []);
            if (($i['auto'] ?? '') === 'services') {
                $children = array_map(static fn (array $s): array => ['label' => (string) $s['name'], 'url' => '/services/' . $s['slug']], SiteContent::published('services'));
            }
            $out[] = ['label' => (string) $i['label'], 'url' => (string) $i['url'], 'children' => self::clean($children)];
        }

        return self::clean($out);
    }

    /** @return list<array<string,mixed>> */
    private static function defaultHeader(): array
    {
        $items = [['label' => 'Home', 'url' => '/'], ['label' => 'About Us', 'url' => '/about'], ['label' => 'Services', 'url' => '/services', 'auto' => 'services']];
        if (SiteContent::hasPublished('team')) {
            $items[] = ['label' => 'Our Team', 'url' => '/our-team'];
        }
        $items[] = ['label' => 'Our Partners', 'url' => '/our-partners'];
        if (SiteContent::hasPublished('portfolio')) {
            $items[] = ['label' => 'Portfolio', 'url' => '/portfolio'];
        }

        return [...$items, ['label' => 'Careers', 'url' => '/careers'], ['label' => 'Contact Us', 'url' => '/contact']];
    }

    /** @return list<array{title:string,links:list<array{label:string,url:string}>}> the non-empty footer columns */
    public static function footerColumns(): array
    {
        $explore = [['label' => 'Home', 'url' => '/'], ['label' => 'About Us', 'url' => '/about'], ['label' => 'Services', 'url' => '/services']];
        if (SiteContent::hasPublished('portfolio')) {
            $explore[] = ['label' => 'Portfolio', 'url' => '/portfolio'];
        }
        if (SiteContent::hasPublished('team')) {
            $explore[] = ['label' => 'Our Team', 'url' => '/our-team'];
        }
        $legal = [];
        foreach (SiteContent::published('policies') as $p) {
            $legal[] = ['label' => (string) $p['title'], 'url' => '/' . $p['slug']];
        }
        $defaults = [
            'footer_explore' => ['Explore', $explore],
            'footer_opportunities' => ['Opportunities', [['label' => 'Our Partners', 'url' => '/our-partners'], ['label' => 'Careers', 'url' => '/careers'], ['label' => 'Project Requirements', 'url' => '/request-quote']]],
            'footer_support' => ['Support', [['label' => 'Contact Us', 'url' => '/contact'], ['label' => 'FAQ', 'url' => '/faq'], ['label' => 'Client login', 'url' => '/login']]],
            'footer_legal' => ['Legal', $legal],
        ];
        $cols = [];
        foreach ($defaults as $loc => [$title, $links]) {
            $links = CmsService::menuSaved($loc) ? CmsService::menu($loc) : $links;
            $links = self::clean(array_map(static fn (array $l): array => ['label' => (string) $l['label'], 'url' => (string) $l['url']], $links));
            if ($links !== []) {
                $cols[] = ['title' => $title, 'links' => $links];
            }
        }

        return $cols;
    }

    /** @return list<array{label:string,url:string}> extra links shown under the columns (the legacy footer menu) */
    public static function footerExtra(): array
    {
        return self::clean(CmsService::menu('footer'));
    }

    /**
     * Removes links to policies that are not published and anything unsafe. Keeps order.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function clean(array $items): array
    {
        $policies = array_column(SiteContent::published('policies'), 'slug');
        $all = self::policySlugs();
        $out = [];
        foreach ($items as $i) {
            $url = trim((string) ($i['url'] ?? ''));
            $path = trim($url, '/');
            if (in_array($path, $all, true) && !in_array($path, $policies, true)) {
                continue;
            }
            if (CmsText::safeUrl($url) === null) {
                continue;
            }
            $out[] = $i;
        }

        return $out;
    }

    /** @return list<string> every policy slug, published or not */
    private static function policySlugs(): array
    {
        try {
            return array_column(\Vertex\Core\Database::all('SELECT slug FROM site_policies'), 'slug');
        } catch (\Throwable) {
            return [];
        }
    }
}
