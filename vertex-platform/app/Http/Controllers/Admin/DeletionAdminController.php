<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\DataDeletion;

/** The Owner's data deletion queue. Every action is Owner-only (`compliance.manage`) and re-checked in the service. */
final class DeletionAdminController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = ['status' => $request->string('status'), 'q' => mb_substr($request->string('q'), 0, 100)];

        return $this->page('admin/compliance/deletion', ['title' => 'Data deletion requests', 'listing' => DataDeletion::listing($this->actorId(), $f), 'filters' => $f, 'statuses' => DataDeletion::STATUS]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $r = DataDeletion::find((int) ($params['id'] ?? 0));

        return $this->page('admin/compliance/deletion-show', ['title' => 'Deletion request ' . $r['reference'], 'r' => $r, 'plan' => DataDeletion::plan($r), 'events' => DataDeletion::events((int) $r['id'], false), 'result' => $r['result_json'] ? json_decode((string) $r['result_json'], true) : null, 'statuses' => DataDeletion::STATUS]);
    }

    private function act(Request $request, array $params, callable $fn, string $msg): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $to = '/admin/compliance/deletion/' . $id;

        return $this->guarded(function () use ($fn, $id, $to, $msg): Response {
            $fn($this->actorId(), $id);

            return $this->ok($to, $msg);
        }, $to);
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, fn (int $u, int $id) => DataDeletion::verifyByOwner($u, $id, $request->string('note')), 'Identity recorded as verified.');
    }

    /** @param array<string,string> $params */
    public function clarify(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, fn (int $u, int $id) => DataDeletion::clarify($u, $id, $request->string('note')), 'The requester was asked for more information.');
    }

    /** @param array<string,string> $params */
    public function approve(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, fn (int $u, int $id) => DataDeletion::approve($u, $id, $request->string('note')), 'Approved. Review the plan, then process it.');
    }

    /** @param array<string,string> $params */
    public function reject(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, fn (int $u, int $id) => DataDeletion::reject($u, $id, $request->string('note')), 'Rejected. The requester was told the reason.');
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, fn (int $u, int $id) => DataDeletion::addNote($u, $id, $request->string('note')), 'Note added.');
    }

    /** @param array<string,string> $params */
    public function process(Request $request, array $params = []): Response
    {
        return $this->act($request, $params, function (int $u, int $id) use ($request): void {
            $this->requireStepUp($request);
            DataDeletion::execute($u, $id);
        }, 'Processed. The outcome was recorded and emailed to the requester.');
    }
}
