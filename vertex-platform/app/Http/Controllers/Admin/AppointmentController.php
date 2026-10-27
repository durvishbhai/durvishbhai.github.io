<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AppointmentConfig;

/** Administration → Appointments: the times a visitor can choose, the meeting places, and the latest requests. */
final class AppointmentController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $requests = Database::all(
            "SELECT e.public_id, e.reference, e.contact_name, e.email, e.phone, e.created_at, s.name AS stage, d.details_json
               FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id LEFT JOIN enquiry_details d ON d.enquiry_id = e.id
              WHERE e.source = 'appointment_request' AND e.archived_at IS NULL ORDER BY e.id DESC LIMIT 30",
        );
        foreach ($requests as &$r) {
            $r['d'] = $r['details_json'] ? (array) json_decode((string) $r['details_json'], true) : [];
        }
        $r = $this->page('admin/appointments/index', ['title' => 'Appointments', 'slots' => AppointmentConfig::slots(), 'places' => AppointmentConfig::places(), 'dayNames' => AppointmentConfig::dayNames(), 'requests' => $requests, 'host' => AppointmentConfig::host()]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveSlot(Request $request, array $params = []): Response
    {
        $id = isset($params['slot']) ? (int) $params['slot'] : null;

        return $this->guarded(function () use ($request, $id): Response {
            AppointmentConfig::saveSlot($this->actorId(), $id, $request->body);

            return $this->ok('/admin/appointments#times', $id === null ? 'The time was added.' : 'The time was saved.');
        }, '/admin/appointments#times', array_intersect_key($request->body, array_flip(['label', 'start_time', 'end_time'])));
    }

    /** @param array<string,string> $params */
    public function deleteSlot(Request $request, array $params = []): Response
    {
        AppointmentConfig::slot((int) ($params['slot'] ?? 0)) ?? throw new HttpException(404);
        AppointmentConfig::deleteSlot($this->actorId(), (int) $params['slot']);

        return $this->ok('/admin/appointments#times', 'The time was deleted.');
    }

    /** @param array<string,string> $params */
    public function savePlace(Request $request, array $params = []): Response
    {
        $id = isset($params['place']) ? (int) $params['place'] : null;

        return $this->guarded(function () use ($request, $id): Response {
            AppointmentConfig::savePlace($this->actorId(), $id, $request->body);

            return $this->ok('/admin/appointments#places', $id === null ? 'The place was added.' : 'The place was saved.');
        }, '/admin/appointments#places', array_intersect_key($request->body, array_flip(['name', 'details'])));
    }

    /** @param array<string,string> $params */
    public function deletePlace(Request $request, array $params = []): Response
    {
        AppointmentConfig::place((int) ($params['place'] ?? 0)) ?? throw new HttpException(404);
        AppointmentConfig::deletePlace($this->actorId(), (int) $params['place']);

        return $this->ok('/admin/appointments#places', 'The place was deleted.');
    }
}
