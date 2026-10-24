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
use Vertex\Core\Database;
use Vertex\Services\Audit;
use Vertex\Services\EnquiryExtras;
use Vertex\Services\EnquiryService;
use Vertex\Services\FileStore;
use Vertex\Services\RateLimiter;
use Vertex\Services\SiteSections;

/**
 * Public website forms: project requirements, contact, appointment REQUEST (not a confirmed booking: scheduling is a
 * later module). Protections: CSRF, per-IP rate limit, honeypot field, explicit consent, strict validation, no
 * confirmation of whether a duplicate exists. No email is sent to the visitor or to staff (notifications are not built).
 */
final class PublicFormController extends Controller
{
    private const FORMS = [
        'website_requirements' => ['view' => 'public/request-quote', 'title' => 'Tell us about your project', 'fields' => ['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline']],
        'contact_form' => ['view' => 'public/contact', 'title' => 'Contact us', 'fields' => ['contact_name', 'email', 'phone', 'company_name', 'service', 'description']],
        'appointment_request' => ['view' => 'public/appointment', 'title' => 'Request an appointment', 'fields' => ['contact_name', 'email', 'phone', 'company_name', 'preferred_slot', 'description']],
    ];

    /** @param array<string,string> $params */
    public function requirements(Request $request, array $params = []): Response
    {
        return $this->show('website_requirements', $request);
    }

    /** @param array<string,string> $params */
    public function contact(Request $request, array $params = []): Response
    {
        return $this->show('contact_form', $request);
    }

    /** @param array<string,string> $params */
    public function appointment(Request $request, array $params = []): Response
    {
        return $this->show('appointment_request', $request);
    }

    /** A copy of the answers so far as a requirements document (nothing is saved). @param array<string,string> $params */
    public function requirementsPdf(Request $request, array $params = []): Response
    {
        if (!\Vertex\Services\RateLimiter::hit('requirements_pdf_ip', $request->ip())) {
            throw new \Vertex\Core\HttpException(429);
        }
        $b = $request->body;
        $pdf = \Vertex\Services\RequirementsPdf::fromForm($b, ['name' => mb_substr(trim((string) ($b['contact_name'] ?? '')), 0, 120), 'email' => mb_substr(trim((string) ($b['email'] ?? '')), 0, 190),
            'phone' => mb_substr(trim((string) ($b['phone'] ?? '')), 0, 32), 'company' => mb_substr(trim((string) ($b['company_name'] ?? '')), 0, 160)]);

        return new Response(200, $pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="project-requirements.pdf"', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** @param array<string,string> $params */
    public function submitRequirements(Request $request, array $params = []): Response
    {
        return $this->submit($request, 'website_requirements', '/request-quote');
    }

    /** @param array<string,string> $params */
    public function submitContact(Request $request, array $params = []): Response
    {
        return $this->submit($request, 'contact_form', '/contact');
    }

    /** @param array<string,string> $params */
    public function submitAppointment(Request $request, array $params = []): Response
    {
        return $this->submit($request, 'appointment_request', '/book-appointment');
    }

    /** @param array<string,string> $params */
    public function received(Request $request, array $params = []): Response
    {
        $ref = Session::pull('enquiry_ref');

        return Response::html(View::render('public/received', ['title' => 'Request received', 'ref' => is_string($ref) ? $ref : null, 'noindex' => true]));
    }

    /** @return array<string,string> */
    private function meta(string $source, string $page): array
    {
        $h = SiteSections::get($page, 'header');
        $base = rtrim((string) Config::get('app.url'), '/');
        $title = \Vertex\Services\SiteText::tokens((string) ($h['title'] ?? self::FORMS[$source]['title']));
        $desc = trim((string) preg_replace('/\s+/', ' ', \Vertex\Services\SiteText::tokens(strip_tags((string) ($h['intro'] ?? '')))));

        return ['description' => mb_strlen($desc) > 160 ? rtrim(mb_substr($desc, 0, 159)) . '…' : $desc, 'canonical' => $base !== '' ? $base . (['contact_form' => '/contact', 'appointment_request' => '/book-appointment'][$source] ?? '/request-quote') : '', 'ogImage' => '', 'ogTitle' => $title . ' · ' . (string) Config::get('app.name')];
    }

    private function show(string $source, Request $request): Response
    {
        $form = self::FORMS[$source];
        $page = $source === 'contact_form' ? 'contact' : 'requirements';
        $html = View::render($form['view'], [
            'title' => (string) (SiteSections::get($page, 'header')['title'] ?? $form['title']), 'errors' => Session::pullFlash('errors', []),
            'services' => Config::get('workflow.services'), 'budgets' => Config::get('workflow.budget_ranges'),
            'inquiryTypes' => EnquiryExtras::INQUIRY_TYPES, 'categories' => EnquiryExtras::CATEGORIES, 'timelines' => EnquiryExtras::TIMELINES, 'serviceChoices' => EnquiryExtras::serviceChoices(),
            'preselect' => preg_match('/^[a-z0-9-]{1,80}$/', (string) ($request->query['service'] ?? '')) ? (string) $request->query['service'] : '',
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('enquiry')), 'extensions' => implode(', ', FileStore::allowedExtensions()),
            'meta' => $this->meta($source, $page),
        ]);
        Session::forget('_old');

        return Response::html($html);
    }

    private function submit(Request $request, string $source, string $back): Response
    {
        $ip = $request->ip();
        // Honeypot: a real visitor never sees or fills this field. Bots get the same "success" page, and nothing is stored.
        if ($request->string('website_url') !== '') {
            Logger::security('Public form honeypot triggered', ['source' => $source, 'ip' => $ip]);
            Session::put('enquiry_ref', null);

            return Response::redirect('/enquiry/received');
        }
        if (!RateLimiter::hit('public_form_ip', $ip)) {
            throw new HttpException(429);
        }
        $in = [];
        foreach (self::FORMS[$source]['fields'] as $f) {
            $in[$f] = $request->string($f);
        }
        $metas = [];
        try {
            $x = match ($source) {
                'contact_form' => EnquiryExtras::contact($request->body),
                'website_requirements' => EnquiryExtras::requirements($request->body),
                default => null,
            };
            $in = array_merge($in, $x['base'] ?? []);
            if ($source === 'website_requirements') {
                foreach (array_slice(self::uploadedFiles($request->files['attachments'] ?? null), 0, 3) as $f) {
                    if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $metas[] = FileStore::accept($f, 'enquiry');
                    }
                }
            }
            $consent = $request->string('consent') === '1';
            $r = Database::transaction(function () use ($source, $in, $consent, $ip, $x, $metas): array {
                $r = EnquiryService::create(null, $source, $in, ['consent' => $consent, 'ip' => $ip]);
                if ($x !== null) {
                    EnquiryExtras::save((int) $r['id'], $x['details'], $metas);
                }

                return $r;
            });
        } catch (ValidationException $e) {
            array_map([FileStore::class, 'discard'], $metas);

            return $this->back($back, $e->errors, array_diff_key($in, ['description' => 1]) + ['description' => $request->string('description')] + array_intersect_key($request->body, array_flip(['subject', 'inquiry_type', 'project_name', 'project_category', 'problem_statement', 'expected_outcome', 'features', 'preferred_tech', 'timeline', 'notes'])));
        } catch (\Throwable $e) {
            array_map([FileStore::class, 'discard'], $metas);
            throw $e;
        }
        Session::put('enquiry_ref', $r['reference']);

        return Response::redirect('/enquiry/received');
    }
}
