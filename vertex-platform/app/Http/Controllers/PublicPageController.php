<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;
use Vertex\Services\CmsService;
use Vertex\Services\Settings;

/** The public website: CMS pages, the sitemap and robots.txt. Only published (or due scheduled) pages are ever served. */
final class PublicPageController
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $slug = isset($params['section']) ? $params['section'] . '/' . $params['slug'] : (string) ($params['slug'] ?? '');
        if (Settings::feature('cms')) {
            $page = CmsService::findLive($slug);
            if ($page !== null) {
                return self::render($page);
            }
        }
        $red = CmsService::redirectFor('/' . $slug);
        if ($red !== null) {
            return new Response($red['code'], '', ['Location' => $red['to']]);
        }
        throw new HttpException(404);
    }

    /** @param array<string,mixed> $page */
    public static function render(array $page): Response
    {
        return Response::html(View::render('public/cms-page', ['title' => (string) ($page['seo_title'] ?: $page['title']), 'html' => CmsService::renderBlocks($page), 'meta' => CmsService::meta($page), 'noindex' => (bool) $page['noindex']], 'layouts/public'), 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /** @param array<string,string> $params */
    public function sitemap(Request $request, array $params = []): Response
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (Settings::feature('cms') ? CmsService::sitemapEntries() : [] as $e) {
            $x .= '  <url><loc>' . htmlspecialchars($e['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $e['lastmod'] . "</lastmod></url>\n";
        }

        return new Response(200, $x . '</urlset>' . "\n", ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }

    /** @param array<string,string> $params */
    public function robots(Request $request, array $params = []): Response
    {
        $base = rtrim((string) \Vertex\Core\Config::get('app.url'), '/');
        $body = "User-agent: *\nDisallow: /admin/\nDisallow: /my/\nDisallow: /login\nDisallow: /dashboard\nDisallow: /search\nDisallow: /uploads/private/\nAllow: /\n" . ($base !== '' ? "\nSitemap: {$base}/sitemap.xml\n" : '');

        return new Response(200, $body, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
