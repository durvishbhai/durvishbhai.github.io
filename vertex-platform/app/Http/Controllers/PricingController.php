<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Gate;
use Vertex\Services\PriceChangeService;
use Vertex\Services\PricingService;
use Vertex\Services\ProjectPolicy;

/** Priority pricing rules (admin) and a project's price history / priority-change proposals. */
final class PricingController extends Controller
{
    /** @param array<string,string> $params */
    public function rules(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/pricing', [
            'title' => 'Priority pricing', 'rules' => PricingService::currentRules(), 'history' => PricingService::history(40),
            'canManage' => Gate::can('pricing.manage', $this->actorId()),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveRule(Request $request, array $params = []): Response
    {
        $in = [
            'service' => $request->string('service'), 'priority' => $request->string('priority'), 'type' => $request->string('type'),
            'fixed' => $request->string('fixed'), 'percent' => $request->string('percent'), 'reason' => $request->string('reason'),
        ];

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            $changed = PricingService::setRule($this->actorId(), $in);

            return $this->ok('/admin/pricing', $changed ? 'New rule version saved. Existing approved prices and pending proposals are unaffected.' : 'That rule already has these values; nothing changed.');
        }, '/admin/pricing', $in);
    }

    /** @param array<string,string> $params */
    public function project(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $ov = PriceChangeService::overview($uid, $project); // 404 unless the user may see pricing
        $level = (string) ProjectPolicy::level($uid, $project);
        $open = PriceChangeService::isOpen($project);
        $r = $this->page('pages/project-pricing', [
            'title' => 'Pricing · ' . $project['title'], 'project' => $project, 'tab' => 'pricing', 'ov' => $ov, 'level' => $level,
            'canChangePriority' => $open && $level !== 'own' && ProjectPolicy::canStaff($uid, $project, 'projects.set_priority'),
            'canRecord' => $open && $ov['current'] === null && ProjectPolicy::canStaff($uid, $project, 'projects.record_approval'),
            'canWithdraw' => $level !== 'own' && ProjectPolicy::canStaff($uid, $project, 'pricing.request_change'),
            'canDecide' => $level === 'own' && Gate::can('pricing.respond_change', $uid),
            'rule' => PricingService::ruleFor($project['service_category'], 'urgent'),
            'rulesNow' => array_map(static fn (string $p): ?array => PricingService::ruleFor($project['service_category'], $p), array_keys((array) Config::get('workflow.priorities'))),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function priority(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];
        $dest = $request->string('return') === 'pricing' ? $to . '/pricing' : $to;

        return $this->guarded(function () use ($request, $project, $dest): Response {
            $res = PriceChangeService::changePriority($this->actorId(), $project, $request->string('priority'), $request->string('note'));
            if ($res['applied']) {
                return $this->ok($dest, 'Project priority updated.');
            }

            return $this->ok($dest, 'A price change of ₹' . \Vertex\Services\FieldRules::formatMinor(abs($res['adjustment_minor'])) . ($res['adjustment_minor'] < 0 ? ' (reduction)' : '')
                . ' was calculated. The priority and the approved price stay as they are until the client approves the proposal.');
        }, $dest);
    }

    /** @param array<string,string> $params */
    public function baseline(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/pricing';
        $in = ['quotation_ref' => $request->string('quotation_ref'), 'base' => $request->string('base'), 'surcharge' => $request->string('surcharge')];

        return $this->guarded(function () use ($request, $project, $in, $to): Response {
            $this->requireStepUp($request);
            PriceChangeService::recordApproval($this->actorId(), $project, $in);

            return $this->ok($to, 'Approved quotation recorded. It can only change through a client-approved revision.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function decide(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/pricing';

        return $this->guarded(function () use ($request, $project, $params, $to): Response {
            $decision = $request->string('decision');
            PriceChangeService::respond($this->actorId(), $project, $params['req'] ?? '', $decision, $request->string('comment'));

            return $this->ok($to, $decision === 'approve' ? 'You approved the change. A new price revision was recorded.' : 'You declined the change. Nothing was changed.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/pricing';

        return $this->guarded(function () use ($project, $params, $to): Response {
            PriceChangeService::withdraw($this->actorId(), $project, $params['req'] ?? '');

            return $this->ok($to, 'Proposal withdrawn.');
        }, $to);
    }
}
