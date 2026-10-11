<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Crm;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AttachmentService;
use Vertex\Services\ClientService;
use Vertex\Services\CrmStageService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryQuery;
use Vertex\Services\EnquiryService;
use Vertex\Services\FieldRules;
use Vertex\Services\Gate;

/**
 * Staff CRM screens. Every handler loads the enquiry through EnquiryPolicy::requireView() (404 when not visible) and the
 * services re-check permission + access, so the route middleware is only the first of three layers.
 */
final class EnquiryController extends Controller
{
    private const PER_PAGE = 20;

    private const FIELDS = ['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline', 'priority'];

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'stage' => $this->enumOrEmpty($request->string('stage'), array_column(CrmStageService::all(), 'code')),
            'source' => $this->enumOrEmpty($request->string('source'), array_keys((array) Config::get('workflow.sources'))),
            'priority' => $this->enumOrEmpty($request->string('priority'), array_keys((array) Config::get('workflow.priorities'))),
            'service' => $this->enumOrEmpty($request->string('service'), array_keys((array) Config::get('workflow.services'))),
            'owner' => preg_match('/^(none|me|[a-f0-9]{32})$/', $request->string('owner')) ? $request->string('owner') : '',
            'followup' => in_array($request->string('followup'), ['due', 'overdue'], true) ? $request->string('followup') : '',
            'archived' => Gate::can('enquiries.view_all', $uid) && $request->string('archived') === '1',
        ];
        $page = $request->page();
        $res = EnquiryQuery::paginate($uid, $f, $page, self::PER_PAGE);
        $qs = $f;
        $qs['archived'] = $f['archived'] ? '1' : '';

        return $this->page('crm/enquiries/index', [
            'title' => 'Enquiries', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => $f, 'qs' => $qs, 'stages' => CrmStageService::all(true), 'owners' => Gate::can('enquiries.view_all', $uid) ? EnquiryService::ownerCandidates() : [],
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('crm/enquiries/create', ['title' => 'New enquiry']);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->input($request);

        return $this->guarded(function () use ($in): Response {
            $r = EnquiryService::create($this->actorId(), 'manual', $in);

            return $this->ok('/crm/enquiries/' . $r['public_id'], "Enquiry {$r['reference']} created.");
        }, '/crm/enquiries/create', $in);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $enq = EnquiryPolicy::requireView($uid, $params['enquiry'] ?? '');
        if (!in_array(EnquiryPolicy::level($uid, $enq), ['all', 'assigned'], true)) {
            return Response::redirect('/partner/referrals/' . $enq['public_id']);
        }
        $page = $request->page();
        $timeline = EnquiryService::timeline((int) $enq['id'], $page, 40);
        $dups = EnquiryService::duplicatesFor($uid, $enq);
        $canConvert = $enq['stage_kind'] === 'won' && $enq['converted_project_id'] === null && Gate::can('enquiries.convert', $uid) && Gate::can('projects.create', $uid)
            && EnquiryPolicy::level($uid, $enq) === 'all';
        $clientMatches = $canConvert ? ClientService::findDuplicates($enq['email'], $enq['phone_digits'], $enq['company_norm']) : ['strong' => [], 'weak' => []];

        $r = $this->page('crm/enquiries/show', [
            'title' => $enq['reference'],
            'e' => $enq,
            'level' => EnquiryPolicy::level($uid, $enq),
            'timeline' => $timeline['rows'], 'timelineTotal' => $timeline['total'], 'page' => $page,
            'followups' => EnquiryService::followups((int) $enq['id']),
            'stages' => CrmStageService::all(true),
            'duplicates' => $dups,
            'attachments' => AttachmentService::forParent('enquiry', (int) $enq['id']),
            'owners' => Gate::can('enquiries.assign', $uid) ? EnquiryService::ownerCandidates() : [],
            'canConvert' => $canConvert,
            'clientMatches' => $clientMatches,
            'convertedProject' => $enq['converted_project_id'] !== null ? \Vertex\Core\Database::one('SELECT public_id, code, title FROM projects WHERE id = ?', [$enq['converted_project_id']]) : null,
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);
        $in = $this->input($request);

        return $this->guarded(function () use ($enq, $in): Response {
            EnquiryService::update($this->actorId(), $enq, $in);

            return $this->ok($this->url($enq), 'Enquiry updated.');
        }, $this->url($enq) . '#edit', $in);
    }

    /** @param array<string,string> $params */
    public function stage(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            EnquiryService::moveStage($this->actorId(), $enq, $request->string('stage'), $request->string('reason'), $request->string('confirm') === '1');

            return $this->ok($this->url($enq), 'Stage updated.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            $owner = $request->string('owner');
            EnquiryService::assign($this->actorId(), $enq, $owner === '' ? null : (int) $owner);

            return $this->ok($this->url($enq), 'Owner updated.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            EnquiryService::addNote($this->actorId(), $enq, $request->string('body'));

            return $this->ok($this->url($enq), 'Note added.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function contact(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            EnquiryService::addContact($this->actorId(), $enq, $request->string('channel'), $request->string('body'));

            return $this->ok($this->url($enq), 'Contact logged.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function followup(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            EnquiryService::addFollowup($this->actorId(), $enq, $request->string('due_date'), $request->string('note'));

            return $this->ok($this->url($enq), 'Follow-up scheduled. (It appears on the CRM dashboard; no email or message reminder is sent.)');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function completeFollowup(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);
        EnquiryService::completeFollowup($this->actorId(), $enq, (int) ($params['followup'] ?? 0));

        return $this->ok($this->url($enq), 'Follow-up completed.');
    }

    /** @param array<string,string> $params */
    public function duplicate(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            if ($request->string('action') === 'dismiss') {
                EnquiryService::dismissDuplicate($this->actorId(), $enq);

                return $this->ok($this->url($enq), 'Marked as not a duplicate.');
            }
            EnquiryService::markDuplicate($this->actorId(), $enq, $request->string('reference'));

            return $this->ok($this->url($enq), 'Marked as a duplicate and closed as Lost.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function convert(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            $r = EnquiryService::convert($this->actorId(), $enq, [
                'client' => $request->string('client', 'new'), 'confirm_new' => $request->string('confirm_new') === '1', 'project_name' => $request->string('project_name'),
            ]);

            return $this->ok('/projects/' . $r['project_public_id'], "Converted: draft project {$r['project_code']} created. The enquiry history is preserved.");
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            EnquiryService::setArchived($this->actorId(), $enq, $request->string('restore') !== '1');

            return $this->ok($this->url($enq), $request->string('restore') === '1' ? 'Enquiry restored.' : 'Enquiry archived.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params */
    public function attach(Request $request, array $params = []): Response
    {
        $enq = $this->load($params);

        return $this->guarded(function () use ($request, $enq): Response {
            \Vertex\Services\EnquiryPolicy::authorizeStaff($this->actorId(), $enq, 'enquiries.edit');
            if ($enq['converted_project_id'] !== null || $enq['archived_at'] !== null) {
                throw new \Vertex\Core\ValidationException(['This enquiry is locked or archived.']);
            }
            if (!\Vertex\Services\RateLimiter::hit('attachment_user', (string) $this->actorId())) {
                throw new \Vertex\Core\HttpException(429);
            }
            AttachmentService::store('enquiry', (int) $enq['id'], $this->actorId(), $request->files['file'] ?? []);
            EnquiryService::recordExternalEvent((int) $enq['id'], 'Attachment uploaded', $this->actorId());

            return $this->ok($this->url($enq), 'File uploaded.');
        }, $this->url($enq));
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function load(array $params): array
    {
        return EnquiryPolicy::requireView($this->actorId(), $params['enquiry'] ?? '');
    }

    /** @param array<string,mixed> $e */
    private function url(array $e): string
    {
        return '/crm/enquiries/' . $e['public_id'];
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

    /** @param list<string> $allowed */
    private function enumOrEmpty(string $v, array $allowed): string
    {
        return in_array($v, $allowed, true) ? $v : '';
    }
}
