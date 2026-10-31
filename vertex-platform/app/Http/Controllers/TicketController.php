<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Audit;
use Vertex\Services\BugEligibilityService;
use Vertex\Services\ChangeRequestService;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\RateLimiter;
use Vertex\Services\TicketAccess;
use Vertex\Services\TicketQuery;
use Vertex\Services\TicketService;
use Vertex\Services\WarrantyService;

/**
 * Tickets and bug reports. Access is decided per record by TicketAccess (404 when missing or not visible); the services re-check each
 * permission. The client and the team get different templates AND different data: internal notes, assignee, priority and reviewer
 * identity never reach a client template.
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $staff = TicketQuery::staffView($uid);
        $f = $this->filters($request, $staff);
        $page = $request->page();
        $res = TicketQuery::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page($staff ? 'pages/tickets' : 'pages/client-tickets', [
            'title' => $staff ? 'Tickets and bug reports' : 'My tickets', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false), 'projects' => $staff ? \Vertex\Services\TaskQuery::projectsFor($uid) : [],
        ]);
    }

    /** @param array<string,string> $params */
    public function dashboard(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $recent = TicketQuery::paginate($uid, ['group' => 'open'], 1, 8);

        return $this->page('pages/support-dashboard', [
            'title' => 'Support dashboard', 'c' => TicketQuery::dashboard($uid), 'recent' => $recent['rows'], 'slaOn' => (int) Config::get('support.sla.first_response_hours', 0) >= 1,
            'warranty' => Gate::can('warranty.view', $uid) ? [
                'active' => WarrantyService::report($uid, ['status' => 'active'], 1, 1)['total'],
                'expiring' => WarrantyService::report($uid, ['expiring' => true], 1, 1)['total'],
                'expired' => WarrantyService::report($uid, ['status' => 'expired'], 1, 1)['total'],
                'pending' => WarrantyService::report($uid, ['status' => 'pending_confirmation'], 1, 1)['total'],
            ] : null,
            'expiringDays' => (int) Config::get('support.warranty_expiring_days', 30),
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $t = TicketAccess::requireView($uid, $params['ticket'] ?? '');
        $role = (string) TicketAccess::role($uid, $t);
        $base = ['ticket' => $t, 'role' => $role, 'conversation' => TicketQuery::conversation($uid, $t), 'activity' => TicketQuery::activity($uid, $t), 'initialFiles' => TicketQuery::initialFiles($uid, $t),
            'changes' => TicketQuery::changes($uid, $t), 'moves' => TicketService::allowedMoves($uid, $t), 'canDownload' => Gate::can('files.download', $uid) || $role === 'client'];
        if ($role === 'client') {
            if ($t['status'] === 'draft') {
                $r = $this->page('pages/bug-new', (new SupportController())->formData('Your draft bug report', $t) + ['draft' => $t]);
                Session::forget('_old');

                return $r;
            }
            TicketService::markRead($uid, $t);
            $review = $t['kind'] === 'bug' ? BugEligibilityService::clientView($t) : null;
            $r = $this->page('pages/ticket-client', array_merge($base, [
                'title' => $t['reference'], 'ticket' => TicketAccess::forClient($t), 'review' => $review,
                'warranty' => $t['kind'] === 'bug' ? json_decode((string) $t['warranty_snapshot_json'], true) : null,
                'canReply' => $t['status'] !== 'closed' && Gate::can('tickets.reply_own', $uid), 'token' => bin2hex(random_bytes(16)),
                'limit' => FileStore::humanSize(FileStore::effectiveLimit('ticket')),
            ]));
            Session::forget('_old');

            return $r;
        }
        TicketService::markRead($uid, $t);
        $project = $t['project_id'] === null ? null : ProjectPolicy::findById((int) $t['project_id']);
        $r = $this->page('pages/ticket', array_merge($base, [
            'title' => $t['reference'], 'notes' => TicketQuery::notes($uid, $t), 'people' => $role === 'staff' && Gate::can('tickets.assign', $uid) ? TicketQuery::assignable($t) : [],
            'warranty' => $project === null ? null : WarrantyService::forProject($project), 'snapshot' => json_decode((string) $t['warranty_snapshot_json'], true) ?: [],
            'canReply' => $role === 'staff' && Gate::can('tickets.reply', $uid) && !in_array($t['status'], ['closed', 'draft'], true), 'canNote' => Gate::can('tickets.note', $uid),
            'canAssign' => $role === 'staff' && Gate::can('tickets.assign', $uid), 'canEscalate' => $role === 'staff' && Gate::can('tickets.escalate', $uid) && $t['escalated_at'] === null,
            'canSeverity' => $t['kind'] === 'bug' && $role === 'staff' && Gate::can('bugs.manage', $uid), 'canPriority' => $role === 'staff' && Gate::can($t['kind'] === 'bug' ? 'bugs.manage' : 'tickets.update_status', $uid),
            'canReview' => $t['kind'] === 'bug' && $role === 'staff' && Gate::can('bugs.review_eligibility', $uid), 'canOverride' => Gate::can('warranty.override', $uid),
            'canPropose' => $t['kind'] === 'bug' && $t['eligibility'] === 'potentially_chargeable' && $role === 'staff' && Gate::can('tickets.propose_change', $uid),
            'token' => bin2hex(random_bytes(16)), 'limit' => FileStore::humanSize(FileStore::effectiveLimit('ticket')),
        ]));
        Session::forget('_old');

        return $r;
    }

    /** The client edits or submits their own draft bug report. @param array<string,string> $params */
    public function draft(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);
        $in = [
            'project' => $request->string('project'), 'title' => $request->string('title'), 'description' => $request->string('description'), 'affected_url' => $request->string('affected_url'),
            'steps' => $request->string('steps'), 'expected' => $request->string('expected'), 'actual' => $request->string('actual'), 'browser' => $request->string('browser'),
            'device' => $request->string('device'), 'severity' => $request->string('severity'),
        ];
        $submit = $request->string('action') === 'submit';

        return $this->guarded(function () use ($request, $t, $in, $submit, $to): Response {
            TicketService::submitDraft($this->actorId(), $t, $in, $submit, $request->userAgent());

            return $this->ok($to, $submit ? 'Your bug report was submitted. Our team will review it.' : 'Draft saved. Nobody on our team can see it until you submit it.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function reply(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            $new = TicketService::reply($this->actorId(), $t, $request->string('body'), self::uploadedFiles($request->files['files'] ?? []), $request->string('token'));

            return $this->ok($to, $new ? 'Message sent.' : 'That message was already sent.');
        }, $to, ['body' => $request->string('body')]);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::transition($this->actorId(), $t, $request->string('status'), $request->string('note'), $request->string('internal_note'));

            return $this->ok($to, 'Status updated.');
        }, $to, ['note' => $request->string('note'), 'internal_note' => $request->string('internal_note')]);
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::assign($this->actorId(), $t, $request->string('assignee') ?: null);

            return $this->ok($to, 'Assignment saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function severity(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::setSeverity($this->actorId(), $t, $request->string('severity'), $request->string('reason'));

            return $this->ok($to, 'Severity updated and the reason recorded.');
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function priority(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::setPriority($this->actorId(), $t, $request->string('priority'), $request->string('reason'));

            return $this->ok($to, 'Priority updated. This is an internal scheduling field; no response time is promised to the client.');
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function escalate(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::escalate($this->actorId(), $t, $request->string('reason'));

            return $this->ok($to, 'Escalated.');
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $t, $to): Response {
            TicketService::addNote($this->actorId(), $t, $request->string('body'), $request->string('kind', 'note'));
            $file = self::uploadedFiles($request->files['file'] ?? [])[0] ?? null;
            if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                TicketService::attachInternal($this->actorId(), $t, $file);
            }

            return $this->ok($to, 'Internal note added. The client cannot see it.');
        }, $to, ['note' => $request->string('body')]);
    }

    /** @param array<string,string> $params */
    public function eligibility(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);
        $in = ['decision' => $request->string('decision'), 'reason' => $request->string('reason'), 'terms' => $request->string('terms'), 'internal_note' => $request->string('internal_note'),
            'considerations' => (array) $request->input('considerations', []), 'quotation_required' => $request->string('quotation_required') === '1'];

        return $this->guarded(function () use ($request, $t, $in, $to): Response {
            if (Gate::can('warranty.override', $this->actorId()) && $request->string('confirm_password') !== '') {
                $this->requireStepUp($request);
            }
            BugEligibilityService::decide($this->actorId(), $t, $in);

            return $this->ok($to, 'The warranty decision was recorded and the client was notified.');
        }, $to, ['reason' => $in['reason'], 'terms' => $in['terms'], 'internal_note' => $in['internal_note']]);
    }

    /** @param array<string,string> $params */
    public function proposeChange(Request $request, array $params = []): Response
    {
        [$t, $to] = $this->load($params);
        $in = ['scope_description' => $request->string('scope_description'), 'client_reason' => $request->string('client_reason'), 'amount' => $request->string('amount'), 'days' => $request->string('days'), 'due_date' => $request->string('due_date')];

        return $this->guarded(function () use ($t, $in): Response {
            $pub = ChangeRequestService::createDraftForTicket($this->actorId(), $t, ChangeRequestService::validateProposal($in));

            return $this->ok('/changes/' . $pub, 'A DRAFT cost proposal was created. Nothing has been sent to the client and nothing is charged.');
        }, $to, $in);
    }

    /** Attachment download. The file must belong to a ticket the user can open and be visible to their role. @param array<string,string> $params */
    public function file(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $t = TicketAccess::requireView($uid, $params['ticket'] ?? '');
        $f = TicketQuery::fileFor($uid, $t, (string) ($params['file'] ?? ''));
        if ($f === null || (TicketAccess::role($uid, $t) !== 'client' && !Gate::can('files.download', $uid))) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('download_user', (string) $uid)) {
            throw new HttpException(429);
        }
        $response = FileStore::send($f);
        Audit::record('ticket.file_downloaded', 'ticket', (int) $t['id'], [], [], ['project_id' => $t['project_id'] === null ? null : (int) $t['project_id']], $uid);

        return $response;
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string} */
    private function load(array $params): array
    {
        $t = TicketAccess::requireView($this->actorId(), $params['ticket'] ?? '');

        return [$t, '/tickets/' . $t['public_id']];
    }

    /** @return array<string,mixed> */
    private function filters(Request $request, bool $staff): array
    {
        $s = static fn (string $k): string => mb_substr($request->string($k), 0, 100);
        $f = ['q' => $s('q'), 'kind' => $s('kind'), 'status' => $s('status'), 'category' => $s('category'), 'severity' => $s('severity'), 'project' => $s('project'),
            'group' => $s('group'), 'from' => $s('from'), 'to' => $s('to')];
        if ($staff) {
            $f += ['priority' => $s('priority'), 'assignee' => $s('assignee'), 'escalated' => $request->string('escalated') === '1'];
        }

        return $f;
    }
}
