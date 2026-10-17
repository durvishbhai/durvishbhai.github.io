<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\BusinessProfile;
use Vertex\Services\GatewayConfig;

/** Administration → Payments (Google Pay) and Business details. Needs `billing.configure` and the password; secrets are written once and never shown again. */
final class BillingSettingsController extends Controller
{
    /** @param array<string,string> $params */
    public function payments(Request $request, array $params = []): Response
    {
        $upi = GatewayConfig::get(GatewayConfig::UPI);
        $api = GatewayConfig::get(GatewayConfig::API);
        $check = Session::pullFlash('upi_check');
        $r = $this->page('admin/payments', ['title' => 'Payments (Google Pay)', 'upi' => $upi, 'api' => $api, 'upiStatus' => GatewayConfig::status(GatewayConfig::UPI), 'apiStatus' => GatewayConfig::status(GatewayConfig::API),
            'networks' => GatewayConfig::NETWORKS, 'authMethods' => GatewayConfig::AUTH_METHODS, 'check' => is_array($check) ? $check : null, 'profileOk' => BusinessProfile::isComplete()]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function savePayments(Request $request, array $params = []): Response
    {
        $action = $request->string('action');
        $to = '/admin/payments';
        $keep = [];
        foreach (['upi_id', 'payee_name', 'merchant_code', 'environment', 'merchant_id', 'merchant_name', 'merchant_origin', 'processor', 'processor_merchant_id', 'processor_key_id'] as $k) {
            $keep[$k] = $request->string($k);
        }

        return $this->guarded(function () use ($request, $action, $to): Response {
            switch ($action) {
                case 'save_upi':
                    $this->requireStepUp($request);
                    GatewayConfig::saveUpi($this->actorId(), ['upi_id' => $request->string('upi_id'), 'payee_name' => $request->string('payee_name'), 'merchant_code' => $request->string('merchant_code'), 'enabled' => $request->string('enabled')]);

                    return $this->ok($to, 'The Google Pay (UPI) settings were saved.');
                case 'check_upi':
                    Session::flash('upi_check', GatewayConfig::checkUpi());

                    return Response::redirect($to);
                case 'save_api':
                    $this->requireStepUp($request);
                    $b = $request->body;
                    GatewayConfig::saveApi($this->actorId(), ['environment' => $request->string('environment'), 'merchant_id' => $request->string('merchant_id'), 'merchant_name' => $request->string('merchant_name'),
                        'merchant_origin' => $request->string('merchant_origin'), 'processor' => $request->string('processor'), 'processor_merchant_id' => $request->string('processor_merchant_id'),
                        'processor_key_id' => $request->string('processor_key_id'), 'networks' => is_array($b['networks'] ?? null) ? $b['networks'] : [], 'auth_methods' => is_array($b['auth_methods'] ?? null) ? $b['auth_methods'] : [],
                        'processor_key_secret' => is_string($b['processor_key_secret'] ?? null) ? $b['processor_key_secret'] : '', 'webhook_secret' => is_string($b['webhook_secret'] ?? null) ? $b['webhook_secret'] : '',
                        'clear_secrets' => $request->string('clear_secrets')]);

                    return $this->ok($to, 'The Google Pay API fields were saved. Secrets are stored encrypted and are not shown again. This does not turn the integration on: see the status below.');
            }
            throw new HttpException(404);
        }, $to, $keep);
    }

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
