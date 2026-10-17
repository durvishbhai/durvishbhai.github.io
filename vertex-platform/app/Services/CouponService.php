<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Module 26: campaigns and coupon codes.
 *
 *  - A coupon is a percentage (basis points, optionally capped) or a fixed amount, with an optional validity window, minimum spend, eligible services,
 *    a total usage limit and a per-client limit. Codes are upper case and unique.
 *  - A coupon is APPLIED to a draft invoice (the discount is calculated on the spend after any manual discount) and REDEEMED when the invoice is issued:
 *    the limits are re-checked under a row lock so two invoices cannot use the last redemption. Voiding the invoice releases the redemption.
 *  - Editing a coupon never changes an invoice that already used it (the discount is stored on the invoice).
 *  - Coupons discount; they never create money: the discount can never exceed the spend.
 */
final class CouponService
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT c.*, g.name AS campaign_name, g.public_id AS campaign_public_id FROM coupons c LEFT JOIN campaigns g ON g.id = c.campaign_id WHERE c.public_id = ?', [$publicId]) : null;
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    // ------------------------------------------------------------------------------------------------ coupons

    /** @param array<string,mixed> $in @return array{public_id:string} */
    public static function create(int $actorId, array $in): array
    {
        Gate::authorize('coupons.manage', $actorId);
        $v = self::validated($in, null);
        $pub = uuid_hex();
        Database::transaction(function () use ($actorId, $v, $pub): void {
            if (Database::one('SELECT 1 AS x FROM coupons WHERE code = ?', [$v['code']]) !== null) {
                throw new ValidationException(['A coupon with the code ' . $v['code'] . ' already exists.']);
            }
            Database::run(
                "INSERT INTO coupons (public_id, code, campaign_id, description, discount_type, percent_bps, amount_minor, max_discount_minor, min_spend_minor, eligible_services, valid_from, valid_until, max_uses, max_uses_per_client, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $v['code'], $v['campaign_id'], $v['description'], $v['type'], $v['percent_bps'], $v['amount_minor'], $v['max_discount_minor'], $v['min_spend_minor'], $v['eligible'], $v['valid_from'], $v['valid_until'], $v['max_uses'], $v['max_per_client'], $actorId],
            );
            Audit::record('coupon.created', 'coupon', Database::insertId(), [], ['code' => $v['code'], 'type' => $v['type'], 'percent_bps' => $v['percent_bps'], 'amount_minor' => $v['amount_minor']], [], $actorId);
        });

        return ['public_id' => $pub];
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $coupon, array $in): void
    {
        Gate::authorize('coupons.manage', $actorId);
        $v = self::validated($in, $coupon);
        Database::transaction(function () use ($actorId, $coupon, $v): void {
            Database::run(
                'UPDATE coupons SET campaign_id = ?, description = ?, discount_type = ?, percent_bps = ?, amount_minor = ?, max_discount_minor = ?, min_spend_minor = ?, eligible_services = ?, valid_from = ?, valid_until = ?, max_uses = ?, max_uses_per_client = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$v['campaign_id'], $v['description'], $v['type'], $v['percent_bps'], $v['amount_minor'], $v['max_discount_minor'], $v['min_spend_minor'], $v['eligible'], $v['valid_from'], $v['valid_until'], $v['max_uses'], $v['max_per_client'], $coupon['id']],
            );
            Audit::record('coupon.updated', 'coupon', (int) $coupon['id'], ['type' => $coupon['discount_type'], 'percent_bps' => $coupon['percent_bps'], 'amount_minor' => $coupon['amount_minor']], ['type' => $v['type'], 'percent_bps' => $v['percent_bps'], 'amount_minor' => $v['amount_minor']], ['code' => $coupon['code']], $actorId);
        });
    }

    public static function setStatus(int $actorId, array $coupon, string $status): void
    {
        Gate::authorize('coupons.manage', $actorId);
        if (!in_array($status, ['active', 'paused'], true)) {
            throw new HttpException(422);
        }
        Database::run('UPDATE coupons SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $coupon['id']]);
        Audit::record('coupon.' . $status, 'coupon', (int) $coupon['id'], ['status' => $coupon['status']], ['status' => $status], ['code' => $coupon['code']], $actorId);
    }

    /** @param array<string,mixed> $in @param array<string,mixed>|null $existing */
    private static function validated(array $in, ?array $existing): array
    {
        $errors = [];
        $get = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $code = $existing['code'] ?? self::normaliseCode((string) ($in['code'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,29}$/', $code)) {
            $errors[] = 'The code must be 3 to 30 letters, digits, dashes or underscores.';
        }
        $type = $get(static fn () => FieldRules::choice($in['discount_type'] ?? '', ['percent' => 'Percentage', 'fixed' => 'Fixed amount'], 'discount type'));
        $bps = null;
        $amount = null;
        $cap = null;
        if ($type === 'percent') {
            $pct = str_replace(',', '.', trim((string) ($in['percent'] ?? '')));
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pct) || (float) $pct <= 0 || (float) $pct > 100) {
                $errors[] = 'The percentage must be above 0 and at most 100 (two decimals at most).';
            } else {
                $bps = (int) round((float) $pct * 100);
            }
            $cap = $get(static fn () => FieldRules::moneyMinor($in['max_discount'] ?? '', 'Maximum discount', false));
        } elseif ($type === 'fixed') {
            $amount = $get(static fn () => FieldRules::moneyMinor($in['amount'] ?? '', 'Discount amount', true));
            if ($amount !== null && $amount < 100) {
                $errors[] = 'The discount amount must be at least 1.00.';
            }
        }
        $minSpend = (int) $get(static fn () => FieldRules::moneyMinor($in['min_spend'] ?? '', 'Minimum spend', false));
        $from = $get(static fn () => FieldRules::date($in['valid_from'] ?? '', 'Valid from', false));
        $until = $get(static fn () => FieldRules::date($in['valid_until'] ?? '', 'Valid until', false));
        if (is_string($from) && is_string($until) && $until < $from) {
            $errors[] = 'Valid until cannot be before valid from.';
        }
        $services = (array) Config::get('workflow.services');
        $eligible = [];
        foreach ((array) ($in['eligible_services'] ?? []) as $s) {
            if (is_string($s) && isset($services[$s])) {
                $eligible[$s] = $s;
            }
        }
        $maxUses = self::optionalInt($in['max_uses'] ?? '', 'Total uses', $errors);
        $maxPer = self::optionalInt($in['max_uses_per_client'] ?? '', 'Uses per client', $errors);
        $campaign = null;
        $cid = trim((string) ($in['campaign'] ?? ''));
        if ($cid !== '') {
            $campaign = Database::one('SELECT id FROM campaigns WHERE public_id = ?', [$cid]);
            if ($campaign === null) {
                $errors[] = 'Choose an existing campaign.';
            }
        }
        $desc = $get(static fn () => FieldRules::line($in['description'] ?? '', 'Description', 2, 255, false));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['code' => $code, 'type' => $type, 'percent_bps' => $bps, 'amount_minor' => $amount, 'max_discount_minor' => $cap, 'min_spend_minor' => $minSpend, 'valid_from' => $from, 'valid_until' => $until,
            'eligible' => $eligible === [] ? null : implode(',', $eligible), 'max_uses' => $maxUses, 'max_per_client' => $maxPer, 'campaign_id' => $campaign['id'] ?? null, 'description' => is_string($desc) ? $desc : null];
    }

    /** @param list<string> $errors */
    private static function optionalInt(mixed $v, string $label, array &$errors): ?int
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!ctype_digit($s) || (int) $s < 1 || (int) $s > 1_000_000) {
            $errors[] = "{$label} must be a whole number of 1 or more, or empty for no limit.";

            return null;
        }

        return (int) $s;
    }

    // ------------------------------------------------------------------------------------------------ using a coupon

    /**
     * Checks a code for a client and a spend and returns the discount. Throws a ValidationException that says exactly why it cannot be used.
     *
     * @return array{coupon:array<string,mixed>,discount_minor:int}
     */
    public static function evaluate(string $code, int $clientId, int $spendMinor, ?string $serviceCategory, ?int $ignoreInvoiceId = null): array
    {
        $code = self::normaliseCode($code);
        $c = Database::one('SELECT * FROM coupons WHERE code = ?', [$code]);
        if ($c === null) {
            throw new ValidationException(["The coupon code {$code} does not exist."]);
        }
        if ($c['status'] !== 'active') {
            throw new ValidationException(["The coupon {$code} is paused."]);
        }
        $today = AppTime::today();
        if ($c['valid_from'] !== null && $today < $c['valid_from']) {
            throw new ValidationException(["The coupon {$code} is valid from " . $c['valid_from'] . '.']);
        }
        if ($c['valid_until'] !== null && $today > $c['valid_until']) {
            throw new ValidationException(["The coupon {$code} expired on " . $c['valid_until'] . '.']);
        }
        if ($spendMinor < (int) $c['min_spend_minor']) {
            throw new ValidationException(["The coupon {$code} needs a spend of at least ₹" . FieldRules::formatMinor((int) $c['min_spend_minor']) . '.']);
        }
        if ($c['eligible_services'] !== null && $c['eligible_services'] !== '' && !in_array((string) $serviceCategory, explode(',', (string) $c['eligible_services']), true)) {
            throw new ValidationException(["The coupon {$code} does not apply to this service."]);
        }
        $ignore = $ignoreInvoiceId ?? 0;
        if ($c['max_uses'] !== null) {
            $used = (int) Database::one('SELECT COUNT(*) AS n FROM coupon_redemptions WHERE coupon_id = ? AND released_at IS NULL AND invoice_id <> ?', [$c['id'], $ignore])['n'];
            if ($used >= (int) $c['max_uses']) {
                throw new ValidationException(["The coupon {$code} has been used the maximum number of times."]);
            }
        }
        if ($c['max_uses_per_client'] !== null) {
            $mine = (int) Database::one('SELECT COUNT(*) AS n FROM coupon_redemptions WHERE coupon_id = ? AND client_id = ? AND released_at IS NULL AND invoice_id <> ?', [$c['id'], $clientId, $ignore])['n'];
            if ($mine >= (int) $c['max_uses_per_client']) {
                throw new ValidationException(["This client has already used the coupon {$code} the maximum number of times."]);
            }
        }
        if ($c['discount_type'] === 'percent') {
            $d = intdiv($spendMinor * (int) $c['percent_bps'], 10000);
            if ($c['max_discount_minor'] !== null) {
                $d = min($d, (int) $c['max_discount_minor']);
            }
        } else {
            $d = (int) $c['amount_minor'];
        }
        $d = max(0, min($d, $spendMinor));
        if ($d < 1) {
            throw new ValidationException(["The coupon {$code} gives no discount on this amount."]);
        }

        return ['coupon' => $c, 'discount_minor' => $d];
    }

    /** Called inside the invoice-issue transaction: locks the coupon, re-checks every limit and records the redemption. */
    public static function redeem(array $invoice, int $actorId): void
    {
        if ($invoice['coupon_id'] === null) {
            return;
        }
        Database::one('SELECT id FROM coupons WHERE id = ? FOR UPDATE', [$invoice['coupon_id']]);
        $spend = (int) $invoice['subtotal_minor'] - (int) $invoice['discount_minor'];
        $service = $invoice['project_id'] === null ? null : (Database::one('SELECT service_category FROM projects WHERE id = ?', [$invoice['project_id']])['service_category'] ?? null);
        try {
            $r = self::evaluate((string) $invoice['coupon_code'], (int) $invoice['client_id'], $spend, $service, (int) $invoice['id']);
        } catch (ValidationException $e) {
            throw new ValidationException([...$e->errors, 'Remove the coupon from the draft (or fix the problem) and issue again.']);
        }
        if ($r['discount_minor'] !== (int) $invoice['coupon_discount_minor']) {
            throw new ValidationException(['The coupon discount changed since the draft was saved. Save the draft again, then issue it.']);
        }
        Database::run('INSERT INTO coupon_redemptions (coupon_id, invoice_id, client_id, discount_minor, redeemed_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$invoice['coupon_id'], $invoice['id'], $invoice['client_id'], $r['discount_minor']]);
        Audit::record('coupon.redeemed', 'coupon', (int) $invoice['coupon_id'], [], ['discount_minor' => $r['discount_minor']], ['invoice_id' => (int) $invoice['id'], 'code' => $invoice['coupon_code']], $actorId);
    }

    /** A voided invoice gives its redemption back. */
    public static function release(int $invoiceId, string $reason): void
    {
        $n = Database::run('UPDATE coupon_redemptions SET released_at = UTC_TIMESTAMP(), release_reason = ? WHERE invoice_id = ? AND released_at IS NULL', [mb_substr($reason, 0, 120), $invoiceId])->rowCount();
        if ($n > 0) {
            Audit::record('coupon.released', 'invoice', $invoiceId, [], [], ['reason' => 'invoice voided'], null);
        }
    }

    // ------------------------------------------------------------------------------------------------ lists and reports

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 25): array
    {
        Gate::authorize('coupons.view', $userId);
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(c.code LIKE ? OR c.description LIKE ? OR g.name LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['campaign'] ?? ''))) {
            $w[] = 'g.public_id = ?';
            $p[] = $f['campaign'];
        }
        $cond = implode(' AND ', $w);
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM coupons c LEFT JOIN campaigns g ON g.id = c.campaign_id WHERE {$cond}", $p)['n'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT c.public_id, c.code, c.description, c.discount_type, c.percent_bps, c.amount_minor, c.valid_from, c.valid_until, c.max_uses, c.status, g.name AS campaign_name,
                    (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id AND r.released_at IS NULL) AS used,
                    (SELECT COALESCE(SUM(r.discount_minor), 0) FROM coupon_redemptions r WHERE r.coupon_id = c.id AND r.released_at IS NULL) AS discounted_minor
               FROM coupons c LEFT JOIN campaigns g ON g.id = c.campaign_id WHERE {$cond} ORDER BY c.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $p,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function redemptions(array $coupon): array
    {
        return Database::all(
            "SELECT r.discount_minor, r.redeemed_at, r.released_at, i.public_id AS invoice_public_id, i.reference AS invoice_reference, i.status AS invoice_status, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name
               FROM coupon_redemptions r JOIN invoices i ON i.id = r.invoice_id JOIN clients c ON c.id = r.client_id WHERE r.coupon_id = ? ORDER BY r.id DESC LIMIT 100",
            [$coupon['id']],
        );
    }

    // ------------------------------------------------------------------------------------------------ campaigns

    /** @return list<array<string,mixed>> */
    public static function campaigns(): array
    {
        return Database::all(
            "SELECT g.public_id, g.name, g.description, g.starts_on, g.ends_on, g.status,
                    (SELECT COUNT(*) FROM coupons c WHERE c.campaign_id = g.id) AS coupons,
                    (SELECT COUNT(*) FROM coupon_redemptions r JOIN coupons c ON c.id = r.coupon_id WHERE c.campaign_id = g.id AND r.released_at IS NULL) AS redemptions,
                    (SELECT COALESCE(SUM(r.discount_minor), 0) FROM coupon_redemptions r JOIN coupons c ON c.id = r.coupon_id WHERE c.campaign_id = g.id AND r.released_at IS NULL) AS discounted_minor,
                    (SELECT COALESCE(SUM(i.total_minor - i.credited_minor), 0) FROM coupon_redemptions r JOIN coupons c ON c.id = r.coupon_id JOIN invoices i ON i.id = r.invoice_id WHERE c.campaign_id = g.id AND r.released_at IS NULL AND i.status <> 'void') AS invoiced_minor
               FROM campaigns g ORDER BY g.id DESC",
        );
    }

    /** @param array<string,mixed> $in */
    public static function saveCampaign(int $actorId, ?array $existing, array $in): string
    {
        Gate::authorize('coupons.manage', $actorId);
        $errors = [];
        $name = null;
        try {
            $name = FieldRules::line($in['name'] ?? '', 'Campaign name', 3, 120);
            $desc = FieldRules::line($in['description'] ?? '', 'Description', 2, 500, false);
            $from = FieldRules::date($in['starts_on'] ?? '', 'Starts on', false);
            $to = FieldRules::date($in['ends_on'] ?? '', 'Ends on', false);
            if ($from !== null && $to !== null && $to < $from) {
                $errors[] = 'The campaign cannot end before it starts.';
            }
            $status = FieldRules::choice($in['status'] ?? 'active', ['active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended'], 'status', false, 'active');
        } catch (ValidationException $e) {
            $errors = [...$errors, ...$e->errors];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $dup = Database::one('SELECT id FROM campaigns WHERE name = ? AND id <> ?', [$name, $existing['id'] ?? 0]);
        if ($dup !== null) {
            throw new ValidationException(['A campaign with that name already exists.']);
        }
        if ($existing === null) {
            $pub = uuid_hex();
            Database::run('INSERT INTO campaigns (public_id, name, description, starts_on, ends_on, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$pub, $name, $desc, $from, $to, $status, $actorId]);
            Audit::record('campaign.created', 'campaign', Database::insertId(), [], ['name' => $name], [], $actorId);

            return $pub;
        }
        Database::run('UPDATE campaigns SET name = ?, description = ?, starts_on = ?, ends_on = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$name, $desc, $from, $to, $status, $existing['id']]);
        Audit::record('campaign.updated', 'campaign', (int) $existing['id'], ['name' => $existing['name'], 'status' => $existing['status']], ['name' => $name, 'status' => $status], [], $actorId);

        return (string) $existing['public_id'];
    }
}
