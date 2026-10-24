<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CrmStageService;

final class CrmStageController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('admin/crm/stages', ['title' => 'CRM stages', 'stages' => CrmStageService::all(), 'usage' => CrmStageService::usage()]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $raw = $request->body['stage'] ?? [];
            $rows = [];
            foreach (is_array($raw) ? $raw : [] as $id => $r) {
                if (is_array($r)) {
                    $rows[(int) $id] = ['name' => (string) ($r['name'] ?? ''), 'position' => (string) ($r['position'] ?? ''), 'active' => $r['active'] ?? ''];
                }
            }
            CrmStageService::update($this->actorId(), $rows);

            return $this->ok('/admin/crm-stages', 'Pipeline stages saved.');
        }, '/admin/crm-stages');
    }
}
