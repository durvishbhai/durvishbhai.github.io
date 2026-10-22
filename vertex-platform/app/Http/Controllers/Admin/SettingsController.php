<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AiService;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\Settings;

/** Module 35: Integration Center. Viewing needs `settings.view`; saving needs `settings.manage` and the actor's password. Secrets are write-only. */
final class SettingsController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/settings/index', ['title' => 'Integrations & settings', 'groups' => Settings::groups(), 'screen' => Settings::forScreen(),
            'razorpay' => RazorpayGateway::status(), 'ai' => AiService::status(), 'webhookUrl' => rtrim((string) \Vertex\Core\Config::get('app.url'), '/') . '/webhooks/razorpay']);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $group = (string) ($params['group'] ?? '');
        if (!isset(Settings::groups()[$group])) {
            throw new HttpException(404);
        }
        $in = [];
        foreach (Settings::definitions() as $key => $d) {
            if ($d['group'] === $group && $d['type'] !== 'secret') {
                $f = str_replace('.', '__', $key);
                $in[$f] = $d['type'] === 'bool' ? ($request->string($f) === '1' ? '1' : '0') : $request->string($f);
            }
        }
        foreach (Settings::definitions() as $key => $d) {
            if ($d['group'] === $group && $d['type'] === 'secret') {
                $in[str_replace('.', '__', $key)] = $request->string(str_replace('.', '__', $key));
            }
        }
        $clear = array_values(array_filter((array) ($request->body['clear'] ?? []), 'is_string'));

        return $this->guarded(function () use ($request, $group, $in, $clear): Response {
            $this->requireStepUp($request);
            Settings::save($this->actorId(), $group, $in, $clear);

            return $this->ok('/admin/settings#' . $group, Settings::groups()[$group] . ' saved.');
        }, '/admin/settings#' . $group, array_diff_key($in, array_flip(array_map(static fn (string $k): string => str_replace('.', '__', $k), array_keys(array_filter(Settings::definitions(), static fn (array $d): bool => $d['type'] === 'secret'))))));
    }
}
