<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Renewals;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Gate;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\RenewalPricing;
use Vertex\Services\RenewalScheduler;
use Vertex\Services\RenewalService;

/** Staff screens for domain / hosting / SSL renewals. Every action re-checks its own permission in the service; money and confirmation steps ask for the password. */
final class RenewalController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = [];
        foreach (['q', 'type', 'status', 'bucket', 'client', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $page = $request->page();
        $res = RenewalService::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page('renewals/index', ['title' => 'Domains & hosting', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== ''), 'dash' => RenewalService::dashboard($uid), 'scheduler' => RenewalScheduler::lastRun(), 'schedulerHealthy' => RenewalScheduler::healthy(),
            'canManage' => Gate::can('renewals.manage', $uid), 'canPrices' => Gate::can('renewals.prices', $uid)]);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params = []): Response
    {
        $f = [];
        foreach (['q', 'type', 'status', 'bucket', 'client', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $rows = RenewalService::export($this->actorId(), $f);
        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return new Response(200, "\xEF\xBB\xBF" . $csv, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="renewals-' . gmdate('Y-m-d') . '.csv"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        Gate::authorize('renewals.manage', $this->actorId());
        $r = $this->page('renewals/form', ['title' => 'Add a domain or hosting service', 's' => null, 'clients' => $this->clients(), 'projects' => Database::all("SELECT p.public_id, p.code, p.title, c.public_id AS client_public_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.archived_at IS NULL ORDER BY p.code DESC LIMIT 500")]);
        Session::forget('_old');

        return $r;
    }

    /** @return list<array<string,mixed>> */
    private function clients(): array
    {
        return Database::all("SELECT public_id, contact_name, company_name FROM clients WHERE archived_at IS NULL ORDER BY COALESCE(NULLIF(company_name, ''), contact_name) LIMIT 1000");
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->fields($request);
        $client = $request->string('client');

        return $this->guarded(function () use ($client, $in): Response {
            $res = RenewalService::create($this->actorId(), $client, $in);

            return $this->ok('/renewals/' . $res['public_id'], "Service {$res['reference']} was added.");
        }, '/renewals/new', $in + ['client' => $client]);
    }

    /** @return array<string,string> */
    private function fields(Request $request): array
    {
        $in = [];
        foreach (['service_type', 'identifier', 'provider', 'activated_on', 'expires_on', 'period_months', 'internal_notes', 'client_notes', 'project'] as $k) {
            $in[$k] = $request->string($k);
        }
        $in['client_reminders'] = $request->string('client_reminders') === '1' ? '1' : '0';

        return $in;
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $s = RenewalService::requireStaff($uid, $params['service'] ?? '');
        $cycle = RenewalService::openCycle($s);
        $period = $cycle !== null && $cycle['period_months'] !== null ? (int) $cycle['period_months'] : (int) $s['period_months'];
        $page = $this->page('renewals/show', [
            'title' => $s['reference'], 's' => $s, 'cycle' => $cycle, 'payments' => $cycle === null ? [] : RenewalService::payments($cycle), 'history' => RenewalService::historyOf($s), 'past' => RenewalService::pastCycles($s),
            'reminders' => RenewalService::reminders($s), 'listPrice' => RenewalPricing::lookup((string) $s['service_type'], $period), 'overdue' => RenewalService::isOverdue($s),
            'next' => (array) Config::get("renewals.manual_transitions.{$s['status']}", []), 'projects' => Database::all('SELECT public_id, code, title FROM projects WHERE client_id = ? AND archived_at IS NULL ORDER BY code DESC', [$s['client_id']]),
            'can' => ['manage' => Gate::can('renewals.manage', $uid), 'approve' => Gate::can('renewals.approve', $uid), 'confirm' => Gate::can('renewals.confirm_provider', $uid), 'request' => Gate::can('payments.request', $uid)],
            'liveRequests' => $cycle === null ? [] : RazorpayGateway::liveRequests((int) $cycle['id'], true),
        ]);
        Session::forget('_old');

        return $page;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $s = RenewalService::requireStaff($uid, $params['service'] ?? '');
        $to = '/renewals/' . $s['public_id'];
        $action = $request->string('action');
        $old = ['reason' => $request->string('reason'), 'invoice_ref' => $request->string('invoice_ref'), 'quotation_ref' => $request->string('quotation_ref'), 'amount' => $request->string('amount'),
            'reference' => $request->string('reference'), 'confirmation_ref' => $request->string('confirmation_ref'), 'new_expiry' => $request->string('new_expiry'), 'net' => $request->string('net'), 'increase' => $request->string('increase')];

        return $this->guarded(function () use ($request, $uid, $s, $to, $action): Response {
            switch ($action) {
                case 'edit':
                    RenewalService::update($uid, $s, $this->fields($request) + ['service_type' => $s['service_type'], 'identifier' => $s['identifier'], 'expires_on' => $s['expires_on']]);

                    return $this->ok($to, 'Saved.');
                case 'expiry':
                    $this->requireStepUp($request);
                    RenewalService::changeExpiry($uid, $s, $request->string('new_expiry'), $request->string('reason'));

                    return $this->ok($to, 'The expiry date was corrected and the change recorded.');
                case 'status':
                    RenewalService::setStatus($uid, $s, $request->string('status'));

                    return $this->ok($to, 'Status updated.');
                case 'approve':
                    $this->requireStepUp($request);
                    RenewalService::approve($uid, $s, ['price_mode' => $request->string('price_mode'), 'net' => $request->string('net'), 'tax_percent' => $request->string('tax_percent'), 'reason' => $request->string('reason'), 'period_months' => $request->string('period_months') ?: (string) $s['period_months']]);

                    return $this->ok($to, 'Approved. The price is now locked for this renewal.');
                case 'reject':
                    RenewalService::reject($uid, $s, $request->string('reason'));

                    return $this->ok($to, 'The renewal was put on hold with your reason recorded.');
                case 'invoice':
                    RenewalService::recordInvoice($uid, $s, ['invoice_ref' => $request->string('invoice_ref'), 'quotation_ref' => $request->string('quotation_ref'), 'amount' => $request->string('amount')]);

                    return $this->ok($to, 'The invoice was recorded.');
                case 'invoice_sent':
                    RenewalService::markInvoiceSent($uid, $s);

                    return $this->ok($to, 'Marked as sent. The client was notified in the portal.');
                case 'raise_price':
                    $this->requireStepUp($request);
                    RenewalService::raisePrice($uid, $s, $request->string('increase'), $request->string('reason'));

                    return $this->ok($to, 'The price was raised and the client was told. Request the extra amount with Razorpay or record it when it arrives.');
                case 'revise':
                    $this->requireStepUp($request);
                    RenewalService::reviseOrVoid($uid, $s, $request->string('reason'));

                    return $this->ok($to, 'The invoice was voided and the renewal sent back for a new price.');
                case 'payment':
                    RenewalService::recordPayment($uid, $s, ['amount' => $request->string('amount'), 'method' => $request->string('method'), 'reference' => $request->string('reference'), 'received_on' => $request->string('received_on')]);

                    return $this->ok($to, 'The payment was recorded. Payment does not renew anything: the provider still has to confirm.');
                case 'start':
                    RenewalService::startProviderRenewal($uid, $s);

                    return $this->ok($to, 'Marked as in progress with the provider.');
                case 'confirm':
                    $this->requireStepUp($request);
                    RenewalService::recordProviderConfirmation($uid, $s, ['confirmation_ref' => $request->string('confirmation_ref'), 'confirmed_on' => $request->string('confirmed_on'), 'new_expiry' => $request->string('new_expiry')]);

                    return $this->ok($to, 'The provider confirmation was recorded. The service is Renewed and its next expiry is set.');
                case 'hold':
                    RenewalService::hold($uid, $s, $request->string('reason'));

                    return $this->ok($to, 'The service is on hold.');
                case 'release':
                    RenewalService::release($uid, $s);

                    return $this->ok($to, 'The hold was released.');
                case 'cancel':
                    $this->requireStepUp($request);
                    RenewalService::cancel($uid, $s, $request->string('reason'));

                    return $this->ok($to, 'The service was cancelled.');
            }
            throw new HttpException(404);
        }, $to, $old);
    }
}
