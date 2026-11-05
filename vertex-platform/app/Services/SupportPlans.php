<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Bug support after the free support period.
 *
 * Free support = the project's warranty period (6 months from final delivery by default). When it is over, for every completed project the client must choose:
 *   1. a bug-support SUBSCRIPTION (monthly or yearly): every bug, major or minor, is fixed while it is active; or
 *   2. PAY PER BUG: each bug is quoted at what it actually costs to solve and the client pays that quote.
 * Until a choice is made the client portal shows a notice that asks for it. A subscription is paid through an ordinary invoice (so receipts, Razorpay or UPI, coupons and
 * the audit trail all work as for any other payment) and becomes active the moment that invoice is paid. Nothing here charges anyone by itself.
 */
final class SupportPlans
{
    public const PLANS = ['monthly' => 'Monthly', 'yearly' => 'Yearly'];

    public static function enabled(): bool
    {
        return (bool) Config::get('support.plans_enabled', true);
    }

    public static function priceMinor(string $plan): int
    {
        $rupees = $plan === 'yearly' ? (int) Config::get('support.plan_yearly_rupees', 2000) : (int) Config::get('support.plan_monthly_rupees', 350);

        return max(1, $rupees) * 100;
    }

    public static function months(string $plan): int
    {
        return $plan === 'yearly' ? 12 : 1;
    }

    /** What paying yearly saves compared with twelve monthly payments. */
    public static function yearlySavingMinor(): int
    {
        return max(0, 12 * self::priceMinor('monthly') - self::priceMinor('yearly'));
    }

    /**
     * The last day of free support, or null when there is none to speak of (the warranty was recorded as not applicable or is suspended, or the project is not finished).
     * A recorded warranty decides; with none recorded the default period (6 months) counts from the day of final delivery (the project being completed).
     *
     * @param array<string,mixed> $project needs id, status, completed_at
     */
    public static function freeEnd(array $project): ?string
    {
        if (($project['status'] ?? '') !== 'completed') {
            return null;
        }
        $w = Database::one('SELECT eligibility, suspended, end_date FROM project_warranties WHERE project_id = ?', [$project['id']]);
        if ($w !== null) {
            return $w['eligibility'] === 'eligible' && (int) $w['suspended'] === 0 && $w['end_date'] !== null ? (string) $w['end_date'] : null;
        }
        $done = (string) ($project['completed_at'] ?? '');
        if ($done === '') {
            return null;
        }
        $start = (new \DateTimeImmutable($done, new \DateTimeZone('UTC')))->setTimezone(AppTime::zone())->format('Y-m-d');

        return WarrantyService::endDate($start, max(1, (int) Config::get('support.warranty_default_months', 6)));
    }

    /** @return array<string,mixed>|null the subscription period that covers today */
    public static function activePeriod(int $projectId, ?string $today = null): ?array
    {
        $today ??= AppTime::today();

        return Database::one("SELECT * FROM support_subscription_periods WHERE project_id = ? AND status = 'active' AND starts_on <= ? AND ends_on >= ? ORDER BY ends_on DESC LIMIT 1", [$projectId, $today, $today]);
    }

    public static function mode(int $projectId): ?string
    {
        $r = Database::one('SELECT mode FROM project_support WHERE project_id = ?', [$projectId]);

        return $r === null ? null : (string) $r['mode'];
    }

    /** @return array<string,mixed>|null a subscription invoice that was created but not paid yet */
    public static function awaiting(int $projectId): ?array
    {
        return Database::one(
            "SELECT p.*, i.public_id AS invoice_public_id, i.reference AS invoice_reference, i.total_minor AS invoice_total FROM support_subscription_periods p JOIN invoices i ON i.id = p.invoice_id
              WHERE p.project_id = ? AND p.status = 'awaiting_payment' AND i.status IN ('issued','partially_paid') ORDER BY p.id DESC LIMIT 1",
            [$projectId],
        );
    }

