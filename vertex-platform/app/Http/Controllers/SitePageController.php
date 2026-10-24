<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Core\View;
use Vertex\Services\CareerService;
use Vertex\Services\EnquiryExtras;
use Vertex\Services\FileStore;
use Vertex\Services\RateLimiter;
use Vertex\Services\Settings;
use Vertex\Services\SiteContent;
use Vertex\Services\SiteHiring;
use Vertex\Services\SiteSections;
use Vertex\Services\SiteText;

/** The public corporate website. Everything shown comes from the database (published rows and saved sections) or the shipped starting copy; drafts are never visible. */
final class SitePageController extends Controller
{
    private function guard(): void
    {
        if (!Settings::feature('cms')) {
            throw new HttpException(404);
        }
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, string $title, array $data = [], string $desc = '', string $path = '', string $image = '', bool $noindex = false, array $metaExtra = []): Response
    {
        $base = rtrim((string) Config::get('app.url'), '/');
        $site = (array) Config::get('site', []);
        $meta = ['description' => $desc !== '' ? $desc : (string) ($site['default_seo_description'] ?? ''), 'canonical' => $base !== '' && $path !== '' ? $base . $path : '', 'ogImage' => $image !== '' && $base !== '' ? $base . $image : '', 'ogTitle' => $title] + [];
        $meta = $metaExtra + $meta;
        $live = session_status() === PHP_SESSION_ACTIVE; // pages without a form do not start a session for anonymous visitors
        $r = Response::html(View::render($view, ['title' => $title, 'meta' => $meta, 'noindex' => $noindex, 'errors' => $live ? Session::pullFlash('errors', []) : [], 'notice' => $live ? Session::pullFlash('notice') : null] + $data), 200, $live ? ['Cache-Control' => 'private, no-store'] : ['Cache-Control' => 'public, max-age=60']);
        if ($live) {
            Session::forget('_old');
        }

        return $r;
    }

