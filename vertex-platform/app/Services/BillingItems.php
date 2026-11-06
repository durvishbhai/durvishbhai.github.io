<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\ValidationException;

/** Line items shared by quotations and invoices: description, quantity (up to two decimals) and unit price in rupees; totals are exact integer paise, never tax. */
final class BillingItems
{
    public const MAX_ITEMS = 50;

    /**
     * @param mixed $rows list of ['description'=>..,'quantity'=>..,'unit'=>..]; completely empty rows are skipped
     * @return array{items:list<array{description:string,quantity_x100:int,unit_minor:int,total_minor:int}>,subtotal_minor:int}
     */
    public static function parse(mixed $rows): array
    {
        $errors = [];
        $items = [];
        $n = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $d = trim((string) ($row['description'] ?? ''));
            $q = trim((string) ($row['quantity'] ?? ''));
            $u = trim((string) ($row['unit'] ?? ''));
            if ($d === '' && $q === '' && $u === '') {
                continue;
            }
            $n++;
            if ($n > self::MAX_ITEMS) {
                $errors[] = 'A document can have at most ' . self::MAX_ITEMS . ' lines.';
                break;
            }
            try {
                $desc = (string) FieldRules::line($d, "Line {$n} description", 2, 300);
                if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $q === '' ? '1' : $q) || (float) ($q === '' ? '1' : $q) <= 0) {
                    throw new ValidationException(["Line {$n}: the quantity must be a positive number with at most two decimals."]);
                }
                $qx = (int) round((float) ($q === '' ? '1' : $q) * 100);
                $unit = (int) FieldRules::moneyMinor($u, "Line {$n} price", true);
                if ($unit < 1) {
                    throw new ValidationException(["Line {$n}: the price must be more than zero."]);
                }
                $prod = $unit * $qx;
                if ($prod % 100 !== 0) {
                    throw new ValidationException(["Line {$n}: quantity × price must come to a whole number of paise. Adjust the quantity or price."]);
                }
                $items[] = ['description' => $desc, 'quantity_x100' => $qx, 'unit_minor' => $unit, 'total_minor' => intdiv($prod, 100)];
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['items' => $items, 'subtotal_minor' => array_sum(array_column($items, 'total_minor'))];
    }

    /** @param list<array{description:string,quantity_x100:int,unit_minor:int,total_minor:int}> $items */
    public static function replace(string $table, string $fk, int $id, array $items): void
    {
        \Vertex\Core\Database::run("DELETE FROM {$table} WHERE {$fk} = ?", [$id]);
        foreach ($items as $i => $it) {
            \Vertex\Core\Database::run("INSERT INTO {$table} ({$fk}, position, description, quantity_x100, unit_minor, total_minor) VALUES (?, ?, ?, ?, ?, ?)", [$id, $i + 1, $it['description'], $it['quantity_x100'], $it['unit_minor'], $it['total_minor']]);
        }
    }

    /** "2" / "2.5" for display. */
    public static function qty(int $x100): string
    {
        return $x100 % 100 === 0 ? (string) intdiv($x100, 100) : rtrim(rtrim(number_format($x100 / 100, 2, '.', ''), '0'), '.');
    }
}
