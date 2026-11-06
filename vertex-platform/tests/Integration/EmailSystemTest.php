<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Services\AccountMail;
use Vertex\Services\AppTime;
use Vertex\Services\Auth;
use Vertex\Services\BusinessProfile;
use Vertex\Services\CareerService;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\EmailChannel;
use Vertex\Services\EmailTemplate;
use Vertex\Services\EnquiryService;
use Vertex\Services\InvoiceService;
use Vertex\Services\Mailer;
use Vertex\Services\Notifier;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\UserService;

/** The shared email design, the notification email channel and the individual transactional emails. All data is fictional. */
final class EmailSystemTest extends HttpTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private string $clientPublic;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        Config::set('notifications.email_clients', true);
        Config::set('notifications.email_staff', true);
        Config::set('notifications.login_alert', true);
        Mailer::$sent = [];
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        BusinessProfile::save($this->admin, ['trade_name' => 'Krazy Integrated Solutions', 'email' => 'support@example.test', 'address' => '12 Test Street', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'Bank: TEST 0000', 'invoice_footer' => 'Thanks']);
        EmailChannel::flush(); // anything queued by the fixtures is not part of the test
        Mailer::$sent = [];
    }

    protected function tearDown(): void
    {
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
        $this->tearDownPhase3();
    }

    /** @return list<array<string,mixed>> */
    private function to(string $email): array
    {
        return array_values(array_filter(Mailer::$sent, static fn (array $m): bool => $m['to'] === $email));
    }

    /** @return array<string,mixed> */
    private function verifiedPayment(string $ref = 'UTR100200300'): array
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'items' => [['description' => 'Design work', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => '1000', 'method' => 'bank_transfer', 'external_ref' => $ref, 'received_on' => AppTime::today()]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));

        return ClientPaymentService::find($r['public_id']);
    }

    // ---- the shared design -----------------------------------------------------------------------------

    public function testTheLayoutHasTheBrandHeaderOneButtonDetailsAndTheStandardFooter(): void
    {
        $m = EmailTemplate::render([
            'subject' => 'Payment received', 'category' => 'Payment successful', 'tone' => 'success', 'heading' => 'We received your payment', 'name' => 'Asha Test',
            'paragraphs' => ['Thank you for your payment.'], 'details' => ['Receipt number' => 'KIS-R-0001', 'Amount' => '₹1,000.00', 'Empty' => ''],
            'button' => ['label' => 'View receipt', 'url' => 'https://portal.example.test/my/receipt/abc'], 'notes' => ['Keep this for your records.'],
        ]);
        $h = $m['html'];
        $this->assertStringContainsString('KRAZY INTEGRATED SOLUTIONS', $h);
        $this->assertStringContainsString('max-width:600px', str_replace(' ', '', $h));
        $this->assertStringContainsString('/img/krazy-mark.png', $h, 'the logo is the project asset, served from the real site address');
        $this->assertStringContainsString('We received your payment', $h);
        $this->assertStringContainsString('Hello Asha Test,', $h);
        $this->assertStringContainsString('KIS-R-0001', $h);
        $this->assertStringNotContainsString('Empty', $h, 'an empty detail is left out');
        $this->assertSame(1, substr_count($h, 'View receipt</a>'), 'exactly one action button');
        $this->assertStringContainsString('This is an automated notification from', $h);
        $this->assertStringContainsString('support@example.test', $h);
        $this->assertStringNotContainsString('<script', $h);
        $this->assertStringContainsString('We received your payment', $m['text']);
        $this->assertStringContainsString('Receipt number: KIS-R-0001', $m['text']);
        $this->assertStringContainsString('https://portal.example.test/my/receipt/abc', $m['text']);
        $this->assertStringNotContainsString('<', $m['text'], 'the plain-text version has no markup');
    }

    public function testDynamicContentIsEscapedAndLongOrMissingValuesAreHandled(): void
    {
        $long = str_repeat('Averylongname', 40);
        $m = EmailTemplate::render(['subject' => "Hi\r\nBcc: evil@x.test", 'heading' => 'Hello', 'name' => '<script>alert(1)</script> ' . $long, 'paragraphs' => ['<b>bold</b> & more'],
            'details' => ['Note' => '<img src=x onerror=1>'], 'button' => ['label' => 'Go', 'url' => 'javascript:alert(1)']]);
        $this->assertStringNotContainsString("\n", $m['subject']);
        $this->assertStringNotContainsString('<script>', $m['html']);
        $this->assertStringNotContainsString('<img src=x', $m['html']);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; more', $m['html']);
        $this->assertStringNotContainsString('javascript:', $m['html'], 'a button needs an absolute http(s) address');
        $this->assertStringNotContainsString($long, $m['html'], 'a very long name is shortened');
        $bare = EmailTemplate::render(['subject' => 'Plain', 'heading' => 'Plain']);
        $this->assertStringContainsString('Hello,', $bare['html'], 'no name: a generic greeting');
        $this->assertStringNotContainsString('role="presentation" class="btn"', $bare['html']);
    }

    public function testMoneyAndDatesAreFormattedForReading(): void
    {
        $this->assertSame('₹12,500.50', EmailTemplate::money(1250050));
        $this->assertSame('USD 20.00', EmailTemplate::money(2000, 'USD'));
        $this->assertSame('', EmailTemplate::money(null));
        $this->assertSame('24 Oct 2026', EmailTemplate::date('2026-10-24'));
        $this->assertSame('24 Oct 2026, 13:05 UTC', EmailTemplate::date('2026-10-24 13:05:00', true));
        $this->assertSame('', EmailTemplate::date('not a date'));
        $this->assertSame('Chrome on Windows', EmailTemplate::browser('Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'));
        $this->assertSame('Unknown device', EmailTemplate::browser(''));
    }

    public function testEveryOldPlainMessageIsStillSentInTheStandardDesign(): void
    {
        Mailer::send('someone@example.test', 'Old style', "Hello Sam,\n\nSomething happened.\n\nhttps://portal.example.test/x\n\nKrazy Integrated Solutions\n");
        $m = Mailer::$sent[0];
        $this->assertStringContainsString('KRAZY INTEGRATED SOLUTIONS', $m['html']);
        $this->assertStringContainsString('Hello Sam,', $m['html']);
        $this->assertStringContainsString('Something happened.', $m['body']);
    }

    // ---- account emails --------------------------------------------------------------------------------

    public function testPasswordChangeResetAndSignInAndStatusEmailsCarryNoSecretsAndTheRightWording(): void
    {
        AccountMail::passwordChanged('asha@example.test', 'Asha');
        AccountMail::signedIn('asha@example.test', 'Asha', '24 Oct 2026, 10:00 UTC', '203.0.113.9', 'Chrome on Windows', 'Email and password');
        AccountMail::status('asha@example.test', 'Asha', false);
        AccountMail::reset('asha@example.test', 'Asha', 'SECRETTOKEN123');
        [$changed, $signed, $status, $reset] = Mailer::$sent;
        $this->assertSame('Your Krazy Integrated Solutions Account Password Has Been Changed', str_replace('KIS', 'Krazy Integrated Solutions', $changed['subject']));
        $this->assertStringContainsString('Your password was changed successfully.', $changed['html']);
        $this->assertStringContainsString('Secure My Account', $changed['html']);
        $this->assertStringContainsString('/forgot-password', $changed['html']);
        $this->assertStringContainsString('203.0.113.9', $signed['html']);
        $this->assertStringContainsString('Chrome on Windows', $signed['body']);
        $this->assertStringContainsString('deactivated', $status['subject']);
        $this->assertStringNotContainsString('Sign in', $status['html'], 'a deactivated person gets no sign-in button');
        $this->assertStringContainsString('/reset-password/SECRETTOKEN123', $reset['body']);
        foreach ([$changed, $signed, $status] as $m) {
            $this->assertStringNotContainsStringIgnoringCase('password_hash', $m['html']);
            $this->assertStringNotContainsString('$2y$', $m['body']);
        }
    }

    public function testSigningInSendsOneAlertAndNotAgainFromTheSameAddressAtOnce(): void
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$this->client]);
        $req = new Request('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0']);
        Auth::signInAlert($user, $req, 'Email and password');
        $this->assertCount(1, $this->to('alpha@client.test'));
        $this->assertStringContainsString('New sign-in', $this->to('alpha@client.test')[0]['subject']);
        Mailer::$sent = [];
        $user['last_login_ip'] = $req->ip();
        $user['last_login_at'] = gmdate('Y-m-d H:i:s', time() - 60);
        Auth::signInAlert($user, $req, 'Email and password');
        $this->assertSame([], Mailer::$sent, 'no second alert within ten minutes from the same address');
        Config::set('notifications.login_alert', false);
        $user['last_login_at'] = null;
        Auth::signInAlert($user, $req, 'Email and password');
        $this->assertSame([], Mailer::$sent, 'the alert can be switched off');
    }

    public function testDeactivatingAndReactivatingAnAccountEmailsThePerson(): void
    {
        UserService::deactivate($this->admin, $this->dev2, 'Left the team');
        $this->assertStringContainsString('deactivated', $this->to('dev2@x.test')[0]['subject']);
        Mailer::$sent = [];
        UserService::reactivate($this->admin, $this->dev2);
        $this->assertStringContainsString('reactivated', $this->to('dev2@x.test')[0]['subject']);
    }

    // ---- notification emails ---------------------------------------------------------------------------

    public function testAVerifiedPaymentEmailsTheClientTheConfirmationAndTheReceiptExactlyOnce(): void
    {
        $this->verifiedPayment('UTR777888999');
        $sent = EmailChannel::flush();
        $this->assertGreaterThanOrEqual(2, $sent);
        $mails = $this->to('alpha@client.test');
        $subjects = implode(' | ', array_column($mails, 'subject'));
        $this->assertStringContainsString('is paid', $subjects);
        $this->assertStringContainsString('Payment receipt', $subjects);
        $rcpt = array_values(array_filter($mails, static fn (array $m): bool => str_contains($m['subject'], 'Payment receipt')))[0];
        $this->assertStringContainsString('UTR777888999', $rcpt['html'], 'the transaction id is in the details card');
        $this->assertStringContainsString('₹1,000.00', $rcpt['body']);
        $this->assertStringContainsString('Receipt number', $rcpt['body']);
        $this->assertStringContainsString('/my/receipt/', $rcpt['html']);
        $this->assertSame(0, EmailChannel::flush(), 'nothing is sent twice');
        $this->assertSame(0, EmailChannel::retryFailed());
        $this->assertGreaterThanOrEqual(2, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND email_status = 'sent'", [$this->client])['c']);
    }

    public function testNothingIsEmailedUntilThePaymentIsVerified(): void
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Site', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '500']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => '500', 'method' => 'bank_transfer', 'external_ref' => 'UTR1', 'received_on' => AppTime::today()]);
        EmailChannel::flush();
        $subjects = implode(' | ', array_column($this->to('alpha@client.test'), 'subject'));
        $this->assertStringContainsString('Invoice', $subjects, 'the invoice itself is announced');
        $this->assertStringNotContainsString('Payment confirmed', $subjects);
        $this->assertStringNotContainsString('receipt', strtolower($subjects));
    }

    public function testFailedSendsAreRecordedAndRetriedButOnlyAFewTimes(): void
    {
        Config::set('mail.driver', 'smtp');
        Config::set('mail.host', '127.0.0.1');
        Config::set('mail.port', 1);
        Config::set('mail.encryption', 'none');
        Config::set('mail.from_address', 'noreply@example.test');
        Notifier::send([$this->client], 'payment.confirmed', 'Payment confirmed: ₹10.00', 'Thanks.', '/my/invoices/x', null, null, null, 'retry-test');
        $this->assertSame(0, EmailChannel::flush());
        $row = Database::one("SELECT id, email_status, email_attempts FROM notifications WHERE user_id = ? AND event_type = 'payment.confirmed'", [$this->client]);
        $this->assertSame('failed', $row['email_status']);
        $this->assertSame(1, (int) $row['email_attempts']);
        Config::set('mail.driver', 'array');
        $this->assertSame(1, EmailChannel::retryFailed());
        $this->assertSame('sent', Database::one('SELECT email_status FROM notifications WHERE id = ?', [$row['id']])['email_status']);
        // a row that failed too often is left alone
        Notifier::send([$this->client], 'payment.confirmed', 'Another', 'Thanks.', null, null, null, null, 'retry-test-2');
        Database::run("UPDATE notifications SET email_status = 'failed', email_attempts = 3 WHERE dedupe_key = ?", [hash('sha256', 'retry-test-2')]);
        EmailChannel::flush();
        Mailer::$sent = [];
        $this->assertSame(0, EmailChannel::retryFailed());
    }

    public function testTheSwitchesAndTheSkipListKeepEmailOff(): void
    {
        Config::set('notifications.email_clients', false);
        Notifier::send([$this->client], 'invoice.paid', 'Invoice paid', 'Thanks.', null, null, null, null, 'sw-1');
        EmailChannel::flush();
        $this->assertSame([], $this->to('alpha@client.test'), 'client emails switched off');
        Config::set('notifications.email_clients', true);
        Notifier::send([$this->client], 'renewal.client_reminder', 'Reminder', 'Soon.', null, null, null, null, 'sw-2');
        Notifier::send([$this->client], 'referral.status_changed', 'Referral update', 'x', null, null, null, null, 'sw-3');
        EmailChannel::flush();
        $this->assertSame([], $this->to('alpha@client.test'), 'events with their own email flow are skipped');
        Config::set('notifications.email_staff', false);
        Notifier::send([$this->admin], 'form.submitted', 'New answer', 'Ref', null, null, null, null, 'sw-4');
        EmailChannel::flush();
        $this->assertSame([], $this->to('admin@x.test'), 'team emails switched off');
        Config::set('notifications.email_staff', true);
        Notifier::send([$this->admin], 'form.submitted', 'New answer 2', 'Ref', '/forms', null, null, null, 'sw-5');
        EmailChannel::flush();
        $mail = $this->to('admin@x.test')[0];
        $this->assertStringContainsString('your team role', $mail['body'], 'a team email says why the person got it');
    }

    public function testADeactivatedPersonOrARolledBackNotificationSendsNothing(): void
    {
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$this->client2]);
        Notifier::send([$this->client2], 'invoice.paid', 'Invoice paid', 'Thanks.', null, null, null, null, 'dead-1');
        try {
            Database::transaction(function (): void {
                Notifier::send([$this->client], 'invoice.paid', 'Rolled back', 'x', null, null, null, null, 'rb-1');
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, EmailChannel::flush());
        $this->assertSame([], Mailer::$sent);
    }

    public function testAFailedOnlinePaymentTellsTheClientWithoutChangingTheOrder(): void
    {
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Site', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '500']], 'discount' => '0', 'project' => '', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        EmailChannel::flush();
        Mailer::$sent = [];
        $inv = InvoiceService::find($d['public_id']);
        $clientId = (int) Database::one('SELECT id FROM clients WHERE user_id = ?', [$this->client])['id'];
        Database::run("INSERT INTO gateway_orders (public_id, merchant_order_id, provider, invoice_id, client_id, amount_minor, status, created_at, updated_at) VALUES (?, 'KIS1XABCDEF123456', 'razorpay', ?, ?, 50000, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [bin2hex(random_bytes(16)), $inv['id'], $clientId]);
        $m = new \ReflectionMethod(RazorpayGateway::class, 'notifyFailed');
        $m->invoke(null, 'KIS1XABCDEF123456', 'pay_FailedOne1', 'Payment failed <b>due</b> to insufficient funds');
        $m->invoke(null, 'KIS1XABCDEF123456', 'pay_FailedOne1', 'again'); // the same attempt reported twice
        EmailChannel::flush();
        $mails = $this->to('alpha@client.test');
        $this->assertCount(1, $mails);
        $this->assertSame('Your payment did not go through', $mails[0]['subject']);
        $this->assertStringContainsString('₹500.00', $mails[0]['body']);
        $this->assertStringContainsString('We have not received it', $mails[0]['body']);
        $this->assertStringContainsString('/my/pay/', $mails[0]['html'], 'an open order can be paid again from the same link');
        $this->assertStringNotContainsString('<b>', $mails[0]['html']);
        $this->assertSame('pending', Database::one("SELECT status FROM gateway_orders WHERE merchant_order_id = 'KIS1XABCDEF123456'")['status']);
    }

    // ---- confirmations of forms ------------------------------------------------------------------------

    public function testAFormSubmissionIsAcknowledgedWithoutPromisingAnything(): void
    {
        $r = EnquiryService::create(null, 'contact_form', ['contact_name' => 'Rhea Visitor', 'email' => 'rhea@example.test', 'phone' => '+91 90000 22222', 'description' => 'Need a small website for my shop soon.', 'service' => 'website_development'], ['consent' => true, 'ip' => '203.0.113.7']);
        $mails = $this->to('rhea@example.test');
        $this->assertCount(1, $mails);
        $this->assertStringContainsString($r['reference'], $mails[0]['subject']);
        $this->assertStringContainsString('not a quotation', $mails[0]['body']);
        $this->assertStringNotContainsStringIgnoringCase('approved', $mails[0]['body']);
        $this->assertStringNotContainsString('<a ', preg_replace('#<a [^>]*>[^<]*</a>#', '', $mails[0]['html']) ?? '', 'no stray links');
    }
}
