<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\AppTime;
use Vertex\Services\DeliveryMail;
use Vertex\Services\DeliveryPublic;
use Vertex\Services\DeliveryService;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\RateLimiter;

/**
 * The delivery appointment of one project: the client books, moves or cancels it here; the team sets the project's preparation period, creates booking links
 * and books on a client's behalf. Every rule is enforced by DeliveryService; this class only checks who is asking.
 */
final class DeliveryController extends Controller
{
    private function backTo(array $project): string
    {
        return '/projects/' . $project['public_id'] . '/delivery';
    }

    /** @return 'staff'|'client' */
    private function role(array $project): string
    {
        $uid = $this->actorId();
        $level = ProjectPolicy::level($uid, $project);
        if (in_array($level, ['all', 'assigned'], true) && Gate::canAny(['delivery.view', 'delivery.view_assigned', 'delivery.manage', 'delivery.configure'], $uid)) {
            return 'staff';
        }
        if ($level === 'own' && Gate::can('delivery.book', $uid)) {
            return 'client';
        }
        throw new HttpException($level === null ? 404 : 403);
    }

    /** The client's own project (for the actions only a client may take). @return array<string,mixed> */
    private function ownProject(Request $request): array
    {
        $project = $request->attributes['project'];
        if (ProjectPolicy::level($this->actorId(), $project) !== 'own' || !Gate::can('delivery.book', $this->actorId())) {
            throw new HttpException(403);
        }

        return $project;
    }

    /** @return array<string,mixed> the appointment of this project the client may act on */
    private function ownAppointment(Request $request, array $project): array
    {
        $a = DeliveryService::byReference($request->string('reference'));
        if ($a === null || (int) $a['project_id'] !== (int) $project['id']) {
            throw new HttpException(404);
        }

        return $a;
    }

    /** @param array<string,string> $params */
    public function project(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $role = $this->role($project);
        $plan = DeliveryService::plan($project);
        $current = DeliveryService::currentFor((int) $project['id']);
        $appt = $current === null ? null : DeliveryService::appointment((int) $current['id']);
        $mode = $request->string('mode') === 'reschedule' && $appt !== null && $appt['status'] === 'confirmed' ? 'reschedule' : 'book';
        $showCalendar = $role === 'client' ? ($mode === 'reschedule' || in_array($plan['state'], ['open', 'preparing'], true)) : in_array($plan['state'], ['open', 'preparing', 'booked'], true);
        $ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')) ? $request->string('month') : substr(max((string) ($plan['earliest'] ?? ''), AppTime::today()), 0, 7);
        $cal = $showCalendar ? DeliveryService::month($ym, $plan) : null;
        $selected = $request->string('date');
        $selectedOk = false;
        if ($cal !== null && DeliveryService::validDate($selected)) {
            foreach ($cal['weeks'] as $w) {
                foreach ($w as $d) {
                    $selectedOk = $selectedOk || ($d !== null && $d['date'] === $selected && $d['state'] === 'available');
                }
            }
        }
        $data = [
            'title' => 'Delivery appointment · ' . $project['title'], 'project' => $project, 'tab' => 'delivery', 'role' => $role, 'plan' => $plan, 'appt' => $appt, 'cal' => $cal, 'mode' => $mode,
            'selected' => $selectedOk ? $selected : '', 'base' => $this->backTo($project), 'sources' => DeliveryService::SOURCES,
        ];
        if ($role === 'client') {
            $data['may'] = $appt !== null && $appt['status'] === 'confirmed' ? ['reschedule' => DeliveryService::clientMay($appt, 'reschedule'), 'cancel' => DeliveryService::clientMay($appt, 'cancel')] : null;
        } else {
            $uid = $this->actorId();
            $data += [
                'history' => $appt !== null ? DeliveryService::history((int) $appt['id']) : [], 'canConfigure' => Gate::can('delivery.configure', $uid), 'canManage' => Gate::can('delivery.manage', $uid),
                'isOwner' => Gate::hasRole('super_admin', $uid), 'liveLinks' => DeliveryPublic::liveTokens((int) $project['id']), 'publicMode' => DeliveryPublic::mode(),
                'all' => Database::all('SELECT * FROM delivery_appointments WHERE project_id = ? ORDER BY id DESC', [$project['id']]), 'newLink' => Session::pullFlash('delivery_new_link'),
            ];
        }
        $r = $this->page('delivery/project', $data);
        Session::forget('_old');

        return $r;
    }

    /** The client's projects with their delivery status. @param array<string,string> $params */
    public function mine(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $rows = Database::all(
            "SELECT p.* FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.archived_at IS NULL AND p.status NOT IN ('draft','cancelled') ORDER BY p.id DESC",
            [$uid],
        );
        $items = [];
        foreach ($rows as $p) {
            $cur = DeliveryService::currentFor((int) $p['id']);
            $items[] = ['project' => $p, 'plan' => DeliveryService::plan($p), 'appt' => $cur === null ? null : DeliveryService::appointment((int) $cur['id'])];
        }

        return $this->page('delivery/mine', ['title' => 'Delivery appointments', 'items' => $items]);
    }

    // ------------------------------------------------------------------------------------------------ client actions

