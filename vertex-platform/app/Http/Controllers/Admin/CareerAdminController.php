<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CareerService;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;

/** Job and freelance applications from the Careers page. Personal data: only people with `careers.view` / `careers.manage` reach any of this. */
final class CareerAdminController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = ['status' => $request->string('status'), 'q' => $request->string('q')];
        $res = CareerService::paginate($uid, $f, $request->page(), 25);

        return $this->page('admin/careers/index', ['title' => 'Job applications', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $request->page(), 'f' => $f, 'statuses' => CareerService::STATUSES]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = CareerService::requireView($uid, (string) ($params['app'] ?? ''));

        return $this->page('admin/careers/show', ['title' => $a['reference'], 'a' => $a, 'statuses' => CareerService::STATUSES, 'canManage' => Gate::can('careers.manage', $uid), 'resume' => $a['file_id'] === null ? null : FileStore::find((int) $a['file_id'])]);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = CareerService::requireView($uid, (string) ($params['app'] ?? ''));
        $to = '/admin/careers/' . $a['public_id'];

        return $this->guarded(function () use ($request, $uid, $a, $to): Response {
            CareerService::setStatus($uid, $a, $request->string('status'), $request->string('internal_notes'));

            return $this->ok($to, 'Status saved.');
        }, $to, ['internal_notes' => $request->string('internal_notes')]);
    }

    /** The résumé or work sample, as a forced download (never displayed in the browser). @param array<string,string> $params */
    public function file(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = CareerService::requireView($uid, (string) ($params['app'] ?? ''));
        $file = CareerService::fileFor($uid, $a);

        return FileStore::send($file);
    }
}
