<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\Gate;
use Vertex\Services\RevisionAccess;

/** Additional-cost proposals. The client decides; staff only draft, send and withdraw. Nothing is charged here. */
final class ChangeRequestController extends Controller
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $c = RevisionAccess::requireChange($uid, $params['change'] ?? '');
        $role = (string) RevisionAccess::changeRole($uid, $c);
        $base = json_decode((string) $c['baseline_json'], true) ?: [];
        if ($role === 'client') { // what the client may know about the baseline: no internal assessment
            $base = array_intersect_key($base, array_flip(['quotation_ref', 'approved_total_minor', 'note']));
        }
        $r = $this->page('pages/change-request', [
            'title' => $c['reference'], 'c' => $c, 'role' => $role, 'base' => $base,
            'canDecide' => $role === 'client' && $c['status'] === 'pending_client' && Gate::can('revisions.respond_cost', $uid) && (int) $c['revision_requested_by'] === $uid,
            'canStaff' => $role === 'staff' && Gate::can('revisions.classify', $uid),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        [$c, $to] = $this->load($params);
        $in = ['scope_description' => $request->string('scope_description'), 'client_reason' => $request->string('client_reason'), 'amount' => $request->string('amount'), 'days' => $request->string('days'), 'due_date' => $request->string('due_date')];

        return $this->guarded(function () use ($c, $in, $to): Response {
            ChangeRequestService::updateDraft($this->actorId(), $c, $in);

            return $this->ok($to, 'Draft proposal updated.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function send(Request $request, array $params = []): Response
    {
        [$c, $to] = $this->load($params);

        return $this->guarded(function () use ($c, $to): Response {
            ChangeRequestService::send($this->actorId(), $c);

            return $this->ok($to, 'The proposal was sent to the client. Nothing is charged unless they approve it.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request, array $params = []): Response
    {
        [$c, $to] = $this->load($params);

        return $this->guarded(function () use ($c, $to): Response {
            ChangeRequestService::withdraw($this->actorId(), $c);

            return $this->ok($to, 'Proposal withdrawn.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function decide(Request $request, array $params = []): Response
    {
        [$c, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $c, $to): Response {
            $decision = $request->string('decision');
            ChangeRequestService::clientDecide($this->actorId(), $c, $decision, $request->string('comment'));

            return $this->ok($to, $decision === 'approve' ? 'You approved the additional work. Nothing has been invoiced; the team will confirm the next steps.' : 'You declined the additional work. Nothing was changed.');
        }, $to);
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string} */
    private function load(array $params): array
    {
        $c = RevisionAccess::requireChange($this->actorId(), $params['change'] ?? '');

        return [$c, '/changes/' . $c['public_id']];
    }
}
