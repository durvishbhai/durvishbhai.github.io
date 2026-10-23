<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * A small, dependency-free PDF 1.4 writer for business documents (quotation, invoice, receipt, credit note): A4, Helvetica / Helvetica-Bold, text,
 * lines, filled rectangles, word wrapping and automatic page breaks. Text is converted to Windows-1252; the rupee sign has no glyph in the standard
 * fonts, so "₹" is written as "Rs." (the HTML/print view keeps the symbol).
 */
final class Pdf
{
    public const W = 595.28;
    public const H = 841.89;
    public const MARGIN = 42.0;

    /** @var list<string> */
    private array $pages = [];
    /** @var list<array{w:int,h:int,data:string}> images used by the document (drawn with image()) */
    private array $images = [];
    private string $buf = '';
    private float $y;

    public function __construct(private string $title = 'Document')
    {
        $this->newPage();
    }

    public function y(): float
    {
        return $this->y;
    }

    public function moveDown(float $by): void
    {
        $this->y -= $by;
    }

    /** Starts a new page if less than $need points remain above the bottom margin. */
    public function ensure(float $need): void
    {
        if ($this->y - $need < self::MARGIN + 20) {
            $this->newPage();
        }
    }

    public function newPage(): void
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = self::H - self::MARGIN;
    }

    /** Approximate Helvetica advance width in points (digits and common punctuation are exact; letters are close enough for wrapping and right alignment). */
    public static function width(string $text, float $size, bool $bold = false): float
    {
        $text = self::encode($text);
        $w = 0.0;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            $w += match (true) {
                $c === ' ' => 278,
                ctype_digit($c) => 556,
                strpbrk($c, 'ijl.,:;\'|!') !== false => 250,
                strpbrk($c, 'ft()[]-/\\') !== false => 300,
                strpbrk($c, 'mwMW%@') !== false => 830,
                ctype_upper($c) => $bold ? 740 : 680,
                default => $bold ? 580 : 540,
            };
        }

        return $w / 1000 * $size;
    }

    /** @return list<string> */
    public static function wrap(string $text, float $maxWidth, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($text)) ?: [''] as $para) {
            $cur = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && self::width($try, $size, $bold) > $maxWidth) {
                    $lines[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
            }
            $lines[] = $cur;
        }

        return $lines;
    }

    /** Draws one line of text at x (left edge) or, with $align 'right', ending at x. y is the baseline. */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $align = 'left', float|array $gray = 0.0): void
    {
        if ($align === 'right') {
            $x -= self::width($text, $size, $bold);
        } elseif ($align === 'center') {
            $x -= self::width($text, $size, $bold) / 2;
        }
        $this->buf .= sprintf("BT /%s %.2F Tf %s %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, self::color($gray), $x, $y, self::escape(self::encode($text)));
    }

    /** Writes wrapped text at the cursor and moves the cursor down. */
    public function paragraph(string $text, float $size = 10, bool $bold = false, ?float $width = null, float $x = self::MARGIN, float $lead = 1.4, float $gray = 0.0): void
    {
        $width ??= self::W - 2 * self::MARGIN - ($x - self::MARGIN);
        foreach (self::wrap($text, $width, $size, $bold) as $line) {
            $this->ensure($size * $lead);
            $this->y -= $size * $lead;
            $this->text($x, $this->y, $line, $size, $bold, 'left', $gray);
        }
    }

    public function rule(float $x1, float $x2, float $y, float $width = 0.5, float $gray = 0.6): void
    {
        $this->buf .= sprintf("%.2F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $gray, $width, $x1, $y, $x2, $y);
    }

    /** @param float|array{0:float,1:float,2:float} $gray a gray level (0 black .. 1 white) or an [r, g, b] colour with parts 0..1 */
    public function fill(float $x, float $y, float $w, float $h, float|array $gray): void
    {
        $this->buf .= sprintf("%s %.2F %.2F %.2F %.2F re f\n", self::color($gray), $x, $y, $w, $h);
    }

    /** An outlined rectangle. @param float|array{0:float,1:float,2:float} $gray */
    public function box(float $x, float $y, float $w, float $h, float|array $gray = 0.8, float $width = 0.6): void
    {
        $this->buf .= sprintf("%s %.2F w %.2F %.2F %.2F %.2F re S\n", self::color($gray, true), $width, $x, $y, $w, $h);
    }

    /** Draws a QR code (a matrix of dark/light modules) with its lower-left corner at x, y and a side of $size points. @param list<list<bool>> $matrix */
    public function qr(array $matrix, float $x, float $y, float $size, float|array $gray = 0.0): void
    {
        $n = count($matrix);
        $cell = $size / $n;
        $this->buf .= self::color($gray) . "\n";
        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $dark) {
                if ($dark) {
                    $this->buf .= sprintf("%.3F %.3F %.3F %.3F re f\n", $x + $c * $cell, $y + $size - ($r + 1) * $cell, $cell + 0.02, $cell + 0.02);
                }
            }
        }
    }

    /**
     * Turns a PNG into a drawable image (needs PHP's GD; null without it). Transparent areas are flattened onto $background so no soft mask is needed.
     *
     * @param array{0:int,1:int,2:int} $background 0..255
     * @return array{w:int,h:int,data:string}|null
     */
    public static function imageFromPng(string $png, array $background = [255, 255, 255]): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($png);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, $background[0], $background[1], $background[2]));
        imagealphablending($flat, true);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($flat, $x, $y);
                $raw .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
            }
        }

        return ['w' => $w, 'h' => $h, 'data' => (string) gzcompress($raw, 6)];
    }

    /** Draws an image from imageFromPng() in the box x, y (lower-left), $w by $h points. @param array{w:int,h:int,data:string} $img */
    public function image(array $img, float $x, float $y, float $w, float $h): void
    {
        $this->images[] = $img;
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, $y, count($this->images));
    }

    /** @param float|array{0:float,1:float,2:float} $c */
    private static function color(float|array $c, bool $stroke = false): string
    {
        if (is_array($c)) {
            return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg');
        }

        return sprintf('%.2F %s', $c, $stroke ? 'G' : 'g');
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->buf;
        $total = count($pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $total; $i++) {
            $kids[] = (6 + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Title (' . self::escape(self::encode($this->title)) . ') /Producer (Krazy Integrated Solutions) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        foreach ($pages as $i => $content) {
            $footer = sprintf("BT /F1 8 Tf 0.45 g %.2F %.2F Td (%s) Tj ET\n", self::W / 2 - 20, 24, self::escape('Page ' . ($i + 1) . ' of ' . $total));
            $stream = $content . $footer;
            $pageNo = 6 + $i * 2;
            $xo = '';
            foreach ($this->images as $k => $_) {
                $xo .= ' /Im' . ($k + 1) . ' ' . (6 + $total * 2 + $k) . ' 0 R';
            }
            $objs[$pageNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo === '' ? '' : ' /XObject <<' . $xo . ' >>') . ' >> /Contents ' . ($pageNo + 1) . ' 0 R >>';
            $objs[$pageNo + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        }
        foreach ($this->images as $k => $img) {
            $objs[6 + $total * 2 + $k] = '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
        }
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }

    private static function encode(string $text): string
    {
        $text = str_replace(['₹', "\u{2013}", "\u{2014}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2022}", "\u{00A0}"], ['Rs. ', '-', '-', "'", "'", '"', '"', '-', ' '], $text);
        $enc = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $enc === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '' : $enc;
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }
}