    /** @param array<string,string> $params */
    public function book(Request $request, array $params = []): Response
    {
        $project = $this->ownProject($request);
        $to = $this->backTo($project);

        return $this->guarded(function () use ($request, $project, $to): Response {
            if (!RateLimiter::hit('delivery_user', (string) $this->actorId())) {
                throw new HttpException(429);
            }
            $a = DeliveryService::book($project, $request->string('date'), 'client_dashboard', $this->actorId(), ['client_notes' => $request->string('client_notes')]);

            return $this->ok($to, 'Your delivery appointment is confirmed for ' . DeliveryService::nice((string) $a['appointment_date']) . '. Reference ' . $a['booking_reference'] . '.');
        }, $to . '?month=' . urlencode(substr($request->string('date'), 0, 7)));
    }

    /** @param array<string,string> $params */
    public function reschedule(Request $request, array $params = []): Response
    {
        $project = $this->ownProject($request);
        $to = $this->backTo($project);

        return $this->guarded(function () use ($request, $project, $to): Response {
            if (!RateLimiter::hit('delivery_user', (string) $this->actorId())) {
                throw new HttpException(429);
            }
            $a = DeliveryService::clientReschedule($this->ownAppointment($request, $project), $request->string('date'), $this->actorId());

            return $this->ok($to, 'Your delivery appointment was moved to ' . DeliveryService::nice((string) $a['appointment_date']) . '.');
        }, $to . '?mode=reschedule');
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $project = $this->ownProject($request);
        $to = $this->backTo($project);

        return $this->guarded(function () use ($request, $project, $to): Response {
            DeliveryService::clientCancel($this->ownAppointment($request, $project), $this->actorId());

            return $this->ok($to, 'Your delivery appointment was cancelled. You can book a new date whenever you are ready.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function requestChange(Request $request, array $params = []): Response
    {
        $project = $this->ownProject($request);
        $to = $this->backTo($project);

        return $this->guarded(function () use ($request, $project, $to): Response {
            if (!RateLimiter::hit('delivery_user', (string) $this->actorId())) {
                throw new HttpException(429);
            }
            DeliveryService::requestChange($this->ownAppointment($request, $project), $request->string('kind'), $request->string('note'), $this->actorId());

            return $this->ok($to, 'Thank you. Our team has your request and will contact you.');
        }, $to, ['note' => $request->string('note')]);
    }

    // ------------------------------------------------------------------------------------------------ team actions on this project

    /** @param array<string,string> $params */
    public function savePlan(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = $this->backTo($project);
        $in = ['preparation_days' => $request->string('preparation_days'), 'prep_start_date' => $request->string('prep_start_date'), 'earliest_date' => $request->string('earliest_date'),
            'latest_date' => $request->string('latest_date'), 'booking_enabled' => $request->string('booking_enabled'), 'allow_multiple' => $request->string('allow_multiple')];

        return $this->guarded(function () use ($project, $in, $to): Response {
            $r = DeliveryService::savePlan($this->actorId(), $project, $in);
            $msg = 'Delivery settings saved. Booking opens on ' . local_date((string) DeliveryService::plan($project)['eligible_from'], 'j F Y') . '.';
            if ($r['earlier'] > 0) {
                $msg .= ' ' . $r['earlier'] . ' confirmed appointment(s) of this project fall before the new first date; they were left unchanged.';
            }

            return $this->ok($to, $msg);
        }, $to, $in);
    }

    /** Creates a private booking link. The link is shown once. @param array<string,string> $params */
    public function createLink(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = $this->backTo($project);

        return $this->guarded(function () use ($request, $project, $to): Response {
            $token = DeliveryPublic::createToken($this->actorId(), $project);
            $url = \Vertex\Services\AccountMail::link('/delivery-appointment/link/' . $token);
            Session::flash('delivery_new_link', $url);
            if ($request->string('send_email') === '1') {
                $client = Database::one('SELECT contact_name, email FROM clients WHERE id = ?', [$project['client_id']]);
                if ($client !== null) {
                    DeliveryMail::bookingLink((string) $client['email'], (string) $client['contact_name'], (string) $project['title'], $url, local_date(AppTime::todayPlus((int) \Vertex\Core\Config::get('delivery.token_days', 30)), 'j F Y'));
                }
            }

            return $this->ok($to, 'The booking link was created. Copy it now: it is not shown again.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function revokeLinks(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $n = DeliveryPublic::revokeTokens($this->actorId(), $project);

        return $this->ok($this->backTo($project), $n . ' booking link(s) switched off.');
    }

    /** A booking made by the team for this project's client. @param array<string,string> $params */
    public function manual(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = $this->backTo($project);
        $in = ['date' => $request->string('date'), 'source' => $request->string('source'), 'internal_notes' => $request->string('internal_notes')];

        return $this->guarded(function () use ($request, $project, $in, $to): Response {
            $source = in_array($in['source'], ['manual', 'admin_reserved'], true) ? $in['source'] : 'manual';
            $a = DeliveryService::book($project, $in['date'], $source, $this->actorId(), ['internal_notes' => $in['internal_notes'], 'override_eligibility' => $request->string('override') === '1']);

            return $this->ok($to, 'Booked for ' . DeliveryService::nice((string) $a['appointment_date']) . '. Reference ' . $a['booking_reference'] . '. The client was emailed.');
        }, $to, $in);
    }
}
