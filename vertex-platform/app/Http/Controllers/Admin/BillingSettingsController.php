<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ManualUpi;

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
    public function manualUpi(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/manual-upi', ['title' => 'Manual UPI payments', 's' => ManualUpi::settings(), 'status' => ManualUpi::status(), 'razorpayOn' => ManualUpi::razorpayOn()]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveManualUpi(Request $request, array $params = []): Response
    {
        $in = ['enabled' => $request->string('enabled') === '1' ? '1' : '0', 'require_proof' => $request->string('require_proof') === '1' ? '1' : '0', 'upi_id' => $request->string('upi_id'), 'payee_name' => $request->string('payee_name'), 'instructions' => $request->string('instructions')];
        $qr = $request->files['qr'] ?? null;

        return $this->guarded(function () use ($request, $in, $qr): Response {
            $this->requireStepUp($request);
            ManualUpi::save($this->actorId(), $in, is_array($qr) && !is_array($qr['name'] ?? null) ? $qr : null, $request->string('remove_qr') === '1');

            return $this->ok('/admin/manual-upi', 'Manual UPI settings saved.');
        }, '/admin/manual-upi', $in);
    }

    /** The saved QR picture, for the admin page. @param array<string,string> $params */
    public function manualUpiQr(Request $request, array $params = []): Response
    {
        return ManualUpi::qrPreview();
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
