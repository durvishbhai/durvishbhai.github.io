<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AiService;
use Vertex\Services\Auth;
use Vertex\Services\Mailer;
use Vertex\Services\ManualUpi;
use Vertex\Services\RateLimiter;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\Settings;

/** Module 35: Integration Center. Viewing needs `settings.view`; saving needs `settings.manage` and the actor's password. Secrets are write-only. */
final class SettingsController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/settings/index', ['title' => 'Integrations & settings', 'groups' => Settings::groups(), 'screen' => Settings::forScreen(),
            'razorpay' => RazorpayGateway::status(), 'ai' => AiService::status(), 'google' => \Vertex\Services\GoogleAuth::status(), 'googleRedirect' => \Vertex\Services\GoogleAuth::redirectUri(), 'webhookUrl' => rtrim((string) \Vertex\Core\Config::get('app.url'), '/') . '/webhooks/razorpay']);
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
            if ($group === 'razorpay' && ($in['razorpay__enabled'] ?? '0') === '1' && (int) ManualUpi::settings()['enabled'] === 1) {
                throw new \Vertex\Core\ValidationException(['Manual UPI payments are switched on. Manual UPI and Razorpay are alternatives: switch manual UPI off first (Administration → Manual UPI payments), then switch Razorpay on.']);
            }
            Settings::save($this->actorId(), $group, $in, $clear);

            return $this->ok('/admin/settings#' . $group, Settings::groups()[$group] . ' saved.');
        }, '/admin/settings#' . $group, array_diff_key($in, array_flip(array_map(static fn (string $k): string => str_replace('.', '__', $k), array_keys(array_filter(Settings::definitions(), static fn (array $d): bool => $d['type'] === 'secret'))))));
    }

    /**
     * Sends a test email to the signed-in Owner using the saved email settings and tells them exactly what happened, including the mail server's own error
     * text, so a wrong password, port or From address can be fixed without reading server logs.
     *
     * @param array<string,string> $params
     */
    public function testEmail(Request $request, array $params = []): Response
    {
        if (!RateLimiter::hit('test_email_user', (string) $this->actorId())) {
            throw new HttpException(429);
        }
        $user = Auth::user() ?? throw new HttpException(403);
        $to = (string) $user['email'];
        if (!Mailer::enabled()) {
            return $this->back('/admin/settings#email', ['Email is switched off or incomplete: choose "SMTP", and fill the host, From address and (for most hosts) the username and password, then save and try again.']);
        }
        if (Mailer::sendTemplate($to, [
            'subject' => 'Test email from ' . (string) \Vertex\Core\Config::get('app.name'), 'category' => 'Email settings', 'tone' => 'success', 'heading' => 'Your email settings work', 'name' => (string) $user['name'],
            'paragraphs' => ['If you can read this, password resets, invitations, payment confirmations and reminders will be delivered.'],
            'details' => ['Sent to' => $to, 'Sent at' => gmdate('d M Y, H:i') . ' UTC'],
        ])) {
            return $this->ok('/admin/settings#email', 'A test email was sent to ' . $to . '. Check that inbox (and the spam folder).');
        }

        return $this->back('/admin/settings#email', ['The test email could not be sent. The mail server said: ' . (Mailer::$lastError !== '' ? Mailer::$lastError : 'no answer') . ' Check the host, port, encryption, username and password (for Hostinger: smtp.hostinger.com with SSL 465 or STARTTLS 587, the full mailbox address as the username).']);
    }
}
