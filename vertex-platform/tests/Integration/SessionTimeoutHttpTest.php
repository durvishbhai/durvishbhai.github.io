<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

/** Idle timeout is enforced server-side (server started with a 3-second idle limit). */
final class SessionTimeoutHttpTest extends HttpTestCase
{
    protected static function extraEnv(): array
    {
        return ['SESSION_IDLE_SECONDS' => '3'];
    }

    public function testIdleSessionExpires(): void
    {
        $this->makeUser('idle@x.test', ['client']);
        $c = $this->loggedIn('idle@x.test');
        $this->assertSame(200, $c->get('/dashboard')['status'], 'immediately usable');
        sleep(4);
        $r = $c->get('/dashboard');
        $this->assertSame(303, $r['status'], 'expired after the idle window');
        $this->assertSame('/login', $r['location']);
    }
}
