<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Auth;
use Vertex\Services\PushChannel;
use Vertex\Services\RateLimiter;
use Vertex\Services\WebPush;

/** The mobile app (installable web app): its manifest, and the device registration for phone notifications. */
final class PwaController extends Controller
{
    /** "Krazy Integrated Solutions" => "KIS". @param string $name */
    public static function shortName(string $name): string
    {
        if (mb_strlen($name) <= 12) {
            return $name;
        }
        $initials = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return mb_substr($initials, 0, 5);
    }

    /** @param array<string,string> $params */
    public function manifest(Request $request, array $params = []): Response
    {
        $name = (string) Config::get('app.name', 'Krazy Integrated Solutions');
        $tagline = trim((string) Config::get('site.tagline', ''));
        $icon = static fn (string $f, string $size, string $purpose = 'any'): array => ['src' => asset('img/' . $f), 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose];
        $manifest = [
            'id' => '/', 'name' => $name, 'short_name' => self::shortName($name), 'description' => $tagline !== '' ? $tagline : $name . ' client and team portal',
            'start_url' => '/dashboard?source=pwa', 'scope' => '/', 'display' => 'standalone', 'background_color' => '#050818', 'theme_color' => '#0b1130', 'lang' => 'en', 'dir' => 'ltr',
            'categories' => ['business', 'productivity'],
            'icons' => [$icon('pwa-192.png', '192x192'), $icon('pwa-512.png', '512x512'), $icon('pwa-maskable-512.png', '512x512', 'maskable')],
            'shortcuts' => [['name' => 'Notifications', 'url' => '/notifications?source=pwa', 'icons' => [$icon('pwa-192.png', '192x192')]], ['name' => 'Dashboard', 'url' => '/dashboard?source=pwa', 'icons' => [$icon('pwa-192.png', '192x192')]]],
        ];

        return new Response(200, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/manifest+json; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    /** @param array<string,string> $params */
    public function key(Request $request, array $params = []): Response
    {
        if (!PushChannel::enabled()) {
            return Response::json(['enabled' => false]);
        }

        try {
            $key = WebPush::keys()['public'];
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Push key unavailable', ['error' => $e->getMessage()]);

            return Response::json(['enabled' => false]); // e.g. APP_KEY missing: notifications are simply unavailable, the site keeps working
        }

        return Response::json(['enabled' => true, 'key' => $key, 'devices' => PushChannel::deviceCount($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function subscribe(Request $request, array $params = []): Response
    {
        if (!PushChannel::enabled()) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('push_subscribe_user', (string) $this->actorId())) {
            throw new HttpException(429);
        }
        try {
            PushChannel::subscribe($this->actorId(), (string) ($request->body['endpoint'] ?? ''), (string) ($request->body['p256dh'] ?? ''), (string) ($request->body['auth'] ?? ''), $request->userAgent());
        } catch (\InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return Response::json(['ok' => true, 'devices' => PushChannel::deviceCount($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function unsubscribe(Request $request, array $params = []): Response
    {
        PushChannel::unsubscribe($this->actorId(), (string) ($request->body['endpoint'] ?? ''));

        return Response::json(['ok' => true, 'devices' => PushChannel::deviceCount($this->actorId())]);
    }

    /** Sends a test notification to the person's own devices. @param array<string,string> $params */
    public function test(Request $request, array $params = []): Response
    {
        if (!PushChannel::enabled()) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('push_test_user', (string) $this->actorId())) {
            throw new HttpException(429);
        }
        $n = PushChannel::sendToUser($this->actorId(), PushChannel::payload('Test notification', 'Notifications are working on this device.', '/notifications', 'test-' . time()));

        return Response::json(['ok' => $n > 0, 'sent' => $n]);
    }
}
