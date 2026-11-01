<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\LegalDocuments;

/**
 * The public legal pages. A document shows its live version (the text, version number, effective date and last update). A document that has not been published yet shows a
 * plain "being finalised" notice with the contact details instead of a missing page, and is kept out of search engines.
 */
final class LegalController extends Controller
{
    private function render(string $view, string $title, array $data = [], bool $noindex = false, string $path = ''): Response
    {
        $base = rtrim((string) Config::get('app.url'), '/');
        $meta = ['description' => '', 'canonical' => $base !== '' && $path !== '' ? $base . $path : '', 'ogTitle' => $title];

        return Response::html(View::render($view, ['title' => $title, 'meta' => $meta, 'noindex' => $noindex, 'errors' => [], 'notice' => null] + $data), 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $key = trim($request->path, '/');
        $doc = LegalDocuments::document($key) ?? throw new HttpException(404);
        $v = LegalDocuments::live($key);
        if ($v === null) {
            return $this->render('site/legal-pending', (string) $doc['title'], ['doc' => $doc, 'email' => ComplianceSettings::email(), 'whatsapp' => ComplianceSettings::whatsapp()], true);
        }
        $html = LegalDocuments::fill((string) $v['body_html']);
        if ($key === 'accessibility-statement') {
            $html .= self::accessibilityExtras();
        }

        return $this->render('site/legal', (string) $v['title'], ['doc' => $doc, 'v' => $v, 'html' => $html, 'toc' => LegalDocuments::toc($html)], false, '/' . $key);
    }

    /** The Owner's current conformance statement and known limitations, appended to the accessibility statement. */
    private static function accessibilityExtras(): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<h2 id="conformance-and-review">Conformance and review</h2><p>' . nl2br($esc(ComplianceSettings::get('accessibility_conformance'))) . '</p>';
        $known = trim(ComplianceSettings::get('accessibility_known_issues'));
        if ($known !== '') {
            $out .= '<h3 id="current-known-limitations">Current known limitations</h3><p>' . nl2br($esc($known)) . '</p>';
        }
        $rev = ComplianceSettings::get('accessibility_reviewed');
        if ($rev !== '') {
            $out .= '<p>Last reviewed: ' . $esc(local_date($rev, 'j F Y')) . '.</p>';
        }

        return $out;
    }

    /** @param array<string,string> $params */
    public function business(Request $request, array $params = []): Response
    {
        $s = static fn (string $k): string => trim(ComplianceSettings::get($k));

        return $this->render('site/legal-business', 'Legal Business Information', ['s' => $s, 'disclosure' => ComplianceSettings::disclosure(), 'email' => ComplianceSettings::email(), 'whatsapp' => ComplianceSettings::whatsapp(),
            'address' => ComplianceSettings::bool('show_address') ? $s('public_address') : ''], false, '/legal-business-information');
    }

    /** @param array<string,string> $params */
    public function contact(Request $request, array $params = []): Response
    {
        $s = static fn (string $k): string => trim(ComplianceSettings::get($k));

        return $this->render('site/legal-contact', 'Contact and Grievance Information', ['email' => ComplianceSettings::email(), 'whatsapp' => ComplianceSettings::whatsapp(), 'gname' => $s('grievance_name'), 'gemail' => $s('grievance_email'), 'gdays' => $s('grievance_days')], false, '/contact-and-grievance');
    }

    /** @param array<string,string> $params */
    public function refundAlias(Request $request, array $params = []): Response
    {
        return Response::redirect('/refund-policy', 301);
    }
}
