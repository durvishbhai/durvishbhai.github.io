<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Repositories\AuditRepository;
use Vertex\Services\Audit;

/** Read-only audit log viewer. There is no edit/delete route, controller method or SQL path for audit rows. */
final class AuditController extends Controller
{
    private const PER_PAGE = 30;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $repo = new AuditRepository();
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'action' => mb_substr(preg_replace('/[^a-z0-9_.]/i', '', $request->string('action')) ?? '', 0, 60),
            'entity' => preg_match('/^[a-z_]{2,60}$/', $request->string('entity')) ? $request->string('entity') : '',
            'from_date' => $this->date($request->string('from')),
            'to_date' => $this->date($request->string('to')),
        ];
        $page = max(1, (int) $request->string('page', '1'));
        $result = $repo->search([
            'q' => $filters['q'], 'action' => $filters['action'], 'entity' => $filters['entity'],
            'from' => $this->utcBoundary($filters['from_date'], 0),
            'to' => $this->utcBoundary($filters['to_date'], 1),
        ], $page, self::PER_PAGE);

        return $this->page('admin/audit/index', [
            'title' => 'Audit log',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'filters' => $filters,
            'entities' => $repo->entityTypes(),
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        $r = Audit::verify();
        Audit::record('audit.verified', 'audit_log', null, [], [], ['ok' => $r['ok'], 'checked' => $r['checked'], 'broken_at' => $r['broken_at']], $this->actorId());

        return $this->ok('/admin/audit', $r['ok']
            ? "Integrity check passed: {$r['checked']} entries verified, chain intact."
            : "INTEGRITY FAILURE at entry #{$r['broken_at']}: {$r['reason']}.");
    }

    private function date(string $s): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : '';
    }

    /** Start of the given local date (or of the day after, when $plusDays = 1) converted to UTC. */
    private function utcBoundary(string $date, int $plusDays): string
    {
        if ($date === '') {
            return '';
        }
        $user = \Vertex\Services\Auth::user();
        $tz = new \DateTimeZone((string) ($user['timezone'] ?? '') ?: (string) config('app.timezone', 'UTC'));
        $d = new \DateTimeImmutable($date . ' 00:00:00', $tz);

        return $d->modify("+{$plusDays} day")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
