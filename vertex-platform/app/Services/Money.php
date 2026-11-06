<?php

declare(strict_types=1);

namespace Vertex\Services;

/** Exact integer money arithmetic for amounts in paise. No floats, no bcmath/gmp requirement. */
final class Money
{
    /**
     * floor(a * b / c) without overflowing 64 bits for any a, b, c >= 0 whose RESULT fits in an int (double-and-add on b).
     */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        if ($c <= 0 || $a < 0 || $b < 0) {
            throw new \InvalidArgumentException('mulDiv needs a >= 0, b >= 0, c > 0.');
        }
        $q = intdiv($a, $c);
        $r = $a % $c;
        $result = $q * $b;
        // floor(r * b / c) with r < c: iterate over the bits of b, keeping value = qq * c + rem
        $qq = 0;
        $rem = 0;
        for ($bit = 62; $bit >= 0; $bit--) {
            $qq *= 2;
            $rem *= 2;
            if ($rem >= $c) {
                $rem -= $c;
                $qq++;
            }
            if (($b >> $bit) & 1) {
                $rem += $r;
                if ($rem >= $c) {
                    $rem -= $c;
                    $qq++;
                }
            }
        }

        return $result + $qq;
    }

    /** floor(amount * bps / 10000). */
    public static function bps(int $amount, int $bps): int
    {
        return self::mulDiv($amount, $bps, 10000);
    }
}
