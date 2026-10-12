<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Referrals;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CommissionRules;
use Vertex\Services\CommissionService;
use Vertex\Services\FieldRules;
use Vertex\Services\Gate;

/** The commission ledger and its rules (staff). Financial actions need the account password (step-up) on top of the permission. */
final class CommissionController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = [];
        foreach (['q', 'status', 'milestone', 'partner', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $f['review'] = $request->string('review') === '1';
        $page = $request->page();
        $res = CommissionService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('commissions/index', ['title' => 'Commission ledger', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false), 'dash' => CommissionService::dashboard($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $e = CommissionService::requireView($uid, $params['entry'] ?? '');
        $r = $this->page('commissions/show', ['title' => $e['reference'], 'e' => $e, 'd' => CommissionService::detail($uid, $e), 'formula' => json_decode((string) $e['formula_json'], true) ?: [],
            'canApprove' => Gate::can('commissions.approve', $uid), 'canAdjust' => Gate::can('commissions.adjust', $uid)]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $e = CommissionService::requireView($uid, $params['entry'] ?? '');
        $to = '/commissions/' . $e['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $e, $to, $action): Response {
            switch ($action) {
                case 'approve':
                    $this->requireStepUp($request);
                    CommissionService::approve($uid, $e);

                    return $this->ok($to, 'Approved.');
                case 'hold':
                    CommissionService::hold($uid, $e, $request->string('reason'), $request->string('partner_note'));

                    return $this->ok($to, 'The entry is on hold.');
                case 'release':
                    CommissionService::release($uid, $e);

                    return $this->ok($to, 'The hold was released.');
                case 'adjust':
                    $this->requireStepUp($request);
                    $amount = (int) FieldRules::moneyMinor($request->string('amount'), 'Adjustment amount', true);
                    CommissionService::adjust($uid, $e, $request->string('direction') === 'increase' ? $amount : -$amount, $request->string('reason'));

                    return $this->ok($to, 'The adjustment line was added. The original calculation is unchanged.');
                case 'reverse':
                    $this->requireStepUp($request);
                    CommissionService::reverse($uid, $e, $request->string('reason'));

                    return $this->ok($to, 'The reversal was recorded.');
                case 'note':
                    CommissionService::addNote($uid, $e, $request->string('body'));

                    return $this->ok($to, 'Note added.');
            }
            throw new HttpException(404);
        }, $to, ['reason' => $request->string('reason')]);
    }

    /** @param array<string,string> $params */
    public function rules(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = $this->page('commissions/rules', ['title' => 'Commission rules', 'current' => CommissionRules::current(), 'history' => CommissionRules::history(), 'canManage' => Gate::can('commissions.rules.manage', $uid)]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveRules(Request $request, array $params = []): Response
    {
        $in = [];
        foreach (CommissionRules::KEYS as $k) {
            $in[$k] = $request->string($k);
        }
        foreach (['exclude_tax', 'net_of_refunds', 'include_change_requests', 'separation_of_duties'] as $k) {
            $in[$k] = $request->string($k) === '1' ? '1' : '0';
        }

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            $v = CommissionRules::save($this->actorId(), $in, $request->string('reason'));

            return $this->ok('/commissions/rules', $v === 0 ? 'Nothing changed.' : "Rules version {$v} is now in force. Existing ledger entries keep the rules they were calculated under.");
        }, '/commissions/rules', $in + ['reason' => $request->string('reason')]);
    }
}
