<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;
use Vertex\Services\FileStore;

/** Upload validation, private storage and the scanner integration point. */
final class FileStoreTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
        Config::set('uploads.scanner.driver', 'none');
        Config::set('uploads.max_bytes.deliverable', 50 * 1024 * 1024);
    }

    private function reject(array $file, string $needle, string $why = ''): void
    {
        try {
            FileStore::accept($file, 'deliverable', false);
            $this->fail("Expected rejection: {$why}");
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' | ', $e->errors), $why);
        }
    }

    /** @param array<string,mixed> $meta */
    private function stored(array $meta): string
    {
        return FileStore::root() . '/' . $meta['stored_path'];
    }

    public function testAcceptsGenuineFilesOfEveryAllowedKindAndStoresThemPrivately(): void
    {
        $zip = tempnam(sys_get_temp_dir(), 'z');
        $za = new \ZipArchive();
        $za->open($zip, \ZipArchive::OVERWRITE);
        $za->addFromString('index.php', '<?php // theme file inside a source archive is fine: it is never extracted or executed');
        $za->close();
        $this->tmpFiles[] = $zip;
        $mp4 = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\0", 64);
        $cases = [
            'design.png' => $this->png(), 'notes.txt' => $this->tmp('notes.txt', "Plain notes\nline two\n"), 'data.csv' => $this->tmp('data.csv', "a,b\n1,2\n"),
            'brief.pdf' => $this->tmp('brief.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"), 'clip.mp4' => $this->tmp('clip.mp4', $mp4),
            'source.zip' => ['name' => 'source.zip', 'tmp_name' => $zip, 'size' => filesize($zip), 'error' => UPLOAD_ERR_OK],
        ];
        foreach ($cases as $name => $f) {
            $m = FileStore::accept($f, 'deliverable', false);
            $this->assertSame($name, $m['name']);
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $m['stored_path'], 'random server-side name');
            $path = $this->stored($m);
            $this->assertFileExists($path);
            $this->assertStringStartsWith(realpath(VERTEX_ROOT . '/storage/uploads'), realpath($path));
            $this->assertStringNotContainsString('/public/', $path, 'never under the web root');
            $this->assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
            $this->assertSame('not_scanned', $m['scan_status'], 'no scanner: never claimed clean');
            $this->assertSame(hash_file('sha256', $path), $m['sha256']);
            FileStore::discard($m);
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testRejectsDisallowedTypesContentMismatchesAndEmptyFiles(): void
    {
        $this->reject($this->tmp('shell.php', '<?php system($_GET["c"]);'), 'not allowed', 'php');
        $this->reject($this->tmp('page.html', '<html><script>alert(1)</script></html>'), 'not allowed', 'html');
        $this->reject($this->tmp('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'not allowed', 'svg');
        $this->reject($this->tmp('run.exe', "MZ\x90\x00"), 'not allowed', 'exe');
        $this->reject($this->tmp('noext', 'abc'), 'extension', 'no extension');
        $this->reject($this->tmp('photo.png', "MZ\x90\x00\x03\x00\x00\x00 an executable pretending to be a png"), 'does not match', 'exe renamed to png');
        $this->reject($this->tmp('page.txt', '<!DOCTYPE html><html><body>hi</body></html>'), 'does not match', 'html renamed to txt');
        $this->reject($this->tmp('doc.pdf', 'this is not a pdf at all'), 'does not match', 'text renamed to pdf');
        $this->reject($this->tmp('bin.txt', "text\0with a null byte"), 'does not match', 'binary renamed to txt');
        $this->reject($this->tmp('empty.png', ''), 'empty', 'empty');
        $this->reject($this->png('logo.php.png'), 'name is not allowed', 'double extension');
        $this->reject($this->png('evil.phtml.png'), 'name is not allowed', 'phtml segment');
        $this->reject($this->png('x.htaccess.png'), 'name is not allowed', 'htaccess segment');
        $this->reject(['name' => 'a.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE], 'choose a file');
        try {
            FileStore::accept(['name' => 'a.png', 'tmp_name' => '/etc/passwd', 'size' => 10, 'error' => UPLOAD_ERR_OK], 'deliverable', true);
            $this->fail('a tmp_name that PHP did not create for this request must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not accepted', implode(' ', $e->errors));
        }
        $this->reject(['name' => 'a.png', 'tmp_name' => '', 'size' => 10, 'error' => UPLOAD_ERR_INI_SIZE], 'limit');
    }

    public function testEnforcesTheConfigurableSizeLimit(): void
    {
        Config::set('uploads.max_bytes.deliverable', 200);
        $this->reject($this->png('big.png', str_repeat('A', 300)), 'larger than', 'over the limit');
        $this->assertSame(200, FileStore::effectiveLimit('deliverable'));
        $m = FileStore::accept($this->png('ok.png'), 'deliverable', false);
        FileStore::discard($m);
        $this->assertSame('200 KB', FileStore::humanSize(200 * 1024));
        $this->assertSame('1 KB', FileStore::humanSize(10));
        $this->assertSame('1.5 MB', FileStore::humanSize((int) (1.5 * 1048576)));
    }

    public function testNamesAreSanitisedAndTraversalIsHarmless(): void
    {
        foreach ([
            ['../../etc/passwd.png', 'passwd.png'], ['..\\..\\windows\\system32\\x.png', 'x.png'], ['/var/www/shell.png', 'shell.png'],
            ["new\nline\r.png", 'newline.png'], ['bad<>:"|?*chars.png', 'bad_chars.png'], ["rtl\u{202E}gnp.exe.png", null], ['  spaced   out  .png', 'spaced out .png'],
            ['....png', null], ['.hidden.png', 'hidden.png'],
        ] as [$raw, $expected]) {
            try {
                $m = FileStore::accept($this->png($raw), 'deliverable', false);
            } catch (ValidationException) {
                $this->assertNull($expected, "'{$raw}' was refused");
                continue;
            }
            if ($expected !== null) {
                $this->assertSame($expected, $m['name'], "'{$raw}'");
            }
            $this->assertStringNotContainsString('..', $m['name']);
            $this->assertDoesNotMatchRegularExpression('#[/\\\\\x00-\x1F]#u', $m['name']);
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{40}$#', $m['stored_path'], 'the stored name never contains the user-supplied name');
            FileStore::discard($m);
        }
        $long = FileStore::accept($this->png(str_repeat('a', 400) . '.png'), 'deliverable', false);
        $this->assertLessThanOrEqual(150, mb_strlen($long['name']));
        $this->assertStringEndsWith('.png', $long['name']);
        FileStore::discard($long);
        $a = FileStore::accept($this->png('same.png'), 'deliverable', false);
        $b = FileStore::accept($this->png('same.png'), 'deliverable', false);
        $this->assertNotSame($a['stored_path'], $b['stored_path'], 'no filename collisions');
        FileStore::discard($a);
        FileStore::discard($b);
    }

    public function testPathHelperRefusesAnythingThatIsNotAnIssuedRandomPath(): void
    {
        foreach (['../../../etc/passwd', '2026/01/../../../x', '2026/01/' . str_repeat('g', 40), '/etc/passwd', '', "2026/01/\0abc"] as $bad) {
            try {
                FileStore::path(['stored_path' => $bad]);
                $this->fail("path '{$bad}' must be refused");
            } catch (\Vertex\Core\HttpException $e) {
                $this->assertSame(404, $e->status);
            }
        }
    }

    public function testScannerIntegrationPoint(): void
    {
        $dir = sys_get_temp_dir() . '/vtx-scan-' . bin2hex(random_bytes(3));
        mkdir($dir);
        $script = static function (string $path, int $code): string {
            file_put_contents($path, "#!/bin/sh\nexit {$code}\n");
            chmod($path, 0755);

            return $path;
        };
        Config::set('uploads.scanner.driver', 'command');
        Config::set('uploads.scanner.args', '--no-summary');

        Config::set('uploads.scanner.command', $script($dir . '/clean.sh', 0));
        $m = FileStore::accept($this->png('a.png'), 'deliverable', false);
        $this->assertSame(['clean', 'clean.sh'], [$m['scan_status'], $m['scanner']], 'clean only when a scanner really ran and passed');
        FileStore::discard($m);

        Config::set('uploads.scanner.command', $script($dir . '/infected.sh', 1));
        $this->reject($this->png('b.png'), 'virus scanner', 'infected');
        Config::set('uploads.scanner.command', $script($dir . '/broken.sh', 2));
        $this->reject($this->png('c.png'), 'could not be scanned', 'scanner error');
        Config::set('uploads.scanner.command', $dir . '/missing.sh');
        $this->reject($this->png('d.png'), 'temporarily unavailable', 'scanner not executable: fail closed');
        $leftovers = glob(FileStore::root() . '/' . gmdate('Y/m') . '/*') ?: [];
        $before = count($leftovers);
        $this->reject($this->png('e.png'), 'temporarily unavailable');
        $this->assertCount($before, glob(FileStore::root() . '/' . gmdate('Y/m') . '/*') ?: [], 'a rejected upload leaves nothing in storage');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    /** The signature check is a second, independent layer behind the MIME check; test it directly. */
    public function testSignatureCheckRecognisesEachKindAndRejectsImpostors(): void
    {
        $sig = new \ReflectionMethod(FileStore::class, 'signatureMatches');
        $check = function (string $bytes, string $kind) use ($sig): bool {
            $f = $this->tmp('x', $bytes);

            return (bool) $sig->invoke(null, $f['tmp_name'], $kind);
        };
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $good = ['png' => $png, 'jpeg' => "\xFF\xD8\xFF\xE0abc", 'gif' => 'GIF89a....', 'webp' => 'RIFF1234WEBPVP8 ', 'wav' => 'RIFF1234WAVEfmt ', 'pdf' => "%PDF-1.7\n", 'psd' => '8BPS' . str_repeat("\0", 8),
            'zip' => "PK\x03\x04rest", 'mp4' => "\0\0\0\x20ftypisom", 'ebml' => "\x1A\x45\xDF\xA3rest", 'mp3' => 'ID3' . "\x04\0", 'text' => "hello \u{00E9} world\n"];
        foreach ($good as $kind => $bytes) {
            $this->assertTrue($check($bytes, $kind), "a genuine {$kind} is recognised");
            $this->assertFalse($check("MZ\x90\x00 not a {$kind}", $kind), "an executable is not a {$kind}");
        }
        $this->assertFalse($check("text\0binary", 'text'), 'NUL bytes mean binary');
        $this->assertFalse($check('anything', 'unknown-kind'), 'unknown kinds never pass');
    }

    public function testInsertRecordsMetadataAndFindReturnsIt(): void
    {
        $m = FileStore::accept($this->png('meta.png'), 'deliverable', false);
        $id = Database::transaction(static fn (): int => FileStore::insert($m, null));
        $row = FileStore::find($id);
        $this->assertSame(['meta.png', 'image/png', (int) $m['size'], $m['stored_path']], [$row['original_name'], $row['mime'], (int) $row['size_bytes'], $row['stored_path']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $row['public_id']);
        FileStore::discard($m);
    }
}
