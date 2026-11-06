<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CmsBlocks;
use Vertex\Services\CmsService;
use Vertex\Services\Gate;

/** Modules 13 and 34: website pages, versions, media, menus, redirects and reusable blocks. */
final class CmsController extends Controller
{
    /** @param array<string,string> $params @return array<string,mixed> */
    private function pageOf(array $params): array
    {
        return CmsService::find((string) ($params['page'] ?? '')) ?? throw new HttpException(404);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/cms/index', ['title' => 'Website', 'pages' => CmsService::all(), 'canEdit' => Gate::can('cms.edit', $this->actorId()), 'old' => Session::get('_old', [])]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $in = ['title' => $request->string('title'), 'slug' => $request->string('slug')];

        return $this->guarded(function () use ($in): Response {
            $p = CmsService::create($this->actorId(), $in['title'], $in['slug']);

            return $this->ok('/admin/cms/' . $p['public_id'], 'Page created as a draft. Add blocks, then publish when it is ready.');
        }, '/admin/cms', $in);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $uid = $this->actorId();
        $r = $this->page('admin/cms/edit', ['title' => 'Edit: ' . $page['title'], 'p' => $page, 'blocks' => CmsService::blocksOf($page), 'types' => CmsBlocks::types(), 'media' => CmsService::media(), 'reusable' => CmsService::reusable(),
            'canEdit' => Gate::can('cms.edit', $uid), 'canPublish' => Gate::can('cms.publish', $uid), 'editIndex' => ctype_digit($request->string('block')) ? (int) $request->string('block') : null, 'addType' => $request->string('add')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function details(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $in = [];
        foreach (['title', 'slug', 'seo_title', 'seo_description', 'canonical_url', 'og_image', 'noindex'] as $k) {
            $in[$k] = $request->string($k);
        }
        $to = '/admin/cms/' . $page['public_id'];

        return $this->guarded(function () use ($page, $in, $to): Response {
            CmsService::updateDetails($this->actorId(), $page, $in);

            return $this->ok($to, 'Details saved.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function addBlock(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $to = '/admin/cms/' . $page['public_id'];
        $type = $request->string('type');

        return $this->guarded(function () use ($request, $page, $type, $to): Response {
            CmsService::addBlock($this->actorId(), $page, $type, $request->body);

            return $this->ok($to, 'Block added.');
        }, $to . '?add=' . rawurlencode($type) . '#add-block', $this->flat($request));
    }

    /** @param array<string,string> $params */
    public function updateBlock(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $i = (int) ($params['index'] ?? -1);
        $to = '/admin/cms/' . $page['public_id'];

        return $this->guarded(function () use ($request, $page, $i, $to): Response {
            CmsService::updateBlock($this->actorId(), $page, $i, $request->body);

            return $this->ok($to, 'Block saved.');
        }, $to . '?block=' . $i . '#block-' . $i, $this->flat($request));
    }

    /** @param array<string,string> $params */
    public function blockAction(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $i = (int) ($params['index'] ?? -1);
        $to = '/admin/cms/' . $page['public_id'];

        return $this->guarded(function () use ($request, $page, $i, $to): Response {
            $a = $request->string('action');
            if ($a === 'delete') {
                CmsService::deleteBlock($this->actorId(), $page, $i);

                return $this->ok($to, 'Block removed. It is still in the version history.');
            }
            if ($a === 'up' || $a === 'down') {
                CmsService::moveBlock($this->actorId(), $page, $i, $a);

                return $this->ok($to, 'Block moved.');
            }
            throw new HttpException(422);
        }, $to);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $to = '/admin/cms/' . $page['public_id'];

        return $this->guarded(function () use ($request, $page, $to): Response {
            $a = $request->string('action');
            if ($a === 'schedule') {
                CmsService::schedule($this->actorId(), $page, $request->string('publish_at'));

                return $this->ok($to, 'Scheduled. It goes live automatically at that time (the scheduled task checks every run).');
            }
            $map = ['publish' => 'published', 'unpublish' => 'draft', 'archive' => 'archived'];
            if (!isset($map[$a])) {
                throw new HttpException(422);
            }
            if ($a !== 'publish') {
                $this->requireStepUp($request);
            }
            CmsService::setStatus($this->actorId(), $page, $map[$a]);

            return $this->ok($to, ['publish' => 'Published. The page is now live.', 'unpublish' => 'The page is a draft again and visitors no longer see it.', 'archive' => 'Archived.'][$a]);
        }, $to);
    }

    /** @param array<string,string> $params */
    public function versions(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);

        return $this->page('admin/cms/versions', ['title' => 'History: ' . $page['title'], 'p' => $page, 'versions' => CmsService::versions($page), 'canEdit' => Gate::can('cms.edit', $this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function restore(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $to = '/admin/cms/' . $page['public_id'] . '/versions';

        return $this->guarded(function () use ($page, $params, $to): Response {
            CmsService::restore($this->actorId(), $page, (int) ($params['version'] ?? 0));

            return $this->ok('/admin/cms/' . $page['public_id'], 'Version restored as a new version. Nothing was lost.');
        }, $to);
    }

    /** Draft preview: the page exactly as visitors will see it, for staff only, never indexed. @param array<string,string> $params */
    public function preview(Request $request, array $params = []): Response
    {
        $page = $this->pageOf($params);
        $res = $this->page('public/cms-page', ['title' => $page['title'], 'html' => CmsService::renderBlocks($page), 'meta' => ['description' => '', 'canonical' => '', 'ogImage' => '', 'ogTitle' => ''], 'noindex' => true, 'preview' => $page['status']], 'layouts/public');
        $res->headers['Cache-Control'] = 'no-store';

        return $res;
    }

    // ------------------------------------------------------------------------------------------------ media

    /** @param array<string,string> $params */
    public function media(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/cms/media', ['title' => 'Media library', 'media' => CmsService::media(), 'canEdit' => Gate::can('cms.edit', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function upload(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $files = self::uploadedFiles($request->files['picture'] ?? null);
            if ($files === []) {
                throw new \Vertex\Core\ValidationException(['Choose a picture to upload.']);
            }
            CmsService::upload($this->actorId(), $files[0], $request->string('alt'));

            return $this->ok('/admin/cms/media', 'Picture uploaded.');
        }, '/admin/cms/media', ['alt' => $request->string('alt')]);
    }

    /** @param array<string,string> $params */
    public function deleteMedia(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request, $params): Response {
            $this->requireStepUp($request);
            CmsService::deleteMedia($this->actorId(), (string) ($params['media'] ?? ''));

            return $this->ok('/admin/cms/media', 'Picture deleted.');
        }, '/admin/cms/media');
    }

    // ------------------------------------------------------------------------------------------------ menus, redirects, reusable

    /** @param array<string,string> $params */
    public function menus(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/cms/menus', ['title' => 'Menus', 'header' => CmsService::menuText('header'), 'footer' => CmsService::menuText('footer'), 'canPublish' => Gate::can('cms.publish', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveMenu(Request $request, array $params = []): Response
    {
        $loc = (string) ($params['location'] ?? '');

        return $this->guarded(function () use ($request, $loc): Response {
            CmsService::saveMenu($this->actorId(), $loc, $request->string('items'));

            return $this->ok('/admin/cms/menus', ucfirst($loc) . ' menu saved.');
        }, '/admin/cms/menus', [$loc . '_items' => $request->string('items')]);
    }

    /** @param array<string,string> $params */
    public function redirects(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/cms/redirects', ['title' => 'Redirects', 'rows' => CmsService::redirects(), 'canPublish' => Gate::can('cms.publish', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function addRedirect(Request $request, array $params = []): Response
    {
        $in = ['from' => $request->string('from'), 'to' => $request->string('to'), 'code' => $request->string('code')];

        return $this->guarded(function () use ($in): Response {
            CmsService::addRedirect($this->actorId(), $in['from'], $in['to'], $in['code']);

            return $this->ok('/admin/cms/redirects', 'Redirect saved.');
        }, '/admin/cms/redirects', $in);
    }

    /** @param array<string,string> $params */
    public function deleteRedirect(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($params): Response {
            CmsService::deleteRedirect($this->actorId(), (int) ($params['id'] ?? 0));

            return $this->ok('/admin/cms/redirects', 'Redirect removed.');
        }, '/admin/cms/redirects');
    }

    /** @param array<string,string> $params */
    public function reusable(Request $request, array $params = []): Response
    {
        $editing = null;
        foreach (CmsService::reusable() as $b) {
            if (($params['block'] ?? '') === $b['public_id']) {
                $editing = $b;
            }
        }
        $r = $this->page('admin/cms/reusable', ['title' => 'Reusable blocks', 'rows' => CmsService::reusable(), 'editing' => $editing, 'types' => array_diff_key(CmsBlocks::types(), ['reusable' => 1]), 'newType' => $request->string('type'), 'media' => CmsService::media(), 'canEdit' => Gate::can('cms.edit', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveReusable(Request $request, array $params = []): Response
    {
        $id = $params['block'] ?? null;
        $type = $request->string('block_type');

        return $this->guarded(function () use ($request, $id, $type): Response {
            if ($request->string('action') === 'delete' && $id !== null) {
                CmsService::deleteReusable($this->actorId(), $id);

                return $this->ok('/admin/cms/reusable', 'Reusable block deleted.');
            }
            CmsService::saveReusable($this->actorId(), $id, $request->string('name'), $type, $request->body);

            return $this->ok('/admin/cms/reusable', 'Reusable block saved. Every page that uses it now shows the new version.');
        }, '/admin/cms/reusable' . ($id !== null ? '/' . $id : '?type=' . rawurlencode($type)), $this->flat($request));
    }

    /** @return array<string,string> posted scalar fields, for refilling a form (never passwords) */
    private function flat(Request $request): array
    {
        $o = [];
        foreach ($request->body as $k => $v) {
            if (is_string($k) && is_string($v) && !str_contains($k, 'password') && $k !== '_token') {
                $o[$k] = mb_substr($v, 0, 20000);
            }
        }

        return $o;
    }
}
