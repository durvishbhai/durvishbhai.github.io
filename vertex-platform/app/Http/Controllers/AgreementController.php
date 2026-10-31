<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\AgreementService;
use Vertex\Services\OfficialAgreement;
use Vertex\Services\DocumentPdf;
use Vertex\Services\Gate;

/** Agreements and the document vault: staff screens, templates, and the client's own agreements. */
final class AgreementController extends Controller
{
    private const PER_PAGE = 20;

    private function pdf(array $a): Response
    {
        if ($a['mode'] !== 'online') {
            throw new HttpException(404);
        }

        return new Response(200, DocumentPdf::agreement($a), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="agreement-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $a['reference']) . '.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    // ---------------------------------------------------------------------------------------------- staff

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = ['q' => mb_substr($request->string('q'), 0, 100), 'status' => $request->string('status')];
        $res = AgreementService::paginate($this->actorId(), $f, $request->page(), self::PER_PAGE);

        return $this->page('agreements/index', ['title' => 'Agreements', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $request->page(), 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => array_filter($f), 'canManage' => Gate::can('agreements.manage', $this->actorId())]);
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return ['clients' => Database::all("SELECT public_id, contact_name, company_name FROM clients WHERE archived_at IS NULL ORDER BY COALESCE(NULLIF(company_name, ''), contact_name) LIMIT 1000"),
            'projects' => Database::all('SELECT p.public_id, p.code, p.title, c.public_id AS client_public_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.archived_at IS NULL ORDER BY p.code DESC LIMIT 500'),
            'templates' => array_values(array_filter(AgreementService::templates(), static fn (array $t): bool => $t['status'] === 'active'))];
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        Gate::authorize('agreements.manage', $this->actorId());
        $r = $this->page('agreements/new', ['title' => 'New agreement'] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['title', 'mode', 'template', 'project', 'body', 'expires_on'] as $k) {
            $in[$k] = $request->string($k);
        }
        $client = $request->string('client');

        return $this->guarded(function () use ($client, $in): Response {
            $r = AgreementService::create($this->actorId(), $client, $in);

            return $this->ok('/agreements/' . $r['public_id'], 'Saved as a draft.');
        }, '/agreements/new', $in + ['client' => $client]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = AgreementService::requireStaff($uid, $params['agreement'] ?? '');
        $r = $this->page('agreements/show', ['title' => $a['reference'], 'a' => $a, 'signatures' => AgreementService::signatures($a), 'files' => AgreementService::files($a), 'events' => AgreementService::events($a),
            'canManage' => Gate::can('agreements.manage', $uid), 'canSign' => Gate::can('agreements.sign_company', $uid), 'canUpload' => Gate::can('agreements.upload', $uid)]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = AgreementService::requireStaff($uid, $params['agreement'] ?? '');
        $to = '/agreements/' . $a['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $a, $to, $action): Response {
            switch ($action) {
                case 'send':
                    AgreementService::send($uid, $a);

                    return $this->ok($to, 'Sent. The client was notified and can sign in their portal.');
                case 'sign_company':
                    AgreementService::sign($uid, $a, 'company', $request->string('typed_name'), (string) ($request->body['confirm_password'] ?? ''), $request->string('declare') === '1', $request->ip(), $request->userAgent());

                    return $this->ok($to, 'Signed for the company.');
                case 'upload':
                    $file = self::uploadedFiles($request->files['file'] ?? null)[0] ?? [];
                    AgreementService::uploadScan($uid, $a, $file, $request->string('note'));

                    return $this->ok($to, 'The signed copy was added to the vault.');
                case 'executed':
                    $this->requireStepUp($request);
                    AgreementService::markPhysicalExecuted($uid, $a, $request->string('signed_on'), $request->string('client_signer'), $request->string('company_signer'), $request->string('note'));

                    return $this->ok($to, 'Marked executed. The client can download the signed copy.');
                case 'cancel':
                    $this->requireStepUp($request);
                    AgreementService::cancel($uid, $a, $request->string('reason'));

                    return $this->ok($to, 'Cancelled.');
            }
            throw new HttpException(404);
        }, $to);
    }

    /** @param array<string,string> $params */
    public function file(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireStaff($this->actorId(), $params['agreement'] ?? '');
        Gate::authorize('agreements.view', $this->actorId());

        return AgreementService::download($a, (int) ($params['version'] ?? 0), false);
    }

    /** @param array<string,string> $params */
    public function document(Request $request, array $params = []): Response
    {
        return $this->pdf(AgreementService::requireStaff($this->actorId(), $params['agreement'] ?? ''));
    }

    /** Saves the details filled into the official KIS agreement. @param array<string,string> $params */
    public function officialSave(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireStaff($this->actorId(), $params['agreement'] ?? '');

        return $this->guarded(function () use ($request, $a): Response {
            OfficialAgreement::save($this->actorId(), $a, $request->body);

            return $this->ok('/agreements/' . $a['public_id'], 'The details of the official agreement were saved.');
        }, '/agreements/' . $a['public_id']);
    }

    /** The official KIS agreement, filled in, ready to print and sign. @param array<string,string> $params */
    public function officialPdf(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireStaff($this->actorId(), $params['agreement'] ?? '');

        return new Response(200, OfficialAgreement::pdf($a), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="KIS-agreement-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $a['reference']) . '.pdf"', 'Cache-Control' => 'no-store']);
    }

    // ---------------------------------------------------------------------------------------------- templates

    /** @param array<string,string> $params */
    public function templates(Request $request, array $params = []): Response
    {
        $r = $this->page('agreements/templates', ['title' => 'Agreement templates', 'templates' => AgreementService::templates(), 'placeholders' => AgreementService::PLACEHOLDERS, 'canManage' => Gate::can('agreements.manage', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveTemplate(Request $request, array $params = []): Response
    {
        $existing = isset($params['template']) ? (AgreementService::template($params['template']) ?? throw new HttpException(404)) : null;

        return $this->guarded(function () use ($request, $existing): Response {
            if ($existing !== null && $request->string('action') === 'retire') {
                AgreementService::retireTemplate($this->actorId(), $existing, $existing['status'] === 'active');

                return $this->ok('/agreement-templates', 'Template updated.');
            }
            AgreementService::saveTemplate($this->actorId(), $existing, $request->string('name'), (string) ($request->body['body'] ?? ''));

            return $this->ok('/agreement-templates', 'Template saved.');
        }, '/agreement-templates', ['name' => $request->string('name'), 'body' => (string) ($request->body['body'] ?? '')]);
    }

    // ---------------------------------------------------------------------------------------------- client

    /** @param array<string,string> $params */
    public function myIndex(Request $request, array $params = []): Response
    {
        return $this->page('agreements/my-index', ['title' => 'My agreements', 'rows' => AgreementService::forClient($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function myShow(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireOwn($this->actorId(), $params['agreement'] ?? '');
        $files = $a['status'] === 'executed' ? array_slice(AgreementService::files($a), 0, 1) : [];
        $r = $this->page('agreements/my-show', ['title' => $a['reference'], 'a' => $a, 'signatures' => AgreementService::signatures($a), 'files' => $files, 'canSign' => Gate::can('agreements.sign', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function mySign(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireOwn($this->actorId(), $params['agreement'] ?? '');
        $to = '/my/agreements/' . $a['public_id'];

        return $this->guarded(function () use ($request, $a, $to): Response {
            AgreementService::sign($this->actorId(), $a, 'client', $request->string('typed_name'), (string) ($request->body['confirm_password'] ?? ''), $request->string('declare') === '1', $request->ip(), $request->userAgent());

            return $this->ok($to, 'Thank you. You have signed this agreement.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function myFile(Request $request, array $params = []): Response
    {
        $a = AgreementService::requireOwn($this->actorId(), $params['agreement'] ?? '');

        return AgreementService::download($a, (int) ($params['version'] ?? 0), true);
    }

    /** @param array<string,string> $params */
    public function myDocument(Request $request, array $params = []): Response
    {
        return $this->pdf(AgreementService::requireOwn($this->actorId(), $params['agreement'] ?? ''));
    }
}