    private static function plain(string $s, int $max = 160): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', SiteText::tokens(strip_tags($s))));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** @param array<string,string> $params */
    public function home(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('home', 'hero');

        $base = rtrim((string) Config::get('app.url'), '/');
        $site = (array) Config::get('site', []);
        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => SiteText::company(), 'url' => $base . '/'];
        if ($base !== '') {
            $org['logo'] = $base . asset('img/krazy-logo.png');
        }
        $same = array_values(array_filter(array_map(static fn (string $k): string => str_starts_with((string) ($site[$k] ?? ''), 'https://') ? (string) $site[$k] : '', ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube'])));
        if ($same !== []) {
            $org['sameAs'] = $same;
        }
        if (($site['email'] ?? '') !== '') {
            $org['email'] = (string) $site['email'];
        }

        return $this->render('site/home', SiteText::company(), ['services' => SiteContent::published('services'), 'projects' => array_slice(SiteContent::published('portfolio'), 0, 3), 'order' => SiteSections::visible('home'),
            'jsonld' => $base === '' ? '' : (string) json_encode($org, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)], self::plain((string) $h['subheading']), '/');
    }

    /** @param array<string,string> $params */
    public function about(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('about', 'header');

        $seoTitle = trim((string) ($h['seo_title'] ?? ''));
        $seoDesc = trim((string) ($h['seo_description'] ?? ''));

        return $this->render('site/about', SiteText::tokens($h['title']), ['visible' => SiteSections::visible('about')], $seoDesc !== '' ? self::plain($seoDesc, 300) : self::plain($h['intro']), '/about', '', false,
            $seoTitle !== '' ? ['title' => SiteText::tokens($seoTitle), 'ogTitle' => SiteText::tokens($seoTitle)] : []);
    }

    /** @param array<string,string> $params */
    public function services(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('services', 'header');

        return $this->render('site/services', SiteText::tokens($h['title']), ['services' => SiteContent::published('services')], self::plain($h['intro']), '/services');
    }

    /** @param array<string,string> $params */
    public function service(Request $request, array $params = []): Response
    {
        $this->guard();
        $s = SiteContent::publishedBySlug('services', (string) ($params['slug'] ?? '')) ?? throw new HttpException(404);
        $related = [];
        foreach (array_filter(explode(',', (string) $s['related'])) as $slug) {
            $r = SiteContent::publishedBySlug('services', $slug);
            if ($r !== null) {
                $related[] = $r;
            }
        }
        if ($related === []) {
            $related = array_slice(array_values(array_filter(SiteContent::published('services'), static fn (array $x): bool => $x['id'] !== $s['id'])), 0, 3);
        }
        $img = SiteContent::image($s, 'cover_media_id');

        return $this->render('site/service', (string) ($s['seo_title'] ?: $s['name']), ['s' => $s, 'related' => $related, 'cover' => $img, 'gallery' => SiteContent::gallery($s)],
            self::plain((string) ($s['seo_description'] ?: $s['summary'])), '/services/' . $s['slug'], $img['url'] ?? '');
    }

    /** @param array<string,string> $params */
    public function team(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('team', 'header');
        $groups = [];
        foreach (SiteContent::published('team') as $m) {
            $groups[(string) $m['department']][] = $m;
        }

        return $this->render('site/team', SiteText::tokens($h['title']), ['groups' => $groups], self::plain($h['intro']), '/our-team');
    }

    /** @param array<string,string> $params */
    public function partners(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('partners', 'header');

        return $this->render('site/partners', SiteText::tokens($h['title']), ['partners' => SiteContent::published('partners')], self::plain($h['intro']), '/our-partners');
    }

    /** @param array<string,string> $params */
    public function clients(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('clients', 'header');
        $groups = [];
        foreach (SiteContent::published('clients') as $c) {
            $groups[trim((string) $c['country']) !== '' ? (string) $c['country'] : ''][] = $c;
        }
        ksort($groups);
        if (isset($groups[''])) { // clients without a country come last
            $none = $groups[''];
            unset($groups['']);
            $groups[''] = $none;
        }

        return $this->render('site/clients', SiteText::tokens($h['title']), ['groups' => $groups], self::plain($h['intro']), '/our-clients');
    }

    /** @param array<string,string> $params */
    public function portfolio(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('portfolio', 'header');

        return $this->render('site/portfolio', SiteText::tokens($h['title']), ['projects' => SiteContent::published('portfolio')], self::plain($h['intro']), '/portfolio');
    }

    /** @param array<string,string> $params */
    public function project(Request $request, array $params = []): Response
    {
        $this->guard();
        $p = SiteContent::publishedBySlug('portfolio', (string) ($params['slug'] ?? '')) ?? throw new HttpException(404);
        $img = SiteContent::image($p, 'cover_media_id');

        return $this->render('site/project', (string) ($p['seo_title'] ?: $p['title']), ['p' => $p, 'cover' => $img, 'gallery' => SiteContent::gallery($p)], self::plain((string) ($p['seo_description'] ?: $p['summary'])), '/portfolio/' . $p['slug'], $img['url'] ?? '');
    }

    /** @param array<string,string> $params */
    public function careers(Request $request, array $params = []): Response
    {
        $this->guard();
        if (!SiteHiring::isVisible()) {
            throw new HttpException(404);
        }
        $h = SiteSections::get('hiring', 'header');
        $open = SiteHiring::isOpen();
        $jobs = $open ? SiteContent::published('jobs') : [];
        $roles = SiteHiring::roles();
        $have = array_column($roles, 'title');
        foreach ($jobs as $j) { // a published opening can be chosen in the form too
            if (!in_array((string) $j['title'], $have, true)) {
                $roles[] = ['title' => (string) $j['title'], 'summary' => '', 'family' => stripos((string) $j['title'], 'video') !== false || stripos((string) $j['title'], 'edit') !== false ? 'video' : 'dev'];
            }
        }

        return $this->render('site/careers', SiteText::tokens($h['title']), ['errorsInline' => true, 'open' => $open, 'jobs' => $jobs, 'roles' => $roles, 'preselect' => (string) ($request->query['role'] ?? ''),
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('career')), 'extensions' => implode(', ', FileStore::allowedExtensions())], self::plain($h['intro']), '/careers');
    }

    /** @param array<string,string> $params */
    public function job(Request $request, array $params = []): Response
    {
        $this->guard();
        if (!SiteHiring::isVisible()) {
            throw new HttpException(404);
        }
        $j = SiteContent::publishedBySlug('jobs', (string) ($params['slug'] ?? '')) ?? throw new HttpException(404);

        return $this->render('site/job', (string) $j['title'], ['j' => $j], self::plain((string) $j['title'] . ' at ' . SiteText::company()), '/careers/' . $j['slug']);
    }

    /** The application form. Applications are only taken while the page is Open. @param array<string,string> $params */
    public function apply(Request $request, array $params = []): Response
    {
        if (!SiteHiring::isOpen()) {
            return Response::redirect('/careers');
        }

        return $this->submitApplication($request, '/careers#apply', '/careers/received');
    }

    /** The old Hiring page address: there is one page now, Careers. Forms posted to the old address are passed on with their data (308). @param array<string,string> $params */
    public function hiringApply(Request $request, array $params = []): Response
    {
        return Response::redirect('/careers/apply', 308);
    }

    private function submitApplication(Request $request, string $back, string $done): Response
    {
        $this->guard();
        $ip = $request->ip();
        if ($request->string('website_url') !== '') { // honeypot: bots get the same page and nothing is stored
            Logger::security('Career application honeypot triggered', ['ip' => $ip]);
            Session::put('career_ref', null);

            return Response::redirect($done);
        }
        if (!RateLimiter::hit('career_ip', $ip)) {
            throw new HttpException(429);
        }
        $in = [];
        foreach (['full_name', 'email', 'phone', 'role', 'skills_experience', 'portfolio_url', 'linkedin_url', 'intro', 'experience', 'availability'] as $f) {
            $in[$f] = $request->string($f);
        }
        $file = self::uploadedFiles($request->files['resume'] ?? null)[0] ?? null;
        try {
            $r = CareerService::apply($in, $file, $request->string('consent') === '1', $ip);
        } catch (ValidationException $e) {
            return $this->back($back, $e->errors, $in);
        }
        Session::put('career_ref', $r['reference']);

        return Response::redirect($done);
    }

    /** @param array<string,string> $params */
    public function hiring(Request $request, array $params = []): Response
    {
        $role = (string) ($request->query['role'] ?? '');

        return Response::redirect('/careers' . ($role !== '' ? '?role=' . rawurlencode($role) : ''), 301);
    }

    /** @param array<string,string> $params */
    public function hiringReceived(Request $request, array $params = []): Response
    {
        return Response::redirect('/careers/received', 301);
    }

    /** @param array<string,string> $params */
    public function applied(Request $request, array $params = []): Response
    {
        $this->guard();
        $ref = Session::pull('career_ref');

        return $this->render('site/career-received', 'Application received', ['ref' => is_string($ref) ? $ref : null], '', '', '', true);
    }

    /** @param array<string,string> $params */
    public function faq(Request $request, array $params = []): Response
    {
        $this->guard();
        $h = SiteSections::get('faq', 'header');
        $groups = [];
        foreach (SiteContent::published('faqs') as $f) {
            $groups[(string) $f['category']][] = $f;
        }

        return $this->render('site/faq', SiteText::tokens($h['title']), ['groups' => $groups, 'q' => mb_substr($request->string('q'), 0, 80)], self::plain($h['intro']), '/faq');
    }

    /** One of the policy pages. The address (/privacy-policy, /terms-and-conditions, ...) names the policy; unpublished ones do not exist for visitors. @param array<string,string> $params */
    public function policy(Request $request, array $params = []): Response
    {
        $this->guard();
        $p = SiteContent::publishedBySlug('policies', trim($request->path, '/')) ?? throw new HttpException(404);

        return $this->render('site/policy', (string) $p['title'], ['p' => $p], self::plain(SiteText::tokens((string) $p['body']), 200), '/' . $p['slug']);
    }

    /** @param array<string,string> $params */
    public function sitemap(Request $request, array $params = []): Response
    {
        $this->guard();
        $groups = ['Company' => [['About Us', '/about'], ['Services', '/services'], ['Our Team', '/our-team'], ['Our Clients', '/our-clients'], ['Our Partners', '/our-partners'], ['Portfolio', '/portfolio']],
            'Get in touch' => [['Contact Us', '/contact'], ['Project Requirements', '/request-quote'], ['FAQ', '/faq'], ['Become a referral partner', '/partners/apply']]];
        if (SiteHiring::isVisible()) {
            $groups['Company'][] = ['Careers', '/careers'];
        }
        foreach (SiteContent::published('services') as $s) {
            $groups['Services'][] = [(string) $s['name'], '/services/' . $s['slug']];
        }
        foreach (SiteContent::published('portfolio') as $p) {
            $groups['Portfolio'][] = [(string) $p['title'], '/portfolio/' . $p['slug']];
        }
        foreach (SiteContent::published('jobs') as $j) {
            $groups['Openings'][] = [(string) $j['title'], '/careers/' . $j['slug']];
        }
        foreach (SiteContent::published('policies') as $p) {
            $groups['Legal'][] = [(string) $p['title'], '/' . $p['slug']];
        }
        try {
            foreach (\Vertex\Core\Database::all("SELECT slug, title FROM cms_pages WHERE status = 'published' AND archived_at IS NULL AND noindex = 0 AND slug <> 'home' ORDER BY slug") as $c) {
                $groups['More pages'][] = [(string) $c['title'], '/' . $c['slug']];
            }
        } catch (\Throwable) {
        }

        return $this->render('site/sitemap', 'Sitemap', ['groups' => $groups], 'Every page on this website.', '/sitemap');
    }

    /** @param array<string,string> $params */
    public function requirementsAlias(Request $request, array $params = []): Response
    {
        $svc = (string) ($request->query['service'] ?? '');
        $q = preg_match('/^[a-z0-9-]{1,80}$/', $svc) ? '?service=' . $svc : '';

        return Response::redirect('/request-quote' . $q, 301);
    }
}
