<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Private file uploads (deliverables, revision attachments).
 *  - Files are stored OUTSIDE the web root: <app root>/storage/uploads/YYYY/MM/<40 hex random name>. The web server's document root
 *    is public/, so nothing in storage/ is reachable by URL; storage/.htaccess additionally denies access for hosts that point
 *    the document root at the app root. Downloads go only through authorised controllers.
 *  - The size limits below are the APPLICATION limits; PHP's upload_max_filesize / post_max_size (php.ini or .user.ini) must be
 *    at least as large or PHP rejects the upload first. The UI shows the effective limit.
 *  - A file is accepted only when its extension, its detected MIME type AND its signature (magic bytes) all agree with this list.
 *    SVG, HTML, scripts and executables are never accepted. Archives are accepted only as the 'zip' source-file type and are
 *    never extracted or executed; they are served as attachments with nosniff.
 */
$mb = static fn (string $key, int $default): int => max(1, (int) Env::get($key, $default)) * 1024 * 1024;

$root = trim((string) Env::get('UPLOAD_STORAGE_PATH', ''));

return [
    // Absolute directory for private uploads. Default: <app root>/storage/uploads. Must be outside the web root and writable by PHP.
    'root' => $root !== '' ? rtrim($root, '/') : VERTEX_ROOT . '/storage/uploads',
    'max_bytes' => [
        'deliverable' => $mb('UPLOAD_MAX_MB', 50),
        'revision' => $mb('UPLOAD_REVISION_MAX_MB', 10),
        'ticket' => $mb('UPLOAD_TICKET_MAX_MB', 10),
        'partner' => $mb('UPLOAD_PARTNER_MAX_MB', 5),
    ],

    // extension => [accepted detected MIME types, signature kind]
    'types' => [
        'mp4' => [['video/mp4'], 'mp4'],
        'mov' => [['video/quicktime', 'video/mp4'], 'mp4'],
        'webm' => [['video/webm'], 'ebml'],
        'mkv' => [['video/x-matroska', 'video/webm'], 'ebml'],
        'mp3' => [['audio/mpeg'], 'mp3'],
        'wav' => [['audio/x-wav', 'audio/wav', 'audio/vnd.wave'], 'wav'],
        'png' => [['image/png'], 'png'],
        'jpg' => [['image/jpeg'], 'jpeg'],
        'jpeg' => [['image/jpeg'], 'jpeg'],
        'gif' => [['image/gif'], 'gif'],
        'webp' => [['image/webp'], 'webp'],
        'psd' => [['image/vnd.adobe.photoshop'], 'psd'],
        'pdf' => [['application/pdf'], 'pdf'],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'zip'],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'zip'],
        'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'], 'zip'],
        'zip' => [['application/zip', 'application/x-zip-compressed'], 'zip'],
        'txt' => [['text/plain'], 'text'],
        'md' => [['text/plain', 'text/markdown'], 'text'],
        'csv' => [['text/plain', 'text/csv', 'application/csv'], 'text'],
    ],

    // Extensions that are refused even as an inner segment of a name ("logo.php.png"), as defence in depth.
    'blocked_segments' => ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'sh', 'bash',
        'js', 'mjs', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb', 'htaccess', 'htpasswd', 'html', 'htm', 'xhtml', 'svg', 'swf', 'jar', 'vbs', 'ps1', 'scr', 'lnk'],

    // Malware scanning integration point. 'none' = no scanner is available; files are then recorded as scan_status=not_scanned
    // (never as clean). 'command' runs UPLOAD_SCANNER_COMMAND <file> (e.g. clamscan --no-summary): exit 0 = clean, 1 = infected, anything
    // else = scanner error; infected/error REJECTS the upload.
    'scanner' => [
        'driver' => (string) Env::get('UPLOAD_SCANNER', 'none'),
        'command' => (string) Env::get('UPLOAD_SCANNER_COMMAND', ''),
        'args' => (string) Env::get('UPLOAD_SCANNER_ARGS', '--no-summary'),
    ],

    // External resource links on a deliverable version (never fetched by the server). https only. Empty list = any host.
    'external_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('DELIVERABLE_LINK_HOSTS', ''))))),

    // May the person who uploaded a version also approve it internally? Default no: QA needs a second pair of eyes.
    'allow_self_approval' => (bool) Env::get('DELIVERABLE_ALLOW_SELF_APPROVAL', false),
];
