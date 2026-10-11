<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Private file storage for deliverables and revision attachments (see config/uploads.php).
 *
 * Two steps so a failed business transaction never leaves a stored file or an orphan row behind:
 *   $meta = FileStore::accept($_FILES['file'], 'deliverable');   // validates, scans, moves to its random private path
 *   Database::transaction(fn () => $fileId = FileStore::insert($meta, $uploaderId) ...);   // inside the caller's transaction
 *   on failure:  FileStore::discard($meta);
 *
 * Nothing here accepts a path or a stored_files id from the browser: downloads are served by the controller of the owning record
 * (deliverable version / revision), which authorises first and only then calls FileStore::send().
 */
final class FileStore
{
    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file one entry of $_FILES
     * @return array{name:string,stored_path:string,mime:string,size:int,sha256:string,scan_status:string,scanner:?string}
     */
    public static function accept(array $file, string $context = 'deliverable', bool $requireHttpUpload = true): array
    {
        $limit = self::effectiveLimit($context);
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['The file is larger than the ' . self::humanSize($limit) . ' limit.']);
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new ValidationException([$err === UPLOAD_ERR_NO_FILE ? 'Choose a file to upload.' : 'The upload did not complete. Please try again.']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || ($requireHttpUpload && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            throw new ValidationException(['The upload was not accepted.']);
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new ValidationException(['The file is empty.']);
        }
        if ($size > $limit) {
            throw new ValidationException(['The file is larger than the ' . self::humanSize($limit) . ' limit.']);
        }

        $name = self::safeName((string) ($file['name'] ?? ''));
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $types = (array) Config::get('uploads.types');
        if (!isset($types[$ext])) {
            throw new ValidationException(['That file type is not allowed. Allowed: ' . implode(', ', array_keys($types)) . '.']);
        }
        foreach (array_slice(explode('.', strtolower($name)), 1, -1) as $segment) {
            if (in_array($segment, (array) Config::get('uploads.blocked_segments'), true)) {
                throw new ValidationException(['That file name is not allowed. Rename the file and try again.']);
            }
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        [$mimes, $kind] = $types[$ext];
        if (!in_array($mime, $mimes, true)) {
            throw new ValidationException(['The file content does not match its extension.']);
        }
        if (!self::signatureMatches($tmp, $kind)) {
            throw new ValidationException(['The file content does not match its extension.']);
        }
        [$scanStatus, $scanner] = self::scan($tmp);

        $rel = gmdate('Y/m') . '/' . bin2hex(random_bytes(20));
        $dir = self::root() . '/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $dest = self::root() . '/' . $rel;
        $moved = $requireHttpUpload ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$moved) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        @chmod($dest, 0640);

        return ['name' => $name, 'stored_path' => $rel, 'mime' => $mime, 'size' => $size, 'sha256' => (string) hash_file('sha256', $dest), 'scan_status' => $scanStatus, 'scanner' => $scanner];
    }

    /** Inserts the metadata row. Call INSIDE the business transaction. @param array<string,mixed> $meta @return int stored_files.id */
    public static function insert(array $meta, ?int $uploadedBy): int
    {
        Database::run(
            'INSERT INTO stored_files (public_id, original_name, stored_path, mime, size_bytes, sha256, scan_status, scanner, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [uuid_hex(), $meta['name'], $meta['stored_path'], $meta['mime'], $meta['size'], $meta['sha256'], $meta['scan_status'], $meta['scanner'], $uploadedBy],
        );

        return Database::insertId();
    }

