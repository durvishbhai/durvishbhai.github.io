<?php

declare(strict_types=1);

/*
 * Builds the Hostinger upload package. Developer tool: not part of the package itself.
 *
 *   php bin/build-release.php --schema=/path/schema.sql --out=/path/dir [--key=INSTALL-KEY] [--vendor=/path/to/prod-vendor]
 *
 * --schema  database file produced by `php bin/console.php schema:export --file=...` against a freshly migrated + synced scratch database
 * --vendor  an existing `composer install --no-dev` vendor directory (default: run composer in a temporary copy)
 *
 * Output (in --out):  vertex/  public_html/  vertex-database.sql  and  vertex-platform-hostinger-<date>.zip
 * The package contains ONLY files with the extensions .js .css .php .sql. Images are embedded in public_html/assets/img.php, and the web
 * installer (public_html/install.php) writes .env and the .htaccess files on the server. The build FAILS if anything else slips in.
 */

const ALLOWED = ['js', 'css', 'php', 'sql'];

$root = dirname(__DIR__);
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
function stop(string $m): never
{
    fwrite(STDERR, "BUILD FAILED: {$m}\n");
    exit(1);
}
$schema = $opt['schema'] ?? stop('--schema=FILE is required');
$out = rtrim($opt['out'] ?? stop('--out=DIR is required'), '/');
is_file($schema) || stop("schema file not found: {$schema}");

$pkg = $out . '/package';
if (is_dir($pkg)) {
    exec('rm -rf ' . escapeshellarg($pkg));
}
mkdir($pkg . '/vertex', 0755, true);
mkdir($pkg . '/public_html/assets', 0755, true);

function ext(string $f): string
{
    return strtolower(pathinfo($f, PATHINFO_EXTENSION));
}

/** Copies a directory keeping only allowed extensions; returns the number of files and names what it skipped. */
function copy_tree(string $from, string $to, array &$skipped): int
{
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) {
            continue;
        }
        $rel = substr($f->getPathname(), strlen($from) + 1);
        if (!in_array(ext($rel), ALLOWED, true) || str_starts_with(basename($rel), '.') || basename($rel) === 'get_oauth_token.php' || array_intersect(explode('/', strtolower($rel)), ['tests', 'test', 'examples', 'docs', '.git'])) {
            $skipped[] = $from . '/' . $rel;
            continue;
        }
        @mkdir(dirname($to . '/' . $rel), 0755, true);
        copy($f->getPathname(), $to . '/' . $rel);
        $n++;
    }

    return $n;
}

$skipped = [];
// 1. Application
foreach (['app', 'config'] as $d) {
    copy_tree($root . '/' . $d, $pkg . '/vertex/' . $d, $skipped);
}
copy_tree($root . '/database/migrations', $pkg . '/vertex/database/migrations', $skipped);
mkdir($pkg . '/vertex/bin', 0755, true);
copy($root . '/bin/console.php', $pkg . '/vertex/bin/console.php');
copy($root . '/bootstrap.php', $pkg . '/vertex/bootstrap.php');

// 2. Production dependencies (PHPMailer + Composer autoloader), reduced to .php files
$vendor = $opt['vendor'] ?? null;
if ($vendor === null) {
    $tmp = sys_get_temp_dir() . '/vis-vendor-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    copy($root . '/composer.json', $tmp . '/composer.json');
    copy($root . '/composer.lock', $tmp . '/composer.lock');
    mkdir($tmp . '/app/Core', 0755, true);
    copy($root . '/app/Core/helpers.php', $tmp . '/app/Core/helpers.php');
    passthru('cd ' . escapeshellarg($tmp) . ' && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts 2>&1', $rc);
    $rc === 0 || stop('composer install failed');
    $vendor = $tmp . '/vendor';
}
is_dir($vendor . '/phpmailer') || stop('vendor has no phpmailer');
$licence = (string) @file_get_contents($vendor . '/phpmailer/phpmailer/LICENSE');
copy_tree($vendor, $pkg . '/vertex/vendor', $skipped);
// PHPMailer is LGPL-2.1 and its licence text must travel with it; it is stored as a PHP comment because only .php files may ship.
$licence !== '' || stop('PHPMailer LICENSE not found');
file_put_contents($pkg . '/vertex/vendor/phpmailer/phpmailer/LICENSE.php', "<?php\n\n// PHPMailer - licence text (GNU LGPL 2.1). This file only carries the text; it does nothing when executed.\nreturn;\n\n/*\n" . str_replace('*/', '* /', $licence) . "\n*/\n");

// 3. Web folder
copy($root . '/public/index.php', $pkg . '/public_html/index.php');
copy_tree($root . '/public/assets/css', $pkg . '/public_html/assets/css', $skipped);
copy_tree($root . '/public/assets/js', $pkg . '/public_html/assets/js', $skipped);

$images = [];
foreach (glob($root . '/public/assets/img/*.png') ?: [] as $png) {
    $images[basename($png)] = base64_encode((string) file_get_contents($png));
}
count($images) === 4 || stop('expected 4 brand images, found ' . count($images));
$php = "<?php\n\ndeclare(strict_types=1);\n\n/*\n * Serves the brand images. They are stored here as text so the upload package contains only .php, .js, .css and .sql files.\n * Usage: /assets/img.php?f=krazy-mark.png\n */\n\nconst IMAGES = [\n";
foreach ($images as $name => $b64) {
    $php .= "    '" . $name . "' => '" . $b64 . "',\n";
}
$php .= "];\n\n\$name = (string) (\$_GET['f'] ?? '');\nif (!isset(IMAGES[\$name])) {\n    http_response_code(404);\n    exit;\n}\n\$data = (string) base64_decode(IMAGES[\$name], true);\n\$etag = '\"' . md5(\$data) . '\"';\nheader('Content-Type: image/png');\nheader('X-Content-Type-Options: nosniff');\nheader('Cache-Control: public, max-age=31536000, immutable');\nheader('ETag: ' . \$etag);\nif ((\$_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === \$etag) {\n    http_response_code(304);\n    exit;\n}\nheader('Content-Length: ' . strlen(\$data));\necho \$data;\n";
file_put_contents($pkg . '/public_html/assets/img.php', $php);

