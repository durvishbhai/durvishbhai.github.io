<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\View;
use Vertex\Services\Auth;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\DataDeletion;

/** The public data-deletion request pages. Nothing here reveals whether data exists for an address; the private status link is the only way to see a request. */
final class DataDeletionController extends Controller
{
    private function render(string $view, string $title, array $data = []): Response
    {
        $html = View::render($view, ['title' => $title, 'meta' => ['description' => '', 'canonical' => '', 'ogTitle' => $title], 'noindex' => true, 'errors' => Session::pullFlash('errors', []), 'notice' => Session::pullFlash('notice')] + $data);
        Session::forget('_old');

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** @param array<string,string> $params */
    public function form(Request $request, array $params = []): Response
    {
        return $this->render('site/deletion-form', 'Data Deletion Request', ['user' => Auth::user(), 'intro' => ComplianceSettings::get('deletion_intro'), 'retention' => ComplianceSettings::get('retention_summary'), 'days' => ComplianceSettings::int('deletion_response_days')]);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        if ($request->string('website_url') !== '') { // honeypot: bots get the same page and nothing is stored
            return Response::redirect('/data-deletion/received?ref=DEL-PENDING');
        }
        $in = ['name' => $request->string('name'), 'email' => $request->string('email'), 'reason' => $request->string('reason'), 'confirm' => $request->string('confirm')];

        return $this->guarded(function () use ($request, $in): Response {
            $r = DataDeletion::submit($in, Auth::user(), $request->ip());

            return Response::redirect('/data-deletion/received?ref=' . urlencode($r['reference']));
        }, '/data-deletion', ['name' => $in['name'], 'email' => $in['email'], 'reason' => $in['reason']]);
    }

    /** @param array<string,string> $params */
    public function received(Request $request, array $params = []): Response
    {
        $ref = preg_match('/^DEL-[A-Z0-9-]{3,20}$/', $request->string('ref')) ? $request->string('ref') : '';

        return $this->render('site/deletion-received', 'Request received', ['ref' => $ref, 'signedIn' => Auth::user() !== null]);
    }

    /** @param array<string,string> $params */
    public function verifyForm(Request $request, array $params = []): Response
    {
        return $this->render('site/deletion-verify', 'Confirm your request', ['token' => (string) ($params['token'] ?? '')]);
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        $r = DataDeletion::verifyLink((string) ($params['token'] ?? ''));

        return $this->render('site/deletion-verified', 'Request confirmed', ['ok' => $r !== null, 'ref' => $r['reference'] ?? '']);
    }

    /** @param array<string,string> $params */
    public function lookupForm(Request $request, array $params = []): Response
    {
        return $this->render('site/deletion-lookup', 'Check a request');
    }

    /** @param array<string,string> $params */
    public function lookup(Request $request, array $params = []): Response
    {
        DataDeletion::sendStatusLink($request->string('reference'), $request->string('email'), $request->ip());

        return $this->ok('/data-deletion/status', 'If that reference and email address match a request, we have emailed a private status link to that address.');
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $r = DataDeletion::byTrack((string) ($params['token'] ?? '')) ?? throw new HttpException(404);

        return $this->render('site/deletion-status', 'Your deletion request', ['r' => $r, 'events' => DataDeletion::events((int) $r['id'], true), 'result' => $r['result_json'] ? json_decode((string) $r['result_json'], true) : null, 'token' => $params['token']]);
    }

    /** @param array<string,string> $params */
    public function reply(Request $request, array $params = []): Response
    {
        $r = DataDeletion::byTrack((string) ($params['token'] ?? '')) ?? throw new HttpException(404);
        $to = '/data-deletion/status/' . $params['token'];

        return $this->guarded(function () use ($request, $r, $to): Response {
            if ($request->string('action') === 'withdraw') {
                DataDeletion::withdraw($r);

                return $this->ok($to, 'Your request was withdrawn.');
            }
            DataDeletion::reply($r, $request->string('note'));

            return $this->ok($to, 'Thank you. Your reply was added to the request.');
        }, $to);
    }
}
