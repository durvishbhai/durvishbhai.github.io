<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\DataExport;
use Vertex\Services\DataImport;
use Vertex\Services\Gate;

/** Data management: CSV export of the lists a user can see, CSV import of clients and leads, import history. */
final class DataController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $id = $this->actorId();

        return $this->page('admin/data', [
            'title' => 'Import & export',
            'exports' => DataExport::available($id),
            'imports' => DataImport::available($id),
            'history' => Gate::can('data.import', $id) ? Database::all('SELECT i.kind, i.filename, i.total_rows, i.imported_rows, i.failed_rows, i.created_at, u.name AS who FROM data_imports i LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT 15') : [],
            'report' => Session::pull('import_report'),
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params = []): Response
    {
        $type = (string) ($params['type'] ?? '');
        $filters = [];
        foreach (['q', 'status', 'stage', 'priority', 'type', 'project', 'client', 'from', 'to'] as $k) {
            $v = mb_substr($request->string($k), 0, 100);
            if ($v !== '') {
                $filters[$k] = $v;
            }
        }
        $r = DataExport::run($this->actorId(), $type, $filters);

        return new Response(200, $r['csv'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $type . '-' . gmdate('Y-m-d') . '.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'X-Export-Rows' => (string) $r['count']]);
    }

    /** @param array<string,string> $params */
    public function template(Request $request, array $params = []): Response
    {
        $type = (string) ($params['type'] ?? '');
        if (!isset(DataImport::available($this->actorId())[$type])) {
            throw new HttpException(403);
        }

        return new Response(200, DataImport::template($type), ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $type . '-template.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,string> $params */
    public function import(Request $request, array $params = []): Response
    {
        $type = (string) ($params['type'] ?? '');

        return $this->guarded(function () use ($request, $type): Response {
            $file = self::uploadedFiles($request->files['file'] ?? null)[0] ?? null;
            if ($file === null || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) && !is_file((string) $file['tmp_name'])) {
                throw new ValidationException(['Choose a CSV file to upload.']);
            }
            if (!preg_match('/\.csv$/i', (string) $file['name'])) {
                throw new ValidationException(['Only .csv files are accepted.']);
            }
            $content = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, DataImport::MAX_BYTES + 1);
            $check = $request->string('mode') !== 'import';
            $report = DataImport::run($this->actorId(), $type, (string) $file['name'], $content, $check);
            Session::put('import_report', $report + ['type' => $type, 'file' => (string) $file['name']]);

            return Response::redirect('/admin/data');
        }, '/admin/data');
    }
}
