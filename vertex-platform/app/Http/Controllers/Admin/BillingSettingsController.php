<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\BusinessProfile;

/** Administration → Business details. Needs `billing.configure` and the password; secrets are written once and never shown again. */
final class BillingSettingsController extends Controller
{
    /** @param array<string,string> $params */
    public function business(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/business', ['title' => 'Business details', 'p' => BusinessProfile::get()]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveBusiness(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['trade_name', 'legal_name', 'address', 'email', 'phone', 'website', 'tax_statement', 'payment_instructions', 'invoice_footer'] as $k) {
            $in[$k] = $request->string($k);
        }

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            BusinessProfile::save($this->actorId(), $in);

            return $this->ok('/admin/business', 'The business details were saved. Invoices issued from now on carry them; invoices already issued are not changed.');
        }, '/admin/business', $in);
    }
}
