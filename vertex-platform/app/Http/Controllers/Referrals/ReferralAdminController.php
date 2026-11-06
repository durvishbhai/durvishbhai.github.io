<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Referrals;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\CommissionService;
use Vertex\Services\FileStore;
use Vertex\Services\Gate;
use Vertex\Services\PartnerApplicationService;
use Vertex\Services\PartnerService;
use Vertex\Services\PayoutService;
use Vertex\Services\ReferralMailService;
use Vertex\Services\ReferralService;

/** Staff screens for partner applications, partners and referrals (all server-side permission checked; 404 for people without access). */
final class ReferralAdminController extends Controller
{
    private const PER_PAGE = 20;

    private function filters(Request $request, array $keys): array
    {
        $f = [];
        foreach ($keys as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }

        return $f;
    }

    /** @param array<string,string> $params */
    public function dashboard(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $from = $this->filters($request, ['from', 'to']);
        $count = static fn (string $sql, array $p = []): int => (int) Database::one($sql, $p)['c'];
        [$w, $p] = PartnerApplicationService::dateRange(['1=1'], [], 'created_at', $from);
        $range = implode(' AND ', $w);

        return $this->page('referrals/dashboard', [
            'title' => 'Referral partners', 'filters' => $from,
            'applications' => Gate::can('referrals.review', $uid) ? [
                'pending' => $count("SELECT COUNT(*) AS c FROM partner_applications WHERE status IN ('received','under_review') AND {$range}", $p),
                'approved' => $count("SELECT COUNT(*) AS c FROM partner_applications WHERE status = 'approved' AND {$range}", $p),
                'rejected' => $count("SELECT COUNT(*) AS c FROM partner_applications WHERE status = 'rejected' AND {$range}", $p),
            ] : null,
            'referrals' => Gate::can('referrals.review', $uid) ? [
                'new' => $count("SELECT COUNT(*) AS c FROM partner_referrals WHERE status = 'received' AND {$range}", $p),
                'duplicates' => $count("SELECT COUNT(*) AS c FROM partner_referrals WHERE (attribution = 'conflict' OR duplicate_status = 'suspected') AND {$range}", $p),
                'in_progress' => $count("SELECT COUNT(*) AS c FROM partner_referrals WHERE status IN ('under_review','approved','contacted','qualified','quote_sent') AND {$range}", $p),
                'converted' => $count("SELECT COUNT(*) AS c FROM partner_referrals WHERE status = 'converted' AND {$range}", $p),
                'not_converted' => $count("SELECT COUNT(*) AS c FROM partner_referrals WHERE status IN ('not_converted','rejected') AND {$range}", $p),
            ] : null,
            'commissions' => CommissionService::dashboard($uid), 'payouts' => PayoutService::dashboard($uid),
            'payments' => Gate::canAny(['payments.view_all', 'payments.record', 'payments.reconcile'], $uid) ? ['to_verify' => $count("SELECT COUNT(*) AS c FROM client_payments WHERE status = 'pending_verification'")] : null,
        ]);
    }

    // ------------------------------------------------------------------------------------------------ applications

