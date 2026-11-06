<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Audit;
use Vertex\Services\Csv;
use Vertex\Services\ReportService;

/** Reports and charts (modules 8, 9, 29). Each report is limited to what the user may see; the CSV is the same data as the tables. */
final class ReportController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('reports/index', ['title' => 'Reports', 'reports' => ReportService::available($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $key = (string) ($params['report'] ?? '');
        $r = ReportService::run($this->actorId(), $key, $request->string('from') ?: null, $request->string('to') ?: null);

        return $this->page('reports/show', ['title' => $r['title'], 'key' => $key, 'r' => $r]);
    }

    /** @param array<string,string> $params */
    public function csv(Request $request, array $params = []): Response
    {
        $key = (string) ($params['report'] ?? '');
        $r = ReportService::run($this->actorId(), $key, $request->string('from') ?: null, $request->string('to') ?: null);
        $t = $r['tables'][max(0, min((int) $request->string('table'), count($r['tables']) - 1))] ?? throw new HttpException(404);
        Audit::record('data.exported', null, null, [], [], ['type' => 'report:' . $key, 'rows' => count($t['rows'])], $this->actorId());

        return new Response(200, Csv::build($t['columns'], $t['rows']), ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="report-' . $key . '-' . gmdate('Y-m-d') . '.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