    /**
     * Where a project stands. needs_choice is true when the free support is over and the client has neither an active subscription nor chosen to pay per bug.
     *
     * @param array<string,mixed> $project
     * @return array{free_end:?string,free_over:bool,days_left:?int,active:?array<string,mixed>,mode:?string,needs_choice:bool,awaiting:?array<string,mixed>,label:string}
     */
    public static function state(array $project): array
    {
        $today = AppTime::today();
        $end = self::freeEnd($project);
        $over = $end !== null && $today > $end;
        $active = self::activePeriod((int) $project['id'], $today);
        $mode = self::mode((int) $project['id']);
        $need = self::enabled() && $over && $active === null && $mode !== 'per_bug' && ($project['archived_at'] ?? null) === null;
        if ($end === null) {
            $label = 'No free support period applies';
        } elseif (!$over) {
            $label = 'Free support until ' . local_date($end);
        } elseif ($active !== null) {
            $label = 'Bug-support subscription active until ' . local_date((string) $active['ends_on']);
        } elseif ($mode === 'per_bug') {
            $label = 'Pay per bug';
        } else {
            $label = 'Free support ended on ' . local_date($end) . ': a choice is needed';
        }

        return ['free_end' => $end, 'free_over' => $over, 'days_left' => $end !== null && !$over ? (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($end))->days : null,
            'active' => $active, 'mode' => $mode, 'needs_choice' => $need, 'awaiting' => self::awaiting((int) $project['id']), 'label' => $label];
    }

