<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Referrals;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\FieldRules;
use Vertex\Services\Gate;

/** Recording, verifying and refunding client payments: the verified-payment source of the commission engine. */
final class ClientPaymentController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = [];
        foreach (['q', 'status', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $page = $request->page();
        $res = ClientPaymentService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('client-payments/index', ['title' => 'Client payments', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== ''), 'canRecord' => Gate::can('payments.record', $this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('client-payments/new', ['title' => 'Record a client payment', 'projects' => Database::all("SELECT public_id, code, title FROM projects WHERE archived_at IS NULL AND status <> 'cancelled' ORDER BY code DESC LIMIT 500"),
            'methods' => Config::get('referrals.payment_methods')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['project', 'role', 'gross', 'tax', 'method', 'external_ref', 'invoice_ref', 'received_on', 'notes'] as $k) {
            $in[$k] = $request->string($k);
        }

        return $this->guarded(function () use ($in): Response {
            $res = ClientPaymentService::record($this->actorId(), $in);

            return $this->ok('/client-payments/' . $res['public_id'], "Payment {$res['reference']} recorded. It counts for nothing until a second person verifies it.");
        }, '/client-payments/new', $in);
    }

    /** @param array<string,string> $params */
    public function proof(Request $request, array $params = []): Response
    {
        $p = ClientPaymentService::requireView($this->actorId(), $params['pay'] ?? '');
        $file = $p['proof_file_id'] === null ? null : \Vertex\Services\FileStore::find((int) $p['proof_file_id']);

        return \Vertex\Services\FileStore::send($file ?? throw new \Vertex\Core\HttpException(404));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = ClientPaymentService::requireView($uid, $params['pay'] ?? '');
        $r = $this->page('client-payments/show', ['title' => $p['reference'], 'p' => $p, 'events' => ClientPaymentService::events($p), 'canVerify' => Gate::can('payments.reconcile', $uid)]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = ClientPaymentService::requireView($uid, $params['pay'] ?? '');
        $to = '/client-payments/' . $p['public_id'];

        return $this->guarded(function () use ($request, $uid, $p, $to): Response {
            $this->requireStepUp($request);
            ClientPaymentService::verify($uid, $p, ['received' => $request->string('received'), 'role' => $request->string('role')]);

            return $this->ok($to, 'The payment is verified. The invoice status and any commission milestones were re-evaluated.');
        }, $to, ['received' => $request->string('received')]);
    }

    /** @param array<string,string> $params */
    public function refund(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = ClientPaymentService::requireView($uid, $params['pay'] ?? '');
        $to = '/client-payments/' . $p['public_id'];

        return $this->guarded(function () use ($request, $uid, $p, $to): Response {
            $this->requireStepUp($request);
            $type = $request->string('type');
            $amount = $type === 'reversed' ? 0 : (int) FieldRules::moneyMinor($request->string('amount'), 'Amount', true);
            ClientPaymentService::refund($uid, $p, $amount, $request->string('reason'), $type);

            return $this->ok($to, 'Recorded. Any commission built on this payment was re-evaluated and adjusted.');
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function reject(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = ClientPaymentService::requireView($uid, $params['pay'] ?? '');
        $to = '/client-payments/' . $p['public_id'];

        return $this->guarded(function () use ($request, $uid, $p, $to): Response {
            ClientPaymentService::reject($uid, $p, $request->string('reason'));

            return $this->ok($to, 'The payment was rejected and the reason recorded. The client was told if they submitted it.');
        }, $to, ['reason' => $request->string('reason')]);
    }
}
