<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectTemplateService;

/** Project templates (admin list, edit) and the per-project checklist and "apply a template" screens. */
final class ProjectKitController extends Controller
{
    /** @param array<string,string> $params */
    public function templates(Request $request, array $params = []): Response
    {
        $r = $this->page('projectkit/templates', ['title' => 'Project templates', 'templates' => ProjectTemplateService::all(), 'services' => Config::get('workflow.services'), 'canManage' => Gate::can('templates.manage', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function templateForm(Request $request, array $params = []): Response
    {
        Gate::authorize('templates.manage', $this->actorId());
        $t = isset($params['template']) ? (ProjectTemplateService::find($params['template']) ?? throw new HttpException(404)) : null;
        $r = $this->page('projectkit/template-form', ['title' => $t === null ? 'New project template' : $t['name'], 't' => $t, 'text' => $t === null ? '' : ProjectTemplateService::toText($t), 'services' => Config::get('workflow.services')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveTemplate(Request $request, array $params = []): Response
    {
        $t = isset($params['template']) ? (ProjectTemplateService::find($params['template']) ?? throw new HttpException(404)) : null;
        $in = ['name' => $request->string('name'), 'description' => $request->string('description'), 'service_category' => $request->string('service_category'), 'items' => (string) ($request->body['items'] ?? '')];
        $to = $t === null ? '/project-templates/new' : '/project-templates/' . $t['public_id'];

        return $this->guarded(function () use ($request, $t, $in): Response {
            if ($t !== null && $request->string('action') === 'retire') {
                ProjectTemplateService::retire($this->actorId(), $t, $t['status'] === 'active');

                return $this->ok('/project-templates', 'Template updated.');
            }
            ProjectTemplateService::save($this->actorId(), $t, $in);

            return $this->ok('/project-templates', 'Template saved.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function checklist(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $uid = $this->actorId();
        $staff = in_array(ProjectPolicy::level($uid, $project), ['all', 'assigned'], true);
        $r = $this->page('projectkit/checklist', ['title' => 'Checklist: ' . $project['title'], 'project' => $project, 'tab' => 'checklist', 'items' => ProjectTemplateService::checklist($project, !$staff), 'progress' => ProjectTemplateService::progress($project), 'staff' => $staff,
            'canEdit' => $staff && Gate::can('checklists.manage', $uid), 'canApply' => $staff && Gate::can('templates.apply', $uid), 'templates' => array_values(array_filter(ProjectTemplateService::all(), static fn (array $t): bool => $t['status'] === 'active')),
            'versions' => $staff ? Database::all('SELECT dv.id, dv.version_no, d.title FROM deliverable_versions dv JOIN deliverables d ON d.id = dv.deliverable_id WHERE d.project_id = ? ORDER BY d.title, dv.version_no DESC LIMIT 200', [$project['id']]) : []]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function checklistAction(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/checklist';
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $project, $to, $action): Response {
            $uid = $this->actorId();
            if ($action === 'add') {
                ProjectTemplateService::addItem($uid, $project, $request->string('title'));

                return $this->ok($to, 'Item added.');
            }
            if ($action === 'apply') {
                $t = ProjectTemplateService::find($request->string('template')) ?? throw new ValidationException(['Choose a template.']);
                $c = ProjectTemplateService::apply($uid, $project, $t, $request->string('confirm') === '1');

                return $this->ok($to, "Template applied: {$c['milestones']} milestone(s), {$c['tasks']} task(s), {$c['checklist']} checklist item(s).");
            }
            $version = $request->string('version') !== '' ? (int) $request->string('version') : null;
            ProjectTemplateService::changeItem($uid, $project, (int) $request->string('item'), $action, $version);

            return Response::redirect($to);
        }, $to);
    }
}
