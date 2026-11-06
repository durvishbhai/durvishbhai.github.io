<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\FormService;
use Vertex\Services\Gate;

/** Staff side of the form builder: forms, questions, answers, CSV export. */
final class FormController extends Controller
{
    private function form(array $params): array
    {
        return FormService::find((string) ($params['form'] ?? '')) ?? throw new HttpException(404);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('forms/index', ['title' => 'Forms', 'forms' => FormService::all(), 'canManage' => Gate::can('forms.manage', $this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        Gate::authorize('forms.manage', $this->actorId());
        $r = $this->page('forms/new', ['title' => 'New form', 'templates' => Config::get('forms.templates'), 'access' => Config::get('forms.access')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['title', 'slug', 'description', 'access', 'success_message', 'submit_label', 'max_submissions', 'closes_on'] as $k) {
            $in[$k] = $request->string($k);
        }
        $in['create_enquiry'] = $request->string('create_enquiry');
        $tpl = $request->string('template');

        return $this->guarded(function () use ($in, $tpl): Response {
            $r = FormService::create($this->actorId(), $in, $tpl);

            return $this->ok('/forms/' . $r['public_id'], 'Form created as a draft. Add your questions, then publish it.');
        }, '/forms/new', $in + ['template' => $tpl]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        if (!Gate::canAny(['forms.manage', 'forms.view_submissions'], $uid)) {
            throw new HttpException(403);
        }
        $f = $this->form($params);
        $r = $this->page('forms/show', ['title' => $f['title'], 'f' => $f, 'fields' => FormService::fields($f), 'canManage' => Gate::can('forms.manage', $uid), 'types' => Config::get('forms.types'), 'access' => Config::get('forms.access'),
            'enquiryFields' => Config::get('forms.enquiry_fields'), 'new' => (int) Database::one('SELECT COUNT(*) AS n FROM form_submissions WHERE form_id = ? AND status = \'new\'', [$f['id']])['n']]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $f = $this->form($params);
        $to = '/forms/' . $f['public_id'];
        $action = $request->string('action');
        $in = [];
        foreach (['title', 'description', 'access', 'success_message', 'submit_label', 'max_submissions', 'closes_on'] as $k) {
            $in[$k] = $request->string($k);
        }
        $in['create_enquiry'] = $request->string('create_enquiry');

        return $this->guarded(function () use ($request, $f, $to, $action, $in): Response {
            $uid = $this->actorId();
            switch ($action) {
                case 'publish':
                    FormService::setStatus($uid, $f, 'published');

                    return $this->ok($to, 'Published. The form is now live at its address.');
                case 'close':
                    FormService::setStatus($uid, $f, 'closed');

                    return $this->ok($to, 'Closed. It no longer accepts answers.');
                case 'draft':
                    FormService::setStatus($uid, $f, 'draft');

                    return $this->ok($to, 'Back to draft: the address no longer works.');
                case 'rotate':
                    $this->requireStepUp($request);
                    FormService::rotateToken($uid, $f);

                    return $this->ok($to, 'A new secret link was created. The old one stopped working.');
                case 'archive':
                    $this->requireStepUp($request);
                    FormService::archive($uid, $f);

                    return $this->ok('/forms', 'The form was archived. Its answers are kept.');
            }
            FormService::update($uid, $f, $in);

            return $this->ok($to, 'Saved.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function addField(Request $request, array $params = []): Response
    {
        $f = $this->form($params);
        $in = $this->fieldInput($request);

        return $this->guarded(function () use ($f, $in): Response {
            FormService::addField($this->actorId(), $f, $in);

            return $this->ok('/forms/' . $f['public_id'], 'Question added.');
        }, '/forms/' . $f['public_id'], $in);
    }

    /** @return array<string,mixed> */
    private function fieldInput(Request $request): array
    {
        $in = [];
        foreach (['label', 'type', 'key', 'options', 'placeholder', 'help', 'min', 'max', 'show_if_field', 'show_if_op', 'show_if_value', 'maps_to'] as $k) {
            $in[$k] = $request->string($k);
        }
        $in['required'] = $request->string('required');

        return $in;
    }

    /** @param array<string,string> $params */
    public function field(Request $request, array $params = []): Response
    {
        $f = $this->form($params);
        $to = '/forms/' . $f['public_id'];
        $fid = (int) ($params['field'] ?? 0);
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $f, $fid, $action, $to): Response {
            $uid = $this->actorId();
            if ($action === 'delete') {
                FormService::deleteField($uid, $f, $fid);

                return $this->ok($to, 'Question removed.');
            }
            if ($action === 'up' || $action === 'down') {
                FormService::moveField($uid, $f, $fid, $action);

                return Response::redirect($to);
            }
            FormService::updateField($uid, $f, $fid, $this->fieldInput($request));

            return $this->ok($to, 'Question saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function submissions(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = $this->form($params);
        $status = $request->string('status');
        $res = FormService::submissions($uid, $f, ['status' => $status], $request->page(), 25);
        $rows = array_map(static function (array $s): array {
            $s['answers'] = (array) json_decode((string) $s['data_json'], true);

            return $s;
        }, $res['rows']);

        return $this->page('forms/submissions', ['title' => $f['title'] . ': answers', 'f' => $f, 'rows' => $rows, 'total' => $res['total'], 'page' => $request->page(), 'perPage' => 25, 'status' => $status, 'canExport' => Gate::can('forms.export', $uid), 'notice' => Session::pullFlash('notice')]);
    }

    /** @param array<string,string> $params */
    public function mark(Request $request, array $params = []): Response
    {
        $f = $this->form($params);

        return $this->guarded(function () use ($request, $f, $params): Response {
            FormService::markSubmission($this->actorId(), (string) ($params['sub'] ?? ''), $request->string('status'));

            return Response::redirect('/forms/' . $f['public_id'] . '/submissions');
        }, '/forms/' . $f['public_id'] . '/submissions');
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params = []): Response
    {
        $f = $this->form($params);
        $r = FormService::exportCsv($this->actorId(), $f);

        return new Response(200, $r['csv'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="form-' . $f['slug'] . '-' . gmdate('Y-m-d') . '.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
