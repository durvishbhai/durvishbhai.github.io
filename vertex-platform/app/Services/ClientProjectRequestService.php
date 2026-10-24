<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * A signed-in client asks for a NEW project from their dashboard with the same requirements form the website uses. It becomes an ordinary enquiry in the CRM
 * (source "client portal", already linked to the client, so the team does not retype or re-match anything) and the client follows it on their own list:
 * received -> under review -> project created -> confirmed. The project is confirmed (Ready to Start) automatically when its ADVANCE payment is verified
 * (ProjectService::confirmAfterAdvance); until then it is only a request.
 */
final class ClientProjectRequestService
{
    /** @return array<string,mixed> the signed-in client's record */
    public static function client(int $userId): array
    {
        $c = Database::one("SELECT * FROM clients WHERE user_id = ? AND status = 'active' AND archived_at IS NULL", [$userId]);

        return $c ?? throw new HttpException(404);
    }

    /**
     * @param array<string,mixed> $body the posted form
     * @param list<array<string,mixed>> $files uploaded files (up to 3)
     * @return array{id:int,public_id:string,reference:string}
     */
    public static function submit(int $userId, array $body, array $files, string $ip): array
    {
        Gate::authorize('requirements.submit', $userId);
        $client = self::client($userId);
        if (!RateLimiter::hit('client_request_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $x = EnquiryExtras::requirements($body); // validates the project questions and builds the description
        $metas = [];
        try {
            foreach (array_slice($files, 0, 3) as $f) {
                if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $metas[] = FileStore::accept($f, 'enquiry');
                }
            }
            $in = $x['base'] + [
                'contact_name' => (string) $client['contact_name'], 'email' => (string) $client['email'], 'phone' => (string) ($client['phone'] ?? ''),
                'company_name' => (string) ($client['company_name'] ?? ''), 'budget_range' => (string) ($body['budget_range'] ?? ''), 'expected_deadline' => (string) ($body['expected_deadline'] ?? ''),
            ];

            return Database::transaction(function () use ($in, $x, $metas, $client, $userId, $ip): array {
                $r = EnquiryService::create(null, 'client_portal', $in, ['consent' => true, 'ip' => $ip]);
                Database::run('UPDATE enquiries SET client_id = ? WHERE id = ?', [$client['id'], $r['id']]);
                EnquiryExtras::save((int) $r['id'], $x['details'], $metas);
                EnquiryService::recordExternalEvent((int) $r['id'], 'Sent by the client from their dashboard', $userId);

                return $r;
            });
        } catch (\Throwable $e) {
            array_map([FileStore::class, 'discard'], $metas);
            throw $e;
        }
    }

    /**
     * The client's own requests, newest first, each with where it stands in words the client understands.
     *
     * @return list<array<string,mixed>>
     */
    public static function listFor(int $userId): array
    {
        $client = self::client($userId);
        $rows = Database::all(
            'SELECT e.public_id, e.reference, e.description, e.created_at, e.archived_at, e.converted_project_id, s.kind AS stage_kind, s.name AS stage_name,
                    p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status
               FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id LEFT JOIN projects p ON p.id = e.converted_project_id
              WHERE e.client_id = ? AND e.source = \'client_portal\' ORDER BY e.id DESC LIMIT 100',
            [$client['id']],
        );
        foreach ($rows as &$r) {
            $r['name'] = preg_match('/^Project: (.+)$/m', (string) $r['description'], $m) ? trim($m[1]) : (string) $r['reference'];
            [$r['state'], $r['state_text']] = self::state($r);
        }

        return $rows;
    }

    /** @param array<string,mixed> $r @return array{0:string,1:string} */
    private static function state(array $r): array
    {
        if ($r['project_status'] !== null) {
            return match (true) {
                in_array($r['project_status'], ['draft', 'awaiting_client_approval', 'awaiting_payment'], true) => ['project', 'Project ' . $r['project_code'] . ' created. It is confirmed and scheduled as soon as the advance payment is received.'],
                $r['project_status'] === 'cancelled' => ['closed', 'Project ' . $r['project_code'] . ' was cancelled.'],
                default => ['confirmed', 'Project ' . $r['project_code'] . ' is confirmed (' . (string) Config::get("workflow.project_statuses.{$r['project_status']}", $r['project_status']) . ').'],
            };
        }
        if ($r['stage_kind'] === 'lost') {
            return ['closed', 'We could not take this request forward. Please contact us if you would like to talk about it.'];
        }
        if ($r['stage_kind'] === 'won') {
            return ['review', 'Accepted. We are setting up your project and will send the quotation and the advance-payment details.'];
        }

        return ['review', 'Received. Our team is reviewing it and will contact you.'];
    }
}
