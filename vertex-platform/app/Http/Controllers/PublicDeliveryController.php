<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Core\View;
use Vertex\Services\AppTime;
use Vertex\Services\DeliveryPublic;
use Vertex\Services\DeliveryService;
use Vertex\Services\RateLimiter;

/**
 * Booking a delivery date without signing in. Project ID and name only start a check: the real proof is the code emailed to the client's registered address
 * (or a private link from the team). Nothing about a project is shown before that, and the answers to a wrong guess are always the same.
 */
final class PublicDeliveryController extends Controller
{
    private function render(string $view, array $data = []): Response
    {
        $html = View::render($view, $data + ['errors' => Session::pullFlash('errors', []), 'notice' => Session::pullFlash('notice'), 'mode' => DeliveryPublic::mode()]);
        Session::forget('_old');

        return Response::html($html, 200, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** @param array<string,string> $params */
    public function start(Request $request, array $params = []): Response
    {
        if (DeliveryPublic::granted() !== null) {
            return Response::redirect('/delivery-appointment/book');
        }

        return $this->render('public/delivery-start', ['title' => 'Book your project delivery']);
    }

    /** @param array<string,string> $params */
    public function lookup(Request $request, array $params = []): Response
    {
        if ($request->string('website_url') !== '') {
            return Response::redirect('/delivery-appointment/verify');
        }
        if (!RateLimiter::hit('delivery_lookup_ip', $request->ip())) {
            throw new HttpException(429);
        }
        $code = mb_substr($request->string('project_id'), 0, 30);
        $name = mb_substr($request->string('client_name'), 0, 160);
        if (trim($code) === '' || trim($name) === '') {
            return $this->back('/delivery-appointment', ['Enter your Project ID and the name registered with us.'], ['project_id' => $code, 'client_name' => $name]);
        }
        DeliveryPublic::start($code, $name, $request->ip());
        Session::put('delivery_lookup', ['project_id' => $code, 'client_name' => $name]);

        return Response::redirect('/delivery-appointment/verify');
    }

    /** @param array<string,string> $params */
    public function verifyForm(Request $request, array $params = []): Response
    {
        if (!is_array(Session::get('delivery_lookup'))) {
            return Response::redirect('/delivery-appointment');
        }

        return $this->render('public/delivery-verify', ['title' => 'Enter your code']);
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        $l = Session::get('delivery_lookup');
        if (!is_array($l)) {
            return Response::redirect('/delivery-appointment');
        }
        if (!RateLimiter::hit('delivery_verify_ip', $request->ip())) {
            throw new HttpException(429);
        }
        if (DeliveryPublic::verify((string) $l['project_id'], (string) $l['client_name'], $request->string('code'))) {
            Session::forget('delivery_lookup');

            return Response::redirect('/delivery-appointment/book');
        }

        return $this->back('/delivery-appointment/verify', ['That code is not correct or has expired. Check your email, or go back and ask for a new code.']);
    }

    /** @param array<string,string> $params */
    public function link(Request $request, array $params = []): Response
    {
        if (!RateLimiter::hit('delivery_verify_ip', $request->ip())) {
            throw new HttpException(429);
        }
        if (!DeliveryPublic::useToken((string) ($params['token'] ?? ''))) {
            return $this->render('public/delivery-start', ['title' => 'Book your project delivery', 'badLink' => true]);
        }

        return Response::redirect('/delivery-appointment/book', 303);
    }

    /** @param array<string,string> $params */
    public function book(Request $request, array $params = []): Response
    {
        $project = DeliveryPublic::granted() ?? null;
        if ($project === null) {
            return Response::redirect('/delivery-appointment');
        }
        $plan = DeliveryService::plan($project);
        $cur = DeliveryService::currentFor((int) $project['id']);
        $appt = $cur === null ? null : DeliveryService::appointment((int) $cur['id']);
        $ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')) ? $request->string('month') : substr(max((string) ($plan['earliest'] ?? ''), AppTime::today()), 0, 7);
        $cal = in_array($plan['state'], ['open', 'preparing'], true) ? DeliveryService::month($ym, $plan) : null;
        $selected = $request->string('date');
        $ok = false;
        foreach ($cal['weeks'] ?? [] as $w) {
            foreach ($w as $d) {
                $ok = $ok || ($d !== null && $d['date'] === $selected && $d['state'] === 'available');
            }
        }

        return $this->render('public/delivery-book', ['title' => 'Book your project delivery', 'project' => $project, 'plan' => $plan, 'appt' => $appt, 'cal' => $cal, 'selected' => $ok ? $selected : '', 'base' => '/delivery-appointment/book', 'client' => \Vertex\Core\Database::one('SELECT contact_name FROM clients WHERE id = ?', [$project['client_id']])]);
    }

    /** @param array<string,string> $params */
    public function confirm(Request $request, array $params = []): Response
    {
        $project = DeliveryPublic::granted();
        if ($project === null) {
            return Response::redirect('/delivery-appointment');
        }
        if (!RateLimiter::hit('delivery_book_ip', $request->ip())) {
            throw new HttpException(429);
        }

        return $this->guarded(function () use ($request, $project): Response {
            $a = DeliveryService::book($project, $request->string('date'), 'public_link', null, ['client_notes' => $request->string('client_notes')]);

            return $this->ok('/delivery-appointment/book', 'Your delivery appointment is confirmed for ' . DeliveryService::nice((string) $a['appointment_date']) . '. Reference ' . $a['booking_reference'] . '. We emailed you the details.');
        }, '/delivery-appointment/book?month=' . urlencode(substr($request->string('date'), 0, 7)));
    }

    /** @param array<string,string> $params */
    public function exit(Request $request, array $params = []): Response
    {
        DeliveryPublic::forget();

        return $this->ok('/delivery-appointment', 'You have left the booking page.');
    }
}
