<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\ClientPolicy;
use Vertex\Services\ClientService;
use Vertex\Services\Gate;
use Vertex\Services\Mailer;
use Vertex\Services\ProjectPolicy;

/**
 * Client records. Staff see clients according to ClientPolicy (all / those on projects they are assigned to);
 * a client sees ONLY their own record via /my/company. Restricted notes are never available to the client role.
 */
final class ClientController extends Controller
{
    private const PER_PAGE = 20;
    private const FIELDS = ['client_type', 'contact_name', 'email', 'phone', 'company_name', 'website', 'tax_id', 'address'];

    /** @param array<string,string> $params */
    public function mine(Request $request, array $params = []): Response
    {
        $row = Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->actorId()]);
        if ($row === null) {
            throw new HttpException(404);
        }

        return Response::redirect('/clients/' . $row['public_id']);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'type' => in_array($request->string('type'), ['individual', 'business'], true) ? $request->string('type') : '',
            'status' => in_array($request->string('status'), ['active', 'inactive'], true) ? $request->string('status') : '',
            'archived' => Gate::can('clients.view_all', $uid) && $request->string('archived') === '1',
        ];
        $page = max(1, (int) $request->string('page', '1'));
        $res = ClientService::paginate($uid, $f, $page, self::PER_PAGE);
        $qs = $f;
        $qs['archived'] = $f['archived'] ? '1' : '';

        return $this->page('clients/index', ['title' => 'Clients', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => $qs]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('clients/create', ['title' => 'New client']);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->input($request);
        $in['confirm_new'] = $request->string('confirm_new') === '1' ? '1' : '';

        return $this->guarded(function () use ($in): Response {
            $c = ClientService::create($this->actorId(), $in);

            return $this->ok('/clients/' . $c['public_id'], 'Client record created.');
        }, '/clients/create', array_map('strval', $in));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $c = ClientPolicy::requireView($uid, $params['client'] ?? '');
        $level = ClientPolicy::level($uid, $c);
        $r = $this->page('clients/show', [
            'title' => $c['contact_name'], 'c' => $c, 'level' => $level,
            'projects' => ClientService::projectsFor($uid, $c),
            'enquiries' => ClientService::enquiriesFor($uid, $c),
            'notes' => ClientService::notes($uid, $c),
            'canNotes' => ClientPolicy::canStaff($uid, $c, 'clients.notes_add'),
            'history' => ClientService::history($uid, $c),
            'mergeTargets' => $level === 'all' && Gate::can('clients.merge', $uid) && $c['user_id'] === null ? ClientService::mergeTargets((int) $c['id']) : [],
            'mailEnabled' => Mailer::enabled(),
            'mergedInto' => $c['merged_into_client_id'] !== null ? Database::one('SELECT public_id, contact_name FROM clients WHERE id = ?', [$c['merged_into_client_id']]) : null,
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $c = $this->load($params);
        $in = $this->input($request);

        return $this->guarded(function () use ($c, $in): Response {
            ClientService::update($this->actorId(), $c, $in);

            return $this->ok($this->url($c), 'Client updated.');
        }, $this->url($c), $in);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $c = $this->load($params);

        return $this->guarded(function () use ($request, $c): Response {
            ClientService::setStatus($this->actorId(), $c, $request->string('status'));

            return $this->ok($this->url($c), 'Status updated.');
        }, $this->url($c));
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        $c = $this->load($params);

        return $this->guarded(function () use ($request, $c): Response {
            $this->requireStepUp($request);
            ClientService::archive($this->actorId(), $c);

            return $this->ok($this->url($c), 'Client archived.');
        }, $this->url($c));
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        $c = $this->load($params);

        return $this->guarded(function () use ($request, $c): Response {
            ClientService::addNote($this->actorId(), $c, $request->string('body'));

            return $this->ok($this->url($c), 'Note added.');
        }, $this->url($c));
    }

    /** @param array<string,string> $params */
    public function merge(Request $request, array $params = []): Response
    {
        $c = $this->load($params);

        return $this->guarded(function () use ($request, $c): Response {
            $this->requireStepUp($request);
            $target = ClientPolicy::find($request->string('target')) ?? throw new \Vertex\Core\ValidationException(['Choose the client to merge into.']);
            ClientService::merge($this->actorId(), (int) $c['id'], (int) $target['id']);

            return $this->ok('/clients/' . $target['public_id'], 'Records merged. The old record is archived and everything it held now belongs to this client.');
        }, $this->url($c));
    }

    /** @param array<string,string> $params */
    public function invite(Request $request, array $params = []): Response
    {
        $c = $this->load($params);

        return $this->guarded(function () use ($c): Response {
            $r = ClientService::invite($this->actorId(), $c);

            return $this->ok($this->url($c), $r['invite_sent']
                ? 'Portal invitation sent. The client sets their own password.'
                : 'Portal login created but no email could be sent (email is not configured). Use the console command user:set-password.');
        }, $this->url($c));
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function load(array $params): array
    {
        return ClientPolicy::requireView($this->actorId(), $params['client'] ?? '');
    }

    /** @param array<string,mixed> $c */
    private function url(array $c): string
    {
        return '/clients/' . $c['public_id'];
    }

    /** @return array<string,string> */
    private function input(Request $request): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = $request->string($f);
        }

        return $out;
    }
}
