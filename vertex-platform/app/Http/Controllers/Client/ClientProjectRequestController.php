<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Client;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ClientProjectRequestService;
use Vertex\Services\EnquiryExtras;
use Vertex\Services\FileStore;

/** The client's own "request a new project" form and the list of what they have asked for (a signed-in client; their own record only). */
final class ClientProjectRequestController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('my-requests/index', ['title' => 'My project requests', 'rows' => ClientProjectRequestService::listFor($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $client = ClientProjectRequestService::client($this->actorId());
        $services = EnquiryExtras::serviceChoices();
        $r = $this->page('my-requests/new', ['title' => 'Request a new project', 'client' => $client, 'categories' => EnquiryExtras::CATEGORIES, 'timelines' => EnquiryExtras::TIMELINES, 'budgets' => (array) Config::get('workflow.budget_ranges'),
            'serviceChoices' => $services !== [] ? $services : (array) Config::get('workflow.services'), 'limit' => FileStore::humanSize(FileStore::effectiveLimit('enquiry')), 'extensions' => implode(', ', FileStore::allowedExtensions())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $old = array_intersect_key($request->body, array_flip(['project_name', 'project_category', 'problem_statement', 'expected_outcome', 'features', 'preferred_tech', 'budget_range', 'expected_deadline', 'timeline', 'notes']));

        return $this->guarded(function () use ($request, $uid): Response {
            $r = ClientProjectRequestService::submit($uid, $request->body, self::uploadedFiles($request->files['attachments'] ?? null), $request->ip());

            return $this->ok('/my/project-requests', 'Your request ' . $r['reference'] . ' was sent. We will review it and contact you. You can follow it here.');
        }, '/my/project-requests/new', array_map(static fn ($v): string => is_string($v) ? $v : '', $old));
    }
}
