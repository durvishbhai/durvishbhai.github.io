<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;
use Vertex\Services\Mailer;

/** Exercises the real PHPMailer SMTP path against a local fake SMTP server (no TLS/auth). */
final class MailerSmtpTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
    }

    public function testSmtpDriverDeliversPlainTextMessage(): void
    {
        $py = trim((string) shell_exec('command -v python3'));
        if ($py === '') {
            $this->markTestSkipped('python3 not available for the fake SMTP server');
        }
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $out = tempnam(sys_get_temp_dir(), 'smtp');
        $proc = proc_open([$py, '-I', VERTEX_ROOT . '/tests/Support/fake_smtp.py', (string) $port, $out], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertSame("ready\n", fgets($pipes[1]), 'fake SMTP server did not start');

        Config::set('app.url', 'https://portal.example.test');
        Config::set('mail.driver', 'smtp');
        Config::set('mail.host', '127.0.0.1');
        Config::set('mail.port', $port);
        Config::set('mail.encryption', 'none');
        Config::set('mail.username', '');
        Config::set('mail.from_address', 'noreply@example.test');
        Config::set('mail.from_name', 'Vertex Test');
        $this->assertSame('smtp', Mailer::driver());

        $ok = Mailer::send('person@example.test', "Hello\r\nBcc: attacker@evil.test", "Line one\nLine two\n");
        $this->assertTrue($ok, 'send() should succeed');
        proc_close($proc);

        $got = (string) file_get_contents($out);
        @unlink($out);
        $this->assertStringContainsString('RCPT:<person@example.test>', $got);
        $this->assertStringContainsString('Subject: Hello Bcc: attacker@evil.test', $got, 'CR/LF stripped from subject');
        $this->assertStringNotContainsString("\nBcc: attacker@evil.test", $got, 'no header injection');
        $this->assertStringContainsString('From: Vertex Test <noreply@example.test>', $got);
        $this->assertStringContainsString('Line one', $got);
    }

    public function testUnreachableSmtpFailsGracefully(): void
    {
        Config::set('app.url', 'https://portal.example.test');
        Config::set('mail.driver', 'smtp');
        Config::set('mail.host', '127.0.0.1');
        Config::set('mail.port', 1);
        Config::set('mail.encryption', 'none');
        Config::set('mail.from_address', 'noreply@example.test');
        $this->assertFalse(Mailer::send('a@example.test', 'S', 'B'), 'returns false instead of throwing');
    }

    public function testDriversAreDisabledWhenUnsafeOrIncomplete(): void
    {
        Config::set('app.url', 'https://portal.example.test');
        Config::set('app.env', 'production');
        Config::set('mail.driver', 'log');
        $this->assertSame('none', Mailer::driver(), 'log driver (links in plaintext logs) refused in production');
        Config::set('mail.driver', 'array');
        $this->assertSame('none', Mailer::driver());
        Config::set('mail.driver', 'smtp');
        Config::set('mail.host', '');
        $this->assertSame('none', Mailer::driver(), 'smtp without host');
        Config::set('mail.host', 'smtp.example.test');
        Config::set('mail.from_address', 'x@example.test');
        Config::set('app.url', '');
        $this->assertSame('none', Mailer::driver(), 'no APP_URL means no links, so no mail');
        Config::set('app.env', 'testing');
    }
}
