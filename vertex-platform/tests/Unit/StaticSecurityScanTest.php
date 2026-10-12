<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cheap, always-on guards over the source tree: constructs that have caused real vulnerabilities elsewhere must not appear, secrets must not be
 * committed, and the web root must hold nothing executable except the front controller. These do not replace the behavioural tests; they stop a
 * careless future edit from quietly reintroducing a classic hole.
 */
final class StaticSecurityScanTest extends TestCase
{
    /** @return list<string> */
    private static function phpFiles(string ...$dirs): array
    {
        $out = [];
        foreach ($dirs as $d) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(VERTEX_ROOT . '/' . $d, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $out[] = $f->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    private static function strip(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    public function testNoDangerousFunctionsOutsideTheReviewedAllowList(): void
    {
        $allowed = [ // file => functions it may call, each reviewed in docs/15
            'app/Services/FileStore.php' => ['proc_open'],   // the optional virus-scanner command, argument array (no shell)
            'app/Core/View.php' => ['extract'],              // EXTR_SKIP into the template scope
            'bin/console.php' => ['shell_exec'],             // `stty -echo` for the hidden password prompt
        ];
        foreach (self::phpFiles('app', 'bin', 'config', 'public', 'database') as $file) {
            $rel = substr($file, strlen(VERTEX_ROOT) + 1);
            $code = self::strip((string) file_get_contents($file));
            foreach (['eval', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'unserialize', 'extract', 'assert', 'create_function', 'preg_replace_callback_array_x', 'mysql_query', 'md5', 'sha1', 'uniqid', 'mt_rand', 'rand'] as $fn) {
                if (preg_match('/(?<![\w>:$])' . $fn . '\s*\(/', $code) && !in_array($fn, $allowed[$rel] ?? [], true)) {
                    // ->exec( on a PDO object is a method call and excluded by the lookbehind above
                    $this->fail("{$rel} calls {$fn}(): not on the reviewed allow-list");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testSuperGlobalsAreReadOnlyInTheRequestAndSessionLayersAndBootstrap(): void
    {
        $allowed = ['app/Core/Request.php', 'app/Core/Session.php', 'app/Core/Csrf.php', 'app/Core/ErrorHandler.php', 'app/Core/Logger.php', 'app/Core/Env.php', 'app/Core/Router.php', 'app/Core/helpers.php', 'app/Core/SecurityHeadersPolicy.php',
            'app/Services/Auth.php', 'app/Services/Audit.php', 'app/Services/RateLimiter.php', 'app/Services/LoginThrottle.php', 'app/Services/FileStore.php', 'app/Views/layouts/app.php', 'public/index.php', 'bin/dev-router.php'];
        foreach (self::phpFiles('app', 'public') as $file) {
            $rel = substr($file, strlen(VERTEX_ROOT) + 1);
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER)\b/', self::strip((string) file_get_contents($file)), "{$rel} reads a superglobal directly: go through Request");
        }
    }

    public function testTheWebRootHoldsNoExecutableCodeExceptTheFrontController(): void
    {
        $php = array_map(static fn (string $f): string => substr($f, strlen(VERTEX_ROOT) + 1), self::phpFiles('public'));
        $this->assertSame(['public/index.php'], $php);
        foreach (['phtml', 'phar', 'php5', 'inc', 'sql', 'env', 'bak', 'log', 'zip'] as $ext) {
            $this->assertSame([], glob(VERTEX_ROOT . '/public/{,*/,*/*/}*.' . $ext, GLOB_BRACE) ?: [], "public/ must not hold .{$ext} files");
        }
    }

    public function testNoSecretsAreTrackedAndEnvironmentFilesAreIgnored(): void
    {
        $tracked = [];
        exec('git -C ' . escapeshellarg(VERTEX_ROOT) . ' ls-files 2>/dev/null', $tracked);
        if ($tracked === []) {
            $this->markTestSkipped('Not a git checkout.');
        }
        foreach ($tracked as $f) {
            $this->assertDoesNotMatchRegularExpression('#(^|/)\.env(\.(?!example$|testing\.example$).+)?$#', $f, "{$f} must not be committed");
            if (!str_starts_with($f, 'database/migrations/')) {
                $this->assertDoesNotMatchRegularExpression('#\.(pem|key|p12|pfx|sql|sqlite|zip|bak)$#', $f, "{$f} looks like a secret or data file");
            }
        }
        $patterns = ['/-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/', '/AKIA[0-9A-Z]{16}/', '/(?<![\w-])sk_live_[0-9a-zA-Z]{16,}/', '/rzp_live_[0-9a-zA-Z]{8,}/', '/xox[baprs]-[0-9A-Za-z-]{10,}/', '/ghp_[0-9A-Za-z]{30,}/', '/APP_KEY=base64:[A-Za-z0-9+\/=]{40,}/', '/DB_PASSWORD=\S{6,}/'];
        foreach ($tracked as $f) {
            if (!preg_match('/\.(php|md|json|js|css|sql|example|txt|yml|yaml|htaccess|html)$|^\.[a-z]+$/', $f) && !str_ends_with($f, '.example')) {
                continue;
            }
            $path = VERTEX_ROOT . '/' . $f;
            if (!is_file($path) || filesize($path) > 2_000_000 || str_contains($f, 'vendor/') || str_contains($f, 'composer.lock')) {
                continue;
            }
            $body = (string) file_get_contents($path);
            foreach ($patterns as $p) {
                $this->assertDoesNotMatchRegularExpression($p, $body, "{$f} matches a secret pattern {$p}");
            }
        }
    }

    public function testEveryRouteFileHandlerIsAnExistingMethod(): void
    {
        $src = (string) file_get_contents(VERTEX_ROOT . '/app/routes.php');
        preg_match_all('/\[([A-Za-z]+)::class, \'([A-Za-z]+)\'\]/', $src, $m, PREG_SET_ORDER);
        $this->assertGreaterThan(200, count($m));
        $uses = [];
        preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;/m', $src, $u, PREG_SET_ORDER);
        foreach ($u as $x) {
            $uses[$x[2] ?? substr($x[1], strrpos($x[1], '\\') + 1)] = $x[1];
        }
        foreach ($m as [, $class, $method]) {
            $fq = $uses[$class] ?? null;
            $this->assertNotNull($fq, "{$class} is not imported in routes.php");
            $this->assertTrue(method_exists($fq, $method), "{$fq}::{$method} does not exist");
        }
    }
}
