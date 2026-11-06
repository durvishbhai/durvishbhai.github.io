<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Renewals;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\RenewalService;

/** The client's own domains and hosting. Read-only: a client cannot change a price, a status, an invoice or a provider confirmation. */
final class ClientServiceController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $rows = array_map(static fn (array $s): array => RenewalService::clientView($s), RenewalService::forClient($this->actorId()));

        return $this->page('renewals/my-index', ['title' => 'Domains & hosting', 'rows' => $rows]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $s = RenewalService::requireOwn($this->actorId(), $params['service'] ?? '');

        $cycle = RenewalService::openCycle($s);
        $live = $cycle === null || !in_array($s['status'], ['invoice_issued', 'awaiting_payment'], true) ? [] : \Vertex\Services\RazorpayGateway::liveRequests((int) $cycle['id'], true);

        return $this->page('renewals/my-show', ['title' => $s['identifier'], 'v' => RenewalService::clientView($s), 'payUrl' => $live === [] ? null : '/my/pay/' . $live[0]['public_id'], 'payAmount' => $live === [] ? 0 : (int) $live[0]['amount_minor']]);
    }
}
