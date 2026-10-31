<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Audit;
use Vertex\Services\ClientApprovalService;
use Vertex\Services\DeliverablePolicy;
use Vertex\Services\DeliverableQuery;
use Vertex\Services\DeliverableService;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\RateLimiter;
use Vertex\Services\RevisionQuery;
use Vertex\Services\TaskQuery;

/**
 * Deliverables. Route middleware is only the first gate: DeliverablePolicy decides per record (404 when not visible) and
 * DeliverableService re-checks the specific permission on every mutation. Staff and client get DIFFERENT templates, and the data
 * handed to the client template is already stripped of internal fields.
 */
final class DeliverableController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        if (!in_array(true, [Gate::can('projects.view_all', $uid), Gate::can('projects.view_assigned', $uid)], true)) {
            return Response::redirect('/my/deliverables');
        }
        $f = $this->filters($request);
        $page = $request->page();
        $res = DeliverableQuery::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page('pages/deliverables', [
            'title' => 'Deliverables', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => $f,
            'projects' => TaskQuery::projectsFor($uid) ?: self::projectChoices($uid), 'qaCounts' => $this->qaCounts($uid),
        ]);
    }

    /** @param array<string,string> $params */
    public function qa(Request $request, array $params = []): Response
    {
        return $this->page('pages/deliverable-qa', ['title' => 'QA review queue', 'queue' => DeliverableQuery::qaQueue($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function projectIndex(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $level = (string) ProjectPolicy::level($uid, $project);
        $staff = in_array($level, ['all', 'assigned'], true);
        $f = $this->filters($request);
        $f['project'] = $project['public_id'];
        $page = $request->page();
        $res = DeliverableQuery::paginate($uid, $f, $page, self::PER_PAGE);
        $canUpload = $staff && ProjectPolicy::canStaff($uid, $project, 'deliverables.upload') && $project['archived_at'] === null && $project['status'] !== 'cancelled';
        $data = [
            'title' => 'Deliverables · ' . $project['title'], 'project' => $project, 'tab' => 'deliverables', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page,
            'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => $f, 'canUpload' => $canUpload,
            'tasks' => $canUpload ? TaskQuery::paginate($uid, ['project' => $project['public_id']], 1, 200)['rows'] : [],
            'milestones' => $canUpload ? MilestoneService::listFor($uid, $project) : [],
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('deliverable')), 'types' => implode(', ', FileStore::allowedExtensions()),
        ];
        if (!$staff) {
            $states = [];
            foreach ($res['rows'] as $r) {
                $d = DeliverablePolicy::find((string) $r['public_id']);
                $states[$r['public_id']] = $d === null ? null : ClientApprovalService::stateFor($d);
            }
            $data['states'] = $states;
        }
        $r = $this->page($staff ? 'pages/project-deliverables' : 'pages/client-project-deliverables', $data);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/deliverables';
        $in = $this->metaInput($request) + $this->contentInput($request);

        return $this->guarded(function () use ($request, $project, $in, $to): Response {
            $res = DeliverableService::create($this->actorId(), $project, $in, $request->files['file'] ?? null);

            return $this->ok('/deliverables/' . $res['public_id'], "Deliverable {$res['code']} created as a draft. It is not visible to the client.");
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $project = DeliverablePolicy::project($d);
        $level = (string) DeliverablePolicy::level($uid, $d);
        $versions = DeliverablePolicy::versionsFor($uid, $d);
        $staff = in_array($level, ['all', 'assigned'], true);

        if (!$staff) {
            $r = $this->page('pages/deliverable-client', [
                'title' => $d['title'], 'd' => $this->clientView($d), 'project' => $project, 'versions' => $versions, 'state' => ClientApprovalService::stateFor($d),
                'history' => ClientApprovalService::history($uid, $d), 'revisions' => RevisionQuery::forDeliverable($uid, $d),
                'canRespond' => Gate::can('deliverables.respond', $uid) && $d['archived_at'] === null, 'canRevise' => Gate::can('revisions.request', $uid) && $d['archived_at'] === null,
                'canDownload' => Gate::can('files.download', $uid),
            ]);
            Session::forget('_old');

            return $r;
        }
        $actions = [];
        foreach ($versions as $v) {
            $actions[$v['public_id']] = DeliverableService::actionsFor($uid, $d, $v);
        }
        $canUpload = DeliverablePolicy::can($uid, $d, 'deliverables.upload') && $d['archived_at'] === null && $d['project_archived_at'] === null && $d['project_status'] !== 'cancelled';
        $r = $this->page('pages/deliverable', [
            'title' => $d['title'], 'd' => $d, 'project' => $project, 'versions' => $versions, 'actions' => $actions, 'tab' => 'deliverables',
            'state' => ClientApprovalService::stateFor($d), 'history' => ClientApprovalService::history($uid, $d),
            'reviews' => $this->reviews($d), 'revisions' => RevisionQuery::forDeliverable($uid, $d),
            'canUpload' => $canUpload, 'canArchive' => DeliverablePolicy::can($uid, $d, 'deliverables.archive'), 'canDownload' => Gate::can('files.download', $uid),
            'tasks' => $canUpload ? TaskQuery::paginate($uid, ['project' => $d['project_public_id']], 1, 200)['rows'] : [],
            'milestones' => $canUpload ? MilestoneService::listFor($uid, $project) : [],
            'limit' => FileStore::humanSize(FileStore::effectiveLimit('deliverable')), 'types' => implode(', ', FileStore::allowedExtensions()),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        [$d, $to] = $this->load($params);
        $in = $this->metaInput($request);

        return $this->guarded(function () use ($d, $in, $to): Response {
            DeliverableService::updateMeta($this->actorId(), $d, $in);

            return $this->ok($to, 'Deliverable updated.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function addVersion(Request $request, array $params = []): Response
    {
        [$d, $to] = $this->load($params);
        $in = $this->contentInput($request);

        return $this->guarded(function () use ($request, $d, $in, $to): Response {
            $v = DeliverableService::addVersion($this->actorId(), $d, $in, $request->files['file'] ?? null);

            return $this->ok($to, "Version V{$v['version_no']} added as a draft. Earlier versions are unchanged.");
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        [$d, $to, $v] = $this->loadVersion($params);

        return $this->guarded(function () use ($d, $v, $to): Response {
            DeliverableService::submitForReview($this->actorId(), $d, $v);

            return $this->ok($to, 'Submitted for internal review.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function review(Request $request, array $params = []): Response
    {
        [$d, $to, $v] = $this->loadVersion($params);

        return $this->guarded(function () use ($request, $d, $v, $to): Response {
            $decision = $request->string('decision');
            DeliverableService::review($this->actorId(), $d, $v, $decision, $request->string('notes'));

            return $this->ok($to, $decision === 'approved' ? 'Approved internally. It is still NOT visible to the client until it is published.' : 'Sent back with your notes.');
        }, $to, ['notes' => $request->string('notes')]);
    }

    /** @param array<string,string> $params */
    public function publish(Request $request, array $params = []): Response
    {
        [$d, $to, $v] = $this->loadVersion($params);

        return $this->guarded(function () use ($d, $v, $to): Response {
            DeliverableService::publish($this->actorId(), $d, $v);

            return $this->ok($to, "V{$v['version_no']} is now the version the client sees.");
        }, $to);
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request, array $params = []): Response
    {
        [$d, $to, $v] = $this->loadVersion($params);

        return $this->guarded(function () use ($request, $d, $v, $to): Response {
            DeliverableService::withdraw($this->actorId(), $d, $v, $request->string('reason'));

            return $this->ok($to, 'Withdrawn: the client can no longer see this version.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        [$d, $to] = $this->load($params);
        $archive = $request->string('restore') !== '1';

        return $this->guarded(function () use ($d, $archive, $to): Response {
            DeliverableService::setArchived($this->actorId(), $d, $archive);

            return $this->ok($to, $archive ? 'Deliverable archived.' : 'Deliverable restored.');
        }, $to);
    }

    /**
     * The ONLY way to obtain a deliverable file: authorised per user, per deliverable, per version, on every request. Rate limited
     * and audited. There is no URL that contains a file id or a storage path.
     *
     * @param array<string,string> $params
     */
    public function download(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $v = DeliverablePolicy::findVersion($d, $params['version'] ?? '');
        if ($v === null || !DeliverablePolicy::canDownload($uid, $d, $v)) {
            \Vertex\Core\Logger::security('Deliverable download denied', ['user' => $uid, 'deliverable' => (int) $d['id']]);
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('download_user', (string) $uid)) {
            throw new HttpException(429);
        }
        $file = FileStore::find((int) $v['file_id']) ?? throw new HttpException(404);
        $response = FileStore::send($file);
        Audit::record('deliverable.downloaded', 'deliverable', (int) $d['id'], [], [], ['version_id' => (int) $v['id'], 'version_no' => (int) $v['version_no'], 'project_id' => (int) $d['project_id']], $uid);

        return $response;
    }

    /** Metadata comparison of two versions the user may see. @param array<string,string> $params */
    public function compare(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $versions = DeliverablePolicy::versionsFor($uid, $d);
        $byId = [];
        foreach ($versions as $v) {
            $byId[$v['public_id']] = $v;
        }
        $a = $byId[$request->string('a')] ?? ($versions[1] ?? null);
        $b = $byId[$request->string('b')] ?? ($versions[0] ?? null);

        return $this->page('pages/deliverable-compare', ['title' => 'Compare versions · ' . $d['title'], 'd' => $d, 'versions' => $versions, 'a' => $a, 'b' => $b, 'staff' => DeliverablePolicy::seesDrafts($uid, $d)]);
    }

    /** @param array<string,string> $params */
    public function clientDashboard(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();

        return $this->page('pages/client-deliverables', ['title' => 'My deliverables', 'groups' => DeliverableQuery::clientDashboard($uid)]);
    }

    /** Confirmation screen for an approval/rejection. @param array<string,string> $params */
    public function respondForm(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $this->requireClient($uid, $d);
        $state = ClientApprovalService::stateFor($d);
        $action = $request->string('action') === 'reject' ? 'rejected' : 'approved';
        $r = $this->page('pages/deliverable-respond', [
            'title' => 'Confirm your response', 'd' => $this->clientView($d), 'state' => $state, 'action' => $action, 'token' => bin2hex(random_bytes(16)),
            'canDownload' => Gate::can('files.download', $uid),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function respond(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $d = DeliverablePolicy::requireView($uid, $params['deliverable'] ?? '');
        $this->requireClient($uid, $d);
        $to = '/deliverables/' . $d['public_id'];
        $action = $request->string('action') === 'rejected' ? 'rejected' : 'approved';
        $in = ['feedback' => $request->string('feedback')];

        return $this->guarded(function () use ($request, $uid, $d, $action, $to): Response {
            $new = ClientApprovalService::respond($uid, $d, $request->string('version'), $action, $request->string('feedback'), $request->string('token'));

            return $this->ok($to, !$new ? 'Your response was already recorded.' : ($action === 'approved' ? 'Thank you. You approved this version.' : 'Your rejection and reason were recorded.'));
        }, $to . '/respond?action=' . ($action === 'rejected' ? 'reject' : 'approve'), $in);
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string} */
    private function load(array $params): array
    {
        $d = DeliverablePolicy::requireView($this->actorId(), $params['deliverable'] ?? '');

        return [$d, '/deliverables/' . $d['public_id']];
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string,2:array<string,mixed>} */
    private function loadVersion(array $params): array
    {
        [$d, $to] = $this->load($params);
        $v = DeliverablePolicy::findVersion($d, $params['version'] ?? '');
        if ($v === null || !DeliverablePolicy::canViewVersion($this->actorId(), $d, $v)) {
            throw new HttpException(404);
        }

        return [$d, $to, $v];
    }

    /** @param array<string,mixed> $d */
    private function requireClient(int $uid, array $d): void
    {
        if (DeliverablePolicy::level($uid, $d) !== 'own' || !Gate::can('deliverables.respond', $uid)) {
            throw new HttpException(403);
        }
    }

    /** The deliverable row reduced to what a client may see (no creator, task or milestone detail, no internal ids). @param array<string,mixed> $d @return array<string,mixed> */
    private function clientView(array $d): array
    {
        return array_intersect_key($d, array_flip(['public_id', 'code', 'title', 'description', 'category', 'status', 'first_published_at', 'archived_at', 'published_version_id', 'project_public_id', 'project_code', 'project_title', 'id', 'project_id']));
    }

    /** @param array<string,mixed> $d @return list<array<string,mixed>> */
    private function reviews(array $d): array
    {
        return \Vertex\Core\Database::all(
            'SELECT r.decision, r.notes, r.created_at, v.version_no, u.name AS reviewer_name FROM deliverable_reviews r JOIN deliverable_versions v ON v.id = r.version_id LEFT JOIN users u ON u.id = r.reviewer_id
              WHERE v.deliverable_id = ? ORDER BY r.id DESC',
            [$d['id']],
        );
    }

    /** @return array{review:int,publish:int} */
    private function qaCounts(int $uid): array
    {
        $q = DeliverableQuery::qaQueue($uid);

        return ['review' => count($q['review']), 'publish' => count($q['publish'])];
    }

    /** @return list<array<string,mixed>> */
    private static function projectChoices(int $uid): array
    {
        $scope = ProjectPolicy::scopeSql($uid);
        if ($scope === null) {
            return [];
        }

        return \Vertex\Core\Database::all("SELECT p.public_id, p.code, p.title FROM projects p JOIN clients c ON c.id = p.client_id WHERE {$scope[0]} AND p.archived_at IS NULL ORDER BY p.title LIMIT 300", $scope[1]);
    }

    /** @return array<string,string> */
    private function metaInput(Request $request): array
    {
        return ['title' => $request->string('title'), 'description' => $request->string('description'), 'category' => $request->string('category'), 'task' => $request->string('task'), 'milestone' => $request->string('milestone')];
    }

    /** @return array<string,string> */
    private function contentInput(Request $request): array
    {
        return ['label' => $request->string('label'), 'change_notes' => $request->string('change_notes'), 'external_url' => $request->string('external_url')];
    }

    /** @return array{q:string,project:string,category:string,status:string,archived:bool} */
    private function filters(Request $request): array
    {
        $in = static fn (string $v, array $allowed): string => in_array($v, $allowed, true) ? $v : '';

        return [
            'q' => mb_substr($request->string('q'), 0, 100),
            'project' => preg_match('/^[a-f0-9]{32}$/', $request->string('project')) ? $request->string('project') : '',
            'category' => $in($request->string('category'), array_keys((array) Config::get('workflow.deliverable_categories'))),
            'status' => $in($request->string('status'), array_keys((array) Config::get('workflow.deliverable_statuses'))),
            'archived' => $request->string('archived') === '1',
        ];
    }
}
