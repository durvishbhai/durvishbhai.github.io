<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Client;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Database;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\SupportPlans;

/** The client's bug-support choices: pay for a subscription (monthly or yearly, with a coupon if they have one) or pay for each bug. Own projects only. */
final class SupportPlanController extends Controller
{
    /** @return array<string,mixed> */
    private function project(Request $request, array $params): array
    {
        $p = ProjectPolicy::find((string) ($params['project'] ?? '')) ?? throw new HttpException(404);
        if (ProjectPolicy::level($this->actorId(), $p) !== 'own') {
            throw new HttpException(404);
        }

        return $p;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $rows = [];
        foreach (Database::all("SELECT p.* FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND p.status = 'completed' AND p.archived_at IS NULL ORDER BY p.completed_at DESC, p.id DESC", [$uid]) as $p) {
            $rows[] = ['project' => $p, 'state' => SupportPlans::state($p)];
        }

        return $this->page('my-support/index', ['title' => 'Bug support', 'rows' => $rows]);
    }

    /** @param array<string,string> $params */
    public function perBug(Request $request, array $params = []): Response
    {
        $p = $this->project($request, $params);

        return $this->guarded(function () use ($p): Response {
            SupportPlans::choosePerBug($this->actorId(), $p);

            return $this->ok('/my/support', 'Noted. For each bug on ' . $p['title'] . ' we will tell you what it costs to fix before you pay anything.');
        }, '/my/support');
    }

    /** @param array<string,string> $params */
    public function subscribe(Request $request, array $params = []): Response
    {
        $p = $this->project($request, $params);
        $plan = $request->string('plan');
        $coupon = $request->string('coupon_code');

        return $this->guarded(function () use ($p, $plan, $coupon): Response {
            $r = SupportPlans::purchase($this->actorId(), $p, $plan, $coupon);

            return $this->ok('/my/invoices/' . $r['invoice_public_id'], 'Your subscription invoice is ready. Pay it below and bug support starts at once.');
        }, '/my/support', ['coupon_code' => $coupon]);
    }
}
