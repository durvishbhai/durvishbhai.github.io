<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\CookieConsent;
use Vertex\Services\RateLimiter;

/** The cookie settings page and the endpoint the banner uses. Optional categories are never on unless the visitor switched them on. */
final class CookieController extends Controller
{
    /** @param array<string,string> $params */
    public function settings(Request $request, array $params = []): Response
    {
        $cur = CookieConsent::current($request);
        $html = View::render('site/cookie-settings', [
            'title' => 'Cookie Settings', 'meta' => ['description' => '', 'canonical' => '', 'ogTitle' => 'Cookie Settings'], 'noindex' => false, 'errors' => [], 'notice' => $request->string('saved') === '1' ? 'Your cookie choices were saved.' : null,
            'cur' => $cur, 'inventory' => CookieConsent::inventory(), 'desc' => self::descriptions(),
        ]);

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string,string> */
    public static function descriptions(): array
    {
        return ['necessary' => ComplianceSettings::get('cookie_desc_necessary'), 'preferences' => ComplianceSettings::get('cookie_desc_preferences'), 'analytics' => ComplianceSettings::get('cookie_desc_analytics'), 'marketing' => ComplianceSettings::get('cookie_desc_marketing')];
    }

    /** Same-origin only: the browser must tell us the request comes from this site. */
    private function sameOrigin(Request $request): bool
    {
        $host = strtolower((string) $request->header('Host'));
        foreach (['Origin', 'Referer'] as $h) {
            $v = (string) $request->header($h);
            if ($v !== '') {
                return $host !== '' && strtolower((string) parse_url($v, PHP_URL_HOST) . ((($p = parse_url($v, PHP_URL_PORT)) !== null) ? ':' . $p : '')) === $host;
            }
        }

        return false;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        if (!$this->sameOrigin($request)) {
            throw new HttpException(403);
        }
        if (!RateLimiter::hit('cookie_consent_ip', $request->ip())) {
            throw new HttpException(429);
        }
        $action = $request->string('action');
        $on = static fn (string $k): bool => in_array($request->string($k), ['1', 'on', 'true'], true);
        [$p, $a, $m] = match ($action) {
            'accept_all' => [true, true, true],
            'reject_all' => [false, false, false],
            default => [$on('preferences'), $on('analytics'), $on('marketing')],
        };
        $action = in_array($action, ['accept_all', 'reject_all'], true) ? $action : 'custom';
        $rec = CookieConsent::record($request, $p, $a, $m, $action);
        $cookie = CookieConsent::header($p, $a, $m, $request->isSecure(), $rec['id']);
        $json = $request->header('X-Requested-With') === 'fetch';
        $r = $json ? Response::json(['ok' => true, 'action' => $rec['action'], 'preferences' => $p, 'analytics' => $a, 'marketing' => $m]) : Response::redirect('/cookie-settings?saved=1');
        $r->headers['Set-Cookie'] = $cookie;
        $r->headers['Cache-Control'] = 'no-store';

        return $r;
    }
}