    /** The client's finished projects that still need a choice. @return list<array{project:array<string,mixed>,state:array<string,mixed>}> */
    public static function pendingChoices(int $userId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $rows = Database::all(
            "SELECT p.* FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.status = 'completed' AND p.archived_at IS NULL ORDER BY p.completed_at, p.id",
            [$userId],
        );
        $out = [];
        foreach ($rows as $p) {
            $s = self::state($p);
            if ($s['needs_choice']) {
                $out[] = ['project' => $p, 'state' => $s];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $project @return array<string,mixed> the project when it is the signed-in client's own */
    private static function own(int $userId, array $project): array
    {
        if (ProjectPolicy::level($userId, $project) !== 'own') {
            throw new HttpException(404);
        }

        return $project;
    }

    /** The client decides to pay for each bug separately. */
    public static function choosePerBug(int $userId, array $project): void
    {
        self::own($userId, $project);
        $s = self::state($project);
        if (!$s['free_over']) {
            throw new ValidationException(['Free support for this project is still running' . ($s['free_end'] !== null ? ' until ' . local_date($s['free_end']) : '') . '. You can choose when it ends.']);
        }
        Database::transaction(function () use ($userId, $project): void {
            Database::run("INSERT INTO project_support (project_id, mode, chosen_by, chosen_at, updated_at) VALUES (?, 'per_bug', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE mode = 'per_bug', chosen_by = VALUES(chosen_by), chosen_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()", [$project['id'], $userId]);
            Audit::record('support.per_bug_chosen', 'project', (int) $project['id'], [], ['mode' => 'per_bug'], [], $userId);
        });
        Notifier::send(self::team((int) $project['id']), 'support.choice', 'Support choice: pay per bug', ($project['client_name'] ?? 'A client') . ' chose to pay for each bug separately on ' . $project['title'] . '.', '/projects/' . $project['public_id'] . '/warranty', (int) $project['id'], 'project', (int) $project['id'], 'support:' . $project['id'] . ':per_bug:' . bin2hex(random_bytes(3)));
    }

    /** @return list<int> user ids of the people who should hear about a project's support */
    private static function team(int $projectId): array
    {
        return array_map('intval', array_column(Database::all('SELECT DISTINCT user_id FROM project_assignments WHERE project_id = ? AND revoked_at IS NULL', [$projectId]), 'user_id'));
    }

    /**
     * Starts a subscription purchase: makes an invoice for the plan (with the client's coupon, if any), issues it, and returns it so the client can pay in the portal.
     * An earlier unpaid subscription invoice for the same project is cancelled first.
     *
     * @return array{invoice_public_id:string}
     */
    public static function purchase(int $userId, array $project, string $plan, string $couponCode = ''): array
    {
        self::own($userId, $project);
        if (!self::enabled()) {
            throw new ValidationException(['Bug-support subscriptions are not available right now.']);
        }
        if (!isset(self::PLANS[$plan])) {
            throw new ValidationException(['Choose monthly or yearly.']);
        }
        $s = self::state($project);
        if (!$s['free_over'] && $s['active'] === null) {
            throw new ValidationException(['Free support for this project is still running' . ($s['free_end'] !== null ? ' until ' . local_date($s['free_end']) : '') . '. You can subscribe when it ends.']);
        }
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$project['client_id']]) ?? throw new HttpException(404);
        $couponCode = CouponService::normaliseCode($couponCode);
        $price = self::priceMinor($plan);
        $label = $plan === 'yearly' ? 'Yearly (12 months)' : 'Monthly (1 month)';

        return Database::transaction(function () use ($userId, $project, $plan, $couponCode, $client, $price, $label): array {
            foreach (Database::all("SELECT p.id, p.invoice_id FROM support_subscription_periods p WHERE p.project_id = ? AND p.status = 'awaiting_payment' FOR UPDATE", [$project['id']]) as $old) {
                $inv = Database::one('SELECT * FROM invoices WHERE id = ?', [$old['invoice_id']]);
                if ($inv !== null && in_array($inv['status'], ['issued', 'draft'], true) && Database::one("SELECT 1 AS x FROM client_payments WHERE invoice_id = ? AND status IN ('pending_verification','verified','reversed')", [$inv['id']]) === null) {
                    InvoiceService::voidSystem((int) $inv['id'], 'Replaced by a new bug-support choice');
                    Database::run("UPDATE support_subscription_periods SET status = 'cancelled' WHERE id = ?", [$old['id']]);
                } else {
                    throw new ValidationException(['You already have a subscription payment in progress for this project. Finish or wait for it to be verified first.']);
                }
            }
            $inv = InvoiceService::createSystem($client, [
                'title' => 'Bug support subscription, ' . self::PLANS[$plan] . ': ' . $project['title'],
                'project' => (string) $project['public_id'],
                'items' => [['description' => 'Bug support, ' . $label . ': every bug fixed, major or minor (' . $project['title'] . ')', 'quantity' => '1', 'unit' => number_format($price / 100, 2, '.', '')]],
                'coupon_code' => $couponCode,
                '_coupon_context' => 'subscription',
                'client_notes' => 'Your subscription starts as soon as this invoice is paid.',
            ], 'support_subscription');
            $row = Database::one('SELECT id FROM invoices WHERE public_id = ?', [$inv['public_id']]);
            Database::run("INSERT INTO support_subscription_periods (project_id, client_id, invoice_id, plan, price_minor, status, created_at) VALUES (?, ?, ?, ?, ?, 'awaiting_payment', UTC_TIMESTAMP())", [$project['id'], $client['id'], $row['id'], $plan, $price]);
            Audit::record('support.subscription_started', 'project', (int) $project['id'], [], ['plan' => $plan, 'price_minor' => $price], ['invoice_id' => (int) $row['id']], $userId);

            return ['invoice_public_id' => (string) $inv['public_id']];
        });
    }

    /** Called when an invoice becomes fully paid: a bug-support subscription invoice switches its period on. @param array<string,mixed> $invoice */
    public static function onInvoicePaid(array $invoice): void
    {
        $p = Database::one("SELECT * FROM support_subscription_periods WHERE invoice_id = ? AND status = 'awaiting_payment' FOR UPDATE", [$invoice['id']]);
        if ($p === null) {
            return;
        }
        $project = ProjectPolicy::findById((int) $p['project_id']);
        $today = AppTime::today();
        $start = $today;
        $free = $project === null ? null : self::freeEnd($project);
        if ($free !== null && $free >= $start) {
            $start = (new \DateTimeImmutable($free))->modify('+1 day')->format('Y-m-d');
        }
        $last = Database::one("SELECT MAX(ends_on) AS e FROM support_subscription_periods WHERE project_id = ? AND status = 'active'", [$p['project_id']]);
        if ($last !== null && $last['e'] !== null && $last['e'] >= $start) {
            $start = (new \DateTimeImmutable((string) $last['e']))->modify('+1 day')->format('Y-m-d');
        }
        $end = (new \DateTimeImmutable($start))->modify('+' . self::months((string) $p['plan']) . ' month')->modify('-1 day')->format('Y-m-d');
        Database::run("UPDATE support_subscription_periods SET status = 'active', starts_on = ?, ends_on = ?, activated_at = UTC_TIMESTAMP() WHERE id = ?", [$start, $end, $p['id']]);
        Database::run("INSERT INTO project_support (project_id, mode, chosen_by, chosen_at, updated_at) VALUES (?, 'subscription', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE mode = 'subscription', updated_at = UTC_TIMESTAMP()", [$p['project_id']]);
        Audit::record('support.subscription_activated', 'project', (int) $p['project_id'], [], ['plan' => $p['plan'], 'starts_on' => $start, 'ends_on' => $end], ['invoice_id' => (int) $invoice['id']], null);
        $uid = Database::one('SELECT user_id FROM clients WHERE id = ?', [$p['client_id']])['user_id'] ?? null;
        if ($uid && $project !== null) {
            Notifier::send([(int) $uid], 'support.activated', 'Bug support is active', 'Every bug on ' . $project['title'] . ' is covered until ' . local_date($end) . '.', '/my/support', (int) $p['project_id'], 'project', (int) $p['project_id'], 'support:' . $p['project_id'] . ':active:' . $p['id']);
        }
        if ($project !== null) {
            Notifier::send(self::team((int) $p['project_id']), 'support.activated', 'Bug-support subscription started', $project['title'] . ': ' . self::PLANS[(string) $p['plan']] . ' plan until ' . local_date($end) . '.', '/projects/' . $project['public_id'] . '/warranty', (int) $p['project_id'], 'project', (int) $p['project_id'], 'support:' . $p['project_id'] . ':active-team:' . $p['id']);
        }
    }

    /** Called when a paid subscription invoice is no longer fully paid (a refund or reversal): the period it bought stops. @param array<string,mixed> $invoice */
    public static function onInvoiceUnpaid(array $invoice): void
    {
        $n = Database::run("UPDATE support_subscription_periods SET status = 'cancelled' WHERE invoice_id = ? AND status = 'active'", [$invoice['id']])->rowCount();
        if ($n > 0) {
            Audit::record('support.subscription_cancelled', 'invoice', (int) $invoice['id'], [], [], ['reason' => 'payment no longer complete'], null);
        }
    }

    /** All finished projects with their support position, for the team. @return list<array<string,mixed>> */
    public static function report(string $filter = 'all'): array
    {
        $rows = Database::all(
            "SELECT p.*, c.company_name, c.contact_name AS client_name FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.status = 'completed' AND p.archived_at IS NULL ORDER BY p.completed_at DESC, p.id DESC LIMIT 500",
        );
        $out = [];
        foreach ($rows as $p) {
            $s = self::state($p);
            $kind = $s['free_end'] === null ? 'none' : (!$s['free_over'] ? 'free' : ($s['active'] !== null ? 'subscription' : ($s['mode'] === 'per_bug' ? 'per_bug' : 'choice')));
            if ($filter === 'all' || $filter === $kind) {
                $out[] = ['project' => $p, 'state' => $s, 'kind' => $kind];
            }
        }

        return $out;
    }
}
