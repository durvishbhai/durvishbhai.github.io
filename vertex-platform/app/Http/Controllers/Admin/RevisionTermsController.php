<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Gate;
use Vertex\Services\RevisionTerms;
use Vertex\Services\TaskQuery;

/** Revision policy foundation: what the agreement says about included revisions. Nothing is assumed when empty. */
final class RevisionTermsController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        RevisionTerms::authorizeView($this->actorId());
        $r = $this->page('admin/revision-policies', [
            'title' => 'Revision policies', 'current' => RevisionTerms::current(), 'history' => RevisionTerms::history(40),
            'canManage' => Gate::can('revisions.policy', $this->actorId()), 'projects' => TaskQuery::projectsFor($this->actorId()) ?: \Vertex\Core\Database::all('SELECT public_id, code, title FROM projects WHERE archived_at IS NULL ORDER BY title LIMIT 300'),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $in = ['scope' => $request->string('scope'), 'service' => $request->string('service'), 'project' => $request->string('project'), 'included' => $request->string('included'),
            'terms' => $request->string('terms'), 'source_reference' => $request->string('source_reference'), 'reason' => $request->string('reason')];

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            $changed = RevisionTerms::set($this->actorId(), $in);

            return $this->ok('/admin/revision-policies', $changed ? 'New policy version saved. Requests already classified keep the snapshot they were classified under.' : 'Nothing changed.');
        }, '/admin/revision-policies', $in);
    }
}
