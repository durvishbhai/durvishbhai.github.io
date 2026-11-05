<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\BusinessProfile;
use Vertex\Services\DocumentPdf;
use Vertex\Services\Pdf;

/** Quotations and invoices follow the KIS template (letterhead, numbered table, Subtotal / Discount / GRAND TOTAL, Terms & conditions, footer bar); the installed app does not zoom. */
final class DocumentDesignTest extends HttpTestCase
{
    use Phase3Fixtures;

    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    /** @return array<string,mixed> */
    private function data(string $kind, int $items = 3): array
    {
        $rows = [];
        for ($n = 1; $n <= $items; $n++) {
            $rows[] = ['description' => "Item {$n} with a description that is long enough to wrap onto a second line of the table", 'quantity_x100' => 100, 'unit_minor' => 100000 * $n, 'total_minor' => 100000 * $n];
        }
        $sum = array_sum(array_column($rows, 'total_minor'));

        return ['title' => strtoupper($kind), 'numberLabel' => strtoupper($kind) . ' NO:', 'number' => 'REF-1', 'date' => '5 Nov 2026', 'extra' => [], 'issuedTo' => 'Fictional Client', 'issuedToSub' => '', 'subject' => 'A project', 'badge' => '',
            'items' => $rows, 'subtotal' => $sum, 'discount' => 0, 'discountLabel' => 'Discount', 'total' => $sum, 'after' => [], 'terms' => BusinessProfile::terms($kind, []), 'notes' => '', 'howToPay' => '', 'footerNote' => '', 'docName' => $kind];
    }

    private function render(array $d): string
    {
        $m = new \ReflectionMethod(DocumentPdf::class, 'template');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $d);
    }

    public function testTheQuotationAndInvoiceCarryTheTemplateElements(): void
    {
        foreach (['quotation', 'invoice'] as $kind) {
            $pdf = $this->render($this->data($kind));
            $this->assertStringStartsWith('%PDF-1.4', $pdf);
            foreach (['(' . strtoupper($kind) . ')', '(ISSUED TO:)', '(' . strtoupper($kind) . ' NO:)', '(ITEM DESCRIPTION)', '(VALIDITY/QTY)', '(SUBTOTAL)', '(Subtotal)', '(Discount)', '(GRAND TOTAL)', '(Terms & conditions)', '(GST not applicable.)',
                '(This is a computer-generated ' . $kind . ' and does not require a physical signature.)', '(Krazy)', '(Integrated)', '(Solutions)'] as $needle) {
                $this->assertStringContainsString($needle, $pdf, $kind . ': ' . $needle);
            }
            $this->assertStringContainsString('/ShadingType 2', $pdf, 'the letterhead swoosh is a gradient');
            $this->assertSame(1, preg_match_all('/\/Type \/Page /', $pdf), 'three items fit on one page');
            $this->assertStringContainsString('(04.)', $pdf, 'the template always shows four numbered lines');
        }
        $this->assertStringContainsString('(50% Advance will be taken for the confirmation of the project.)', $this->render($this->data('quotation')));
        $this->assertStringContainsString('(No refund after this stage.)', $this->render($this->data('invoice')));
        $this->assertStringNotContainsString('No refund after this stage', $this->render($this->data('quotation')), 'each document has its own terms');
    }

    public function testManyItemsContinueOnANewPageWithTheSameHeader(): void
    {
        $pdf = $this->render($this->data('invoice', 14));
        $this->assertGreaterThan(1, preg_match_all('/\/Type \/Page /', $pdf));
        $this->assertSame(1, preg_match_all('/\(GRAND TOTAL\)/', $pdf));
        $this->assertGreaterThan(1, preg_match_all('/\(ITEM DESCRIPTION\)/', $pdf), 'the table header repeats');
        $this->assertStringContainsString('(Item 14', $pdf);
    }

    public function testTextWidthsAreTheRealHelveticaMetrics(): void
    {
        $this->assertEqualsWithDelta(264.0, Pdf::width('QUOTATION', 44, true), 0.5);
        $this->assertEqualsWithDelta(556.0 * 6 / 1000 * 10, Pdf::width('123456', 10), 0.01);
    }

    public function testTheOwnersTermsReplaceTheStandardWording(): void
    {
        $this->assertSame(BusinessProfile::INVOICE_TERMS, BusinessProfile::terms('invoice', ['invoice_terms' => '']));
        $own = BusinessProfile::terms('quotation', ['quotation_terms' => "First line.\r\n\r\n  Second line.  \n"]);
        $this->assertSame(['First line.', 'Second line.'], $own);
        BusinessProfile::save($this->admin, ['trade_name' => 'KIS', 'tax_statement' => 'Tax is not applicable.', 'quotation_terms' => 'Only this term applies.', 'invoice_terms' => '']);
        $row = BusinessProfile::get();
        $this->assertSame('Only this term applies.', $row['quotation_terms']);
        $this->assertSame(['Only this term applies.'], BusinessProfile::terms('quotation', $row));
        $this->assertSame(BusinessProfile::INVOICE_TERMS, BusinessProfile::terms('invoice', $row));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'billing.profile_changed'")['c']);
    }

    public function testTheInvoicePrintPageUsesTheTemplate(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/img/krazy-doc-logo.png');
        $print = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/invoices/print.php');
        foreach (['kd-sheet', 'ISSUED TO:', 'INVOICE NO:', 'GRAND TOTAL', 'Terms &amp; conditions', 'computer-generated invoice', 'krazy-doc-logo.png'] as $needle) {
            $this->assertStringContainsString($needle, $print);
        }
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/', $print, 'no inline styles (strict CSP)');
    }

    public function testTheInstalledAppDoesNotZoomButTheWebsiteStillDoes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/theme.js');
        $this->assertMatchesRegularExpression('/@media \(display-mode: standalone\)[^{]*\{[^}]*touch-action: pan-x pan-y/s', $css);
        $this->assertStringContainsString('html.pwa-app', $css);
        foreach (['gesturestart', 'user-scalable=no', "classList.add('pwa-app')", 'touches.length > 1', 'e.ctrlKey'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertStringContainsString('display-mode: standalone', $js, 'the lock applies only inside the installed app');
        foreach (['layouts/public.php', 'layouts/app.php', 'layouts/bare.php'] as $layout) {
            $html = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/' . $layout);
            $this->assertStringNotContainsString('user-scalable=no', $html, $layout . ': a normal browser tab keeps its zoom (accessibility)');
        }
    }
}
