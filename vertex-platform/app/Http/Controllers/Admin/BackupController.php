<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Audit;
use Vertex\Services\Auth;
use Vertex\Services\BackupService;
use Vertex\Services\RateLimiter;

/**
 * Administration -> Backup: download the database (.sql) or every uploaded file (.zip), and restore either one. Owner only (backups.manage); every action asks for the
 * password, restores also need the word RESTORE typed, and everything is audited. A database restore keeps a safety copy of the current data first.
 */
final class BackupController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $tab = $request->string('tab') === 'files' ? 'files' : 'database';
        $dir = BackupService::dir();
        $safety = array_map('basename', glob($dir . '/pre-restore-*.sql') ?: []);
        rsort($safety);
        $r = $this->page('admin/backup', ['title' => 'Backup & restore', 'tab' => $tab, 'limit' => BackupService::uploadLimit(), 'zip' => class_exists(\ZipArchive::class), 'safety' => array_slice($safety, 0, 5)]);
        Session::forget('_old');

        return $r;
    }

    /** The uploaded backup file, checked: a real upload with an allowed extension. @return array{path:string,name:string} */
    private function upload(Request $request, string $ext): array
    {
        $f = $request->files['backup_file'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['Choose the backup file first.']);
        }
        if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['The file is bigger than this server accepts (' . round(BackupService::uploadLimit() / 1048576) . ' MB). For a database, use phpMyAdmin → Import instead; for files, ask the host to raise the upload limit or restore in parts.']);
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new ValidationException(['The file could not be uploaded. Please try again.']);
        }
        if (strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== $ext) {
            throw new ValidationException(['Choose a .' . $ext . ' file.']);
        }

        return ['path' => (string) $f['tmp_name'], 'name' => (string) $f['name']];
    }

    private function confirmed(Request $request): void
    {
        $this->requireStepUp($request);
        if (strtoupper($request->string('confirm_text')) !== 'RESTORE') {
            throw new ValidationException(['Type the word RESTORE in the box to confirm.']);
        }
    }

    /** @param array<string,string> $params */
    public function downloadFiles(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        try {
            $this->requireStepUp($request);
        } catch (ValidationException $e) {
            return $this->back('/admin/backup?tab=files', $e->errors);
        }
        if (!RateLimiter::hit('backup_files_user', (string) $uid)) {
            throw new HttpException(429);
        }
        @set_time_limit(0);
        try {
            $d = BackupService::filesZip();
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Files backup failed', ['error' => $e->getMessage()]);

            return $this->back('/admin/backup?tab=files', ['The files backup could not be created. Nothing was downloaded. ' . ($e instanceof \RuntimeException ? $e->getMessage() : 'Please try again.')]);
        }
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) Config::get('app.name', 'backup'), ' -')));
        Audit::record('backup.files_downloaded', 'backup', null, [], ['files' => $d['files'], 'bytes' => $d['bytes']], [], $uid);

        return Response::streamDownload($d['path'], ($slug !== '' ? $slug : 'backup') . '-files-' . gmdate('Ymd-His') . '-utc.zip', 'application/zip', true);
    }

    /** @param array<string,string> $params */
    public function restoreSql(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        try {
            $this->confirmed($request);
            if (!RateLimiter::hit('backup_restore_user', (string) $uid)) {
                throw new HttpException(429);
            }
            $up = $this->upload($request, 'sql');
            $r = BackupService::restoreSql($up['path']);
        } catch (ValidationException $e) {
            return $this->back('/admin/backup?tab=database', $e->errors);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('SQL restore refused or failed', ['error' => $e->getMessage()]);

            return $this->back('/admin/backup?tab=database', [$e instanceof \RuntimeException ? $e->getMessage() : 'The restore failed. Your data was not changed.']);
        }
        // the restored database may not contain this person: record the event against them only when they exist there
        $actor = \Vertex\Core\Database::one('SELECT id FROM users WHERE id = ?', [$uid]) !== null ? $uid : null;
        try {
            Audit::record('backup.sql_restored', 'backup', null, [], ['file' => mb_substr($up['name'], 0, 120), 'tables' => $r['tables'], 'statements' => $r['statements']], ['safety_copy' => $r['safety_copy'], 'by_user_id_before' => $uid], $actor);
        } catch (\Throwable) {
            // the record is a courtesy: the restore itself has succeeded
        }
        Auth::reset();
        Session::destroy();

        return $this->page('admin/backup-restored', ['title' => 'Database restored', 'kind' => 'database', 'r' => $r], 'layouts/bare');
    }

    /** @param array<string,string> $params */
    public function restoreFiles(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        try {
            $this->confirmed($request);
            if (!RateLimiter::hit('backup_restore_user', (string) $uid)) {
                throw new HttpException(429);
            }
            $up = $this->upload($request, 'zip');
            @set_time_limit(0);
            $r = BackupService::restoreFiles($up['path']);
        } catch (ValidationException $e) {
            return $this->back('/admin/backup?tab=files', $e->errors);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->back('/admin/backup?tab=files', [$e instanceof \RuntimeException ? $e->getMessage() : 'The restore failed.']);
        }
        Audit::record('backup.files_restored', 'backup', null, [], ['file' => mb_substr($up['name'], 0, 120), 'restored' => $r['restored'], 'unchanged' => $r['unchanged'], 'problems' => count($r['problems'])], [], $uid);
        $msg = 'Files restored: ' . $r['restored'] . ' put back, ' . $r['unchanged'] . ' already identical.';
        if ($r['problems'] !== []) {
            return $this->back('/admin/backup?tab=files', ['Some files were not restored: ' . implode(' · ', array_slice($r['problems'], 0, 6)) . (count($r['problems']) > 6 ? ' · and ' . (count($r['problems']) - 6) . ' more' : '') . '. ' . $msg]);
        }

        return $this->ok('/admin/backup?tab=files', $msg);
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        try {
            $this->requireStepUp($request);
        } catch (ValidationException $e) {
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
