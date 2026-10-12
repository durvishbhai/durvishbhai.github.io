<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Referrals;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Gate;
use Vertex\Services\PartnerService;
use Vertex\Services\PayoutService;

/** Payout review, approval and manual payment recording (staff). Approving and paying are different actions with different permissions. */
final class PayoutController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = [];
        foreach (['q', 'status', 'partner', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $page = $request->page();
        $res = PayoutService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('payouts/index', ['title' => 'Payout requests', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== ''), 'dash' => PayoutService::dashboard($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = PayoutService::requireStaff($uid, $params['payout'] ?? '');
        $details = Gate::can('payouts.record', $uid) && $request->string('reveal') === '1' ? PartnerService::revealForPayout($uid, $p) : null;
        $r = $this->page('payouts/show', ['title' => $p['reference'], 'p' => $p, 'history' => PayoutService::history_for($p, true), 'notes' => PayoutService::notes($uid, $p), 'allocations' => PayoutService::allocations($p),
            'balance' => Gate::can('commissions.view_all', $uid) ? \Vertex\Services\CommissionService::balance((int) $p['partner_id']) : null,
            'canApprove' => Gate::can('payouts.approve', $uid), 'canRecord' => Gate::can('payouts.record', $uid), 'details' => $details]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = PayoutService::requireStaff($uid, $params['payout'] ?? '');
        $to = '/payouts/' . $p['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $p, $to, $action): Response {
            switch ($action) {
                case 'review':
                    PayoutService::startReview($uid, $p);

                    return $this->ok($to, 'Moved to Under Review.');
                case 'approve':
                    $this->requireStepUp($request);
                    PayoutService::approve($uid, $p, $request->string('note'));

                    return $this->ok($to, 'Approved. Nothing has been paid: someone with the payout-recording permission must record the actual payment.');
                case 'reject':
                    PayoutService::reject($uid, $p, $request->string('reason'));

                    return $this->ok($to, 'Rejected. The reserved amount was released to the partner.');
                case 'processing':
                    PayoutService::markProcessing($uid, $p);

                    return $this->ok($to, 'Marked as processing.');
                case 'pay':
                    $this->requireStepUp($request);
                    PayoutService::recordPayment($uid, $p, ['method' => $request->string('method'), 'reference' => $request->string('reference'), 'date' => $request->string('date'),
                        'amount' => $request->string('amount'), 'note' => $request->string('note')]);

                    return $this->ok($to, 'The payment was recorded and the payout is marked Paid.');
                case 'note':
                    PayoutService::addNote($uid, $p, $request->string('body'));

                    return $this->ok($to, 'Note added.');
            }
            throw new HttpException(404);
        }, $to, ['reason' => $request->string('reason'), 'method' => $request->string('method'), 'reference' => $request->string('reference'), 'date' => $request->string('date'), 'amount' => $request->string('amount')]);
    }
}
