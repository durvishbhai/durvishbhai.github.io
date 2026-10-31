<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Priority/urgency surcharge RULES (configuration only: nothing here charges money or touches any quotation).
 *
 *  - A rule exists per (service category, priority). service '*' is the default for every service; a service-specific rule,
 *    when present, wins.
 *  - A surcharge is none, a FIXED amount (integer paise) or a PERCENTAGE of the project's base price (basis points,
 *    2 decimals of a percent, rounded half-up to the nearest paisa).
 *  - Rules are VERSIONED: a change inserts a new row and supersedes the old one; history is never edited or deleted.
 *  - Shipped defaults are all "none". The business's real rates are not invented (decision D-33).
 */
final class PricingService
{
    public const MAX_FIXED_MINOR = 10_000_000_000;   // ₹10 crore
    public const MAX_PERCENT_BPS = 50_000;           // 500 %

    /** Surcharge in paise for a rule row (or null = no rule) on a base price in paise. @param array<string,mixed>|null $rule */
    public static function surchargeFor(?array $rule, int $baseMinor): int
    {
        if ($rule === null || $baseMinor < 0) {
            return 0;
        }

        return match ($rule['surcharge_type']) {
            'fixed' => (int) $rule['fixed_minor'],
            'percent' => self::percentOf($baseMinor, (int) $rule['percent_bps']),
            default => 0,
        };
    }

    /** base * bps / 10000, rounded half-up, without overflowing 64-bit integers for realistic prices. */
    public static function percentOf(int $baseMinor, int $bps): int
    {
        $q = intdiv($baseMinor, 10000);
        $r = $baseMinor % 10000;

        return $q * $bps + intdiv($r * $bps + 5000, 10000);
    }

    /** The rule that applies to a service and priority: the service-specific one, else the default. @return array<string,mixed>|null */
    public static function ruleFor(?string $service, string $priority): ?array
    {
        if ($service !== null && $service !== '') {
            $specific = Database::one('SELECT * FROM priority_pricing_rules WHERE service_category = ? AND priority = ? AND current_flag = 1', [$service, $priority]);
            if ($specific !== null) {
                return $specific;
            }
        }

        return Database::one("SELECT * FROM priority_pricing_rules WHERE service_category = '*' AND priority = ? AND current_flag = 1", [$priority]);
    }

    /** All current rules keyed [service][priority]. @return array<string,array<string,array<string,mixed>>> */
    public static function currentRules(): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM priority_pricing_rules WHERE current_flag = 1 ORDER BY service_category, FIELD(priority,\'low\',\'normal\',\'high\',\'urgent\')') as $r) {
            $out[$r['service_category']][$r['priority']] = $r;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first */
    public static function history(int $limit = 60): array
    {
        return Database::all(
            'SELECT r.*, u.name AS author FROM priority_pricing_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT ' . max(1, min(500, $limit)),
        );
    }

    /**
     * Creates a new version of one rule. Needs `pricing.manage` (a sensitive permission; the controller also demands the
     * actor's password). Identical values create nothing.
     *
     * @param array<string,mixed> $in service, priority, type (none|fixed|percent), fixed (rupees), percent (e.g. 12.5), reason
     * @return bool true when a new version was written
     */
    public static function setRule(int $actorId, array $in): bool
    {
        Gate::authorize('pricing.manage', $actorId);
        $services = ['*' => 'All services'] + (array) Config::get('workflow.services');
        $service = (string) FieldRules::choice($in['service'] ?? '', $services, 'service');
        $priority = (string) FieldRules::choice($in['priority'] ?? '', (array) Config::get('workflow.priorities'), 'priority');
        $type = (string) FieldRules::choice($in['type'] ?? '', ['none' => 'None', 'fixed' => 'Fixed', 'percent' => 'Percentage'], 'surcharge type');
        $reason = (string) FieldRules::line($in['reason'] ?? '', 'Reason for the change', 5, 255);
        $fixed = 0;
        $bps = 0;
        if ($type === 'fixed') {
            $fixed = (int) FieldRules::moneyMinor($in['fixed'] ?? '', 'Fixed amount', true);
            if ($fixed < 1 || $fixed > self::MAX_FIXED_MINOR) {
                throw new ValidationException(['The fixed amount must be between ₹0.01 and ₹10,00,00,000.']);
            }
        } elseif ($type === 'percent') {
            $bps = self::parsePercent($in['percent'] ?? '');
        }

        return Database::transaction(function () use ($actorId, $service, $priority, $type, $fixed, $bps, $reason): bool {
            $cur = Database::one('SELECT * FROM priority_pricing_rules WHERE service_category = ? AND priority = ? AND current_flag = 1 FOR UPDATE', [$service, $priority]);
            if ($cur !== null && $cur['surcharge_type'] === $type && (int) $cur['fixed_minor'] === $fixed && (int) $cur['percent_bps'] === $bps) {
                return false;
            }
            $version = (int) (Database::one('SELECT COALESCE(MAX(version), 0) AS v FROM priority_pricing_rules WHERE service_category = ? AND priority = ?', [$service, $priority])['v']) + 1;
            if ($cur !== null) {
                Database::run('UPDATE priority_pricing_rules SET current_flag = NULL, superseded_at = UTC_TIMESTAMP() WHERE id = ?', [$cur['id']]);
            }
            Database::run(
                'INSERT INTO priority_pricing_rules (service_category, priority, surcharge_type, fixed_minor, percent_bps, version, current_flag, reason, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())',
                [$service, $priority, $type, $fixed, $bps, $version, $reason, $actorId],
            );
            Audit::record(
                'pricing.rule_changed',
                'pricing_rule',
                Database::insertId(),
                $cur === null ? [] : ['type' => $cur['surcharge_type'], 'fixed_minor' => (int) $cur['fixed_minor'], 'percent_bps' => (int) $cur['percent_bps'], 'version' => (int) $cur['version']],
                ['type' => $type, 'fixed_minor' => $fixed, 'percent_bps' => $bps, 'version' => $version],
                ['service' => $service, 'priority' => $priority],
                $actorId,
            );

            return true;
        });
    }

    /** "12.5" / "12.50" / "12" -> basis points. */
    public static function parsePercent(mixed $v): int
    {
        $s = str_replace(['%', ' '], '', FieldRules::clean($v));
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) {
            throw new ValidationException(['Enter a percentage like 10 or 12.5.']);
        }
        [$whole, $frac] = array_pad(explode('.', $s), 2, '0');
        $bps = (int) $whole * 100 + (int) str_pad($frac, 2, '0');
        if ($bps < 1 || $bps > self::MAX_PERCENT_BPS) {
            throw new ValidationException(['The percentage must be between 0.01 and 500.']);
        }

        return $bps;
    }

    /** Human description of a rule row, e.g. "12.5% of the base price" / "₹2,500.00 fixed". @param array<string,mixed>|null $rule */
    public static function describe(?array $rule): string
    {
        if ($rule === null || $rule['surcharge_type'] === 'none') {
            return 'No surcharge';
        }
        if ($rule['surcharge_type'] === 'fixed') {
            return '₹' . FieldRules::formatMinor((int) $rule['fixed_minor']) . ' fixed';
        }
        $bps = (int) $rule['percent_bps'];

        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') . '% of the base price';
    }

    /** @throws HttpException */
    public static function authorizeView(int $userId): void
    {
        Gate::authorize('pricing.view', $userId);
    }
}
