<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\CookieConsent;

/** The cookie consent platform: banner, categories, logged choices, withdrawal, versions, same-origin protection. */
final class CookieConsentTest extends HttpTestCase
{
    private function origin(): array
    {
        return ['Origin: ' . self::$base, 'X-Requested-With: fetch'];
    }

    private function cookieValue(\Vertex\Tests\Support\HttpClient $c): ?string
    {
        foreach ($c->setCookies as $line) {
            if (preg_match('/kis_consent=([^;]+)/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public function testTheBannerOffersTheThreeChoicesAndNothingOptionalIsPreselected(): void
    {
        $home = $this->client()->get('/');
        $b = $home['body'];
        foreach (['id="kis-cookie-banner"', 'Accept All', 'Reject Non-Essential', 'Customize', 'Strictly necessary', 'Preferences', 'Analytics', 'Marketing', 'consent.js', 'data-cookie-settings'] as $needle) {
            $this->assertStringContainsString($needle, $b);
        }
        $this->assertMatchesRegularExpression('/id="kis-cookie-banner"[^>]*hidden/', $b, 'hidden until the script decides it is needed');
        preg_match_all('/<input type="checkbox" name="(preferences|analytics|marketing)"[^>]*>/', $b, $m);
        $this->assertCount(3, $m[0]);
        foreach ($m[0] as $input) {
            $this->assertStringNotContainsString('checked', $input, 'no optional category is preselected');
        }
        $this->assertStringContainsString('data-scripts="[]"', $b, 'no optional script is configured');
        $this->assertDoesNotMatchRegularExpression('~<script[^>]+src="https?://~i', $b, 'no third-party script on the page');
        $this->assertStringContainsString('href="/cookie-settings"', $b, 'a permanent footer link');
    }

    public function testAcceptAllRejectAndCustomChoicesAreStoredAndLogged(): void
    {
        $c = $this->client();
        $r = $c->request('POST', '/cookie-settings', ['action' => 'accept_all'], $this->origin());
        $this->assertSame(200, $r['status']);
        $this->assertSame(['ok' => true, 'action' => 'accept_all', 'preferences' => true, 'analytics' => true, 'marketing' => true], json_decode($r['body'], true));
        $v = $this->cookieValue($c);
        $this->assertMatchesRegularExpression('/^v1\.\d{10}\.111\.[a-f0-9]{32}$/', (string) $v);
        $line = implode(' ', $c->setCookies);
        foreach (['Path=/', 'SameSite=Lax', 'Max-Age=' . (180 * 86400)] as $attr) {
            $this->assertStringContainsString($attr, $line);
        }
        $this->assertStringNotContainsString('HttpOnly', $line, 'the banner script has to read it');
        $page = $c->get('/cookie-settings')['body'];
        $this->assertSame(3, preg_match_all('/name="(?:preferences|analytics|marketing)" value="1" checked/', $page), 'the saved choice is shown');

        $r = $c->request('POST', '/cookie-settings', ['action' => 'reject_all'], $this->origin());
        $this->assertSame(['ok' => true, 'action' => 'withdraw', 'preferences' => false, 'analytics' => false, 'marketing' => false], json_decode($r['body'], true), 'rejecting after accepting is a withdrawal');
        $this->assertMatchesRegularExpression('/^v1\.\d{10}\.000\./', (string) $this->cookieValue($c));

        $r = $c->request('POST', '/cookie-settings', ['action' => 'custom', 'analytics' => '1'], $this->origin());
        $this->assertSame(['ok' => true, 'action' => 'custom', 'preferences' => false, 'analytics' => true, 'marketing' => false], json_decode($r['body'], true));
        $this->assertMatchesRegularExpression('/^v1\.\d{10}\.010\./', (string) $this->cookieValue($c));

        $rows = Database::all('SELECT * FROM cookie_consents ORDER BY id');
        $this->assertSame(['accept_all', 'withdraw', 'custom'], array_column($rows, 'action'));
        $this->assertCount(1, array_unique(array_column($rows, 'visitor_id')), 'one browser, one id');
        $this->assertSame([1, 0, 0], [(int) $rows[2]['analytics'], (int) $rows[2]['preferences'], (int) $rows[2]['marketing']]);
        $this->assertNotNull($rows[0]['ip_hash']);
        $this->assertStringNotContainsString('127.0.0.1', (string) $rows[0]['ip_hash'], 'only a hash of the address is kept');
        $this->assertNotNull($rows[0]['created_at']);
        $this->assertSame(1, (int) $rows[0]['consent_version']);
    }

    public function testTheFormFallbackWorksWithoutJavascript(): void
    {
        $c = $this->client();
        $r = $c->request('POST', '/cookie-settings', ['action' => 'custom', 'preferences' => '1'], ['Origin: ' . self::$base]);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/cookie-settings?saved=1', $r['location']);
        $this->assertMatchesRegularExpression('/^v1\.\d{10}\.100\./', (string) $this->cookieValue($c));
        $this->assertStringContainsString('Your cookie choices were saved', $c->get('/cookie-settings?saved=1')['body']);
    }

    public function testOnlyRequestsFromThisSiteAreAccepted(): void
    {
        $c = $this->client();
        $this->assertSame(403, $c->request('POST', '/cookie-settings', ['action' => 'accept_all'])['status'], 'no Origin or Referer');
        $this->assertSame(403, $c->request('POST', '/cookie-settings', ['action' => 'accept_all'], ['Origin: https://evil.test'])['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM cookie_consents')['c']);
        $this->assertNull($this->cookieValue($c));
        $this->assertSame(200, $c->request('POST', '/cookie-settings', ['action' => 'accept_all'], ['Referer: ' . self::$base . '/about', 'X-Requested-With: fetch'])['status']);
        $weird = $c->request('POST', '/cookie-settings', ['action' => '<script>', 'analytics' => 'yes'], $this->origin());
        $this->assertSame('custom', json_decode($weird['body'], true)['action'], 'unknown actions become a plain custom choice');
    }

    public function testAChoiceStopsCountingWhenTheVersionChangesOrItExpires(): void
    {
        $id = str_repeat('a', 32);
        $now = time();
        $this->assertSame(['preferences' => false, 'analytics' => true, 'marketing' => false, 'at' => $now, 'id' => $id], CookieConsent::parse("v1.{$now}.010.{$id}"));
        $this->assertNull(CookieConsent::parse("v2.{$now}.010.{$id}"), 'another version');
        $this->assertNull(CookieConsent::parse('v1.' . ($now - 181 * 86400) . ".010.{$id}"), 'older than the validity');
        $this->assertNull(CookieConsent::parse("v1.{$now}.012.{$id}"));
        $this->assertNull(CookieConsent::parse("v1.{$now}.010.nothex"));
        $this->assertNull(CookieConsent::parse(''));
        ComplianceSettings::save($this->makeUser('owner@x.test', ['super_admin']), 'cookies', ['cookie_version' => '2']);
        $this->assertNotNull(CookieConsent::parse("v2.{$now}.010.{$id}"));
        $this->assertNull(CookieConsent::parse("v1.{$now}.010.{$id}"), 'raising the version asks everyone again');
        $this->assertSame(2, CookieConsent::version());
    }

    public function testThePageListsWhatIsReallyStoredAndNothingElse(): void
    {
        $page = $this->client()->get('/cookie-settings');
        $this->assertSame(200, $page['status']);
        foreach (['vertex_sid', 'kis_consent', 'kis-theme', 'Strictly necessary', 'This website (first party)', 'Third-party services'] as $needle) {
            $this->assertStringContainsString($needle, $page['body']);
        }
        foreach (['_ga', '_fbp', 'Google Analytics', 'Meta Pixel'] as $absent) {
            $this->assertStringNotContainsString($absent, $page['body'], $absent . ' is not used');
        }
        $this->assertStringContainsString('You have not made a choice yet', $page['body']);
    }
}
