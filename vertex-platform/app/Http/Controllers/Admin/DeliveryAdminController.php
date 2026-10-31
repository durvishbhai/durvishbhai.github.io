<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AppTime;
use Vertex\Services\DeliveryPublic;
use Vertex\Services\DeliveryService;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;

/** The team's Delivery Appointment Management: overview, calendar, every appointment, manual bookings and (for those allowed) daily capacity. */
final class DeliveryAdminController extends Controller
{
    private const PER_PAGE = 25;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = ['from' => $request->string('from'), 'to' => $request->string('to'), 'status' => $request->string('status'), 'source' => $request->string('source'),
            'project' => mb_substr($request->string('project'), 0, 100), 'client' => mb_substr($request->string('client'), 0, 100), 'requests' => $request->string('requests') === '1'];
        $page = $request->page();
        $res = DeliveryService::search($uid, $f, $page, self::PER_PAGE);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')) ? $request->string('month') : substr(AppTime::today(), 0, 7);
        $date = DeliveryService::validDate($request->string('date')) ? $request->string('date') : '';
        $r = $this->page('admin/delivery/index', [
            'title' => 'Delivery appointments', 'stats' => DeliveryService::stats($uid), 'cal' => DeliveryService::month($month, null), 'date' => $date,
            'dateRows' => $date !== '' ? DeliveryService::onDate($uid, $date) : [], 'dateInfo' => $date !== '' ? DeliveryService::day($date) : null,
            'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false),
            'canManage' => Gate::can('delivery.manage', $uid), 'canConfigure' => Gate::can('delivery.configure', $uid), 'projects' => Gate::can('delivery.manage', $uid) ? DeliveryService::bookableProjects($uid) : [],
            'sources' => DeliveryService::SOURCES, 'statuses' => DeliveryService::STATUSES,
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function capacity(Request $request, array $params = []): Response
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->string('month')) ? $request->string('month') : substr(AppTime::today(), 0, 7);
        $cal = DeliveryService::month($month, null);
        $r = $this->page('admin/delivery/capacity', [
            'title' => 'Delivery capacity', 'cal' => $cal, 'overrides' => DeliveryService::overrides(), 'reservations' => DeliveryService::reservations(AppTime::today(), AppTime::todayPlus(730)),
            'log' => DeliveryService::generalLog(30), 'defaultCapacity' => DeliveryService::defaultCapacity(),
            'values' => ['notify_email' => (string) \Vertex\Core\Config::get('delivery.notify_email', ''), 'public_mode' => DeliveryPublic::mode(), 'client_cutoff_days' => (int) \Vertex\Core\Config::get('delivery.client_cutoff_days', 3),
                'max_reschedules' => (int) \Vertex\Core\Config::get('delivery.max_reschedules', 2), 'max_days_ahead' => (int) \Vertex\Core\Config::get('delivery.max_days_ahead', 180)],
        ]);
        Session::forget('_old');

        return $r;
    }

    private function toCapacity(Request $request): string
    {
        return '/admin/delivery/capacity' . (preg_match('/^\d{4}-\d{2}$/', $request->string('month')) ? '?month=' . $request->string('month') : '');
    }

    /** @param array<string,string> $params */
    public function saveDefaults(Request $request, array $params = []): Response
    {
        $in = $request->body;

        return $this->guarded(function () use ($request, $in): Response {
            DeliveryService::saveDefaults($this->actorId(), $in);

            return $this->ok($this->toCapacity($request), 'Delivery settings saved.');
        }, '/admin/delivery/capacity', array_map('strval', array_filter($in, 'is_scalar')));
    }

    /** @param array<string,string> $params */
    public function setCapacity(Request $request, array $params = []): Response
    {
        $raw = trim($request->string('capacity'));

        return $this->guarded(function () use ($request, $raw): Response {
            if ($raw !== '' && !ctype_digit($raw)) {
                throw new ValidationException(['Capacity must be a whole number, or empty to use the default.']);
            }
            DeliveryService::setDateCapacity($this->actorId(), $request->string('date'), $raw === '' ? null : (int) $raw);

            return $this->ok($this->toCapacity($request), 'Capacity saved for ' . local_date($request->string('date'), 'j F Y') . '. Appointments already confirmed were not changed.');
        }, $this->toCapacity($request), ['date' => $request->string('date'), 'capacity' => $raw]);
    }

