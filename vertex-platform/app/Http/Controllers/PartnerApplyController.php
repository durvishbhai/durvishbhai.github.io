<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Core\View;
use Vertex\Services\Auth;
use Vertex\Services\FileStore;
use Vertex\Services\PartnerApplicationService;
use Vertex\Services\RateLimiter;

/**
 * The public Referral Partner application form. Same protections as the other public forms (CSRF, honeypot, per-IP rate limit, explicit agreement) and
 * the page never reveals whether an e-mail address already applied. Submitting never creates or activates an account.
 */
final class PartnerApplyController extends Controller
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $terms = PartnerApplicationService::currentTerms();
        $html = View::render('public/partner-apply', [
            'title' => 'Become a Referral Partner', 'errors' => Session::pullFlash('errors', []), 'terms' => $terms, 'signedIn' => Auth::user(),
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('partner')), 'types' => implode(', ', FileStore::allowedExtensions()),
        ]);
        Session::forget('_old');

        return Response::html($html);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        $ip = $request->ip();
        if ($request->string('website_url') !== '') { // honeypot: bots get the same page and nothing is stored
            Logger::security('Partner application honeypot triggered', ['ip' => $ip]);
            Session::put('partner_app_ref', null);

            return Response::redirect('/partners/apply/received');
        }
        if (!RateLimiter::hit('public_form_ip', $ip)) {
            throw new HttpException(429);
        }
        $in = [];
        foreach (['full_name', 'email', 'phone', 'organization', 'professional_info', 'referral_source', 'agree'] as $f) {
            $in[$f] = $request->string($f);
        }
        $files = self::uploadedFiles($request->files['document'] ?? []);
        try {
            $r = PartnerApplicationService::submit(Auth::id(), $in, $files[0] ?? null, $ip);
        } catch (ValidationException $e) {
            return $this->back('/partners/apply', $e->errors, array_diff_key($in, ['agree' => 1]));
        }
        Session::put('partner_app_ref', $r['reference']);

        return Response::redirect('/partners/apply/received');
    }

    /** @param array<string,string> $params */
    public function received(Request $request, array $params = []): Response
    {
        $ref = Session::pull('partner_app_ref');

        return Response::html(View::render('public/partner-received', ['title' => 'Application received', 'ref' => is_string($ref) ? $ref : null, 'noindex' => true]));
    }
}
