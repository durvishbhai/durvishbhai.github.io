<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CouponService;
use Vertex\Services\InvoiceService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\SupportPlans;

/** Bug support after the free period: the choice, the subscription paid through an invoice, coupons on project payments and subscriptions. All data is fictional. */
final class SupportPlansTest extends HttpTestCase
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
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        Config::set('mail.driver', 'none');
        Config::set('support.plans_enabled', true);
        Config::set('support.plan_monthly_rupees', 350);
        Config::set('support.plan_yearly_rupees', 2000);
        BusinessProfile::save($this->admin, ['trade_name' => 'KIS', 'tax_statement' => 'Tax is not applicable.']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** Marks Alpha finished $monthsAgo months ago (final delivery). @return array<string,mixed> */
    private function finished(int $monthsAgo): array
    {
        $at = (new \DateTimeImmutable(AppTime::today(), AppTime::zone()))->modify("-{$monthsAgo} months")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        Database::run("UPDATE projects SET status = 'completed', completed_at = ? WHERE id = ?", [$at, $this->alpha['id']]);

        return ProjectPolicy::find($this->alpha['public_id']);
    }

    private function pay(array $inv): void
    {
        $r = ClientPaymentService::record($this->finance, ['invoice' => $inv['public_id'], 'role' => 'other', 'gross' => number_format(InvoiceService::balance($inv)['outstanding'] / 100, 2, '.', ''), 'method' => 'bank_transfer', 'external_ref' => 'UTR' . bin2hex(random_bytes(4)), 'received_on' => AppTime::today()]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));
    }

    private function invalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
        }
    }

    private function coupon(string $code, string $appliesTo, string $percent = '10'): void
    {
        CouponService::create($this->owner, ['code' => $code, 'discount_type' => 'percent', 'percent' => $percent, 'applies_to' => $appliesTo]);
    }

    // ------------------------------------------------------------------------------------------------ free support and the choice

    public function testFreeSupportIsSixMonthsFromFinalDeliveryAndTheChoiceIsAskedOnlyAfterIt(): void
    {
        $this->assertNull(SupportPlans::freeEnd($this->alphaRow()), 'a project that is not finished has no free support end');
        $p = $this->finished(3);
        $s = SupportPlans::state($p);
        $this->assertTrue($s['free_end'] > AppTime::today());
        $this->assertFalse($s['free_over']);
        $this->assertFalse($s['needs_choice'], 'still inside free support');
        $this->assertSame([], SupportPlans::pendingChoices($this->client));

        $p = $this->finished(7);
        $s = SupportPlans::state($p);
        $this->assertTrue($s['free_over']);
        $this->assertTrue($s['needs_choice']);
        $this->assertCount(1, SupportPlans::pendingChoices($this->client));
        $this->assertSame([], SupportPlans::pendingChoices($this->client2), 'only the owner of the project is asked');
        $this->assertStringContainsString('a choice is needed', $s['label']);
    }

    public function testARecordedWarrantyDecidesAndNotApplicableMeansNoPrompt(): void
    {
        $p = $this->finished(7);
        Database::run("INSERT INTO project_warranties (project_id, eligibility, duration_months, start_basis, start_date, end_date, created_at, updated_at) VALUES (?, 'eligible', 12, 'final_delivery', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$p['id'], date('Y-m-d', strtotime('-7 months')), date('Y-m-d', strtotime('+5 months'))]);
        $this->assertFalse(SupportPlans::state($p)['needs_choice'], 'a 12 month warranty is still running');
        Database::run("UPDATE project_warranties SET eligibility = 'not_applicable' WHERE project_id = ?", [$p['id']]);
        $this->assertNull(SupportPlans::freeEnd($p));
        $this->assertFalse(SupportPlans::state($p)['needs_choice']);
    }

    public function testPayingPerBugEndsThePromptAndNothingIsCharged(): void
    {
        $p = $this->finished(7);
        $before = (int) Database::one('SELECT COUNT(*) c FROM invoices')['c'];
        SupportPlans::choosePerBug($this->client, $p);
        $this->assertSame('per_bug', SupportPlans::mode((int) $p['id']));
        $this->assertFalse(SupportPlans::state($p)['needs_choice']);
        $this->assertSame([], SupportPlans::pendingChoices($this->client));
        $this->assertSame($before, (int) Database::one('SELECT COUNT(*) c FROM invoices')['c'], 'choosing creates no invoice');
        // someone else's project cannot be chosen for
        $this->expectException(HttpException::class);
        SupportPlans::choosePerBug($this->client2, $p);
    }

    public function testNoChoiceIsPossibleWhileFreeSupportIsStillRunning(): void
    {
        $p = $this->finished(2);
        $this->invalid(fn () => SupportPlans::choosePerBug($this->client, $p), 'still running');
        $this->invalid(fn () => SupportPlans::purchase($this->client, $p, 'monthly'), 'still running');
    }

    // ------------------------------------------------------------------------------------------------ subscription

    public function testASubscriptionIsAnInvoiceThatSwitchesOnWhenPaid(): void
    {
        $p = $this->finished(7);
        $r = SupportPlans::purchase($this->client, $p, 'monthly');
        $inv = InvoiceService::find($r['invoice_public_id']);
        $this->assertSame('issued', $inv['status']);
        $this->assertSame('support_subscription', $inv['purpose']);
        $this->assertSame(35000, (int) $inv['total_minor'], '₹350 a month');
        $this->assertSame((int) $p['id'], (int) $inv['project_id']);
        $this->assertNull(SupportPlans::activePeriod((int) $p['id']), 'not active before it is paid');
        $this->assertTrue(SupportPlans::state($p)['needs_choice'], 'the prompt stays until it is paid');
        $this->assertNotNull(SupportPlans::state($p)['awaiting']);

        $this->pay($inv);

        $a = SupportPlans::activePeriod((int) $p['id']);
        $this->assertNotNull($a);
        $this->assertSame(AppTime::today(), $a['starts_on']);
        $this->assertSame(date('Y-m-d', strtotime('+1 month -1 day')), $a['ends_on']);
        $this->assertSame('subscription', SupportPlans::mode((int) $p['id']));
        $this->assertFalse(SupportPlans::state($p)['needs_choice']);
        $this->assertStringContainsString('subscription active until', SupportPlans::state($p)['label']);
    }

    public function testYearlyCostsTwoThousandAndRenewalExtendsFromTheEndOfTheCurrentPeriod(): void
    {
        $p = $this->finished(8);
        $this->assertSame(220000, SupportPlans::yearlySavingMinor(), 'saves ₹2,200 against twelve months');
        $y = InvoiceService::find(SupportPlans::purchase($this->client, $p, 'yearly')['invoice_public_id']);
        $this->assertSame(200000, (int) $y['total_minor']);
        $this->pay($y);
        $first = SupportPlans::activePeriod((int) $p['id']);
        $this->assertSame(date('Y-m-d', strtotime('+12 months -1 day')), $first['ends_on']);
        // renewing while active starts the next period the day after this one ends
        $m = InvoiceService::find(SupportPlans::purchase($this->client, $p, 'monthly')['invoice_public_id']);
        $this->pay($m);
        $rows = Database::all("SELECT starts_on, ends_on FROM support_subscription_periods WHERE project_id = ? AND status = 'active' ORDER BY id", [$p['id']]);
        $this->assertCount(2, $rows);
        $this->assertSame(date('Y-m-d', strtotime($first['ends_on'] . ' +1 day')), $rows[1]['starts_on']);
    }

    public function testAnExpiredSubscriptionAsksAgain(): void
    {
        $p = $this->finished(9);
        $this->pay(InvoiceService::find(SupportPlans::purchase($this->client, $p, 'monthly')['invoice_public_id']));
        $this->assertFalse(SupportPlans::state($p)['needs_choice']);
        Database::run("UPDATE support_subscription_periods SET starts_on = ?, ends_on = ? WHERE project_id = ?", [date('Y-m-d', strtotime('-40 days')), date('Y-m-d', strtotime('-10 days')), $p['id']]);
        $s = SupportPlans::state($p);
        $this->assertNull($s['active']);
        $this->assertTrue($s['needs_choice'], 'expired and nothing chosen: the client is asked again');
    }

    public function testChoosingAgainReplacesTheUnpaidInvoiceAndAPaidOneIsNotTouched(): void
    {
        $p = $this->finished(7);
        $one = SupportPlans::purchase($this->client, $p, 'monthly');
        $two = SupportPlans::purchase($this->client, $p, 'yearly');
        $this->assertSame('void', InvoiceService::find($one['invoice_public_id'])['status'], 'the first, unpaid invoice is cancelled');
        $this->assertSame('issued', InvoiceService::find($two['invoice_public_id'])['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM support_subscription_periods WHERE project_id = ? AND status = 'awaiting_payment'", [$p['id']])['c']);
    }

    public function testRefundingTheSubscriptionPaymentStopsTheCover(): void
    {
        $p = $this->finished(7);
        $inv = InvoiceService::find(SupportPlans::purchase($this->client, $p, 'monthly')['invoice_public_id']);
        $this->pay($inv);
        $this->assertNotNull(SupportPlans::activePeriod((int) $p['id']));
        $pay = Database::one("SELECT * FROM client_payments WHERE invoice_id = ? AND status = 'verified'", [$inv['id']]);
        ClientPaymentService::refund($this->admin, ClientPaymentService::find($pay['public_id']), (int) $pay['gross_minor'], 'Refunded in the test', 'refund');
        $this->assertNull(SupportPlans::activePeriod((int) $p['id']), 'no cover once the money went back');
    }

    public function testSwitchedOffNothingIsAsked(): void
    {
        $p = $this->finished(7);
        Config::set('support.plans_enabled', false);
        $this->assertSame([], SupportPlans::pendingChoices($this->client));
        $this->invalid(fn () => SupportPlans::purchase($this->client, $p, 'monthly'), 'not available');
    }

    // ------------------------------------------------------------------------------------------------ coupons

    public function testACouponWorksOnASubscriptionAndOnlyWhereItAppliesTo(): void
    {
        $p = $this->finished(7);
        $this->coupon('SUBS20', 'subscription', '20');
        $this->coupon('PROJ10', 'project', '10');
        $this->coupon('BOTH5', 'all', '5');
        $this->invalid(fn () => SupportPlans::purchase($this->client, $p, 'monthly', 'proj10'), 'cannot be used on a bug-support subscription');
        $this->invalid(fn () => SupportPlans::purchase($this->client, $p, 'monthly', 'nosuchcode'), 'does not exist');
        $r = SupportPlans::purchase($this->client, $p, 'monthly', 'subs20');
        $inv = InvoiceService::find($r['invoice_public_id']);
        $this->assertSame(7000, (int) $inv['coupon_discount_minor'], '20% of ₹350');
        $this->assertSame(28000, (int) $inv['total_minor']);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM coupon_redemptions WHERE invoice_id = ?', [$inv['id']])['c']);
        $this->pay($inv);
        $this->assertNotNull(SupportPlans::activePeriod((int) $p['id']));
        $r2 = SupportPlans::purchase($this->client, $p, 'yearly', 'both5');
        $this->assertSame(190000, (int) InvoiceService::find($r2['invoice_public_id'])['total_minor'], '5% of ₹2,000 off');
    }

    public function testAClientAppliesACouponToTheirOwnProjectInvoiceBeforePaying(): void
    {
        $this->coupon('PROJ10', 'project', '10');
        $this->coupon('SUBONLY', 'subscription', '50');
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Website work', 'project' => $this->alpha['public_id'], 'items' => [['description' => 'Website', 'quantity' => '1', 'unit' => '10000']], 'discount' => '0']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $inv = InvoiceService::find($d['public_id']);
        $this->invalid(fn () => CouponService::applyToInvoice($this->client, $inv, 'subonly'), 'bug-support subscriptions only');
        $r = CouponService::applyToInvoice($this->client, $inv, 'proj10');
        $this->assertSame(100000, $r['discount_minor']);
        $after = InvoiceService::find($d['public_id']);
        $this->assertSame(1000000, (int) $after['total_minor'], 'the issued invoice itself is never edited');
        $this->assertSame(100000, (int) $after['credited_minor']);
        $this->assertSame(900000, InvoiceService::balance($after)['outstanding'], 'the client now owes ₹9,000');
        $this->assertSame('Coupon PROJ10', Database::one('SELECT reason FROM credit_notes WHERE invoice_id = ?', [$after['id']])['reason']);
        $this->invalid(fn () => CouponService::applyToInvoice($this->client, $after, 'proj10'), 'already has a coupon');
    }

    public function testACouponCannotBeUsedOnceAPaymentHasStarted(): void
    {
        $this->coupon('PROJ10', 'project', '10');
        $d = InvoiceService::create($this->finance, $this->clientPublic, ['title' => 'Work', 'project' => '', 'items' => [['description' => 'Work', 'quantity' => '1', 'unit' => '1000']], 'discount' => '0']);
        InvoiceService::issue($this->admin, InvoiceService::find($d['public_id']));
        $r = ClientPaymentService::record($this->finance, ['invoice' => $d['public_id'], 'role' => 'other', 'gross' => '100', 'method' => 'bank_transfer', 'external_ref' => 'UTR1', 'received_on' => AppTime::today()]);
        $this->invalid(fn () => CouponService::applyToInvoice($this->client, InvoiceService::find($d['public_id']), 'proj10'), 'payment has already');
    }

    // ------------------------------------------------------------------------------------------------ pages

    public function testThePopupAppearsForTheClientUntilTheyChooseAndTheTeamSeesTheList(): void
    {
        $this->finished(7);
        $c = $this->loggedIn('alpha@client.test');
        $dash = $c->get('/dashboard');
        $this->assertSame(200, $dash['status']);
        foreach (['support-popup', 'Your free support has ended', 'Alpha Website', '₹350 per month', '₹2,000 per year', 'You save ₹2,200', 'Pay for each bug separately', 'Coupon code'] as $needle) {
            $this->assertStringContainsString($needle, $dash['body'], $needle);
        }
        $this->assertStringNotContainsString('support-popup', $c->get('/my/support')['body'] === '' ? 'x' : preg_replace('/<style.*?<\/style>/s', '', $c->get('/my/invoices')['body']), 'the payment pages are never blocked');
        // choose per bug
        $r = $c->post('/my/support/' . $this->alpha['public_id'] . '/per-bug', [], '/my/support');
        $this->assertSame(303, $r['status']);
        $this->assertStringNotContainsString('data-support-popup', $c->get('/dashboard')['body']);
        // another client's project cannot be used
        $this->assertSame(404, $c->post('/my/support/' . $this->bravo['public_id'] . '/per-bug', [], '/my/support')['status']);
        // team
        $s = $this->loggedIn('admin@x.test');
        $list = $s->get('/support-plans?show=per_bug');
        $this->assertSame(200, $list['status']);
        $this->assertStringContainsString('Alpha Website', $list['body']);
        $this->assertSame(403, $c->get('/support-plans')['status'], 'clients do not see the team list');
    }

    public function testSubscribingThroughThePortalCreatesTheInvoiceAndShowsItToPay(): void
    {
        $this->finished(7);
        $this->coupon('SUBS20', 'subscription', '20');
        $c = $this->loggedIn('alpha@client.test');
        $r = $c->post('/my/support/' . $this->alpha['public_id'] . '/subscribe', ['plan' => 'yearly', 'coupon_code' => 'subs20'], '/my/support');
        $this->assertSame(303, $r['status']);
        $this->assertStringStartsWith('/my/invoices/', (string) $r['location']);
        $page = $c->get((string) $r['location']);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Bug support, Yearly', $page['body']);
        $this->assertStringContainsString('₹1,600.00', str_replace(',', ',', $page['body']) !== '' ? $page['body'] : '', '₹2,000 less 20%');
        // a bad coupon is explained and nothing is created
        $before = (int) Database::one('SELECT COUNT(*) c FROM invoices')['c'];
        $c->post('/my/support/' . $this->alpha['public_id'] . '/subscribe', ['plan' => 'monthly', 'coupon_code' => 'nope'], '/my/support');
        $this->assertSame($before, (int) Database::one("SELECT COUNT(*) c FROM invoices WHERE status <> 'void'")['c'], 'no new invoice after a bad coupon');
    }

    public function testTheOwnerSetsThePricesInSettingsAndNewPurchasesUseThem(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $page = $o->get('/admin/settings');
        foreach (['Monthly bug-support subscription', 'Yearly bug-support subscription', 'Ask clients to choose bug support'] as $needle) {
            $this->assertStringContainsString($needle, $page['body'], $needle);
        }
        $p = $this->finished(7);
        \Vertex\Services\Settings::save($this->owner, 'support', ['support__plan_monthly_rupees' => '400', 'support__plan_yearly_rupees' => '3000', 'support__plans_enabled' => '1']);
        \Vertex\Services\Settings::reload();
        $this->assertSame(40000, SupportPlans::priceMinor('monthly'));
        $this->assertSame(300000, SupportPlans::priceMinor('yearly'));
        $this->assertSame(180000, SupportPlans::yearlySavingMinor(), '12 x ₹400 less ₹3,000');
        $inv = InvoiceService::find(SupportPlans::purchase($this->client, $p, 'monthly')['invoice_public_id']);
        $this->assertSame(40000, (int) $inv['total_minor']);
    }

    public function testCouponsCanBeCreatedForSubscriptionsFromTheCouponScreens(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $this->assertStringContainsString('Bug-support subscriptions only', $o->get('/coupons/new')['body']);
        $o->post('/coupons', ['code' => 'WELCOME15', 'discount_type' => 'percent', 'percent' => '15', 'applies_to' => 'subscription'], '/coupons/new');
        $this->assertSame('subscription', Database::one("SELECT applies_to a FROM coupons WHERE code = 'WELCOME15'")['a']);
    }
}
