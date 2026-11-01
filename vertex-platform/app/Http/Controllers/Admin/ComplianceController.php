<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\View;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\HtmlSanitizer;
use Vertex\Services\LegalDocuments;

/** Compliance & legal management: Owner only (`compliance.manage`, which Administrators do not hold by default). Every action is checked again in the services. */
final class ComplianceController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('admin/compliance/index', ['title' => 'Compliance & legal', 'docs' => LegalDocuments::documents(), 'missing' => ComplianceSettings::missingFacts(), 'groups' => ComplianceSettings::GROUPS]);
    }

    // ------------------------------------------------------------------------------------------------ documents

    /** @param array<string,string> $params */
    public function document(Request $request, array $params = []): Response
    {
        $doc = LegalDocuments::document((string) ($params['key'] ?? '')) ?? throw new HttpException(404);
        $versions = LegalDocuments::versions($doc['doc_key']);
        $work = null;
        foreach ($versions as $v) {
            if (in_array($v['status'], ['draft', 'scheduled'], true)) {
                $work = LegalDocuments::version($doc['doc_key'], (int) $v['version_no']);
                break;
            }
        }
        $live = LegalDocuments::live($doc['doc_key']);
        $from = $work ?? $live ?? LegalDocuments::version($doc['doc_key'], (int) ($versions[0]['version_no'] ?? 1));
        $old = Session::get('_old', []);
        $r = $this->page('admin/compliance/document', [
            'title' => $doc['title'], 'doc' => $doc, 'versions' => $versions, 'work' => $work, 'live' => $live, 'form' => (is_array($old) && $old !== [] ? $old : []) + ['title' => $from['title'] ?? $doc['title'], 'body_html' => $from['body_html'] ?? '', 'change_summary' => $work['change_summary'] ?? '',
                'is_material' => (string) ($work['is_material'] ?? '0'), 'effective_date' => (string) ($work['effective_date'] ?? ''), 'acceptance_days' => (string) ($work['acceptance_days'] ?? '')],
            'blockers' => $work !== null ? LegalDocuments::blockers($doc, $work) : [], 'tokens' => LegalDocuments::tokenMap(),
        ]);
        Session::forget('_old');

        return $r;
    }

    private function formIn(Request $request): array
    {
        return ['title' => $request->string('title'), 'body_html' => (string) ($request->body['body_html'] ?? ''), 'change_summary' => $request->string('change_summary'), 'is_material' => $request->string('is_material'),
            'effective_date' => $request->string('effective_date'), 'acceptance_days' => $request->string('acceptance_days')];
    }

    private function to(array $params): string
    {
        return '/admin/compliance/documents/' . rawurlencode((string) ($params['key'] ?? ''));
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $in = $this->formIn($request);

        return $this->guarded(function () use ($params, $in): Response {
            LegalDocuments::saveDraft($this->actorId(), (string) $params['key'], $in);

            return $this->ok($this->to($params), 'Draft saved. Nothing changes on the public website until you publish.');
        }, $this->to($params), $in);
    }

    /** Shows the draft exactly as visitors would see it (with the real business facts filled in), without saving or publishing. @param array<string,string> $params */
    public function preview(Request $request, array $params = []): Response
    {
        $doc = LegalDocuments::document((string) ($params['key'] ?? '')) ?? throw new HttpException(404);
        $in = $this->formIn($request);
        $html = LegalDocuments::fill(HtmlSanitizer::clean((string) $in['body_html']));
        $v = ['title' => $in['title'] !== '' ? $in['title'] : $doc['title'], 'version_no' => 'preview', 'effective_date' => $in['effective_date'] !== '' ? $in['effective_date'] : null, 'published_at' => gmdate('Y-m-d H:i:s')];

        return Response::html(View::render('site/legal', ['title' => 'Preview: ' . $v['title'], 'doc' => $doc, 'v' => $v, 'html' => $html, 'toc' => LegalDocuments::toc($html), 'noindex' => true, 'meta' => [], 'errors' => [], 'notice' => 'PREVIEW ONLY: this is not published.']), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string,string> $params */
    public function publish(Request $request, array $params = []): Response
    {
        $in = $this->formIn($request);

        return $this->guarded(function () use ($request, $params, $in): Response {
            $this->requireStepUp($request);
            LegalDocuments::saveDraft($this->actorId(), (string) $params['key'], $in);
            LegalDocuments::publish($this->actorId(), (string) $params['key'], $in);

            return $this->ok($this->to($params), 'Published. The public page now shows this version.');
        }, $this->to($params), $in);
    }

    /** @param array<string,string> $params */
    public function schedule(Request $request, array $params = []): Response
    {
        $in = $this->formIn($request);

        return $this->guarded(function () use ($request, $params, $in): Response {
            $this->requireStepUp($request);
            LegalDocuments::saveDraft($this->actorId(), (string) $params['key'], $in);
            LegalDocuments::schedule($this->actorId(), (string) $params['key'], $request->string('publish_at'), $in);

            return $this->ok($this->to($params), 'Scheduled. It goes live automatically at that time.');
        }, $this->to($params), $in);
    }

    /** @param array<string,string> $params */
    public function cancelSchedule(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($params): Response {
            LegalDocuments::cancelSchedule($this->actorId(), (string) $params['key']);

            return $this->ok($this->to($params), 'The schedule was cancelled; the text is a draft again.');
        }, $this->to($params));
    }

    /** @param array<string,string> $params */
    public function discard(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($params): Response {
            LegalDocuments::discardDraft($this->actorId(), (string) $params['key']);

            return $this->ok($this->to($params), 'The draft was discarded. The published version is unchanged.');
        }, $this->to($params));
    }

    /** @param array<string,string> $params */
    public function unpublish(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request, $params): Response {
            $this->requireStepUp($request);
            LegalDocuments::unpublish($this->actorId(), (string) $params['key'], $request->string('reason'));

            return $this->ok($this->to($params), 'Taken offline. Visitors now see a "being finalised" notice.');
        }, $this->to($params), ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function version(Request $request, array $params = []): Response
    {
        $doc = LegalDocuments::document((string) ($params['key'] ?? '')) ?? throw new HttpException(404);
        $v = LegalDocuments::version($doc['doc_key'], (int) ($params['no'] ?? 0)) ?? throw new HttpException(404);

        return $this->page('admin/compliance/version', ['title' => $doc['title'] . ' · version ' . $v['version_no'], 'doc' => $doc, 'v' => $v, 'html' => LegalDocuments::fill((string) $v['body_html'])]);
    }

    /** @param array<string,string> $params */
    public function restore(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request, $params): Response {
            LegalDocuments::restore($this->actorId(), (string) $params['key'], (int) ($params['no'] ?? 0));

            return $this->ok($this->to($params), 'Version ' . (int) $params['no'] . ' was copied into the draft. Review it, then publish.');
        }, $this->to($params));
    }

    // ------------------------------------------------------------------------------------------------ settings

    /** @param array<string,string> $params */
    public function settings(Request $request, array $params = []): Response
    {
        $g = (string) ($params['group'] ?? '');
        if (!isset(ComplianceSettings::GROUPS[$g])) {
            throw new HttpException(404);
        }
        $r = $this->page('admin/compliance/settings', ['title' => ComplianceSettings::GROUPS[$g], 'group' => $g, 'fields' => ComplianceSettings::group($g), 'groups' => ComplianceSettings::GROUPS]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveSettings(Request $request, array $params = []): Response
    {
        $g = (string) ($params['group'] ?? '');
        $to = '/admin/compliance/settings/' . rawurlencode($g);

        return $this->guarded(function () use ($request, $g, $to): Response {
            ComplianceSettings::save($this->actorId(), $g, $request->body);

            return $this->ok($to, 'Saved. The public website now shows these details.');
        }, $to, array_map('strval', array_filter($request->body, 'is_scalar')));
    }
}
