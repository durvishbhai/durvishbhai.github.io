<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Secure file attachments for enquiries and tasks.
 *  - Files are stored OUTSIDE the web root (storage/uploads/YYYY/MM/<random>) under random names; the original name
 *    lives only in the database and is sanitised again on download.
 *  - Extension AND detected content type (finfo, not the browser's claim) must both be on the allow-list. SVG, HTML,
 *    scripts, archives and executables are never accepted.
 *  - Max 5 MB. Every download goes through an authorized controller; there is no direct URL to a file.
 */
final class AttachmentService
{
    public const MAX_BYTES = 5_242_880;

    /** extension => accepted detected MIME types */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file one entry of $_FILES
     * @return array<string,mixed> the stored attachment row
     */
    public static function store(string $parentType, int $parentId, int $uploadedBy, array $file, bool $requireHttpUpload = true): array
    {
        if (!in_array($parentType, ['enquiry', 'task'], true)) {
            throw new \InvalidArgumentException('Unsupported attachment parent.');
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['The file is larger than the 5 MB limit.']);
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new ValidationException(['Choose a file to upload.']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($requireHttpUpload && !is_uploaded_file($tmp)) {
            throw new ValidationException(['The upload was not accepted.']);
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new ValidationException(['Files must be between 1 byte and 5 MB.']);
        }
        $orig = self::safeName((string) ($file['name'] ?? 'file'));
        $ext = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new ValidationException(['That file type is not allowed. Allowed: ' . implode(', ', array_keys(self::ALLOWED)) . '.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            throw new ValidationException(['The file content does not match its extension.']);
        }

        $rel = gmdate('Y/m') . '/' . bin2hex(random_bytes(20));
        $dir = FileStore::root() . '/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $dest = FileStore::root() . '/' . $rel;
        $moved = $requireHttpUpload ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$moved) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        @chmod($dest, 0640);

        try {
            return Database::transaction(function () use ($parentType, $parentId, $uploadedBy, $orig, $rel, $mime, $size, $dest): array {
                $pub = uuid_hex();
                Database::run(
                    'INSERT INTO attachments (public_id, parent_type, parent_id, original_name, stored_path, mime, size_bytes, sha256, uploaded_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$pub, $parentType, $parentId, $orig, $rel, $mime, $size, hash_file('sha256', $dest), $uploadedBy],
                );
                $id = Database::insertId();
                Audit::record('attachment.uploaded', $parentType, $parentId, [], [], ['attachment_id' => $id, 'mime' => $mime, 'size' => $size], $uploadedBy);

                return Database::one('SELECT * FROM attachments WHERE id = ?', [$id]) ?? [];
            });
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one('SELECT * FROM attachments WHERE public_id = ? AND deleted_at IS NULL', [$publicId]);
    }

    /** @return list<array<string,mixed>> */
    public static function forParent(string $type, int $parentId): array
    {
        return Database::all(
            'SELECT a.public_id, a.original_name, a.size_bytes, a.mime, a.created_at, u.name AS uploader FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by
              WHERE a.parent_type = ? AND a.parent_id = ? AND a.deleted_at IS NULL ORDER BY a.id',
            [$type, $parentId],
        );
    }

    /** Absolute path of the stored file (never exposed to clients). @param array<string,mixed> $att */
    public static function path(array $att): string
    {
        $p = FileStore::root() . '/' . $att['stored_path'];
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{40}$#', (string) $att['stored_path']) || !is_file($p)) {
            throw new HttpException(404);
        }

        return $p;
    }

    public static function delete(int $actorId, array $att): void
    {
        Database::transaction(function () use ($actorId, $att): void {
            Database::run('UPDATE attachments SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND deleted_at IS NULL', [$att['id']]);
            Audit::record('attachment.deleted', (string) $att['parent_type'], (int) $att['parent_id'], [], [], ['attachment_id' => (int) $att['id']], $actorId);
        });
        @unlink(FileStore::root() . '/' . $att['stored_path']);
    }

    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\'<>|:*?]/u', '', $name);
        $name = trim($name, ". \t");

        return mb_substr($name === '' ? 'file' : $name, 0, 150);
    }
}
