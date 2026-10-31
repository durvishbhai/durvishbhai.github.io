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
use Vertex\Services\Auth;
use Vertex\Services\FormService;
use Vertex\Services\RateLimiter;

/** Public side of the form builder: /f/<slug>. */
final class DynamicFormController extends Controller
{
    /** @return array{0:array<string,mixed>,1:?string} form and the secret key from the address */
    private function load(Request $request, array $params): array
    {
        $form = FormService::findBySlug((string) ($params['slug'] ?? '')) ?? throw new HttpException(404);

        return [$form, $request->string('k') ?: null];
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        [$form, $token] = $this->load($request, $params);
        $uid = Auth::id();
        $why = FormService::blocker($form, $uid, $token);
        if ($why === 'login') {
            Session::put('intended', '/f/' . $form['slug']);

            return Response::redirect('/login');
        }
        if ($why === 'not_found' || $why === 'forbidden') {
            throw new HttpException($why === 'forbidden' ? 403 : 404);
        }
        $html = View::render('public/dynamic-form', [
            'title' => $form['title'], 'noindex' => $form['access'] !== 'public', 'form' => $form, 'fields' => FormService::fields($form), 'closed' => $why === 'closed', 'token' => $token,
            'errors' => Session::pullFlash('errors', []), 'submitToken' => bin2hex(random_bytes(16)), 'guest' => $uid === null,
        ]);
        Session::forget('_old');

        return Response::html($html);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        [$form, $token] = $this->load($request, $params);
        $to = '/f/' . $form['slug'] . ($token !== null ? '?k=' . rawurlencode($token) : '');
        $ip = $request->ip();
        if ($request->string('website_url') !== '') { // honeypot: bots get the normal thank-you page and nothing is stored
            Logger::security('Dynamic form honeypot triggered', ['form' => $form['slug'], 'ip' => $ip]);

            return Response::redirect('/f/' . $form['slug'] . '/thanks');
        }
        if (!RateLimiter::hit('public_form_ip', $ip)) {
            throw new HttpException(429);
        }
        $raw = is_array($request->body['a'] ?? null) ? $request->body['a'] : [];
        try {
            $r = FormService::submit($form, $raw, Auth::id(), $token, $ip, $request->string('consent') === '1', $request->string('submit_token'));
        } catch (ValidationException $e) {
            return $this->back($to, $e->errors, ['a' => $raw]);
        }
        Session::put('form_thanks', ['slug' => $form['slug'], 'ref' => $r['reference'], 'enquiry' => $r['enquiry_ref']]);

        return Response::redirect('/f/' . $form['slug'] . '/thanks');
    }

    /** @param array<string,string> $params */
    public function thanks(Request $request, array $params = []): Response
    {
        $form = FormService::findBySlug((string) ($params['slug'] ?? '')) ?? throw new HttpException(404);
        $t = Session::pull('form_thanks');
        $ref = is_array($t) && ($t['slug'] ?? '') === $form['slug'] ? (string) $t['ref'] : null;

        return Response::html(View::render('public/dynamic-form-thanks', ['title' => $form['title'], 'noindex' => true, 'form' => $form, 'ref' => $ref]));
    }
}
