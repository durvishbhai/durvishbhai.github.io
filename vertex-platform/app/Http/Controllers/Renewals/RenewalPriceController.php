<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Renewals;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Gate;
use Vertex\Services\RenewalPricing;

/** The renewal price list: viewable by renewal staff, edited (append-only) by `renewals.prices` with the password. */
final class RenewalPriceController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $r = $this->page('renewals/prices', ['title' => 'Renewal prices', 'prices' => RenewalPricing::all(), 'canEdit' => Gate::can('renewals.prices', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['service_type', 'period_months', 'base', 'markup', 'discount', 'tax_percent', 'effective_from', 'note'] as $k) {
            $in[$k] = $request->string($k);
        }

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            RenewalPricing::add($this->actorId(), $in);

            return $this->ok('/renewals/prices', 'The price was added. Renewals already approved keep the price they were approved at.');
        }, '/renewals/prices', $in);
    }
}
