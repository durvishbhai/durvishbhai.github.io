<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;
use Vertex\Services\TicketAccess;
use Vertex\Services\TicketQuery;
use Vertex\Services\TicketService;
use Vertex\Services\WarrantyService;

/** The client's Support Centre: report a bug, open a ticket, see their own tickets and warranty. Everything is scoped to the signed-in client. */
final class SupportController extends Controller
{
    /** @param array<string,string> $params */
    public function home(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        if (TicketQuery::staffView($uid) && Gate::can('support.dashboard', $uid)) {
            return Response::redirect('/support/dashboard');
        }
        $open = TicketQuery::paginate($uid, ['group' => 'open'], 1, 5);
        $resolved = TicketQuery::paginate($uid, ['group' => 'resolved'], 1, 1);
        $drafts = TicketQuery::paginate($uid, ['group' => 'draft'], 1, 5);

        return $this->page('pages/support-home', [
            'title' => 'Support Centre', 'open' => $open, 'resolvedTotal' => $resolved['total'], 'drafts' => $drafts['rows'], 'warranty' => WarrantyService::forClient($uid),
            'unread' => TicketQuery::unreadForClient($uid), 'canBug' => Gate::can('bugs.report', $uid), 'canTicket' => Gate::can('tickets.create', $uid),
        ]);
    }

    /** @param array<string,string> $params */
    public function bugForm(Request $request, array $params = []): Response
    {
        $r = $this->page('pages/bug-new', $this->formData('Report a bug', null));
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function bugStore(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $in = [
            'project' => $request->string('project'), 'title' => $request->string('title'), 'description' => $request->string('description'), 'affected_url' => $request->string('affected_url'),
            'steps' => $request->string('steps'), 'expected' => $request->string('expected'), 'actual' => $request->string('actual'), 'browser' => $request->string('browser'),
            'device' => $request->string('device'), 'severity' => $request->string('severity'), 'token' => $request->string('token'),
        ];
        $draft = $request->string('action') === 'draft';

        return $this->guarded(function () use ($request, $uid, $in, $draft): Response {
            $res = TicketService::createBug($uid, $in, self::uploadedFiles($request->files['files'] ?? []), $draft, $request->userAgent());
            $msg = $draft ? "Draft {$res['reference']} saved. Nobody on our team can see it until you submit it."
                : ($res['created'] ? "Thank you. Your bug report {$res['reference']} was submitted and our team will review it." : "Your bug report {$res['reference']} was already submitted.");

            return $this->ok('/tickets/' . $res['public_id'], $msg);
        }, '/support/bugs/new', $in);
    }

    /** @param array<string,string> $params */
    public function ticketForm(Request $request, array $params = []): Response
    {
        $r = $this->page('pages/ticket-new', $this->formData('Create a support ticket', null));
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function ticketStore(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $in = ['category' => $request->string('category'), 'subject' => $request->string('subject'), 'description' => $request->string('description'), 'project' => $request->string('project'), 'token' => $request->string('token')];

        return $this->guarded(function () use ($request, $uid, $in): Response {
            $res = TicketService::createSupport($uid, $in, self::uploadedFiles($request->files['files'] ?? []));

            return $this->ok('/tickets/' . $res['public_id'], $res['created'] ? "Your ticket {$res['reference']} was created." : "Your ticket {$res['reference']} was already created.");
        }, '/support/tickets/new', $in);
    }

    /** @param array<string,string> $params */
    public function warranty(Request $request, array $params = []): Response
    {
        return $this->page('pages/client-warranty', ['title' => 'Website warranty', 'items' => WarrantyService::forClient($this->actorId())]);
    }

    /** @return array<string,mixed> */
    public function formData(string $title, ?array $t): array
    {
        $uid = $this->actorId();

        return [
            'title' => $title, 'projects' => TicketQuery::ownProjects($uid), 't' => $t, 'token' => bin2hex(random_bytes(16)),
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('ticket')), 'types' => implode(', ', FileStore::allowedExtensions()),
        ];
    }
}
