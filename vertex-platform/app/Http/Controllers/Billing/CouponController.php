<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CouponService;
use Vertex\Services\Gate;

/** Coupons and campaigns (module 26). */
final class CouponController extends Controller
{
    private const PER_PAGE = 25;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = ['q' => mb_substr($request->string('q'), 0, 100), 'campaign' => $request->string('campaign')];
        $res = CouponService::paginate($this->actorId(), $f, $request->page(), self::PER_PAGE);

        return $this->page('coupons/index', ['title' => 'Coupons', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $request->page(), 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== ''), 'campaigns' => CouponService::campaigns(), 'canManage' => Gate::can('coupons.manage', $this->actorId())]);
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return ['campaigns' => Database::all('SELECT public_id, name FROM campaigns ORDER BY name'), 'services' => Config::get('workflow.services')];
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        Gate::authorize('coupons.manage', $this->actorId());
        $r = $this->page('coupons/new', ['title' => 'New coupon', 'c' => null] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @return array<string,mixed> */
    private function fields(Request $request): array
    {
        $in = [];
        foreach (['code', 'description', 'discount_type', 'percent', 'max_discount', 'amount', 'min_spend', 'valid_from', 'valid_until', 'max_uses', 'max_uses_per_client', 'campaign'] as $k) {
            $in[$k] = $request->string($k);
        }
        $in['eligible_services'] = is_array($request->body['eligible_services'] ?? null) ? array_values($request->body['eligible_services']) : [];

        return $in;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->fields($request);

        return $this->guarded(function () use ($in): Response {
            $r = CouponService::create($this->actorId(), $in);

            return $this->ok('/coupons/' . $r['public_id'], 'Coupon created.');
        }, '/coupons/new', $in);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        Gate::authorize('coupons.view', $this->actorId());
        $c = CouponService::find($params['coupon'] ?? '') ?? throw new HttpException(404);
        $r = $this->page('coupons/show', ['title' => $c['code'], 'c' => $c, 'redemptions' => CouponService::redemptions($c), 'canManage' => Gate::can('coupons.manage', $this->actorId())] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $c = CouponService::find($params['coupon'] ?? '') ?? throw new HttpException(404);
        $to = '/coupons/' . $c['public_id'];
        $in = $this->fields($request);

        return $this->guarded(function () use ($request, $c, $to, $in): Response {
            if ($request->string('action') === 'status') {
                CouponService::setStatus($this->actorId(), $c, $c['status'] === 'active' ? 'paused' : 'active');

                return $this->ok($to, $c['status'] === 'active' ? 'Coupon paused. It cannot be used until you resume it.' : 'Coupon is active.');
            }
            CouponService::update($this->actorId(), $c, $in);

            return $this->ok($to, 'Saved. Invoices that already used this coupon keep the discount they were given.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function saveCampaign(Request $request, array $params = []): Response
    {
        $existing = isset($params['campaign']) ? (Database::one('SELECT * FROM campaigns WHERE public_id = ?', [$params['campaign']]) ?? throw new HttpException(404)) : null;
        $in = ['name' => $request->string('name'), 'description' => $request->string('description'), 'starts_on' => $request->string('starts_on'), 'ends_on' => $request->string('ends_on'), 'status' => $request->string('status')];

        return $this->guarded(function () use ($existing, $in): Response {
            CouponService::saveCampaign($this->actorId(), $existing, $in);

            return $this->ok('/coupons', $existing === null ? 'Campaign created.' : 'Campaign saved.');
        }, '/coupons', $in);
    }
}
