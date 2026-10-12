<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * The renewal price list. A price is: net = base + markup - discount; tax = floor(net x tax_bps / 10000); total = net + tax. Rows are append-only: the row
 * in force for a service type and period on a given day is the one with the latest effective_from not after that day. A renewal cycle snapshots the price
 * it was approved at, so changing the list never changes an approved or invoiced renewal.
 */
final class RenewalPricing
{
    /** @return array{net_minor:int,tax_minor:int,total_minor:int,tax_bps:int,base_minor:int,markup_minor:int,discount_minor:int,effective_from:string}|null */
    public static function lookup(string $type, int $periodMonths, ?string $on = null): ?array
    {
        $row = Database::one('SELECT * FROM renewal_prices WHERE service_type = ? AND period_months = ? AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1', [$type, $periodMonths, $on ?? AppTime::today()]);
        if ($row === null) {
            return null;
        }
        $net = max(0, (int) $row['base_minor'] + (int) $row['markup_minor'] - (int) $row['discount_minor']);
        $tax = Money::bps($net, (int) $row['tax_bps']);

        return ['net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $net + $tax, 'tax_bps' => (int) $row['tax_bps'], 'base_minor' => (int) $row['base_minor'], 'markup_minor' => (int) $row['markup_minor'],
            'discount_minor' => (int) $row['discount_minor'], 'effective_from' => (string) $row['effective_from']];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT r.*, u.name AS author FROM renewal_prices r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.service_type, r.period_months, r.effective_from DESC, r.id DESC');
    }

    /** @param array<string,mixed> $in */
    public static function add(int $actorId, array $in): void
    {
        Gate::authorize('renewals.prices', $actorId);
        $errors = [];
        $type = null;
        try {
            $type = FieldRules::choice($in['service_type'] ?? '', (array) Config::get('renewals.types'), 'service type');
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        $period = (int) ($in['period_months'] ?? 0);
        if (!in_array($period, (array) Config::get('renewals.periods'), true)) {
            $errors[] = 'Choose one of the standard renewal periods.';
        }
        $money = [];
        foreach (['base' => ['Base price', true], 'markup' => ['Markup or fee', false], 'discount' => ['Discount', false]] as $k => [$label, $req]) {
            try {
                $money[$k] = (int) FieldRules::moneyMinor($in[$k] ?? '', $label, $req);
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
                $money[$k] = 0;
            }
        }
        $taxPct = trim((string) ($in['tax_percent'] ?? '0'));
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $taxPct) || (float) $taxPct > 50) {
            $errors[] = 'Tax must be a percentage between 0 and 50 with at most two decimals.';
            $taxPct = '0';
        }
        $from = null;
        try {
            $from = FieldRules::date($in['effective_from'] ?? '', 'Effective date', true);
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        if ($money['discount'] > $money['base'] + $money['markup']) {
            $errors[] = 'The discount cannot be larger than the base price plus markup.';
        }
        $note = null;
        try {
            $note = FieldRules::line($in['note'] ?? '', 'Note', 3, 255);
        } catch (ValidationException $e) {
            array_push($errors, ...$e->errors);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $bps = (int) round((float) $taxPct * 100);
        try {
            Database::transaction(function () use ($actorId, $type, $period, $money, $bps, $from, $note): void {
                Database::run('INSERT INTO renewal_prices (service_type, period_months, base_minor, markup_minor, discount_minor, tax_bps, effective_from, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$type, $period, $money['base'], $money['markup'], $money['discount'], $bps, $from, $note, $actorId]);
                Audit::record('renewal.price_added', 'renewal_price', Database::insertId(), [], ['service_type' => $type, 'period_months' => $period, 'base_minor' => $money['base'], 'markup_minor' => $money['markup'],
                    'discount_minor' => $money['discount'], 'tax_bps' => $bps, 'effective_from' => $from], [], $actorId);
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['A price for this type, period and effective date already exists. Choose a later effective date: prices are never edited in place.']);
            }
            throw $e;
        }
    }
}