    /** Removes a file that was accepted but whose database work failed. @param array<string,mixed> $meta */
    public static function discard(array $meta): void
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{40}$#', (string) ($meta['stored_path'] ?? ''))) {
            @unlink(self::root() . '/' . $meta['stored_path']);
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM stored_files WHERE id = ?', [$id]);
    }

    /**
     * The download response for a file the CALLER HAS ALREADY AUTHORISED. Always an attachment, never inline, with nosniff and
     * no caching. @param array<string,mixed> $file a stored_files row
     */
    public static function send(array $file): \Vertex\Core\Response
    {
        return \Vertex\Core\Response::download(self::path($file), (string) $file['original_name'], (string) $file['mime']);
    }

    /** @param array<string,mixed> $file */
    public static function path(array $file): string
    {
        $rel = (string) $file['stored_path'];
        $p = self::root() . '/' . $rel;
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $rel) || !is_file($p)) {
            throw new HttpException(404);
        }

        return $p;
    }

    public static function root(): string
    {
        return (string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads');
    }

    /** The limit that really applies: the application limit capped by PHP's own upload limits. */
    public static function effectiveLimit(string $context): int
    {
        $app = (int) Config::get("uploads.max_bytes.{$context}", 5 * 1024 * 1024);
        $php = array_filter([self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))], static fn (int $b): bool => $b > 0);

        return $php === [] ? $app : min($app, min($php));
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** @return list<string> extensions, for help text */
    public static function allowedExtensions(): array
    {
        return array_keys((array) Config::get('uploads.types'));
    }

    /** Display-safe file name: no path, no control characters, no markup, bounded length, never starts with a dot. */
    public static function safeName(string $raw): string
    {
        $name = str_replace('\\', '/', $raw);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = (string) preg_replace('/[<>:"|?*\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '_', $name); // incl. bidi override characters
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");
        if ($name === '' || !str_contains($name, '.')) {
            throw new ValidationException(['The file needs a name with an extension, such as report.pdf.']);
        }
        if (mb_strlen($name) > 150) {
            $ext = (string) pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140 - mb_strlen($ext)) . '.' . $ext;
        }

        return ltrim($name, '.');
    }

    /** @return array{0:string,1:?string} [scan_status, scanner] — 'clean' only when a scanner really ran and passed */
    private static function scan(string $path): array
    {
        $driver = (string) Config::get('uploads.scanner.driver', 'none');
        if ($driver === 'none' || $driver === '') {
            return ['not_scanned', null];
        }
        if ($driver !== 'command') {
            throw new \RuntimeException("Unknown UPLOAD_SCANNER '{$driver}'.");
        }
        $cmd = (string) Config::get('uploads.scanner.command');
        if ($cmd === '' || !is_file($cmd) || !is_executable($cmd)) {
            \Vertex\Core\Logger::error('Upload scanner is configured but not executable', ['command' => basename($cmd)]);
            throw new ValidationException(['Uploads are temporarily unavailable (the virus scanner is not working). Please try again later.']);
        }
        $args = array_values(array_filter(preg_split('/\s+/', trim((string) Config::get('uploads.scanner.args', ''))) ?: []));
        $proc = proc_open([$cmd, ...$args, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new ValidationException(['Uploads are temporarily unavailable (the virus scanner could not start).']);
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0) {
            return ['clean', basename($cmd)];
        }
        if ($code === 1) {
            \Vertex\Core\Logger::security('Upload rejected by scanner', ['scanner' => basename($cmd)]);
            throw new ValidationException(['The file was rejected by the virus scanner.']);
        }
        \Vertex\Core\Logger::error('Upload scanner error', ['exit' => $code]);
        throw new ValidationException(['The file could not be scanned, so it was not accepted. Please try again later.']);
    }

    private static function signatureMatches(string $path, string $kind): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);
        $at = static fn (int $o, int $n): string => substr($head, $o, $n);

        return match ($kind) {
            'png' => $at(0, 8) === "\x89PNG\r\n\x1a\n",
            'jpeg' => $at(0, 3) === "\xFF\xD8\xFF",
            'gif' => in_array($at(0, 6), ['GIF87a', 'GIF89a'], true),
            'webp' => $at(0, 4) === 'RIFF' && $at(8, 4) === 'WEBP',
            'wav' => $at(0, 4) === 'RIFF' && $at(8, 4) === 'WAVE',
            'pdf' => str_contains($at(0, 1024), '%PDF-'),
            'psd' => $at(0, 4) === '8BPS',
            'zip' => $at(0, 4) === "PK\x03\x04",
            'mp4' => $at(4, 4) === 'ftyp',
            'ebml' => $at(0, 4) === "\x1A\x45\xDF\xA3",
            'mp3' => $at(0, 3) === 'ID3' || (strlen($head) > 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
            'text' => !str_contains($head, "\0") && ($head === '' || mb_check_encoding($head, 'UTF-8') || mb_check_encoding(substr($head, 0, -3), 'UTF-8')),
            default => false,
        };
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