// 4. Database file
$sql = (string) file_get_contents($schema);
$migrations = glob($root . '/database/migrations/*.sql') ?: [];
substr_count($sql, "\n-- ===== ") >= count($migrations) || stop('schema file does not contain every migration');
foreach ($migrations as $mf) {
    str_contains($sql, '-- ===== ' . basename($mf) . ' =====') || stop('schema file is missing ' . basename($mf));
    str_contains($sql, hash_file('sha256', $mf)) || stop('schema file ledger does not match ' . basename($mf));
}
str_contains($sql, "INSERT INTO `roles`") && str_contains($sql, "INSERT INTO `permissions`") || stop('schema file has no roles/permissions');
!preg_match('/INSERT INTO `?users`?/i', $sql) || stop('schema file contains user data');
file_put_contents($pkg . '/vertex-database.sql', $sql);
@mkdir($pkg . '/vertex/database', 0755, true);
file_put_contents($pkg . '/vertex/database/schema.sql', $sql);

// 5. Installer with its own one-time key
$key = $opt['key'] ?? implode('-', str_split(strtoupper(substr(strtr(base64_encode(random_bytes(24)), ['+' => 'A', '/' => 'B', '=' => '']), 0, 24)), 6));
$installer = (string) file_get_contents($root . '/tools/release/install.php');
str_contains($installer, '__INSTALL_KEY_HASH__') || stop('installer template lost its placeholder');
file_put_contents($pkg . '/public_html/install.php', str_replace('__INSTALL_KEY_HASH__', hash('sha256', $key), $installer));

// 6. Verify: allow-list, syntax, secrets
$count = 0;
$bytes = 0;
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkg, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($pkg) + 1);
    in_array(ext($rel), ALLOWED, true) || stop("forbidden file type in package: {$rel}");
    !str_starts_with(basename($rel), '.') || stop("hidden file in package: {$rel}");
    if (ext($rel) === 'php') {
        exec('php -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $o, $rc);
        $rc === 0 || stop("syntax error in {$rel}: " . implode(' ', $o));
        $o = [];
    }
    $body = (string) file_get_contents($f->getPathname());
    if (ext($rel) !== 'sql' && preg_match('/APP_KEY\s*=\s*.base64:[A-Za-z0-9+\/=]{20,}|DB_PASS' . 'WORD\s*=\s*"[^"$]{3,}"/', $body)) {
        stop("possible secret in {$rel}");
    }
    $files[] = $rel;
    $count++;
    $bytes += $f->getSize();
}
sort($files);
$count > 100 || stop('package suspiciously small');

// 6b. Single-folder layout: everything inside one folder to upload straight into public_html, with the database file beside it (not inside the web folder).
if (($opt['layout'] ?? '') === 'inside') {
    $inside = $out . '/package-inside';
    exec('rm -rf ' . escapeshellarg($inside));
    mkdir($inside . '/upload_to_public_html', 0755, true);
    exec('cp -R ' . escapeshellarg($pkg . '/public_html') . '/. ' . escapeshellarg($inside . '/upload_to_public_html') . '/ && cp -R ' . escapeshellarg($pkg . '/vertex') . ' ' . escapeshellarg($inside . '/upload_to_public_html/vertex') . ' && cp ' . escapeshellarg($pkg . '/vertex-database.sql') . ' ' . escapeshellarg($inside . '/vertex-database.sql'), $o2, $rc2);
    $rc2 === 0 || stop('could not assemble the single-folder layout');
    $zipIn = $out . '/vertex-platform-hostinger-single-folder-' . gmdate('Y-m-d') . '.zip';
    @unlink($zipIn);
    $z = new ZipArchive();
    $z->open($zipIn, ZipArchive::CREATE) === true || stop('cannot create the single-folder zip');
    $itn = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inside, FilesystemIterator::SKIP_DOTS));
    foreach ($itn as $f) {
        if ($f->isFile()) {
            $relIn = substr($f->getPathname(), strlen($inside) + 1);
            in_array(ext($relIn), ALLOWED, true) && !str_starts_with(basename($relIn), '.') || stop("forbidden file in single-folder package: {$relIn}");
            $z->addFile($f->getPathname(), $relIn);
        }
    }
    $z->close();
    echo "Single-folder zip: {$zipIn} (" . filesize($zipIn) . " bytes)\n";
}

// 7. Zip
$zipFile = $out . '/vertex-platform-hostinger-' . gmdate('Y-m-d') . '.zip';
@unlink($zipFile);
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE) === true || stop('cannot create zip');
foreach ($files as $rel) {
    $zip->addFile($pkg . '/' . $rel, $rel);
}
$zip->close();

echo "Files: {$count} (" . round($bytes / 1024) . " KB)   Skipped (not allowed or hidden): " . count($skipped) . "\n";
echo "Zip:   {$zipFile} (" . filesize($zipFile) . " bytes)\n";
echo 'SHA256: ' . hash_file('sha256', $zipFile) . "\n";
echo "INSTALL KEY: {$key}\n";
