<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * A small QR Code encoder (ISO/IEC 18004, byte mode, error correction level M, versions 1-25) that draws an SVG on the server, so the page needs no
 * script, no third-party service and the payment text never leaves our own response. The algorithm and tables are the standard ones; the unit test decodes
 * the generated pictures with a real QR reader.
 */
final class QrCode
{
    /** error-correction codewords per block, level M, index = version */
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28];
    /** number of blocks, level M, index = version */
    private const BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21];
    public const MAX_VERSION = 25;

    private int $size;
    /** @var list<list<bool>> */
    private array $m = [];
    /** @var list<list<bool>> */
    private array $fn = [];

    /** @param list<int> $codewords */
    private function __construct(private int $ver, array $codewords)
    {
        $this->size = $ver * 4 + 17;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->m;
        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
            $this->applyMask($mask); // undo (xor)
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /** @return string an SVG picture of the code, black on white with the 4-module quiet zone scanners need */
    public static function svg(string $text, string $label = 'QR code'): string
    {
        $q = self::encode($text);
        $n = $q->size;
        $quiet = 4;
        $d = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if (!$q->m[$y][$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $n && $q->m[$y][$x]) {
                    $x++;
                }
                $d .= 'M' . ($start + $quiet) . ',' . ($y + $quiet) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }
        $t = $n + 2 * $quiet;

        return '<svg class="qr-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" shape-rendering="crispEdges">'
            . '<rect width="' . $t . '" height="' . $t . '" fill="#ffffff"/><path d="' . $d . '" fill="#000000"/></svg>';
    }

    /** @return list<list<bool>> the module matrix (true = dark) */
    public static function matrix(string $text): array
    {
        return self::encode($text)->m;
    }

    private static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        if ($bytes === []) {
            throw new \InvalidArgumentException('Nothing to encode.');
        }
        $ver = 0;
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $cap = intdiv(self::rawModules($v), 8) - self::ECC_PER_BLOCK[$v] * self::BLOCKS[$v];
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * count($bytes) <= $cap * 8) {
                $ver = $v;
                break;
            }
        }
        if ($ver === 0) {
            throw new \InvalidArgumentException('The text is too long for a QR code here.');
        }
        $cap = intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::BLOCKS[$ver];
        $bits = [];
        $put = static function (int $val, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put(count($bytes), $ver <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            $put($b, 8);
        }
        $put(0, min(4, $cap * 8 - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) {
                $v = ($v << 1) | $bits[$i + $j];
            }
            $data[] = $v;
        }
        for ($pad = 0xEC; count($data) < $cap; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        return new self($ver, self::addEccAndInterleave($data, $ver));
    }

    private static function rawModules(int $ver): int
    {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $a = intdiv($ver, 7) + 2;
            $r -= (25 * $a - 10) * $a - 55;
            if ($ver >= 7) {
                $r -= 36;
            }
        }

        return $r;
    }

    /** @param list<int> $data @return list<int> */
    private static function addEccAndInterleave(array $data, int $ver): array
    {
        $blocks = self::BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $raw = intdiv(self::rawModules($ver), 8);
        $short = $blocks - $raw % $blocks;
        $shortLen = intdiv($raw, $blocks);
        $div = self::rsDivisor($eccLen);
        $out = [];
        $k = 0;
        for ($i = 0; $i < $blocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $short ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $div);
            if ($i < $short) {
                $dat[] = 0; // placeholder so every block has the same length for interleaving
            }
            $out[] = array_merge($dat, $ecc);
        }
        $res = [];
        for ($i = 0, $n = count($out[0]); $i < $n; $i++) {
            foreach ($out as $j => $blk) {
                if ($i !== $shortLen - $eccLen || $j >= $short) {
                    $res[] = $blk[$i];
                }
            }
        }

        return $res;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }

        return $r;
    }

    /** @param list<int> $data @param list<int> $div @return list<int> */
    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $coef) {
                $r[$i] ^= self::gfMul($coef, $factor);
            }
        }

        return $r;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions();
        $na = count($pos);
        for ($i = 0; $i < $na; $i++) {
            for ($j = 0; $j < $na; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->ver === 1) {
            return [];
        }
        $na = intdiv($this->ver, 7) + 2;
        $step = $this->ver === 32 ? 26 : (int) (ceil(($this->ver * 4 + 4) / ($na * 2 - 2)) * 2);
        $res = [6];
        for ($pos = $this->size - 7; count($res) < $na; $pos -= $step) {
            array_splice($res, 1, 0, [$pos]);
        }

        return $res;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // error correction level M has the format value 0
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        $n = $this->size;
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->ver < 7) {
            return;
        }
        $rem = $this->ver;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $dark);
            $this->set($b, $a, $dark);
        }
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->fn[$y][$x]) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** The usual penalty rules (runs, 2x2 blocks, finder-like patterns, dark/light balance); only used to choose the mask. */
    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $lines = [];
        for ($y = 0; $y < $n; $y++) {
            $lines[] = implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $this->m[$y]));
        }
        for ($x = 0; $x < $n; $x++) {
            $col = '';
            for ($y = 0; $y < $n; $y++) {
                $col .= $this->m[$y][$x] ? '1' : '0';
            }
            $lines[] = $col;
        }
        foreach ($lines as $l) {
            if (preg_match_all('/(.)\1{4,}/', $l, $mm)) {
                foreach ($mm[0] as $run) {
                    $score += 3 + (strlen($run) - 5);
                }
            }
            $score += 40 * (preg_match_all('/(?=00001011101|10111010000)/', $l));
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->m[$y][$x];
                if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $dark = 0;
        foreach ($this->m as $row) {
            $dark += count(array_filter($row));
        }
        $total = $n * $n;
        $score += (int) ((ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10);

        return $score;
    }
}
