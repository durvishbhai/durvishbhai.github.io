<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\AppTime;
use Vertex\Services\ApprovalCenter;
use Vertex\Services\BusinessProfile;
use Vertex\Services\Chart;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CouponService;
use Vertex\Services\CreditNoteService;
use Vertex\Services\DocumentPdf;
use Vertex\Services\InvoiceService;
use Vertex\Services\Pdf;
use Vertex\Services\ReportService;

/** Phase 10: coupons and campaigns, credit notes, PDFs, reports and charts, approval centre. */
final class Phase10ServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private string $clientPublic;
    private string $client2Public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        $this->clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $this->client2Public = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client2])['public_id'];
        Config::set('mail.driver', 'none');
        BusinessProfile::save($this->admin, ['trade_name' => 'Vertex Integrated Solutions', 'legal_name' => '', 'address' => "12 Test Street\nMumbai", 'email' => 'billing@vertex.test', 'phone' => '+91 90000 00000', 'website' => 'https://vertex.test', 'tax_statement' => 'Tax is not applicable.', 'payment_instructions' => 'UPI: pay@bank', 'invoice_footer' => 'Thank you.']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    private function invalid(callable $fn, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            if ($needle !== '') {
                $this->assertStringContainsStringIgnoringCase($needle, implode(' ', $e->errors));
            }
            $this->addToAssertionCount(1);
        }
    }

    /** @param array<string,mixed> $over */
    private function coupon(array $over = []): array
    {
        $r = CouponService::create($this->owner, $over + ['code' => 'SAVE10', 'discount_type' => 'percent', 'percent' => '10', 'description' => 'Test']);

        return CouponService::find($r['public_id']);
    }

    private function draft(string $total = '10000', array $over = [], ?array $project = null, ?string $client = null): array
    {
        $r = InvoiceService::create($this->finance, $client ?? $this->clientPublic, $over + ['title' => 'Website work', 'project' => $project['public_id'] ?? '', 'items' => [['description' => 'Website', 'quantity' => '1', 'unit' => $total]], 'discount' => '0', 'due_date' => '', 'client_notes' => '', 'internal_notes' => '']);

        return InvoiceService::find($r['public_id']);
    }

    private function issue(array $d): array
    {
        InvoiceService::issue($this->admin, $d);

        return InvoiceService::find($d['public_id']);
    }

    private function payInvoice(array $inv, string $amount): array
    {
        $r = ClientPaymentService::record($this->finance, ['invoice' => $inv['public_id'], 'role' => 'other', 'gross' => $amount, 'method' => 'bank_transfer', 'external_ref' => 'UTR' . bin2hex(random_bytes(4)), 'received_on' => AppTime::today()]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));

        return ClientPaymentService::find($r['public_id']);
    }

    // ------------------------------------------------------------------------------------------------ coupons

    public function testPercentCouponReducesTheDraftTotalAndIsRedeemedOnlyWhenIssued(): void
    {
        $c = $this->coupon();
        $d = $this->draft('10000', ['coupon_code' => 'save10']);
        $this->assertSame(100000, (int) $d['coupon_discount_minor'], '10% of 10,000');
        $this->assertSame(900000, (int) $d['total_minor']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM coupon_redemptions')['c'], 'a draft does not use the coupon up');
        $i = $this->issue($d);
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM coupon_redemptions WHERE released_at IS NULL')['c']);
        $this->assertSame(900000, (int) $i['total_minor']);
        InvoiceService::void($this->owner, $i, 'Client cancelled the order');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM coupon_redemptions WHERE released_at IS NULL')['c'], 'voiding gives the use back');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'coupon.released'")['c']);
        $this->assertNotNull($c);
    }

    public function testFixedCapMinimumSpendAndDiscountNeverExceedsTheSpend(): void
    {
        $this->coupon(['code' => 'CAP', 'percent' => '50', 'max_discount' => '200']);
        $this->assertSame(20000, (int) $this->draft('10000', ['coupon_code' => 'CAP'])['coupon_discount_minor'], '50% capped at 200');
        $this->coupon(['code' => 'FLAT', 'discount_type' => 'fixed', 'percent' => '', 'amount' => '500']);
        $this->assertSame(50000, (int) $this->draft('10000', ['coupon_code' => 'FLAT'])['coupon_discount_minor']);
        $this->assertSame(30000, (int) $this->draft('300', ['coupon_code' => 'FLAT'])['coupon_discount_minor'], 'a fixed coupon never discounts more than the spend');
        $this->coupon(['code' => 'BIG', 'min_spend' => '5000']);
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'BIG']), 'at least');
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'NOPE']), 'does not exist');
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'FLAT', 'discount' => '1000']), 'no discount');
    }

    public function testValidityWindowPausedAndEligibleServices(): void
    {
        $this->coupon(['code' => 'FUTURE', 'valid_from' => AppTime::todayPlus(5)]);
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'FUTURE']), 'valid from');
        $this->coupon(['code' => 'OLD', 'valid_from' => AppTime::todayPlus(-10), 'valid_until' => AppTime::todayPlus(-1)]);
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'OLD']), 'expired');
        $c = $this->coupon(['code' => 'PAUSE']);
        CouponService::setStatus($this->owner, $c, 'paused');
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'PAUSE']), 'paused');
        $this->coupon(['code' => 'VIDEO', 'eligible_services' => ['video_editing']]);
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'VIDEO']), 'does not apply');
        Database::run("UPDATE projects SET service_category = 'video_editing' WHERE id = ?", [$this->alpha['id']]);
        $this->assertSame(10000, (int) $this->draft('1000', ['coupon_code' => 'VIDEO'], $this->alphaRow())['coupon_discount_minor']);
    }

    public function testUsageLimitsTotalAndPerClientAreEnforcedAtIssue(): void
    {
        $this->coupon(['code' => 'ONCE', 'max_uses' => '1']);
        $a = $this->draft('1000', ['coupon_code' => 'ONCE']);
        $b = $this->draft('1000', ['coupon_code' => 'ONCE'], null, $this->client2Public);
        $this->issue($a);
        $this->invalid(fn () => InvoiceService::issue($this->admin, $b), 'maximum number of times');
        $this->assertSame('draft', InvoiceService::find($b['public_id'])['status'], 'the second invoice stays a draft');
        $this->coupon(['code' => 'PERCLIENT', 'max_uses_per_client' => '1']);
        $this->issue($this->draft('1000', ['coupon_code' => 'PERCLIENT']));
        $this->invalid(fn () => $this->draft('1000', ['coupon_code' => 'PERCLIENT']), 'already used');
        $this->issue($this->draft('1000', ['coupon_code' => 'PERCLIENT'], null, $this->client2Public));
    }

    public function testEditingACouponNeverChangesAnIssuedInvoiceAndOnlyAuthorisedStaffManage(): void
    {
        $c = $this->coupon();
        $i = $this->issue($this->draft('1000', ['coupon_code' => 'SAVE10']));
        CouponService::update($this->owner, $c, ['code' => 'SAVE10', 'discount_type' => 'percent', 'percent' => '90']);
        $this->assertSame(10000, (int) InvoiceService::find($i['public_id'])['coupon_discount_minor']);
        try {
            CouponService::create($this->finance, ['code' => 'X1', 'discount_type' => 'percent', 'percent' => '5']);
            $this->fail('finance cannot create coupons');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->invalid(fn () => CouponService::create($this->owner, ['code' => 'save10', 'discount_type' => 'percent', 'percent' => '5']), 'already exists');
        $this->invalid(fn () => CouponService::create($this->owner, ['code' => 'BAD', 'discount_type' => 'percent', 'percent' => '150']), 'at most 100');
        $this->invalid(fn () => CouponService::create($this->owner, ['code' => 'b!', 'discount_type' => 'fixed', 'amount' => '10']), 'code must be');
    }

    public function testCampaignReportsRedemptionsAndDiscount(): void
    {
        $camp = CouponService::saveCampaign($this->owner, null, ['name' => 'Diwali 2026', 'description' => 'Festival offer', 'starts_on' => '', 'ends_on' => '', 'status' => 'active']);
        $this->coupon(['code' => 'DIWALI', 'campaign' => $camp]);
        $this->issue($this->draft('2000', ['coupon_code' => 'DIWALI']));
        $row = CouponService::campaigns()[0];
        $this->assertSame([1, 1, 20000], [(int) $row['coupons'], (int) $row['redemptions'], (int) $row['discounted_minor']]);
        $this->assertSame(180000, (int) $row['invoiced_minor']);
    }

    // ------------------------------------------------------------------------------------------------ credit notes

    public function testCreditNoteReducesWhatIsOwedAndCannotExceedTheUnpaidBalance(): void
    {
        $i = $this->issue($this->draft('1000'));
        $this->payInvoice($i, '400');
        $i = InvoiceService::find($i['public_id']);
        $this->assertSame('partially_paid', $i['status']);
        $this->assertSame(60000, CreditNoteService::creditable($i), 'unpaid balance 600 can be credited');
        $this->invalid(fn () => CreditNoteService::issue($this->owner, $i, '700', 'Goodwill reduction'), 'between');
        $n = CreditNoteService::issue($this->owner, $i, '600', 'Scope reduced by agreement');
        $this->assertMatchesRegularExpression('/^CRN-\d{4}-\d{5}$/', $n['reference']);
        $after = InvoiceService::find($i['public_id']);
        $this->assertSame(60000, (int) $after['credited_minor']);
        $this->assertSame(40000, InvoiceService::payable($after));
        $this->assertSame('paid', $after['status'], 'verified payments now cover the reduced amount');
        $this->assertSame(0, InvoiceService::balance($after)['outstanding']);
        $note = CreditNoteService::find($n['public_id']);
        CreditNoteService::void($this->owner, $note, 'Issued by mistake today');
        $back = InvoiceService::find($i['public_id']);
        $this->assertSame([0, 'partially_paid'], [(int) $back['credited_minor'], $back['status']]);
    }

    public function testCreditNoteGuards(): void
    {
        $draft = $this->draft('1000');
        $this->invalid(fn () => CreditNoteService::issue($this->owner, $draft, '10', 'Reduction for client'), 'issued invoice');
        $i = $this->issue($draft);
        $this->invalid(fn () => CreditNoteService::issue($this->owner, $i, '1000', 'Whole thing credited'), 'Void');
        $this->invalid(fn () => CreditNoteService::issue($this->owner, $i, '10', 'short'), 'Reason');
        try {
            CreditNoteService::issue($this->finance, $i, '10', 'Reduction for client');
            $this->fail('finance cannot issue credit notes');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        CreditNoteService::issue($this->owner, $i, '100', 'Reduction for client');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'credit_note.issued'")['c']);
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'credit_note.issued'")['c']);
    }

    // ------------------------------------------------------------------------------------------------ PDFs and charts

    public function testPdfsAreValidDocumentsWithTheRightFacts(): void
    {
        $this->coupon();
        $i = $this->issue($this->draft('12345.50', ['coupon_code' => 'SAVE10']));
        $p = $this->payInvoice($i, '1000');
        $n = CreditNoteService::issue($this->owner, InvoiceService::find($i['public_id']), '500', 'Reduction for client');
        $docs = [
            'invoice' => [DocumentPdf::invoice(InvoiceService::find($i['public_id'])), [$i['reference'], '11,110.95', 'SAVE10', 'Tax is not applicable']],
            'receipt' => [DocumentPdf::receipt($p, InvoiceService::find($i['public_id'])), [$p['reference'], '1,000.00']],
            'credit' => [DocumentPdf::creditNote(CreditNoteService::find($n['public_id'])), [$n['reference'], '500.00']],
        ];
        foreach ($docs as $name => [$bytes, $needles]) {
            $this->assertStringStartsWith('%PDF-1.4', $bytes, $name);
            $this->assertStringEndsWith("%%EOF\n", $bytes);
            $file = tempnam(sys_get_temp_dir(), 'pdf');
            file_put_contents($file, $bytes);
            $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
            @unlink($file);
            if ($text !== '') { // poppler is only a convenience for this check
                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, $text, "{$name} PDF shows {$needle}");
                }
            }
        }
        $pdf = new Pdf('Long');
        for ($k = 0; $k < 200; $k++) {
            $pdf->paragraph("Row {$k} with some text (brackets) and \\ backslash", 10);
        }
        $out = $pdf->output();
        $this->assertGreaterThan(3, substr_count($out, '/Type /Page '), 'long documents flow onto more pages');
    }

    public function testChartsAreAccessibleSvgWithoutInlineStyles(): void
    {
        foreach ([Chart::bars(['Jan', 'Feb'], [100, 250], 'Revenue', 'inr'), Chart::hbars(['A', 'B'], [3, 5], 'Counts'), Chart::donut(['Open', 'Closed'], [4, 6], 'Status'), Chart::line([1, 3, 2, 5], 'Trend')] as $svg) {
            $this->assertStringStartsWith('<svg', $svg);
            $this->assertStringContainsString('role="img"', $svg);
            $this->assertStringContainsString('<title>', $svg);
            $this->assertDoesNotMatchRegularExpression('/style=|<script|onload=|javascript:/i', $svg);
        }
        $this->assertSame('', Chart::bars([], [], 'Empty'));
        $this->assertStringNotContainsString('<script', Chart::bars(['<script>alert(1)</script>'], [1], 'x'));
    }

    // ------------------------------------------------------------------------------------------------ reports

    public function testEveryReportRunsAndMatchesTheLedger(): void
    {
        $this->coupon();
        $i = $this->issue($this->draft('1000', ['coupon_code' => 'SAVE10']));
        $this->payInvoice($i, '300');
        $this->issue($this->draft('500'));
        foreach (array_keys(ReportService::catalog()) as $key) {
            $r = ReportService::run($this->owner, $key, null, null);
            $this->assertNotSame('', $r['title']);
            $this->assertIsArray($r['tables'], $key);
            foreach ($r['tables'] as $t) {
                foreach ($t['rows'] as $row) {
                    $this->assertCount(count($t['columns']), $row, "{$key}: every row has a cell per column");
                }
            }
        }
        $rev = ReportService::run($this->owner, 'revenue', null, null);
        $this->assertSame('₹300.00', $rev['summary']['Received in period']);
        $this->assertSame('₹1,400.00', $rev['summary']['Invoiced in period'], '900 after the coupon + 500');
        $this->assertSame('₹1,100.00', $rev['summary']['Outstanding now'], '(900 - 300) + 500');
        $aging = ReportService::run($this->owner, 'aging', null, null);
        $this->assertSame('₹1,100.00', $aging['summary']['Total outstanding']);
        $rec = ReportService::run($this->owner, 'reconciliation', null, null);
        $this->assertSame('0', $rec['summary']['Status mismatches'], 'invoice statuses agree with verified payments');
        $cp = ReportService::run($this->owner, 'coupons', null, null);
        $this->assertSame('1', $cp['summary']['Redemptions']);
    }

    public function testReportsAreLimitedByPermissionAndByWhatTheUserMaySee(): void
    {
        try {
            ReportService::run($this->client, 'revenue', null, null);
            $this->fail('clients have no reports');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->assertSame([], ReportService::available($this->client));
        $this->assertArrayHasKey('revenue', ReportService::available($this->finance));
        $this->assertArrayNotHasKey('workload', ReportService::available($this->finance), 'finance has no operational reports');
        // a manager's operational report only counts projects they are assigned to
        $mgr = ReportService::run($this->manager, 'projects', null, null);
        $own = (int) $mgr['summary']['Projects in view'];
        $all = (int) ReportService::run($this->owner, 'projects', null, null)['summary']['Projects in view'];
        $this->assertLessThanOrEqual($all, $own);
        $this->assertGreaterThan(0, $all);
        $ids = array_column(Database::all('SELECT project_id FROM project_assignments WHERE user_id = ? AND revoked_at IS NULL', [$this->manager]), 'project_id');
        $this->assertSame(count($ids), $own, 'exactly the assigned projects');
    }

    // ------------------------------------------------------------------------------------------------ approval centre

    public function testApprovalCentreListsOnlyWhatTheUserCanDecide(): void
    {
        $d = $this->draft('1000');
        $r = ClientPaymentService::record($this->finance, ['invoice' => $this->issue($d)['public_id'], 'role' => 'other', 'gross' => '100', 'method' => 'cash', 'external_ref' => 'CASH1', 'received_on' => AppTime::today()]);
        $this->assertNotEmpty($r['public_id']);
        $this->draft('700');
        $owner = array_column(ApprovalCenter::pending($this->owner), 'count', 'label');
        $this->assertSame(1, $owner['Client payments waiting to be checked against the bank']);
        $this->assertSame(1, $owner['Draft invoices ready to issue']);
        $fin = array_column(ApprovalCenter::pending($this->finance), 'label');
        $this->assertNotContains('Draft invoices ready to issue', $fin, 'finance cannot issue invoices');
        $this->assertSame([], ApprovalCenter::pending($this->client));
        $this->assertNotEmpty(ApprovalCenter::history($this->owner));
        $this->assertSame([], ApprovalCenter::history($this->finance), 'no audit permission, no history');
    }
}
