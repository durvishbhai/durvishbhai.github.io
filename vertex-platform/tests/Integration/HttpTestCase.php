<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Tests\Support\HttpClient;

/**
 * Boots a real PHP built-in server (public/ + bin/dev-router.php) against the throwaway database, so tests exercise
 * the whole stack: routing, middleware, sessions, cookies, CSRF, controllers, views. Email uses the `log` driver and
 * is read back from storage/logs/mail-*.log.
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    private static mixed $proc = null;
    protected static string $base = '';
    protected static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        self::$port = $port;
        self::$base = "http://127.0.0.1:{$port}";

        $env = [
            'PATH' => (string) getenv('PATH'),
            'VERTEX_ENV_FILE' => '.env.testing',
            'APP_ENV' => 'testing',
            'APP_URL' => self::$base,
            'REGISTRATION_ENABLED' => 'true',
            'MAIL_DRIVER' => 'log',
            'LOGIN_MAX_ATTEMPTS' => '5',
            'UPLOAD_STORAGE_PATH' => (string) getenv('UPLOAD_STORAGE_PATH'),
        ] + static::extraEnv();
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', VERTEX_ROOT . '/public', VERTEX_ROOT . '/bin/dev-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            VERTEX_ROOT,
            $env,
        );
        $client = new HttpClient(self::$base);
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            try {
                if ($client->get('/health')['status'] === 200) {
                    return;
                }
            } catch (\Throwable) {
            }
        }
        self::fail('Test web server did not start.');
    }

    /** @return array<string,string> additional environment for the test web server (override per class) */
    protected static function extraEnv(): array
    {
        return [];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(VERTEX_ROOT . '/storage/logs/mail-*.log') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function client(): HttpClient
    {
        return new HttpClient(self::$base);
    }

    /** A client already signed in as $email (user must exist with the default password). */
    protected function loggedIn(string $email): HttpClient
    {
        $c = $this->client();
        $r = $c->login($email, self::PASSWORD);
        $this->assertSame(303, $r['status'], "login as {$email} failed");
        $this->assertSame('/dashboard', $r['location'], "login as {$email} did not reach the dashboard");
        $c->formPage = '/account';

        return $c;
    }

    /** Returns the most recent URL path matching $pattern from the mail log, e.g. '/verify-email/'. */
    protected function mailLink(string $prefix, ?string $to = null): ?string
    {
        $log = '';
        foreach (glob(VERTEX_ROOT . '/storage/logs/mail-*.log') ?: [] as $f) {
            $log .= (string) file_get_contents($f);
        }
        if ($to !== null && !str_contains($log, "TO={$to}")) {
            return null;
        }
        if (!preg_match_all('#' . preg_quote(self::$base . $prefix, '#') . '([a-f0-9]{64})#', $log, $m)) {
            return null;
        }

        return $prefix . end($m[1]);
    }

    protected function mailCount(): int
    {
        $n = 0;
        foreach (glob(VERTEX_ROOT . '/storage/logs/mail-*.log') ?: [] as $f) {
            $n += substr_count((string) file_get_contents($f), ' TO=');
        }

        return $n;
    }
}
