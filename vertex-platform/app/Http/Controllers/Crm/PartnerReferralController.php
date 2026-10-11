<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Crm;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\EnquiryQuery;
use Vertex\Services\EnquiryService;

/**
 * Referral partner area. A partner sees ONLY enquiries they submitted, with a deliberately coarse view: submitted
 * details plus a simple status. No internal notes, owner, pipeline stage names, duplicates or other partners' records.
 */
final class PartnerReferralController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $page = max(1, (int) $request->string('page', '1'));
        $res = EnquiryQuery::paginate($this->actorId(), ['q' => mb_substr($request->string('q'), 0, 100)], $page, self::PER_PAGE);

        return $this->page('partner/index', ['title' => 'My referrals', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => ['q' => $request->string('q')]]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('partner/create', ['title' => 'Submit a referral']);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline'] as $f) {
            $in[$f] = $request->string($f);
        }

        return $this->guarded(function () use ($in): Response {
            $r = EnquiryService::create($this->actorId(), 'referral_partner', $in);

            return $this->ok('/partner/referrals/' . $r['public_id'], "Referral {$r['reference']} submitted.");
        }, '/partner/referrals/create', $in);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $enq = EnquiryPolicy::requireView($uid, $params['enquiry'] ?? '');
        if (EnquiryPolicy::level($uid, $enq) !== 'own') {
            // staff are sent to the full CRM view; this page is the partner's restricted view only
            return Response::redirect('/crm/enquiries/' . $enq['public_id']);
        }
        $status = match ($enq['stage_kind']) {
            'won' => 'Accepted',
            'lost' => 'Not proceeding',
            default => 'In progress',
        };

        return $this->page('partner/show', ['title' => $enq['reference'], 'e' => $enq, 'status' => $status]);
    }
}