    /** @param array<string,string> $params */
    public function block(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $n = DeliveryService::blockDate($this->actorId(), $request->string('date'), $request->string('reason'));

            return $this->ok($this->toCapacity($request), local_date($request->string('date'), 'j F Y') . ' is now marked fully booked.' . ($n > 0 ? ' ' . $n . ' confirmed appointment(s) on it stay as they are.' : ''));
        }, $this->toCapacity($request), ['date' => $request->string('date'), 'reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function unblock(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            DeliveryService::unblockDate($this->actorId(), $request->string('date'));

            return $this->ok($this->toCapacity($request), local_date($request->string('date'), 'j F Y') . ' is open for booking again.');
        }, $this->toCapacity($request));
    }

    /** @param array<string,string> $params */
    public function reserve(Request $request, array $params = []): Response
    {
        $slots = trim($request->string('slots'));

        return $this->guarded(function () use ($request, $slots): Response {
            DeliveryService::reserve($this->actorId(), $request->string('date'), ctype_digit($slots) ? (int) $slots : 0, $request->string('reason'));

            return $this->ok($this->toCapacity($request), 'Capacity reserved. Clients now see fewer slots on that date.');
        }, $this->toCapacity($request), ['date' => $request->string('date'), 'slots' => $slots, 'reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function releaseReservation(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request, $params): Response {
            DeliveryService::releaseReservation($this->actorId(), (int) ($params['id'] ?? 0));

            return $this->ok($this->toCapacity($request), 'The reservation was released.');
        }, $this->toCapacity($request));
    }

    // ------------------------------------------------------------------------------------------------ appointments

    /** @return array<string,mixed> */
    private function appt(array $params): array
    {
        $a = DeliveryService::appointment((int) ($params['id'] ?? 0)) ?? throw new HttpException(404);
        $project = ProjectPolicy::findById((int) $a['project_id']);
        $scope = DeliveryService::scope($this->actorId());
        if ($project === null || $scope === null || ($scope['params'] !== [] && !ProjectPolicy::isAssigned($this->actorId(), (int) $a['project_id']))) {
            throw new HttpException(404);
        }

        return $a;
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);
        $uid = $this->actorId();
        $project = ProjectPolicy::findById((int) $a['project_id']);
        $r = $this->page('admin/delivery/show', [
            'title' => 'Delivery appointment ' . $a['booking_reference'], 'a' => $a, 'project' => $project, 'plan' => DeliveryService::plan($project), 'history' => DeliveryService::history((int) $a['id']),
            'day' => DeliveryService::day((string) $a['appointment_date']), 'canManage' => Gate::can('delivery.manage', $uid) && in_array(ProjectPolicy::level($uid, $project), ['all', 'assigned'], true),
            'canConfigure' => Gate::can('delivery.configure', $uid), 'sources' => DeliveryService::SOURCES, 'statuses' => DeliveryService::STATUSES,
        ]);
        Session::forget('_old');

        return $r;
    }

    private function toAppt(array $a): string
    {
        return '/admin/delivery/appointments/' . $a['id'];
    }

    /** @param array<string,string> $params */
    public function reschedule(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);

        return $this->guarded(function () use ($request, $a): Response {
            $n = DeliveryService::reschedule($a, $request->string('date'), $this->actorId(), true, $request->string('override') === '1');

            return $this->ok($this->toAppt($a), 'Moved to ' . DeliveryService::nice((string) $n['appointment_date']) . '. The client was emailed.');
        }, $this->toAppt($a), ['date' => $request->string('date')]);
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);

        return $this->guarded(function () use ($request, $a): Response {
            DeliveryService::cancel($a, $this->actorId(), true, $request->string('reason'));

            return $this->ok($this->toAppt($a), 'The appointment was cancelled and its date is free again. The client was emailed.');
        }, $this->toAppt($a), ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function complete(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);

        return $this->guarded(function () use ($a): Response {
            DeliveryService::complete($a, $this->actorId());

            return $this->ok($this->toAppt($a), 'Marked as completed.');
        }, $this->toAppt($a));
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);

        return $this->guarded(function () use ($request, $a): Response {
            DeliveryService::addNote($a, $this->actorId(), $request->string('note'));

            return $this->ok($this->toAppt($a), 'Note added.');
        }, $this->toAppt($a), ['note' => $request->string('note')]);
    }

    /** @param array<string,string> $params */
    public function dismiss(Request $request, array $params = []): Response
    {
        $a = $this->appt($params);

        return $this->guarded(function () use ($a): Response {
            DeliveryService::dismissRequest($a, $this->actorId());

            return $this->ok($this->toAppt($a), 'The request was closed without changing the appointment.');
        }, $this->toAppt($a));
    }

    /** A booking for a client who asked by phone or WhatsApp. @param array<string,string> $params */
    public function manual(Request $request, array $params = []): Response
    {
        $in = ['project' => $request->string('project'), 'date' => $request->string('date'), 'source' => $request->string('source'), 'internal_notes' => $request->string('internal_notes')];

        return $this->guarded(function () use ($request, $in): Response {
            $project = ProjectPolicy::find($in['project']);
            if ($project === null || !ProjectPolicy::canStaff($this->actorId(), $project, 'delivery.manage')) {
                throw new ValidationException(['Choose one of your projects.']);
            }
            $source = in_array($in['source'], ['manual', 'admin_reserved'], true) ? $in['source'] : 'manual';
            $a = DeliveryService::book($project, $in['date'], $source, $this->actorId(), ['internal_notes' => $in['internal_notes'], 'override_eligibility' => $request->string('override') === '1']);

            return $this->ok($this->toAppt($a), 'Booked for ' . DeliveryService::nice((string) $a['appointment_date']) . '. The client was emailed.');
        }, '/admin/delivery', $in);
    }
}