    /** @param array<string,string> $params */
    public function applications(Request $request, array $params = []): Response
    {
        $f = $this->filters($request, ['q', 'status', 'from', 'to']);
        $page = $request->page();
        $res = PartnerApplicationService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('referrals/applications', ['title' => 'Partner applications', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== '')]);
    }

    /** @param array<string,string> $params */
    public function application(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = PartnerApplicationService::requireView($uid, $params['app'] ?? '');
        $r = $this->page('referrals/application', ['title' => $a['reference'], 'a' => $a, 'notes' => PartnerApplicationService::notes($uid, $a), 'canDecide' => Gate::can('referrals.decide', $uid)]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function applicationAction(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = PartnerApplicationService::requireView($uid, $params['app'] ?? '');
        $to = '/referrals/applications/' . $a['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $a, $to, $action): Response {
            switch ($action) {
                case 'review':
                    PartnerApplicationService::markUnderReview($uid, $a);

                    return $this->ok($to, 'Moved to Under Review.');
                case 'approve':
                    $this->requireStepUp($request);
                    $r = PartnerApplicationService::approve($uid, $a, $request->string('note'));

                    return $this->ok($to, $r['linked_existing'] ? 'Approved. The partner role was added to the applicant\'s own account.' : ($r['invite_sent'] ? 'Approved. An invitation email was sent; the applicant activates their account with it.' : 'Approved. The account was created but NO invitation email could be sent (email is not configured). Send the invitation from the Users screen once email works.'));
                case 'reject':
                    PartnerApplicationService::reject($uid, $a, $request->string('reason'));

                    return $this->ok($to, 'The application was rejected and the reason recorded.');
                case 'note':
                    PartnerApplicationService::addNote($uid, $a, $request->string('body'));

                    return $this->ok($to, 'Note added.');
            }
            throw new HttpException(404);
        }, $to, ['reason' => $request->string('reason'), 'note' => $request->string('note')]);
    }

    /** The application's supporting file: only people who may review applications. @param array<string,string> $params */
    public function applicationFile(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $a = PartnerApplicationService::requireView($uid, $params['app'] ?? '');
        if ($a['doc_file_id'] === null || (string) ($params['file'] ?? '') !== $a['file_public_id'] || !Gate::can('files.download', $uid) && !Gate::can('referrals.decide', $uid)) {
            throw new HttpException(404);
        }
        $f = FileStore::find((int) $a['doc_file_id']);
        if ($f === null || !\Vertex\Services\RateLimiter::hit('download_user', (string) $uid)) {
            throw new HttpException($f === null ? 404 : 429);
        }
        $response = FileStore::send($f);
        \Vertex\Services\Audit::record('partner.application_file_downloaded', 'partner_application', (int) $a['id'], [], [], [], $uid);

        return $response;
    }

    // ------------------------------------------------------------------------------------------------ terms

    /** @param array<string,string> $params */
    public function terms(Request $request, array $params = []): Response
    {
        $r = $this->page('referrals/terms', ['title' => 'Referral Partner Terms', 'current' => PartnerApplicationService::currentTerms(), 'canManage' => Gate::can('referrals.decide', $this->actorId()),
            'versions' => Database::all('SELECT version, title, created_at FROM partner_terms ORDER BY version DESC')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function saveTerms(Request $request, array $params = []): Response
    {
        $in = ['title' => $request->string('title'), 'terms' => $request->string('terms')];

        return $this->guarded(function () use ($request, $in): Response {
            $this->requireStepUp($request);
            $v = PartnerApplicationService::recordTerms($this->actorId(), $in);

            return $this->ok('/referrals/terms', "Terms version {$v} recorded. New applications agree to this version.");
        }, '/referrals/terms', $in);
    }

    // ------------------------------------------------------------------------------------------------ partners

    /** @param array<string,string> $params */
    public function partners(Request $request, array $params = []): Response
    {
        $f = $this->filters($request, ['q', 'status']);
        $page = $request->page();
        $res = PartnerService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('referrals/partners', ['title' => 'Referral partners', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => array_filter($f, static fn ($v): bool => $v !== '')]);
    }

    /** @param array<string,string> $params */
    public function partner(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $p = Gate::canAny(['referrals.review', 'commissions.view_all', 'payouts.view_all'], $uid) ? PartnerService::find($params['partner'] ?? '') : null;
        if ($p === null) {
            throw new HttpException(404);
        }

        return $this->page('referrals/partner', ['title' => $p['reference'], 'p' => $p, 'balance' => Gate::can('commissions.view_all', $uid) ? CommissionService::balance((int) $p['id']) : null, 'canDecide' => Gate::can('referrals.decide', $uid),
            'referrals' => ReferralService::paginate($uid, ['partner' => $p['public_id']], 1, 10)['rows']]);
    }

    /** @param array<string,string> $params */
    public function partnerAction(Request $request, array $params = []): Response
    {
        $p = PartnerService::find($params['partner'] ?? '') ?? throw new HttpException(404);
        $to = '/referrals/partners/' . $p['public_id'];

        return $this->guarded(function () use ($request, $p, $to): Response {
            $suspend = $request->string('action') === 'suspend';
            PartnerService::setSuspended($this->actorId(), $p, $suspend, $request->string('reason'));

            return $this->ok($to, $suspend ? 'The partner was suspended.' : 'The partner was reactivated.');
        }, $to);
    }

    // ------------------------------------------------------------------------------------------------ referrals

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = $this->filters($request, ['q', 'status', 'attribution', 'partner', 'from', 'to']);
        $f['duplicates'] = $request->string('duplicates') === '1';
        $page = $request->page();
        $res = ReferralService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('referrals/index', ['title' => 'Referrals', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false)]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = ReferralService::requireStaff($uid, $params['ref'] ?? '');
        $history = ReferralService::historyFor($uid, $r, true);
        $emails = [];
        foreach ($history as $h) {
            if ($h['event'] === 'status' && $h['visibility'] === 'partner') {
                $emails[] = (int) $h['id'];
            }
        }
        $page = $this->page('referrals/show', [
            'title' => $r['reference'], 'r' => $r, 'history' => $history, 'notes' => ReferralService::notes($uid, $r), 'canManage' => Gate::can('referrals.manage', $uid), 'canDecide' => Gate::can('referrals.decide', $uid),
            'next' => (array) Config::get("referrals.referral_transitions.{$r['status']}", []), 'emailLog' => ReferralMailService::log($uid, $r), 'mailable' => $emails,
            'commissions' => Gate::can('commissions.view_all', $uid) ? Database::all('SELECT public_id, reference, milestone, status, amount_minor FROM commission_entries WHERE referral_id = ? ORDER BY id', [$r['id']]) : [],
            'claims' => $r['duplicate_status'] === 'suspected' || $r['attribution'] === 'conflict' ? Database::all(
                "SELECT x.public_id, x.reference, x.attribution, x.status, pu.name AS partner_name FROM partner_referrals x JOIN referral_partners p ON p.id = x.partner_id JOIN users pu ON pu.id = p.user_id
                  WHERE x.id <> ? AND ((x.email_norm IS NOT NULL AND x.email_norm = ?) OR (x.phone_digits IS NOT NULL AND x.phone_digits = ?))", [$r['id'], $r['email_norm'], $r['phone_digits']]) : [],
        ]);
        Session::forget('_old');

        return $page;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = ReferralService::requireStaff($uid, $params['ref'] ?? '');
        $to = '/referrals/' . $r['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $r, $to, $action): Response {
            switch ($action) {
                case 'status':
                    ReferralService::setStatus($uid, $r, $request->string('status'), $request->string('reason'), $request->string('internal_note'));

                    return $this->ok($to, 'Status updated. The partner was notified in the portal. No email was sent.');
                case 'note':
                    ReferralService::addNote($uid, $r, $request->string('body'));

                    return $this->ok($to, 'Internal note added. The partner cannot see it.');
                case 'attribution':
                    ReferralService::decideAttribution($uid, $r, $request->string('decision'), $request->string('reason'));

                    return $this->ok($to, 'The attribution decision was recorded.');
                case 'link':
                    ReferralService::linkRecords($uid, $r, $request->string('enquiry'), $request->string('project'));

                    return $this->ok($to, 'The records were linked.');
                case 'enquiry':
                    ReferralService::createEnquiry($uid, $r);

                    return $this->ok($to, 'A CRM enquiry was created from this referral.');
                case 'convert':
                    ReferralService::markConverted($uid, $r, $request->string('project'));

                    return $this->ok($to, 'The referral is marked Converted and linked to the project.');
            }
            throw new HttpException(404);
        }, $to, ['reason' => $request->string('reason'), 'internal_note' => $request->string('internal_note')]);
    }

    /** The email preview: nothing is sent from this page. @param array<string,string> $params */
    public function emailPreview(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = ReferralService::requireStaff($uid, $params['ref'] ?? '');
        $preview = ReferralMailService::preview($uid, $r, (int) ($params['history'] ?? 0));

        return $this->page('referrals/email-preview', ['title' => 'Preview email', 'r' => $r, 'p' => $preview]);
    }

    /** @param array<string,string> $params */
    public function emailSend(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $r = ReferralService::requireStaff($uid, $params['ref'] ?? '');
        $hid = (int) ($params['history'] ?? 0);
        $to = '/referrals/' . $r['public_id'];

        return $this->guarded(function () use ($request, $uid, $r, $hid, $to): Response {
            if ($request->string('confirm') !== '1') {
                throw new \Vertex\Core\ValidationException(['Tick the confirmation box to send this email.']);
            }
            $res = ReferralMailService::send($uid, $r, $hid, $request->string('hash'));

            return $this->ok($to, match ($res) {
                'sent' => 'The email was sent to the partner.',
                'failed' => 'The email could not be delivered. You can preview and try again.',
                default => 'Email is not configured on this system, so nothing was sent.',
            });
        }, $to);
    }
}
