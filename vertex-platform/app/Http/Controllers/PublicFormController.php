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
use Vertex\Services\Audit;
use Vertex\Services\EnquiryService;
use Vertex\Services\RateLimiter;

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
        return $this->show('website_requirements');
    }

    /** @param array<string,string> $params */
    public function contact(Request $request, array $params = []): Response
    {
        return $this->show('contact_form');
    }

    /** @param array<string,string> $params */
    public function appointment(Request $request, array $params = []): Response
    {
        return $this->show('appointment_request');
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

    private function show(string $source): Response
    {
        $form = self::FORMS[$source];
        $html = View::render($form['view'], [
            'title' => $form['title'], 'errors' => Session::pullFlash('errors', []),
            'services' => Config::get('workflow.services'), 'budgets' => Config::get('workflow.budget_ranges'),
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

        try {
            $r = EnquiryService::create(null, $source, $in, ['consent' => $request->string('consent') === '1', 'ip' => $ip]);
        } catch (ValidationException $e) {
            return $this->back($back, $e->errors, $in);
        }
        Session::put('enquiry_ref', $r['reference']);

        return Response::redirect('/enquiry/received');
    }
}
