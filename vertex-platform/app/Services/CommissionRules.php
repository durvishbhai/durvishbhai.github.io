<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * The commission rules in force. Versioned and append-only: saving creates a new version, the previous one stays for the record, and every
 * ledger entry stores the version and a snapshot it was calculated under. Changing a rule never rewrites an existing entry.
 */
final class CommissionRules
{
    public const KEYS = ['advance_rate_bps', 'full_rate_bps', 'formula_mode', 'advance_min_bps', 'exclude_tax', 'net_of_refunds', 'include_change_requests',
        'full_payment_tolerance_minor', 'advance_without_completion', 'payable_hold_days', 'min_payout_minor', 'separation_of_duties'];

    /** @return array{version:int,rules:array<string,mixed>,source:string} */
    public static function current(): array
    {
        $defaults = (array) Config::get('referrals.rules');
        $row = Database::one('SELECT version, rules_json FROM commission_rules WHERE current_flag = 1');
        if ($row === null) {
            return ['version' => 0, 'rules' => $defaults, 'source' => 'defaults'];
        }

        return ['version' => (int) $row['version'], 'rules' => array_replace($defaults, (array) json_decode((string) $row['rules_json'], true)), 'source' => 'database'];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> validated rules */
    public static function validate(array $in): array
    {
        $errors = [];
        $bps = static function (string $k, string $label, int $max) use ($in, &$errors): int {
            $v = $in[$k] ?? '';
            if (!is_numeric($v) || (string) (int) $v !== trim((string) $v) || (int) $v < 0 || (int) $v > $max) {
                $errors[] = "{$label} must be a whole number of basis points between 0 and {$max} (100 = 1%).";

                return 0;
            }

            return (int) $v;
        };
        $rules = [
            'advance_rate_bps' => $bps('advance_rate_bps', 'Advance milestone rate', 5000),
            'full_rate_bps' => $bps('full_rate_bps', 'Full-payment milestone rate', 5000),
            'advance_min_bps' => $bps('advance_min_bps', 'Minimum advance (share of contract value)', 10000),
            'payable_hold_days' => $bps('payable_hold_days', 'Hold days', 365),
        ];
        $mode = (string) ($in['formula_mode'] ?? '');
        if (!in_array($mode, ['cumulative', 'per_milestone'], true)) {
            $errors[] = 'Choose a formula mode.';
        }
        $policy = (string) ($in['advance_without_completion'] ?? '');
        if (!in_array($policy, ['keep', 'reverse'], true)) {
            $errors[] = 'Choose what happens to the advance milestone when a project is cancelled.';
        }
        foreach (['full_payment_tolerance_minor' => 'Full-payment tolerance', 'min_payout_minor' => 'Minimum payout'] as $k => $label) {
            try {
                $rules[$k] = (int) FieldRules::moneyMinor($in[$k] ?? '0', $label, false);
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        foreach (['exclude_tax', 'net_of_refunds', 'include_change_requests', 'separation_of_duties'] as $k) {
            $rules[$k] = !empty($in[$k]) && $in[$k] !== '0' && $in[$k] !== 'false';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $rules + ['formula_mode' => $mode, 'advance_without_completion' => $policy];
    }

    /** @param array<string,mixed> $in @return int new version (or the current one when nothing changed) */
    public static function save(int $actorId, array $in, string $reason): int
    {
        Gate::authorize('commissions.rules.manage', $actorId);
        $rules = self::validate($in);
        $reason = (string) FieldRules::line($reason, 'Reason for the change', 5, 255);

        return Database::transaction(function () use ($actorId, $rules, $reason): int {
            Database::one('SELECT id FROM commission_rules WHERE current_flag = 1 FOR UPDATE');
            $cur = self::current();
            $normalise = static function (array $r): array {
                $r = array_intersect_key($r, array_flip(self::KEYS));
                ksort($r);

                return $r;
            };
            if ($normalise($cur['rules']) == $normalise($rules)) {
                return $cur['version'];
            }
            Database::run('UPDATE commission_rules SET current_flag = NULL WHERE current_flag = 1');
            $v = (int) Database::one('SELECT COALESCE(MAX(version), 0) AS v FROM commission_rules')['v'] + 1;
            Database::run('INSERT INTO commission_rules (version, rules_json, reason, current_flag, created_by, created_at) VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())',
                [$v, json_encode($normalise($rules), JSON_THROW_ON_ERROR), $reason, $actorId]);
            Audit::record('commission.rules_changed', 'commission_rules', Database::insertId(), $normalise($cur['rules']), $normalise($rules), ['version' => $v, 'previous_version' => $cur['version'], 'reason_recorded' => true], $actorId);

            return $v;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function history(): array
    {
        return Database::all('SELECT r.version, r.rules_json, r.reason, r.created_at, u.name AS author FROM commission_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.version DESC');
    }
}
