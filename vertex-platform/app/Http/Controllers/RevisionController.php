<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Audit;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;
use Vertex\Services\Notifier;
use Vertex\Services\RateLimiter;
use Vertex\Services\RevisionAccess;
use Vertex\Services\RevisionQuery;
use Vertex\Services\RevisionService;
use Vertex\Services\RevisionTerms;
use Vertex\Services\TaskQuery;

/**
 * Revision requests. Access is decided per request by RevisionAccess (staff: revisions.manage + project access; client: their own
 * requests only). Staff and client get different templates and different data (internal notes, classification and draft proposals
 * never reach the client templates).
 */
final class RevisionController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $staff = Gate::can('revisions.manage', $uid) && Gate::canAny(['projects.view_all', 'projects.view_assigned'], $uid);
        $f = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'status' => in_array($request->string('status'), array_keys((array) Config::get('workflow.revision_statuses')), true) ? $request->string('status') : '',
            'project' => preg_match('/^[a-f0-9]{32}$/', $request->string('project')) ? $request->string('project') : '',
            'mine' => $staff && $request->string('mine') === '1',
            'open' => $request->string('open') === '1',
        ];
        $page = $request->page();
        $res = RevisionQuery::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page($staff ? 'pages/revisions' : 'pages/client-revisions', [
            'title' => $staff ? 'Revision review queue' : 'My revision requests', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false), 'projects' => $staff ? TaskQuery::projectsFor($uid) : [],
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $this->requireClient($uid, $d);
        $state = \Vertex\Services\ClientApprovalService::stateFor($d);
        $r = $this->page('pages/revision-new', [
            'title' => 'Request a revision', 'd' => $d, 'version' => $state['version'], 'token' => bin2hex(random_bytes(16)),
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('revision')), 'types' => implode(', ', FileStore::allowedExtensions()),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $this->requireClient($uid, $d);
        $to = '/deliverables/' . $d['public_id'] . '/revisions/new';
        $in = ['description' => $request->string('description'), 'version' => $request->string('version'), 'token' => $request->string('token')];

        return $this->guarded(function () use ($request, $uid, $d, $in): Response {
            $res = RevisionService::submit($uid, $d, $in, self::files($request->files['files'] ?? []));

            return $this->ok('/revisions/' . $res['public_id'], $res['created'] ? "Your request {$res['reference']} was sent." : "Your request {$res['reference']} was already sent.");
        }, $to, ['description' => $in['description']]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = RevisionAccess::requireView($uid, $params['revision'] ?? '');
        $role = (string) RevisionAccess::role($uid, $r);
        $project = RevisionAccess::project($r);
        $moves = RevisionService::allowedMoves($uid, $r);
        $data = [
            'title' => $r['reference'], 'r' => $r, 'history' => RevisionQuery::history($uid, $r), 'files' => RevisionQuery::files($r), 'changes' => RevisionQuery::changes($uid, $r),
            'moves' => $moves, 'canDownload' => Gate::can('files.download', $uid),
        ];
        if ($role === 'client') {
            $page = $this->page('pages/revision-client', ['r' => RevisionAccess::forClient($r)] + $data);
            Session::forget('_old');

            return $page;
        }
        $versions = Database::all('SELECT public_id, version_no, label, status FROM deliverable_versions WHERE deliverable_id = ? AND version_no <> ? ORDER BY version_no DESC', [$r['deliverable_id'], $r['version_no']]);
        $terms = RevisionTerms::forProject($project, (int) $r['id']);
        $page = $this->page('pages/revision', $data + [
            'notes' => RevisionQuery::notes($uid, $r), 'terms' => $terms, 'snapshot' => json_decode((string) $r['policy_snapshot_json'], true) ?: [], 'versions' => $versions,
            'canClassify' => Gate::can('revisions.classify', $uid) && $r['status'] === 'under_review', 'canOverride' => Gate::can('revisions.override', $uid) && $r['status'] === 'potentially_chargeable',
            'people' => Notifier::staffWith('revisions.manage', (int) $r['project_id']) === [] ? [] : self::reviewers((int) $r['project_id']),
            'overAllowance' => $terms['limit'] !== null && $terms['used'] >= $terms['limit'],
        ]);
        Session::forget('_old');

        return $page;
    }

    /** @param array<string,string> $params */
    public function transition(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $r, $to): Response {
            RevisionService::transition($this->actorId(), $r, $request->string('status'), $request->string('client_note'), $request->string('internal_note'), $request->string('resolved_version') ?: null);

            return $this->ok($to, 'Status updated.');
        }, $to, ['client_note' => $request->string('client_note'), 'internal_note' => $request->string('internal_note')]);
    }

    /** @param array<string,string> $params */
    public function classify(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);
        $in = [
            'reason' => $request->string('reason'), 'scope_description' => $request->string('scope_description'), 'client_reason' => $request->string('client_reason'),
            'amount' => $request->string('amount'), 'days' => $request->string('days'), 'due_date' => $request->string('due_date'),
        ];

        return $this->guarded(function () use ($request, $r, $in, $to): Response {
            $decision = $request->string('decision');
            RevisionService::classify($this->actorId(), $r, $decision, $in);

            return $this->ok($to, $decision === 'included' ? 'Classified as included.' : 'Classified as potentially chargeable. A DRAFT proposal was created; nothing has been sent to the client.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function override(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $r, $to): Response {
            $this->requireStepUp($request);
            RevisionService::overrideToIncluded($this->actorId(), $r, $request->string('reason'));

            return $this->ok($to, 'Overridden to included. The override is recorded in the audit log.');
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $r, $to): Response {
            RevisionService::assign($this->actorId(), $r, $request->string('assignee'));

            return $this->ok($to, 'Assignment saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $r, $to): Response {
            RevisionService::addNote($this->actorId(), $r, $request->string('body'));

            return $this->ok($to, 'Internal note added. The client cannot see it.');
        }, $to, ['note' => $request->string('body')]);
    }

    /** @param array<string,string> $params */
    public function response(Request $request, array $params = []): Response
    {
        [$r, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $r, $to): Response {
            RevisionService::setClientResponse($this->actorId(), $r, $request->string('response'));

            return $this->ok($to, 'Response saved. The client can read it.');
        }, $to, ['response' => $request->string('response')]);
    }

    /** Attachment of a revision request. Authorised through the REQUEST; the file must belong to it. @param array<string,string> $params */
    public function file(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = RevisionAccess::requireView($uid, $params['revision'] ?? '');
        $pid = (string) ($params['file'] ?? '');
        $f = preg_match('/^[a-f0-9]{32}$/', $pid) ? Database::one('SELECT f.* FROM revision_files rf JOIN stored_files f ON f.id = rf.file_id WHERE rf.revision_id = ? AND f.public_id = ?', [$r['id'], $pid]) : null;
        if ($f === null || !Gate::can('files.download', $uid)) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('download_user', (string) $uid)) {
            throw new HttpException(429);
        }
        $response = FileStore::send($f);
        Audit::record('revision.file_downloaded', 'revision', (int) $r['id'], [], [], ['project_id' => (int) $r['project_id']], $uid);

        return $response;
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string} */
    private function load(array $params): array
    {
        $r = RevisionAccess::requireView($this->actorId(), $params['revision'] ?? '');

        return [$r, '/revisions/' . $r['public_id']];
    }

    /** @param array<string,mixed> $d */
    private function requireClient(int $uid, array $d): void
    {
        if (DeliverablePolicy::level($uid, $d) !== 'own' || !Gate::can('revisions.request', $uid)) {
            throw new HttpException(403);
        }
    }

    /** Normalises a multi-file $_FILES entry into a list of single entries. @param mixed $f @return list<array<string,mixed>> */
    private static function files(mixed $f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i], 'type' => $f['type'][$i] ?? ''];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function reviewers(int $projectId): array
    {
        $ids = Notifier::staffWith('revisions.manage', $projectId);

        return $ids === [] ? [] : Database::all('SELECT public_id, name FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY name');
    }
}
