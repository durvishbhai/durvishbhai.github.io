<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * Dependency-free SVG charts (module 9). Pure SVG with CSS classes (the page's Content-Security-Policy forbids inline styles and scripts), a title and a
 * description for screen readers, and every number is also in the table next to the chart, so nothing depends on colour or on seeing the picture.
 */
final class Chart
{
    private const SERIES = 6;

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Vertical bars. @param list<string> $labels @param list<int|float> $values */
    public static function bars(array $labels, array $values, string $title, string $unit = '', int $w = 640, int $h = 240): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        $max = max(1, (int) ceil(max($values)));
        $left = 56;
        $bottom = 34;
        $top = 14;
        $plotW = $w - $left - 12;
        $plotH = $h - $top - $bottom;
        $slot = $plotW / $n;
        $bw = max(4, min(48, $slot * 0.6));
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::e($title) . '"><title>' . self::e($title) . '</title>';
        foreach ([0, 0.5, 1] as $f) {
            $y = $top + $plotH - $plotH * $f;
            $svg .= '<line class="chart-grid" x1="' . $left . '" x2="' . ($w - 12) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>';
            $svg .= '<text class="chart-text" x="' . ($left - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . self::e(self::short($max * $f, $unit)) . '</text>';
        }
        foreach ($values as $i => $v) {
            $bh = $plotH * ($v / $max);
            $x = $left + $slot * $i + ($slot - $bw) / 2;
            $svg .= '<rect class="chart-bar" x="' . round($x, 1) . '" y="' . round($top + $plotH - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round(max(0, $bh), 1) . '" rx="2"><title>' . self::e($labels[$i] . ': ' . self::short((float) $v, $unit)) . '</title></rect>';
            if ($n <= 14 || $i % (int) ceil($n / 12) === 0) {
                $svg .= '<text class="chart-text" x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 12) . '" text-anchor="middle">' . self::e(mb_substr($labels[$i], 0, 9)) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /** Horizontal bars with the value at the end of each bar. @param list<string> $labels @param list<int|float> $values */
    public static function hbars(array $labels, array $values, string $title, string $unit = '', int $w = 640): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        $max = max(1, (int) ceil(max($values)));
        $row = 26;
        $h = $n * $row + 10;
        $left = 150;
        $plotW = $w - $left - 90;
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::e($title) . '"><title>' . self::e($title) . '</title>';
        foreach ($values as $i => $v) {
            $y = 5 + $i * $row;
            $bw = $plotW * ($v / $max);
            $svg .= '<text class="chart-text" x="' . ($left - 8) . '" y="' . ($y + 15) . '" text-anchor="end">' . self::e(mb_substr($labels[$i], 0, 22)) . '</text>';
            $svg .= '<rect class="chart-bar chart-s' . ($i % self::SERIES) . '" x="' . $left . '" y="' . $y . '" width="' . round(max(0, $bw), 1) . '" height="18" rx="2"><title>' . self::e($labels[$i] . ': ' . self::short((float) $v, $unit)) . '</title></rect>';
            $svg .= '<text class="chart-text" x="' . round($left + $bw + 6, 1) . '" y="' . ($y + 14) . '">' . self::e(self::short((float) $v, $unit)) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** Donut with a legend. @param list<string> $labels @param list<int|float> $values */
    public static function donut(array $labels, array $values, string $title, int $size = 200): string
    {
        $total = array_sum($values);
        if ($total <= 0) {
            return '';
        }
        $r = 70.0;
        $c = 2 * M_PI * $r;
        $svg = '<svg class="chart chart-donut" viewBox="0 0 ' . ($size + 260) . ' ' . $size . '" role="img" aria-label="' . self::e($title) . '"><title>' . self::e($title) . '</title>';
        $offset = 0.0;
        foreach ($values as $i => $v) {
            $len = $c * ($v / $total);
            if ($len > 0) {
                $svg .= '<circle class="chart-ring chart-r' . ($i % self::SERIES) . '" cx="100" cy="100" r="' . $r . '" fill="none" stroke-width="34" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 100 100)"><title>' . self::e($labels[$i] . ': ' . $v) . '</title></circle>';
            }
            $offset += $len;
        }
        $svg .= '<text class="chart-total" x="100" y="106" text-anchor="middle">' . (int) $total . '</text>';
        foreach ($labels as $i => $l) {
            $y = 24 + $i * 22;
            if ($y > $size - 8) {
                break;
            }
            $svg .= '<rect class="chart-bar chart-s' . ($i % self::SERIES) . '" x="215" y="' . ($y - 11) . '" width="12" height="12" rx="2"/><text class="chart-text" x="234" y="' . $y . '">' . self::e(mb_substr($l, 0, 26) . ': ' . $values[$i]) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** Small trend line. @param list<int|float> $values */
    public static function line(array $values, string $title, int $w = 640, int $h = 160): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max(1, max($values));
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round(10 + ($w - 20) * $i / ($n - 1), 1) . ',' . round($h - 14 - ($h - 28) * ($v / $max), 1);
        }

        return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::e($title) . '"><title>' . self::e($title) . '</title><polyline class="chart-line" fill="none" stroke-width="2.5" points="' . implode(' ', $pts) . '"/></svg>';
    }

    private static function short(float $v, string $unit): string
    {
        if ($unit === 'inr') {
            $r = $v / 100;
            if ($r >= 10_000_000) {
                return '₹' . round($r / 10_000_000, 1) . 'Cr';
            }
            if ($r >= 100_000) {
                return '₹' . round($r / 100_000, 1) . 'L';
            }
            if ($r >= 1000) {
                return '₹' . round($r / 1000, 1) . 'k';
            }

            return '₹' . (int) round($r);
        }

        return $unit === '' ? (string) (int) round($v) : (int) round($v) . ' ' . $unit;
    }
}
