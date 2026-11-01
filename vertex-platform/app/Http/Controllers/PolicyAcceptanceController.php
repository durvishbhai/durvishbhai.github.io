<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\ValidationException;
use Vertex\Services\ComplianceSettings;
use Vertex\Services\LegalDocuments;
use Vertex\Services\PolicyAcceptance;

/** A client reviews and accepts (or declines) the materially updated policies. Declining restricts the account; nothing is deleted. */
final class PolicyAcceptanceController extends Controller
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $st = PolicyAcceptance::state($uid);
        if ($st['pending'] === []) {
            return $this->ok('/dashboard', 'There is nothing to accept right now.');
        }
        $docs = [];
        foreach ($st['pending'] as $r) {
            $v = LegalDocuments::live((string) $r['doc_key']);
            $docs[] = $r + ['html' => $v ? LegalDocuments::fill((string) $v['body_html']) : ''];
        }

        return $this->page('pages/policy-acceptance', ['title' => 'Updated terms', 'docs' => $docs, 'st' => $st, 'email' => ComplianceSettings::email(), 'whatsapp' => ComplianceSettings::whatsapp()]);
    }

    /** @param array<string,string> $params */
    public function decide(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $ids = [];
        foreach ((array) ($request->body['versions'] ?? []) as $v) {
            if (is_scalar($v) && ctype_digit((string) $v)) {
                $ids[] = (int) $v;
            }
        }
        $pending = array_map(static fn (array $r): int => (int) $r['version_id'], PolicyAcceptance::state($uid)['pending']);
        $ids = array_values(array_intersect($ids === [] ? $pending : $ids, $pending));

        return $this->guarded(function () use ($request, $uid, $ids): Response {
            if ($request->string('decision') === 'decline') {
                PolicyAcceptance::decline($uid, $ids, $request->ip(), $request->userAgent());

                return $this->ok('/policy-acceptance', 'You did not accept the updated terms. Your account is restricted: nothing has been deleted, and you can still open your invoices, receipts and agreements. You can accept at any time to restore access.');
            }
            if ($request->string('confirm') !== '1') {
                throw new ValidationException(['Tick the box to confirm that you have read and accept the updated terms.']);
            }
            PolicyAcceptance::accept($uid, $ids, $request->ip(), $request->userAgent());

            return $this->ok('/dashboard', 'Thank you. Your acceptance has been recorded.');
        }, '/policy-acceptance');
    }
}
