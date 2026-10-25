<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\Http;
use Vertex\Services\Notifier;
use Vertex\Services\PushChannel;
use Vertex\Services\WebPush;

/** The installable mobile app (manifest, icons, head tags) and phone notifications (Web Push: encryption, identification, devices, delivery). */
final class PwaTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        Config::set('notifications.push', true);
        Config::set('app.url', 'https://portal.example.test');
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        Config::set('mail.driver', 'none');
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        $this->tearDownPhase3();
    }

    /** @return array{0:\OpenSSLAsymmetricKey,1:string,2:string} a fake phone: private key, public point (base64url), auth secret (base64url) */
    private function phone(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($k)['ec'];
        $pub = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return [$k, WebPush::b64e($pub), WebPush::b64e(random_bytes(16))];
    }

    public function testTheManifestMakesItAnInstallableAppWithNameAndIcons(): void
    {
        $c = $this->client();
        $r = $c->get('/manifest.webmanifest');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('application/manifest+json', $c->lastHeaders['content-type']);
        $m = json_decode($r['body'], true);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/', $m['scope']);
        $this->assertStringStartsWith('/dashboard', $m['start_url']);
        $this->assertNotSame('', $m['name']);
        $this->assertNotSame('', $m['short_name']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $m['theme_color']);
        $sizes = array_column($m['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($m['icons'], 'purpose'));
        foreach (['pwa-192.png' => 192, 'pwa-512.png' => 512, 'pwa-maskable-512.png' => 512, 'apple-touch-icon.png' => 180, 'pwa-badge.png' => 96] as $file => $px) {
            $i = getimagesize(VERTEX_ROOT . '/public/assets/img/' . $file);
            $this->assertSame([$px, $px], [$i[0], $i[1]], $file);
        }
        $this->assertSame('KIS', \Vertex\Http\Controllers\PwaController::shortName('Krazy Integrated Solutions'));
        $this->assertSame('Acme', \Vertex\Http\Controllers\PwaController::shortName('Acme'));
    }

    public function testEveryLayoutAdvertisesTheAppToThePhone(): void
    {
        foreach (['/' => $this->client(), '/login' => $this->client(), '/offline' => $this->client(), '/dashboard' => $this->loggedIn('owner@x.test')] as $path => $c) {
            $body = $c->get($path)['body'];
            $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $body, $path);
            $this->assertStringContainsString('apple-mobile-web-app-capable', $body, $path);
            $this->assertStringContainsString('viewport-fit=cover', $body, $path);
        }
        $dash = $this->loggedIn('owner@x.test')->get('/dashboard')['body'];
        $this->assertStringContainsString('data-install-banner', $dash);
        $this->assertStringContainsString('data-push-banner', $dash);
        $this->assertStringContainsString('data-push-toggle', $this->loggedIn('owner@x.test')->get('/account')['body']);
        $sw = (string) file_get_contents(VERTEX_ROOT . '/public/assets/js/sw.js');
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
    }

    public function testMessagesAreEncryptedSoOnlyThePhoneCanReadThem(): void
    {
        [$priv, $pub, $auth] = $this->phone();
        $body = WebPush::encrypt('{"title":"Hello"}', WebPush::b64d($pub), WebPush::b64d($auth));
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1], 'record size');
        $this->assertSame(65, ord($body[20]), 'the sender key id is the 65-byte public key');
        $this->assertStringNotContainsString('Hello', $body);
        $this->assertSame('{"title":"Hello"}', WebPush::decrypt($body, $priv, WebPush::b64d($pub), WebPush::b64d($auth)));
        $this->assertSame('', WebPush::decrypt($body, $priv, WebPush::b64d($pub), random_bytes(16)), 'a wrong auth secret cannot read it');
        $this->assertNotSame($body, WebPush::encrypt('{"title":"Hello"}', WebPush::b64d($pub), WebPush::b64d($auth)), 'fresh keys and salt every time');
        $this->expectException(\InvalidArgumentException::class);
        WebPush::encrypt('x', 'short', WebPush::b64d($auth));
    }

    public function testTheServerProvesItsIdentityWithAVapidSignature(): void
    {
        $keys = WebPush::keys();
        $this->assertSame($keys['public'], WebPush::keys()['public'], 'the key pair is created once');
        $this->assertSame(65, strlen(WebPush::b64d($keys['public'])));
        $jwt = WebPush::jwt('https://fcm.googleapis.com', 'mailto:team@example.test', $keys['private'], 1_900_000_000);
        [$h, $c, $s] = explode('.', $jwt);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64d($h), true));
        $claims = json_decode(WebPush::b64d($c), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame(1_900_000_000 + 43200, $claims['exp']);
        $raw = WebPush::b64d($s);
        $this->assertSame(64, strlen($raw));
        // rebuild the DER signature the way a push service would check it
        $der = static function (string $r, string $s): string {
            $enc = static fn (string $n): string => "\x02" . chr(strlen(($n[0] >= "\x80" ? "\0" : '') . ltrim($n, "\0") ?: "\0")) . ($n[0] >= "\x80" ? "\0" : '') . (ltrim($n, "\0") ?: "\0");
            $body = $enc($r) . $enc($s);

            return "\x30" . chr(strlen($body)) . $body;
        };
        $pubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . WebPush::b64d($keys['public'])), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify("{$h}.{$c}", $der(substr($raw, 0, 32), substr($raw, 32)), $pubPem, OPENSSL_ALGO_SHA256));
    }

    public function testOnlyRealPushServicesAreEverCalled(): void
    {
        foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/xyz', 'https://web.push.apple.com/QAbc', 'https://wns2-par02p.notify.windows.com/w/?token=1'] as $ok) {
            $this->assertTrue(WebPush::allowedEndpoint($ok), $ok);
        }
        foreach (['http://fcm.googleapis.com/x', 'https://evil.example/x', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.evil.example/x', 'https://user@fcm.googleapis.com/x', 'https://localhost/x', 'javascript:alert(1)'] as $bad) {
            $this->assertFalse(WebPush::allowedEndpoint($bad), $bad);
        }
    }

    public function testADeviceRegistersAndUnregistersThroughTheSite(): void
    {
        $uid = $this->makeUser('phone@x.test', ['client']);
        $c = $this->loggedIn('phone@x.test');
        [, $pub, $auth] = $this->phone();
        $ep = 'https://fcm.googleapis.com/fcm/send/device-1';
        $key = json_decode($c->get('/push/key')['body'], true);
        $this->assertTrue($key['enabled']);
        $this->assertSame(65, strlen(WebPush::b64d($key['key'])));
        $r = json_decode($c->post('/push/subscribe', ['endpoint' => $ep, 'p256dh' => $pub, 'auth' => $auth], '/account')['body'], true);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['devices']);
        $c->post('/push/subscribe', ['endpoint' => $ep, 'p256dh' => $pub, 'auth' => $auth], '/account');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?', [$uid])['c'], 'the same device is not stored twice');
        $bad = $c->post('/push/subscribe', ['endpoint' => 'https://evil.example/x', 'p256dh' => $pub, 'auth' => $auth], '/account');
        $this->assertSame(422, $bad['status']);
        $bad2 = $c->post('/push/subscribe', ['endpoint' => $ep . '2', 'p256dh' => 'AAAA', 'auth' => $auth], '/account');
        $this->assertSame(422, $bad2['status']);
        $this->assertSame(302, $this->client()->get('/push/key')['status'] === 303 ? 302 : $this->client()->get('/push/key')['status'], 'signed-out visitors are sent to sign in');
        $c->post('/push/unsubscribe', ['endpoint' => $ep], '/account');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?', [$uid])['c']);
    }

    public function testANewNotificationReachesThePhoneEncryptedAndDeadDevicesAreForgotten(): void
    {
        $uid = $this->makeUser('phone@x.test', ['client']);
        [$priv, $pub, $auth] = $this->phone();
        PushChannel::subscribe($uid, 'https://fcm.googleapis.com/fcm/send/live', $pub, $auth, 'Mozilla/5.0 (Linux; Android 14) Chrome/120');
        PushChannel::subscribe($uid, 'https://fcm.googleapis.com/fcm/send/dead', $pub, $auth, 'Mozilla/5.0 (Linux; Android 13) Chrome/119');
        $calls = [];
        Http::fake(function (string $m, string $url, array $h, ?string $body) use (&$calls): array {
            $calls[$url] = ['method' => $m, 'headers' => $h, 'body' => (string) $body];

            return ['status' => str_ends_with($url, '/dead') ? 410 : 201, 'body' => ''];
        });
        PushChannel::flush();
        Notifier::send([$uid], 'payment.confirmed', 'Payment confirmed: ₹1,000.00', 'We received your payment. Thank you.', '/my/invoices/abc', null, null, null, 'push-1');
        $this->assertSame(1, PushChannel::flush());
        $this->assertCount(2, $calls);
        $live = $calls['https://fcm.googleapis.com/fcm/send/live'];
        $this->assertSame('POST', $live['method']);
        $this->assertSame('aes128gcm', $live['headers']['Content-Encoding']);
        $this->assertStringStartsWith('vapid t=', $live['headers']['Authorization']);
        $this->assertStringContainsString(', k=' . WebPush::keys()['public'], $live['headers']['Authorization']);
        $this->assertSame('high', $live['headers']['Urgency'], 'payments are urgent');
        $msg = json_decode(WebPush::decrypt($live['body'], $priv, WebPush::b64d($pub), WebPush::b64d($auth)), true);
        $this->assertSame('Payment confirmed: ₹1,000.00', $msg['title']);
        $this->assertSame('/my/invoices/abc', $msg['url']);
        $this->assertStringContainsString('pwa-192.png', $msg['icon']);
        $this->assertStringNotContainsString('Payment confirmed', $live['body'], 'the push service cannot read it');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?', [$uid])['c'], 'the device that answered "gone" was removed');

        $calls = [];
        Notifier::send([$uid], 'payment.confirmed', 'Same event', null, null, null, null, null, 'push-1'); // the same dedupe key: no second notification
        $this->assertSame(0, PushChannel::flush());
        Config::set('notifications.push', false);
        Notifier::send([$uid], 'invoice.paid', 'Switched off', null, null, null, null, null, 'push-2');
        $this->assertSame(0, PushChannel::flush());
        $this->assertSame([], $calls);
    }

    public function testTheTestNotificationGoesOnlyToTheOwnDevices(): void
    {
        $a = $this->makeUser('a@x.test', ['client']);
        $b = $this->makeUser('b@x.test', ['client']);
        [, $pub, $auth] = $this->phone();
        PushChannel::subscribe($b, 'https://fcm.googleapis.com/fcm/send/b-device', $pub, $auth, 'x');
        $this->assertSame(0, PushChannel::sendToUser($a, PushChannel::payload('t', 'b', '/x', 'tag')), 'nothing for someone with no device');
        $this->assertSame(1, PushChannel::deviceCount($b));
        $this->assertSame(0, PushChannel::deviceCount($a));
    }
}
