<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AutomationService;
use Vertex\Services\Gate;
use Vertex\Services\RoleService;
use Vertex\Services\SlaService;

/** Workflow rules and the support SLA policy. */
final class AutomationController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $rule = isset($params['rule']) ? (AutomationService::find($params['rule']) ?? throw new HttpException(404)) : null;
        $r = $this->page('admin/automation/index', [
            'title' => 'Workflow rules', 'rules' => AutomationService::all(), 'rule' => $rule, 'triggers' => AutomationService::triggers(),
            'roles' => RoleService::definitions(), 'permissions' => array_column(Database::all('SELECT perm_key FROM permissions ORDER BY perm_key'), 'perm_key'),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $rule = isset($params['rule']) ? (AutomationService::find($params['rule']) ?? throw new HttpException(404)) : null;
        $in = [];
        foreach (['name', 'trigger_type', 'threshold', 'notify_target', 'escalate_to', 'escalate_after', 'enabled'] as $k) {
            $in[$k] = $request->string($k);
        }
        $to = '/admin/automation' . ($rule === null ? '' : '/' . $rule['public_id']);

        return $this->guarded(function () use ($request, $rule, $in): Response {
            if ($rule !== null && $request->string('action') === 'delete') {
                $this->requireStepUp($request);
                AutomationService::delete($this->actorId(), $rule);

                return $this->ok('/admin/automation', 'Rule deleted.');
            }
            AutomationService::save($this->actorId(), $rule, $in);

            return $this->ok('/admin/automation', 'Rule saved. It is checked every time the scheduled task runs.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function sla(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/automation/sla', ['title' => 'Support SLA', 'policies' => SlaService::policies(), 'canManage' => Gate::can('sla.manage', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveSla(Request $request, array $params = []): Response
    {
        $in = is_array($request->body['p'] ?? null) ? $request->body['p'] : [];

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            SlaService::savePolicies($this->actorId(), $in);

            return $this->ok('/admin/sla', 'SLA saved. It applies to tickets submitted from now on.');
        }, '/admin/sla');
    }
}
