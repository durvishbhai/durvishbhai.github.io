<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Core\ValidationException;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\ConsentRules;
use Vertex\Services\Http;
use Vertex\Services\Mailer;
use Vertex\Services\Marketing;
use Vertex\Services\PolicyMail;
use Vertex\Services\Turnstile;

/** Separate consent and age confirmation on every public form, marketing readiness (unsubscribe), and the optional Turnstile bot check. */
final class ConsentFormsTest extends HttpTestCase
{
    private int $owner;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Vertex\Services\PermissionSync::run(true);
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        ComplianceSettings::flush();
        Config::set('turnstile.enabled', false);
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32))); // the same key the test web server runs with, so signed links made here verify there
    }

    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
        Config::set('turnstile.enabled', false);
        Http::fake(null);
    }

    private function contact(array $over = []): array
    {
        return $over + ['contact_name' => 'Asha Visitor', 'email' => 'asha@web.test', 'inquiry_type' => 'general', 'subject' => 'A website for my shop', 'description' => 'Hello, can you help me with a website for my shop please?', 'consent' => '1', 'age_confirm' => '1', 'website_url' => ''];
    }

    public function testTheFormsShowSeparateConsentAndAgeBoxesAndNoMarketingBox(): void
    {
        \Vertex\Services\PartnerApplicationService::recordTerms($this->owner, ['title' => 'Referral Partner Terms', 'terms' => 'Test terms for the partner programme.']);
        foreach (['/contact', '/register', '/request-quote', '/careers', '/partners/apply'] as $path) {
            $body = $this->client()->get($path)['body'];
            $this->assertStringContainsString('name="consent"', $body, $path);
            $this->assertStringContainsString('name="age_confirm"', $body, $path);
            $this->assertStringContainsString('18 years old or older', $body, $path);
            $this->assertStringContainsString('Privacy Policy', $body, $path);
            $this->assertStringNotContainsString('marketing_consent', $body, $path . ': nothing marketing-related while marketing is off');
            $this->assertDoesNotMatchRegularExpression('/name="(consent|age_confirm)" value="1" checked/', $body, $path . ': nothing is preticked');
        }
        $this->assertStringNotContainsString('date of birth', mb_strtolower($this->client()->get('/contact')['body']), 'no date of birth is asked for');
    }

    public function testAnEnquiryNeedsTheAgeConfirmationAndRecordsWhatWasAgreed(): void
    {
        $c = $this->client();
        $before = (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'];
        $r = $c->post('/contact', $this->contact(['age_confirm' => '']), '/contact');
        $this->assertSame(303, $r['status']);
        $this->assertSame('/contact', $r['location']);
        $this->assertStringContainsString('18 or older', $c->get('/contact')['body'], 'the reason is shown');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
        $c->post('/contact', $this->contact(['consent' => '']), '/contact');
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c'], 'the privacy acknowledgement is still required');
        $ok = $c->post('/contact', $this->contact(), '/contact');
        $this->assertSame('/enquiry/received', $ok['location']);
        $this->assertSame($before + 1, (int) Database::one('SELECT COUNT(*) c FROM enquiries')['c']);
        $rec = Database::one('SELECT * FROM consent_records');
        $this->assertSame('contact_form', $rec['form_key']);
        $this->assertSame('asha@web.test', $rec['subject_email']);
        $this->assertSame([1, 1, 0], [(int) $rec['privacy_ack'], (int) $rec['age_confirmed'], (int) $rec['marketing_opt_in']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rec['text_hash']);
        $this->assertNotNull($rec['ip_hash']);
        $this->assertStringNotContainsString('127.0.0.1', (string) $rec['ip_hash']);
    }

    public function testSigningUpNeedsBothConfirmations(): void
    {
        Database::run("INSERT INTO app_settings (setting_key, value_text, is_secret, updated_at) VALUES ('auth.registration_mode', 'instant', 0, UTC_TIMESTAMP())");
        $c = $this->client();
        $form = ['name' => 'New Client', 'email' => 'new@client.test', 'phone' => '+91 90000 77777', 'password' => 'a-long-enough-pass-123', 'password_confirmation' => 'a-long-enough-pass-123'];
        $c->post('/register', $form + ['consent' => '1', 'age_confirm' => ''], '/register');
        $c->post('/register', $form + ['consent' => '', 'age_confirm' => '1'], '/register');
        $this->assertNull(Database::one("SELECT id FROM users WHERE email = 'new@client.test'"));
        $c->post('/register', $form + ['consent' => '1', 'age_confirm' => '1'], '/register');
        $u = Database::one("SELECT id FROM users WHERE email = 'new@client.test'");
        $this->assertNotNull($u);
        $this->assertSame('signup', Database::one("SELECT form_key f FROM consent_records WHERE subject_email = 'new@client.test'")['f']);
    }

    public function testApplicationsAndPartnerFormsRefuseWithoutTheAgeConfirmation(): void
    {
        \Vertex\Services\PartnerApplicationService::recordTerms($this->owner, ['title' => 'Referral Partner Terms', 'terms' => 'Test terms for the partner programme.']);
        $c = $this->client();
        $r = $c->post('/careers/apply', ['full_name' => 'Jo Applicant', 'email' => 'jo@web.test', 'phone' => '+91 90000 88888', 'role' => 'x', 'consent' => '1', 'age_confirm' => '', 'website_url' => ''], '/careers');
        $this->assertSame(303, $r['status']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM site_job_applications')['c']);
        $p = $c->post('/partners/apply', ['full_name' => 'Pat Partner', 'email' => 'pat@web.test', 'phone' => '+91 90000 99999', 'agree' => '1', 'age_confirm' => '', 'website_url' => ''], '/partners/apply');
        $this->assertSame('/partners/apply', $p['location']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM partner_applications')['c']);
    }

    public function testTheMarketingBoxExistsOnlyWhileMarketingIsOnAndIsNeverPreticked(): void
    {
        ComplianceSettings::save($this->owner, 'marketing', ['marketing_enabled' => '1']);
        $body = $this->client()->get('/contact')['body'];
        $this->assertSame(1, preg_match('/<input type="checkbox" name="marketing_consent" value="1">/', $body), 'unticked');
        $c = $this->client();
        $c->post('/contact', $this->contact(['email' => 'no@web.test']), '/contact');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM email_preferences')['c'], 'not ticked = no marketing');
        $c->post('/contact', $this->contact(['email' => 'yes@web.test', 'marketing_consent' => '1']), '/contact');
        $this->assertSame(1, (int) Database::one("SELECT marketing_opt_in o FROM email_preferences WHERE email = 'yes@web.test'")['o']);
        $this->assertSame(1, (int) Database::one("SELECT marketing_opt_in m FROM consent_records WHERE subject_email = 'yes@web.test'")['m']);
        ComplianceSettings::save($this->owner, 'marketing', ['marketing_enabled' => '']);
        $this->assertStringNotContainsString('marketing_consent', $this->client()->get('/contact')['body']);
    }

    // ------------------------------------------------------------------------------------------------ unsubscribe

    public function testMarketingEmailGoesOnlyToOptedInPeopleWithAnUnsubscribeLinkAndNeverTouchesTransactionalMail(): void
    {
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        Mailer::$sent = [];
        $spec = ['subject' => 'Our news', 'heading' => 'News', 'name' => 'Asha', 'paragraphs' => ['Hello']];
        $this->assertFalse(Marketing::send('asha@web.test', $spec), 'off by default');
        ComplianceSettings::save($this->owner, 'marketing', ['marketing_enabled' => '1']);
        $this->assertFalse(Marketing::send('asha@web.test', $spec), 'not opted in');
        Marketing::optIn('asha@web.test', 'test');
        $this->assertTrue(Marketing::send('asha@web.test', $spec));
        $this->assertCount(1, Mailer::$sent);
        $this->assertMatchesRegularExpression('~Unsubscribe any time: https://portal\.example\.test/unsubscribe/[A-Za-z0-9_-]+\.[a-f0-9]{64}~', Mailer::$sent[0]['body']);
        Marketing::unsubscribe('asha@web.test');
        Mailer::$sent = [];
        $this->assertFalse(Marketing::send('asha@web.test', $spec), 'an unsubscribe is honoured at once');
        Marketing::optIn('asha@web.test', 'again');
        $this->assertFalse(Marketing::mayEmail('asha@web.test'), 'an old opt-in cannot undo a later unsubscribe');
        // transactional mail has no unsubscribe link
        PolicyMail::reactivated('asha@web.test', 'Asha');
        $this->assertCount(1, Mailer::$sent);
        $this->assertStringNotContainsString('nsubscribe', Mailer::$sent[0]['body']);
    }

    public function testUnsubscribeLinksAreSignedAndWorkWithoutSigningIn(): void
    {
        $path = Marketing::unsubscribePath('Asha@Web.test');
        $this->assertSame('asha@web.test', Marketing::emailFromToken(substr($path, strlen('/unsubscribe/'))));
        $tok = substr($path, strlen('/unsubscribe/'));
        $this->assertNull(Marketing::emailFromToken(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a')), 'a tampered signature');
        $this->assertNull(Marketing::emailFromToken(strtr(base64_encode('other@web.test'), '+/=', '-_') . '.' . substr($tok, strpos($tok, '.') + 1)), 'a signature for another address');
        $this->assertNull(Marketing::emailFromToken('junk'));
        Marketing::optIn('asha@web.test', 't');
        $c = $this->client();
        $page = $c->get($path);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('asha@web.test', $page['body']);
        $this->assertStringContainsString('not marketing', $page['body']);
        $this->assertSame(1, (int) Database::one('SELECT marketing_opt_in o FROM email_preferences')['o'], 'opening the link changes nothing');
        $done = $c->post($path, [], $path);
        $this->assertStringContainsString('will no longer receive marketing email', $done['body']);
        $this->assertSame(0, (int) Database::one('SELECT marketing_opt_in o FROM email_preferences')['o']);
        Marketing::optIn('one@web.test', 't');
        $one = $this->client()->request('POST', Marketing::unsubscribePath('one@web.test') . '/one-click', ['List-Unsubscribe' => 'One-Click']);
        $this->assertSame(200, $one['status']);
        $this->assertSame(0, (int) Database::one("SELECT marketing_opt_in o FROM email_preferences WHERE email = 'one@web.test'")['o']);
        $this->assertSame(404, $this->client()->request('POST', '/unsubscribe/bad.' . str_repeat('0', 64) . '/one-click')['status']);
        $this->assertStringContainsString('not valid', $this->client()->get('/unsubscribe/bad.' . str_repeat('0', 64))['body']);
    }

    // ------------------------------------------------------------------------------------------------ Turnstile

    private function request(array $body = []): Request
    {
        return new Request('POST', '/x', [], $body, ['REMOTE_ADDR' => '203.0.113.5']);
    }

    public function testTurnstileIsOffByDefaultAndCheckedOnTheServerWhenOn(): void
    {
        $this->assertFalse(Turnstile::enabled());
        $this->assertStringNotContainsString('cf-turnstile', \Vertex\Core\View::partial('turnstile'));
        $this->assertStringNotContainsString('challenges.cloudflare.com', implode(' ', \Vertex\Core\SecurityHeadersPolicy::headers()));
        Turnstile::require($this->request()); // nothing happens while it is off
        Config::set('turnstile.enabled', true);
        $this->assertFalse(Turnstile::enabled(), 'both keys are needed');
        Config::set('turnstile.site_key', '1x00000000000000000000AA');
        Config::set('turnstile.secret_key', 'secret-value');
        $this->assertTrue(Turnstile::enabled());
        $w = \Vertex\Core\View::partial('turnstile');
        $this->assertStringContainsString('data-sitekey="1x00000000000000000000AA"', $w);
        $this->assertStringNotContainsString('secret-value', $w . implode(' ', \Vertex\Core\SecurityHeadersPolicy::headers()), 'the secret never reaches the browser');
        $this->assertStringContainsString('https://challenges.cloudflare.com', \Vertex\Core\SecurityHeadersPolicy::headers()['Content-Security-Policy']);
        $calls = [];
        Http::fake(static function (string $m, string $url, array $h, ?string $body) use (&$calls): array {
            $calls[] = [$url, $body];

            return ['status' => 200, 'body' => json_encode(['success' => str_contains((string) $body, 'response=good')])];
        });
        $this->refused(fn () => Turnstile::require($this->request()), 'security check');
        $this->refused(fn () => Turnstile::require($this->request(['cf-turnstile-response' => 'bad'])), 'security check');
        Turnstile::require($this->request(['cf-turnstile-response' => 'good']));
        $this->assertSame(Turnstile::VERIFY_URL, $calls[0][0]);
        $this->assertStringContainsString('secret=secret-value', (string) $calls[0][1]);
        Http::fake(static fn () => throw new \RuntimeException('network down'));
        $this->refused(fn () => Turnstile::require($this->request(['cf-turnstile-response' => 'good'])), 'security check');
        Http::fake(static fn (): array => ['status' => 500, 'body' => '{}']);
        $this->refused(fn () => Turnstile::require($this->request(['cf-turnstile-response' => 'good'])), 'security check');
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }
}
