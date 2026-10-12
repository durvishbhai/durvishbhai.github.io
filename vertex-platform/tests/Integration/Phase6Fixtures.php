<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\AppTime;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CommissionRules;
use Vertex\Services\CommissionService;
use Vertex\Services\PayoutService;
use Vertex\Services\ReferralService;

/**
 * Extra people and helpers for the Phase 6 suites (use together with Phase3Fixtures and call setUpPhase6() after setUpPhase3()):
 *   owner (super admin: the second approver)  finance, finance2 (record payments, pay payouts)  partner, partner2 (approved partners)  prospect data.
 * The contract value of the Alpha project is set to INR 1,00,000 (10,000,000 paise) and all rates are the shipped defaults.
 */
trait Phase6Fixtures
{
    private int $owner;
    private int $finance;
    private int $finance2;
    private int $partner2;
    /** @var array<string,mixed> */
    private array $partnerRow;
    /** @var array<string,mixed> */
    private array $partner2Row;
    private int $payN = 0;

    protected function setUpPhase6(): void
    {
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->finance = $this->makeUser('fin@x.test', ['finance_staff']);
        $this->finance2 = $this->makeUser('fin2@x.test', ['finance_staff']);
        $this->partner2 = $this->makeUser('partner2@x.test', ['referral_partner']);
        $this->partnerRow = $this->makePartner($this->partner);
        $this->partner2Row = $this->makePartner($this->partner2);
        Database::run('UPDATE projects SET approved_amount_minor = 10000000 WHERE id = ?', [$this->alpha['id']]);
        Database::run('UPDATE projects SET approved_amount_minor = 5000000 WHERE id = ?', [$this->bravo['id']]);
        Config::set('referrals.rules', Config::get('referrals.rules')); // shipped defaults; individual tests change rules through CommissionRules::save
    }

    /** @return array<string,mixed> a referral_partners row for an existing active user */
    protected function makePartner(int $userId): array
    {
        Database::run("INSERT INTO referral_partners (public_id, reference, user_id, status, created_at, updated_at) VALUES (?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [uuid_hex(), \Vertex\Services\CrmReference::next('partner'), $userId]);

        return Database::one('SELECT * FROM referral_partners WHERE id = ?', [Database::insertId()]);
    }

    /** @return array<string,mixed> */
    protected function refRow(string $publicId): array
    {
        return ReferralService::find($publicId);
    }

    /**
     * A credited, converted referral for $partnerUser on the Alpha project (so payments on it can earn commission).
     *
     * @return array<string,mixed>
     */
    protected function creditedReferral(?int $partnerUser = null, string $email = 'lead@prospect.test', ?array $project = null): array
    {
        $partnerUser ??= $this->partner;
        $res = ReferralService::submit($partnerUser, ['prospect_name' => 'Pat Prospect', 'email' => $email, 'phone' => '', 'business_name' => 'Prospect Co', 'service' => 'website_development',
            'description' => 'Needs a company website with a catalogue.', 'estimated_budget' => '100000', 'notes' => '', 'token' => bin2hex(random_bytes(16))]);
        $r = $this->refRow($res['public_id']);
        ReferralService::setStatus($this->admin, $r, 'under_review');
        ReferralService::setStatus($this->admin, $this->refRow($r['public_id']), 'approved');
        ReferralService::decideAttribution($this->admin, $this->refRow($r['public_id']), 'credited', 'Prospect was new to us; the partner introduced them.');
        ReferralService::markConverted($this->admin, $this->refRow($r['public_id']), ($project ?? $this->alpha)['public_id']);

        return $this->refRow($r['public_id']);
    }

    /**
     * Records AND verifies a payment (recorded by finance, verified by admin: two people).
     *
     * @return array<string,mixed> the client_payments row
     */
    protected function pay(string $role, string $rupees, string $tax = '0', ?array $project = null, ?string $extRef = null): array
    {
        $r = ClientPaymentService::record($this->finance, ['project' => ($project ?? $this->alpha)['public_id'], 'role' => $role, 'gross' => $rupees, 'tax' => $tax, 'method' => 'bank_transfer',
            'external_ref' => $extRef ?? 'UTR' . (++$this->payN) . bin2hex(random_bytes(3)), 'received_on' => AppTime::today(), 'invoice_ref' => 'INV-TEST-' . $this->payN]);
        ClientPaymentService::verify($this->admin, ClientPaymentService::find($r['public_id']));

        return ClientPaymentService::find($r['public_id']);
    }

    /** @return array<string,mixed>|null the newest entry of a milestone for a referral */
    protected function entry(array $referral, string $milestone): ?array
    {
        return Database::one('SELECT * FROM commission_entries WHERE referral_id = ? AND milestone = ? ORDER BY seq DESC LIMIT 1', [$referral['id'], $milestone]);
    }

    /** Approves an entry as the owner (a different person from the payment recorder/verifier). @return array<string,mixed> the refreshed entry */
    protected function approveEntry(array $entry): array
    {
        CommissionService::approve($this->owner, $entry);

        return Database::one('SELECT * FROM commission_entries WHERE id = ?', [$entry['id']]);
    }

    protected function useRules(array $over): void
    {
        $cur = CommissionRules::current()['rules'];
        CommissionRules::save($this->owner, array_replace($cur, $over, [
            'full_payment_tolerance_minor' => (string) (($over['full_payment_tolerance_minor'] ?? $cur['full_payment_tolerance_minor']) / 100),
            'min_payout_minor' => (string) (($over['min_payout_minor'] ?? $cur['min_payout_minor']) / 100),
        ]), 'test rules');
    }

    protected function partnerBalance(?array $partnerRow = null): array
    {
        return CommissionService::balance((int) (($partnerRow ?? $this->partnerRow)['id']));
    }

    protected function saveDetails(int $userId, array $partnerRow): void
    {
        \Vertex\Services\PartnerService::saveDetails($userId, $partnerRow, ['method' => 'bank_transfer', 'account_name' => 'Priya Partner', 'identifier' => '1234567890123456', 'bank_name' => 'Test Bank, IFSC TEST0001']);
    }

    /** Requests a payout of $rupees as the partner. @return array{public_id:string,reference:string,created:bool} */
    protected function requestPayout(string $rupees, ?int $userId = null): array
    {
        return PayoutService::request($userId ?? $this->partner, ['amount' => $rupees, 'notes' => '', 'token' => bin2hex(random_bytes(16))]);
    }
}
