<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Audit;
use Vertex\Services\BackupService;
use Vertex\Services\RateLimiter;

/** Administration -> Backup: download the whole database as one .sql file. Owner only (backups.manage), password re-entry, every download is audited. */
final class BackupController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/backup', ['title' => 'Backup']);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        try {
            $this->requireStepUp($request);
        } catch (\Vertex\Core\ValidationException $e) {
            return $this->back('/admin/backup', $e->errors);
        }
        if (!RateLimiter::hit('backup_download_user', (string) $uid)) {
            throw new HttpException(429);
        }
        try {
            $d = BackupService::sqlDump();
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('SQL backup failed', ['error' => $e->getMessage()]);

            return $this->back('/admin/backup', ['The backup could not be created. Nothing was downloaded. Please try again, or ask your host to export the database from phpMyAdmin.']);
        }
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) Config::get('app.name', 'backup'), ' -')));
        $name = ($slug !== '' ? $slug : 'backup') . '-database-' . gmdate('Ymd-His') . '-utc.sql';
        Audit::record('backup.sql_downloaded', 'backup', null, [], ['tables' => $d['tables'], 'rows' => $d['rows'], 'bytes' => $d['bytes']], [], $uid);
        $response = Response::download($d['path'], $name, 'application/sql');
        @unlink($d['path']);

        return $response;
    }
}
