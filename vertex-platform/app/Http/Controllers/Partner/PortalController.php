<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Partner;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CommissionRules;
use Vertex\Services\CommissionService;
use Vertex\Services\PartnerApplicationService;
use Vertex\Services\PartnerService;
use Vertex\Services\PayoutService;
use Vertex\Services\ReferralService;

/**
 * The Referral Partner's own portal. Every method derives the partner from the SIGNED-IN user (PartnerService::require); nothing here accepts a partner id.
 * Partners see their referrals, their commission ledger (milestone, rate, amount, plain-language status) and their payouts: never invoices, payments,
 * project prices, other partners, internal notes or the reason behind a hold.
 */
final class PortalController extends Controller
{
    private const PER_PAGE = 15;

    /** An applicant's own application status (also for people who are not partners yet). @param array<string,string> $params */
    public function application(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();

        return $this->page('partner/application', ['title' => 'Referral Partner application', 'applications' => PartnerApplicationService::forUser($uid), 'partner' => PartnerService::forUser($uid)]);
    }

    /** @param array<string,string> $params */
    public function dashboard(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $partner = PartnerService::require($uid);
        \Vertex\Core\Database::transaction(static fn () => CommissionService::releaseDue((int) $partner['id']));
        $page = $request->page();
        $f = ['q' => mb_substr($request->string('q'), 0, 100), 'status' => $request->string('status')];
        $refs = ReferralService::paginateOwn($uid, $f, $page, self::PER_PAGE);
        $payouts = PayoutService::paginateOwn($uid, 1, 5);

        return $this->page('partner/dashboard', [
            'title' => 'Partner portal', 'partner' => $partner, 'counts' => ReferralService::countsOwn($uid), 'balance' => CommissionService::balance((int) $partner['id']),
            'referrals' => $refs['rows'], 'total' => $refs['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== ''),
            'payouts' => $payouts['rows'], 'applications' => PartnerApplicationService::forUser($uid), 'canSubmit' => \Vertex\Services\Gate::can('referrals.submit', $uid), 'canPayout' => \Vertex\Services\Gate::can('payouts.request', $uid),
        ]);
    }

    /** @param array<string,string> $params */
    public function submitForm(Request $request, array $params = []): Response
    {
        PartnerService::require($this->actorId(), 'referrals.submit');
        $r = $this->page('partner/submit', ['title' => 'Submit a referral', 'token' => bin2hex(random_bytes(16)), 'services' => Config::get('workflow.services')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function submit(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['prospect_name', 'email', 'phone', 'business_name', 'service', 'description', 'estimated_budget', 'notes', 'token'] as $f) {
            $in[$f] = $request->string($f);
        }

        return $this->guarded(function () use ($in): Response {
            $res = ReferralService::submit($this->actorId(), $in);

            return $this->ok('/partner/r/' . $res['public_id'], $res['created'] ? "Thank you. Referral {$res['reference']} was submitted." : "Referral {$res['reference']} was already submitted.");
        }, '/partner/submit', $in);
    }

    /** @param array<string,string> $params */
    public function referral(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = ReferralService::requireOwn($uid, $params['ref'] ?? '');
        $entries = \Vertex\Core\Database::all('SELECT 1 FROM commission_entries WHERE referral_id = ? LIMIT 1', [$r['id']]);

        return $this->page('partner/referral', ['title' => $r['reference'], 'r' => ReferralService::partnerView($r), 'history' => ReferralService::historyFor($uid, $r, false), 'hasCommission' => $entries !== []]);
    }

    /** @param array<string,string> $params */
    public function commissions(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $partner = PartnerService::require($uid, 'commissions.view_own');
        \Vertex\Core\Database::transaction(static fn () => CommissionService::releaseDue((int) $partner['id']));
        $page = $request->page();
        $res = CommissionService::paginateOwn($uid, $page, self::PER_PAGE);
        $rules = CommissionRules::current()['rules'];

        return $this->page('partner/commissions', ['title' => 'My commissions', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => [], 'qs' => [],
            'balance' => CommissionService::balance((int) $partner['id']), 'advanceBps' => (int) $rules['advance_rate_bps'], 'fullBps' => (int) $rules['full_rate_bps'], 'mode' => (string) $rules['formula_mode']]);
    }

    /** @param array<string,string> $params */
    public function payouts(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $partner = PartnerService::require($uid, 'payouts.view_own');
        $page = $request->page();
        $res = PayoutService::paginateOwn($uid, $page, self::PER_PAGE);

        return $this->page('partner/payouts', ['title' => 'My payouts', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => [], 'qs' => [],
            'balance' => CommissionService::balance((int) $partner['id']), 'hasDetails' => $partner['payout_details_enc'] !== null, 'hint' => $partner['payout_details_hint']]);
    }

    /** @param array<string,string> $params */
    public function payoutForm(Request $request, array $params = []): Response
    {
        $partner = PartnerService::require($this->actorId(), 'payouts.request');
        \Vertex\Core\Database::transaction(static fn () => CommissionService::releaseDue((int) $partner['id']));
        $r = $this->page('partner/payout-new', ['title' => 'Request a payout', 'balance' => CommissionService::balance((int) $partner['id']), 'hasDetails' => $partner['payout_details_enc'] !== null,
            'hint' => $partner['payout_details_hint'], 'token' => bin2hex(random_bytes(16)), 'min' => (int) CommissionRules::current()['rules']['min_payout_minor']]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function payoutRequest(Request $request, array $params = []): Response
    {
        $in = ['amount' => $request->string('amount'), 'notes' => $request->string('notes'), 'token' => $request->string('token')];

        return $this->guarded(function () use ($in): Response {
            $res = PayoutService::request($this->actorId(), $in);

            return $this->ok('/partner/payouts/' . $res['public_id'], $res['created'] ? "Payout request {$res['reference']} was submitted. Nothing is paid until our team approves it and records the payment." : "Request {$res['reference']} was already submitted.");
        }, '/partner/payouts/new', $in);
    }

    /** @param array<string,string> $params */
    public function payout(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = PayoutService::requireOwn($uid, $params['payout'] ?? '');

        return $this->page('partner/payout', ['title' => $p['reference'], 'p' => PayoutService::partnerView($p), 'history' => PayoutService::history_for($p, false)]);
    }

    /** @param array<string,string> $params */
    public function payoutCancel(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = PayoutService::requireOwn($uid, $params['payout'] ?? '');

        return $this->guarded(function () use ($uid, $p): Response {
            PayoutService::cancelByPartner($uid, $p);

            return $this->ok('/partner/payouts/' . $p['public_id'], 'The request was cancelled and the amount is available again.');
        }, '/partner/payouts/' . $p['public_id']);
    }

    /** @param array<string,string> $params */
    public function detailsForm(Request $request, array $params = []): Response
    {
        $partner = PartnerService::require($this->actorId(), 'payouts.request');

        return $this->page('partner/details', ['title' => 'Payout details', 'hint' => $partner['payout_details_hint'], 'updated' => $partner['payout_details_updated_at']]);
    }

    /** @param array<string,string> $params */
    public function detailsSave(Request $request, array $params = []): Response
    {
        $in = ['method' => $request->string('method'), 'account_name' => $request->string('account_name'), 'identifier' => $request->string('identifier'), 'bank_name' => $request->string('bank_name')];

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            $uid = $this->actorId();
            PartnerService::saveDetails($uid, PartnerService::require($uid, 'payouts.request'), $in);

            return $this->ok('/partner/payouts', 'Your payout details were saved securely. Only a masked hint is shown from now on.');
        }, '/partner/payout-details', ['method' => $in['method'], 'account_name' => $in['account_name'], 'bank_name' => $in['bank_name']]);
    }
}
