<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Services\Audit;
use Vertex\Services\AppTime;
use Vertex\Services\BackupService;
use Vertex\Services\CommissionService;
use Vertex\Services\PayoutService;
use Vertex\Services\ReferralService;
use Vertex\Services\RenewalPricing;
use Vertex\Services\RenewalScheduler;
use Vertex\Services\RenewalService;

/**
 * The modules that handle money and customer data, exercised together as one business story, ending with a disaster-recovery round trip:
 * referral partner -> credited, converted referral -> verified client payments -> 10% + 5% commission -> approved payout paid by a different person
 * -> the same client's domain renewal (approval, invoice, payments, provider confirmation) -> backup -> everything deleted -> restored.
 * A second client and a second partner must see none of it; no existing project or payment record may change.
 */
final class FullPlatformJourneyTest extends DatabaseTestCase
{
    use Phase3Fixtures;
    use Phase6Fixtures;

    private string $uploads;
    private string $dir;
    private string $origUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->setUpPhase6();
        $this->uploads = sys_get_temp_dir() . '/vis-jr-up-' . bin2hex(random_bytes(4));
        $this->dir = sys_get_temp_dir() . '/vis-jr-bk-' . bin2hex(random_bytes(4));
        mkdir($this->uploads, 0750, true);
        $this->origUploads = (string) Config::get('uploads.root');
        Config::set('uploads.root', $this->uploads);
        Config::set('mail.driver', 'none');
    }

    protected function tearDown(): void
    {
        Config::set('uploads.root', $this->origUploads);
        foreach ([$this->uploads, $this->uploads . '-restored', $this->dir] as $d) {
            if (is_dir($d)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($d);
            }
        }
        $this->tearDownPhase3();
    }

    public function testReferralToCommissionToPayoutAndRenewalTogetherThenBackupAndRestore(): void
    {
        $projectBefore = Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]);

        // ---- referral -> verified payments -> 10% then 5% commission (cumulative: total 15% of what was received)
        $ref = $this->creditedReferral();
        $this->assertSame('converted', $ref['status']);
        $this->pay('advance', '50000');
        $adv = $this->approveEntry($this->entry($ref, 'advance'));
        $this->assertSame(500000, (int) $adv['amount_minor'], '10% of INR 50,000');
        $this->assertSame('pending_eligibility', $this->entry($ref, 'full')['status'], 'no second milestone before full payment');
        $this->pay('balance', '50000');
        $full = $this->approveEntry($this->entry($ref, 'full'));
        $this->assertSame(1000000, (int) $full['amount_minor'], '15% of INR 1,00,000 minus the INR 5,000 already earned');
        $this->assertSame(1500000, $this->partnerBalance()['available'], 'INR 15,000 payable in total');

        // ---- payout: requested by the partner, approved by the admin, paid and recorded by finance
        $this->saveDetails($this->partner, $this->partnerRow);
        $req = $this->requestPayout('15000');
        $po = PayoutService::find($req['public_id']);
        PayoutService::approve($this->admin, $po, 'ok');
        $po = PayoutService::find($req['public_id']);
        $this->assertSame('approved', $po['status']);
        PayoutService::recordPayment($this->finance, $po, ['method' => 'bank_transfer', 'reference' => 'UTR-JOURNEY-1', 'date' => AppTime::today(), 'amount' => '15000', 'note' => '']);
        $this->assertSame('paid', PayoutService::find($req['public_id'])['status']);
        $bal = $this->partnerBalance();
        $this->assertSame(0, $bal['available'] + $bal['reserved']);
        $this->assertSame(1500000, $bal['paid']);

        // ---- the same client's domain: approval, invoice, two payments, provider confirmation
        RenewalPricing::add($this->owner, ['service_type' => 'domain', 'period_months' => '12', 'base' => '1000', 'markup' => '0', 'tax_percent' => '18', 'effective_from' => '2020-01-01', 'note' => 'Standard']);
        $clientPublic = (string) Database::one('SELECT public_id FROM clients WHERE user_id = ?', [$this->client])['public_id'];
        $expires = AppTime::todayPlus(7);
        $r = RenewalService::create($this->manager, $clientPublic, ['service_type' => 'domain', 'identifier' => 'journey-client.com', 'provider' => 'Registrar', 'activated_on' => AppTime::todayPlus(-358),
            'expires_on' => $expires, 'period_months' => '12', 'internal_notes' => '', 'client_notes' => '', 'client_reminders' => '1', 'project' => $this->alpha['public_id']]);
        $s = RenewalService::find($r['public_id']);
        $this->assertSame('awaiting_admin_review', $s['status'], 'seven days out: Admin review is due');
        RenewalService::approve($this->admin, $s, ['price_mode' => 'list', 'period_months' => '12']);
        RenewalService::recordInvoice($this->manager, RenewalService::find($r['public_id']), ['invoice_ref' => 'INV-J-1', 'amount' => '1180.00']);
        RenewalService::markInvoiceSent($this->manager, RenewalService::find($r['public_id']));
        RenewalService::recordPayment($this->manager, RenewalService::find($r['public_id']), ['amount' => '500', 'method' => 'upi', 'reference' => 'UPI-J-1', 'received_on' => AppTime::today()]);
        RenewalService::recordPayment($this->manager, RenewalService::find($r['public_id']), ['amount' => '680', 'method' => 'upi', 'reference' => 'UPI-J-2', 'received_on' => AppTime::today()]);
        $this->assertSame($expires, RenewalService::find($r['public_id'])['expires_on'], 'paid but not renewed');
        RenewalService::startProviderRenewal($this->manager, RenewalService::find($r['public_id']));
        $newExpiry = AppTime::todayPlus(372);
        RenewalService::recordProviderConfirmation($this->admin, RenewalService::find($r['public_id']), ['confirmation_ref' => 'REG-J-1', 'confirmed_on' => AppTime::today(), 'new_expiry' => $newExpiry]);
        $this->assertSame('renewed', RenewalService::find($r['public_id'])['status']);
        RenewalScheduler::run();

        // ---- isolation: another client and another partner see none of it
        $this->assertSame([], RenewalService::forClient($this->client2));
        try {
            RenewalService::requireOwn($this->client2, $r['public_id']);
            $this->fail('a second client must not open the first client\'s service');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        try {
            ReferralService::requireOwn($this->partner2, $ref['public_id']);
            $this->fail('a second partner must not open the first partner\'s referral');
        } catch (HttpException $e) {
            $this->assertContains($e->status, [403, 404]);
        }
        $this->assertSame(0, $this->partnerBalance($this->partner2Row)['available'] + $this->partnerBalance($this->partner2Row)['paid']);

        // ---- existing records were never touched by any of this
        $this->assertSame($projectBefore, Database::one('SELECT * FROM projects WHERE id = ?', [$this->alpha['id']]));
        $this->assertTrue(Audit::verify()['ok']);

        // ---- disaster recovery: back up, destroy everything, restore, compare
        $snapshot = [
            'commissions' => Database::all('SELECT reference, milestone, status, amount_minor FROM commission_entries ORDER BY id'),
            'payouts' => Database::all('SELECT reference, status, amount_minor, payment_reference FROM payout_requests ORDER BY id'),
            'renewal' => Database::one('SELECT reference, status, expires_on, cycle_no FROM renewal_services'),
            'payments' => Database::all('SELECT reference, gross_minor, status FROM client_payments ORDER BY id'),
            'details_enc_unreadable_as_text' => (string) Database::one('SELECT payout_details_enc FROM referral_partners WHERE id = ?', [$this->partnerRow['id']])['payout_details_enc'],
        ];
        $b = BackupService::create($this->dir);
        $this->assertTrue(BackupService::verify($b['path'])['ok']);
        self::dropAllTables();
        $res = BackupService::restore($b['path'], (string) Config::get('database.database'), $this->uploads . '-restored');
        $this->assertSame([], $res['problems']);
        $this->assertTrue($res['row_counts_match']);
        $this->assertSame($snapshot['commissions'], Database::all('SELECT reference, milestone, status, amount_minor FROM commission_entries ORDER BY id'));
        $this->assertSame($snapshot['payouts'], Database::all('SELECT reference, status, amount_minor, payment_reference FROM payout_requests ORDER BY id'));
        $this->assertSame($snapshot['renewal'], Database::one('SELECT reference, status, expires_on, cycle_no FROM renewal_services'));
        $this->assertSame($snapshot['payments'], Database::all('SELECT reference, gross_minor, status FROM client_payments ORDER BY id'));
        $this->assertSame($snapshot['details_enc_unreadable_as_text'], (string) Database::one('SELECT payout_details_enc FROM referral_partners WHERE id = ?', [$this->partnerRow['id']])['payout_details_enc']);
        $this->assertTrue(Audit::verify()['ok'], 'the audit chain verifies after the restore');
        // and with the same APP_KEY the encrypted payout details are still readable after the restore
        $partner = \Vertex\Services\PartnerService::forUser($this->partner);
        $this->assertNotNull($partner);
        $paidPayout = PayoutService::find($po['public_id']);
        $this->assertNotNull(\Vertex\Services\PartnerService::revealForPayout($this->finance, $paidPayout));
    }
}
