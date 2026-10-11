<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\WarrantyService;

/**
 * Warranty tracking. Staff with `warranty.view` see the recorded terms and dates; only `warranty.manage` (administrators) can change
 * the contractual settings, each change with a reason and the account password. Clients see only their own projects' warranty facts.
 */
final class WarrantyController extends Controller
{
    private const PER_PAGE = 25;

    /** @param array<string,string> $params */
    public function report(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = ['q' => mb_substr($request->string('q'), 0, 100), 'status' => $request->string('status'), 'expiring' => $request->string('expiring') === '1'];
        if ($f['status'] !== '' && !array_key_exists($f['status'], (array) Config::get('workflow.warranty_statuses'))) {
            $f['status'] = '';
        }
        $page = max(1, (int) $request->string('page', '1'));
        $res = WarrantyService::report($uid, $f, $page, self::PER_PAGE);

        return $this->page('pages/warranties', [
            'title' => 'Warranties', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false), 'days' => (int) Config::get('support.warranty_expiring_days', 30),
        ]);
    }

    /** @param array<string,string> $params */
    public function project(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $level = (string) ProjectPolicy::level($uid, $project);
        $w = WarrantyService::forProject($project);
        if ($level === 'own') {
            $item = array_values(array_filter(WarrantyService::forClient($uid), static fn (array $i): bool => $i['project']['public_id'] === $project['public_id']));

            return $this->page('pages/client-warranty', ['title' => 'Warranty · ' . $project['title'], 'items' => $item, 'project' => $project, 'tab' => 'warranty']);
        }
        $r = $this->page('pages/project-warranty', [
            'title' => 'Warranty · ' . $project['title'], 'project' => $project, 'tab' => 'warranty', 'w' => $w, 'hints' => WarrantyService::startHints($project),
            'history' => WarrantyService::history($project), 'terms' => WarrantyService::termsVersions(), 'canManage' => Gate::can('warranty.manage', $uid),
            'expiringDays' => (int) Config::get('support.warranty_expiring_days', 30),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/warranty';
        $in = [
            'eligibility' => $request->string('eligibility'), 'months' => $request->string('months'), 'start_basis' => $request->string('start_basis'), 'start_date' => $request->string('start_date'),
            'quotation_ref' => $request->string('quotation_ref'), 'terms_version' => $request->string('terms_version'), 'project_terms' => $request->string('project_terms'),
            'admin_notes' => $request->string('admin_notes'), 'reason' => $request->string('reason'),
        ];

        return $this->guarded(function () use ($request, $project, $in, $to): Response {
            $this->requireStepUp($request);
            WarrantyService::record($this->actorId(), $project, $in);

            return $this->ok($to, 'Warranty information saved and recorded in the history.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function suspend(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/warranty';

        return $this->guarded(function () use ($request, $project, $to): Response {
            $this->requireStepUp($request);
            $suspend = $request->string('action') === 'suspend';
            WarrantyService::setSuspended($this->actorId(), $project, $suspend, $request->string('reason'));

            return $this->ok($to, $suspend ? 'Warranty suspended.' : 'Warranty resumed.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function saveTerms(Request $request, array $params = []): Response
    {
        $to = '/warranties';
        $in = ['title' => $request->string('title'), 'terms' => $request->string('terms'), 'default_months' => $request->string('default_months'), 'source_reference' => $request->string('source_reference')];

        return $this->guarded(function () use ($request, $in, $to): Response {
            $this->requireStepUp($request);
            $v = WarrantyService::recordTerms($this->actorId(), $in);

            return $this->ok($to, "Warranty terms version {$v} recorded.");
        }, $to, $in);
    }
}
