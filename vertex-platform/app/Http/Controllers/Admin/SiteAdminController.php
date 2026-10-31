<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CmsService;
use Vertex\Services\Gate;
use Vertex\Services\SiteContent;
use Vertex\Services\SiteDefaults;
use Vertex\Services\SiteEntities;
use Vertex\Services\SiteHiring;
use Vertex\Services\SiteSeeder;
use Vertex\Services\SiteSections;

/** Administration -> Website: page sections, services, team, partners, portfolio, openings, FAQs, policies and menus. Every action is checked again in the services. */
final class SiteAdminController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $just = null;
        if (!SiteSeeder::done() && Gate::can('cms.publish', $uid)) {
            $just = SiteSeeder::run($uid); // first visit: load the starting services, FAQs and draft policies once
        }
        $site = (array) Config::get('site', []);
        $todo = [];
        foreach (SiteContent::rows('policies') as $p) {
            if ($p['status'] !== 'published') {
                $todo[] = ['text' => $p['title'] . ' is a draft: review it and publish it when it is correct.', 'href' => '/admin/site/policies/' . $p['public_id']];
            }
        }
        foreach (['email' => 'a public email address', 'phone' => 'a phone number'] as $k => $what) {
            if (($site[$k] ?? '') === '') {
                $todo[] = ['text' => "Add {$what} so it can be shown on the Contact page and footer.", 'href' => '/admin/settings#website'];
            }
        }
        $r = $this->page('admin/site/index', ['title' => 'Website', 'counts' => SiteContent::counts(), 'defs' => SiteEntities::defs(), 'pages' => SiteDefaults::pages(), 'todo' => $todo, 'just' => $just,
            'canPublish' => Gate::can('cms.publish', $uid), 'applications' => (Gate::can('careers.view', $uid) || Gate::can('careers.manage', $uid)) ? (int) \Vertex\Core\Database::one("SELECT COUNT(*) AS c FROM site_job_applications WHERE status = 'new'")['c'] : null]);

        return $r;
    }

    /** @param array<string,string> $params */
    public function seed(Request $request, array $params = []): Response
    {
        return $this->guarded(function (): Response {
            $a = SiteSeeder::run($this->actorId(), true);

            return $this->ok('/admin/site', "Starting content loaded: {$a['services']} service(s), {$a['faqs']} FAQ(s), {$a['policies']} draft policy(ies) and {$a['team']} team profile(s). Nothing you already have was changed.");
        }, '/admin/site');
    }

    // ------------------------------------------------------------------------------------------------ sections

    /** @param array<string,string> $params */
    public function sections(Request $request, array $params = []): Response
    {
        $page = (string) ($params['page'] ?? '');
        $def = $this->pageDef($page);
        $r = $this->page('admin/site/sections', ['title' => $def['label'], 'page' => $page, 'def' => $def, 'rows' => SiteSections::all($page), 'media' => CmsService::media(), 'canEdit' => Gate::can('cms.edit', $this->actorId()), 'canPublish' => Gate::can('cms.publish', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @return array<string,mixed> */
    private function pageDef(string $page): array
    {
        return SiteDefaults::pages()[$page] ?? throw new HttpException(404);
    }

    /** @param array<string,string> $params */
    public function saveSections(Request $request, array $params = []): Response
    {
        $page = (string) ($params['page'] ?? '');
        $this->pageDef($page);
        $to = '/admin/site/sections/' . $page;

        return $this->guarded(function () use ($request, $page, $to): Response {
            SiteSections::save($this->actorId(), $page, (array) ($request->body['s'] ?? []));

            return $this->ok($to, 'Saved. The page now shows your wording.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function resetSections(Request $request, array $params = []): Response
    {
        $page = (string) ($params['page'] ?? '');
        $this->pageDef($page);
        $to = '/admin/site/sections/' . $page;

        return $this->guarded(function () use ($request, $page, $to): Response {
            $this->requireStepUp($request);
            SiteSections::reset($this->actorId(), $page);

            return $this->ok($to, 'The original wording is back.');
        }, $to);
    }

    // ------------------------------------------------------------------------------------------------ hiring

    /** @param array<string,string> $params */
    public function hiring(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $apps = (Gate::can('careers.view', $uid) || Gate::can('careers.manage', $uid)) ? (int) \Vertex\Core\Database::one("SELECT COUNT(*) AS c FROM site_job_applications WHERE status = 'new'")['c'] : null;

        return $this->page('admin/site/hiring', ['title' => 'Careers', 'state' => SiteHiring::state(), 'states' => SiteHiring::STATES, 'roles' => SiteHiring::roles(), 'canPublish' => Gate::can('cms.publish', $uid), 'newApplications' => $apps]);
    }

    /** @param array<string,string> $params */
    public function hiringState(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            SiteHiring::set($this->actorId(), $request->string('state'));
            $msg = ['open' => 'Hiring is open: the page shows the roles and the form.', 'closed' => 'Hiring is closed: the page says so and takes no applications.', 'hidden' => 'The Careers page is hidden from visitors.'];

            return $this->ok('/admin/site/hiring', $msg[SiteHiring::state()] ?? 'Saved.');
        }, '/admin/site/hiring');
    }

    // ------------------------------------------------------------------------------------------------ menus

    /** @param array<string,string> $params */
    public function menus(Request $request, array $params = []): Response
    {
        $texts = [];
        $saved = [];
        foreach (CmsService::MENU_LOCATIONS as $loc => $label) {
            $texts[$loc] = old($loc . '_items', CmsService::menuText($loc));
            $saved[$loc] = CmsService::menuSaved($loc);
        }
        $r = $this->page('admin/site/menus', ['title' => 'Menus', 'locations' => CmsService::MENU_LOCATIONS, 'texts' => $texts, 'saved' => $saved, 'canPublish' => Gate::can('cms.publish', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveMenu(Request $request, array $params = []): Response
    {
        $loc = (string) ($params['location'] ?? '');

        return $this->guarded(function () use ($request, $loc): Response {
            CmsService::saveMenu($this->actorId(), $loc, $request->string('items'));

            return $this->ok('/admin/site/menus', (CmsService::MENU_LOCATIONS[$loc] ?? 'Menu') . ' saved.');
        }, '/admin/site/menus', [$loc . '_items' => $request->string('items')]);
    }

    /** @param array<string,string> $params */
    public function resetMenu(Request $request, array $params = []): Response
    {
        $loc = (string) ($params['location'] ?? '');

        return $this->guarded(function () use ($loc): Response {
            CmsService::resetMenu($this->actorId(), $loc);

            return $this->ok('/admin/site/menus', 'The built-in links are back.');
        }, '/admin/site/menus');
    }

    // ------------------------------------------------------------------------------------------------ lists

    /** @param array<string,string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $entity = (string) ($params['entity'] ?? '');
        $d = SiteEntities::def($entity);
        $uid = $this->actorId();
        $status = $request->string('status');

        return $this->page('admin/site/list', ['title' => $d['label'], 'entity' => $entity, 'd' => $d, 'rows' => SiteContent::rows($entity, $status), 'status' => $status,
            'canEdit' => Gate::can($d['edit'], $uid), 'canPublish' => Gate::can($d['publish'], $uid) && ($entity !== 'policies' || Gate::can('cms.publish', $uid))]);
    }

    /** @param array<string,string> $params */
    public function form(Request $request, array $params = []): Response
    {
        $entity = (string) ($params['entity'] ?? '');
        $d = SiteEntities::def($entity);
        $uid = $this->actorId();
        $row = isset($params['id']) ? (SiteContent::find($entity, $params['id']) ?? throw new HttpException(404)) : null;
        if ($row === null && !empty($d['noCreate'])) {
            throw new HttpException(404);
        }
        $r = $this->page('admin/site/form', ['title' => $row === null ? 'New ' . $d['singular'] : 'Edit: ' . $row[$d['title']], 'entity' => $entity, 'd' => $d, 'row' => $row,
            'values' => $row === null ? [] : SiteContent::formValues($entity, $row), 'media' => CmsService::media(), 'services' => $entity === 'services' ? SiteContent::rows('services') : [],
            'canEdit' => Gate::can($d['edit'], $uid), 'canPublish' => Gate::can($d['publish'], $uid) && ($entity !== 'policies' || Gate::can('cms.publish', $uid))]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params = []): Response
    {
        $entity = (string) ($params['entity'] ?? '');
        SiteEntities::def($entity);
        $id = $params['id'] ?? null;
        $back = '/admin/site/' . $entity . ($id === null ? '/new' : '/' . $id);

        return $this->guarded(function () use ($request, $entity, $id): Response {
            $row = SiteContent::save($this->actorId(), $entity, $id, $request->body);

            return $this->ok('/admin/site/' . $entity . '/' . $row['public_id'], 'Saved' . (($row['status'] ?? 'draft') === 'published' ? ' and live.' : ' as a draft. It is not visible to visitors until you publish it.'));
        }, $back, $this->flat($request));
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $entity = (string) ($params['entity'] ?? '');
        SiteEntities::def($entity);
        $row = SiteContent::find($entity, (string) ($params['id'] ?? '')) ?? throw new HttpException(404);
        $to = '/admin/site/' . $entity . '/' . $row['public_id'];

        return $this->guarded(function () use ($request, $entity, $row, $to): Response {
            $a = $request->string('action');
            $map = ['publish' => 'published', 'unpublish' => 'draft', 'archive' => 'archived', 'close' => 'closed'];
            if ($a === 'delete') {
                $this->requireStepUp($request);
                SiteContent::delete($this->actorId(), $entity, $row);

                return $this->ok('/admin/site/' . $entity, 'Deleted.');
            }
            if (!isset($map[$a])) {
                throw new HttpException(422);
            }
            if ($a !== 'publish') {
                $this->requireStepUp($request);
            }
            SiteContent::setStatus($this->actorId(), $entity, $row, $map[$a]);

            return $this->ok($to, ['publish' => 'Published. Visitors can see it now.', 'unpublish' => 'Back to draft: visitors no longer see it.', 'archive' => 'Archived.', 'close' => 'Closed.'][$a]);
        }, $to);
    }

    /** @param array<string,string> $params */
    public function move(Request $request, array $params = []): Response
    {
        $entity = (string) ($params['entity'] ?? '');
        SiteEntities::def($entity);
        $row = SiteContent::find($entity, (string) ($params['id'] ?? '')) ?? throw new HttpException(404);
        $dir = $request->string('dir') === 'up' ? 'up' : 'down';

        return $this->guarded(function () use ($entity, $row, $dir): Response {
            SiteContent::move($this->actorId(), $entity, $row, $dir);

            return Response::redirect('/admin/site/' . $entity);
        }, '/admin/site/' . $entity);
    }

    /** @param array<string,string> $params */
    public function versions(Request $request, array $params = []): Response
    {
        $row = SiteContent::find('policies', (string) ($params['id'] ?? '')) ?? throw new HttpException(404);

        return $this->page('admin/site/versions', ['title' => 'History: ' . $row['title'], 'p' => $row, 'versions' => SiteContent::policyVersions($row), 'canEdit' => Gate::can('legal.manage', $this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function restoreVersion(Request $request, array $params = []): Response
    {
        $row = SiteContent::find('policies', (string) ($params['id'] ?? '')) ?? throw new HttpException(404);

        return $this->guarded(function () use ($row, $params): Response {
            SiteContent::restorePolicy($this->actorId(), $row, (int) ($params['version'] ?? 0));

            return $this->ok('/admin/site/policies/' . $row['public_id'], 'Restored as a new version. Nothing was lost.');
        }, '/admin/site/policies/' . $row['public_id'] . '/versions');
    }

    /** @return array<string,mixed> posted values for refilling the form (arrays kept, never passwords) */
    private function flat(Request $request): array
    {
        $o = [];
        foreach ($request->body as $k => $v) {
            if (is_string($k) && $k !== '_token' && !str_contains($k, 'password')) {
                $o[$k] = is_array($v) ? array_values(array_filter($v, 'is_string')) : mb_substr((string) $v, 0, 40000);
            }
        }

        return $o;
    }
}
