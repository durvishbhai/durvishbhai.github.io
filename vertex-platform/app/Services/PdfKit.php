<?php

declare(strict_types=1);

namespace Vertex\Services;

/** Shared drawing pieces for the KIS business PDFs (requirements document, official agreement): header band, section headings, tables, key-value grids, notice boxes. */
final class PdfKit
{
    public const L = 45.0;
    public const R = Pdf::W - 45.0;
    public const NAVY = [0.02, 0.05, 0.12];
    public const PURPLE = [0.45, 0.20, 0.93];
    public const PANEL = [0.945, 0.96, 0.985];
    public const LINE = [0.80, 0.83, 0.89];
    public const GRAY = 0.42;
    public const WHITE = [1.0, 1.0, 1.0];

    /** The dark brand band with the logo and company name; leaves the cursor below it. */
    public static function header(Pdf $pdf, string $company = 'KRAZY INTEGRATED SOLUTIONS'): void
    {
        $H = Pdf::H;
        $pdf->fill(self::L, $H - 100, self::R - self::L, 62, self::NAVY);
        if (($img = DocumentPdf::logo()) !== null) {
            $pdf->image($img, self::L + 10, $H - 94, 64, 64 * $img['h'] / $img['w']);
        }
        $pdf->text(self::L + 96, $H - 66, $company, 14, true, 'left', self::WHITE);
        $pdf->text(self::L + 96, $H - 83, 'Your Ideas. Our Integrated Solutions.', 10, true, 'left', [0.55, 0.88, 0.97]);
        $pdf->moveDown(70);
    }

    public static function section(Pdf $pdf, string $title, string $count = ''): void
    {
        $pdf->ensure(60);
        $pdf->moveDown(14);
        $y = $pdf->y();
        $pdf->text(self::L, $y - 10, strtoupper($title) . ($count !== '' ? '  (' . $count . ')' : ''), 11, true, 'left', self::NAVY);
        $pdf->rule(self::L, self::R, $y - 16, 1.0, 0.1);
        $pdf->moveDown(24);
    }

    /**
     * A table with a dark header row and zebra rows. Widths are in points and should add up to R - L.
     *
     * @param list<float> $widths @param list<string> $head @param list<list<string>> $rows @param list<string> $align 'left'|'center'|'right' per column
     */
    public static function table(Pdf $pdf, array $widths, array $head, array $rows, array $align = [], string $blank = '-'): void
    {
        $drawHead = static function () use ($pdf, $widths, $head): void {
            $pdf->ensure(40);
            $y = $pdf->y();
            $pdf->fill(self::L, $y - 18, array_sum($widths), 18, self::NAVY);
            $x = self::L;
            foreach ($head as $i => $h) {
                $pdf->text($x + 5, $y - 13, $h, 9, true, 'left', self::WHITE);
                $x += $widths[$i];
            }
            $pdf->moveDown(18);
        };
        $drawHead();
        foreach ($rows as $n => $row) {
            $lines = 1;
            $wrapped = [];
            foreach ($row as $i => $cell) {
                $wrapped[$i] = Pdf::wrap($cell === '' ? $blank : $cell, $widths[$i] - 10, 9.5);
                $lines = max($lines, count($wrapped[$i]));
            }
            $h = 6 + $lines * 12;
            if ($pdf->y() - $h < Pdf::MARGIN + 28) {
                $pdf->newPage();
                $drawHead();
            }
            $y = $pdf->y();
            if ($n % 2 === 1) {
                $pdf->fill(self::L, $y - $h, array_sum($widths), $h, self::PANEL);
            }
            $x = self::L;
            foreach ($wrapped as $i => $ls) {
                $al = $align[$i] ?? 'left';
                foreach ($ls as $k => $l) {
                    $tx = $al === 'right' ? $x + $widths[$i] - 5 : ($al === 'center' ? $x + $widths[$i] / 2 : $x + 5);
                    $pdf->text($tx, $y - 14 - $k * 12, $l, 9.5, $i === 1 && count($row) > 2 && $head[1] !== 'Feature / Module' ? true : false, $al);
                }
                $x += $widths[$i];
            }
            $pdf->rule(self::L, self::L + array_sum($widths), $y - $h, 0.3, 0.85);
            $pdf->moveDown($h);
        }
        $pdf->moveDown(4);
    }

    /**
     * A two-pair-per-row grid like "Client Name | Asha | Email | asha@..." with shaded label cells.
     *
     * @param list<array{0:string,1:string}> $pairs
     */
    public static function pairs(Pdf $pdf, array $pairs): void
    {
        $w = self::R - self::L;
        $lw = 100.0;
        $vw = $w / 2 - $lw;
        foreach (array_chunk($pairs, 2) as $row) {
            $h = 6.0;
            $cells = [];
            foreach ($row as $k => [$label, $value]) {
                $cells[$k] = Pdf::wrap($value === '' ? '-' : $value, $vw - 10, 9.5);
                $h = max($h, 6 + count($cells[$k]) * 12);
            }
            $pdf->ensure($h + 4);
            $y = $pdf->y();
            foreach ($row as $k => [$label]) {
                $x = self::L + $k * $w / 2;
                $pdf->fill($x, $y - $h, $lw, $h, self::PANEL);
                $pdf->box($x, $y - $h, $lw + $vw, $h, self::LINE, 0.5);
                $pdf->text($x + 5, $y - 14, $label, 9.5, true, 'left', self::NAVY);
                foreach ($cells[$k] as $i => $l) {
                    $pdf->text($x + $lw + 5, $y - 14 - $i * 12, $l, 9.5);
                }
            }
            $pdf->moveDown($h);
        }
        $pdf->moveDown(6);
    }

    /** A bordered notice with centred text. @param array{0:float,1:float,2:float} $color */
    public static function notice(Pdf $pdf, string $text, array $color = self::NAVY): void
    {
        $lines = Pdf::wrap($text, self::R - self::L - 30, 10, true);
        $h = 14 + count($lines) * 13;
        $pdf->ensure($h + 10);
        $pdf->moveDown(8);
        $y = $pdf->y();
        $pdf->box(self::L, $y - $h, self::R - self::L, $h, $color, 0.9);
        foreach ($lines as $i => $l) {
            $pdf->text((self::L + self::R) / 2, $y - 19 - $i * 13, $l, 10, true, 'center', $color);
        }
        $pdf->moveDown($h + 6);
    }
}
