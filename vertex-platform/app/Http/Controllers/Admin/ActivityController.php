<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ActivityService;
use Vertex\Services\Audit;

/** Owner only (`activity.view_all`): every account's sign-ins and actions, and a page per account. Read-only. */
final class ActivityController extends Controller
{
    private const PER_PAGE = 25;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $view = $request->string('view') === 'feed' ? 'feed' : 'accounts';
        $page = $request->page();
        $filters = [
            'view' => $view,
            'q' => mb_substr($request->string('q'), 0, 100),
            'group' => in_array($request->string('group'), ['team', 'clients', 'partners'], true) ? $request->string('group') : '',
            'status' => in_array($request->string('status'), ['active', 'pending', 'deactivated', 'suspended', 'archived'], true) ? $request->string('status') : '',
            'category' => isset(ActivityService::CATEGORIES[$request->string('category')]) ? $request->string('category') : '',
            'from' => self::date($request->string('from')),
            'to' => self::date($request->string('to')),
        ];
        $data = ['title' => 'Account activity', 'filters' => $filters, 'overview' => ActivityService::overview(), 'categories' => ActivityService::CATEGORIES, 'page' => $page, 'perPage' => self::PER_PAGE];
        $data += $view === 'feed'
            ? ActivityService::feed(['q' => $filters['q'], 'category' => $filters['category'], 'from' => self::utc($filters['from'], 0), 'to' => self::utc($filters['to'], 1)], $page, self::PER_PAGE)
            : ActivityService::accounts($filters, $page, self::PER_PAGE);

        return $this->page('admin/activity/index', $data);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $account = ActivityService::account((string) ($params['user'] ?? '')) ?? throw new HttpException(404);
        $page = $request->page();
        $dir = in_array($request->string('direction'), ['by', 'to'], true) ? $request->string('direction') : 'both';
        $filters = [
            'direction' => $dir === 'both' ? '' : $dir,
            'category' => isset(ActivityService::CATEGORIES[$request->string('category')]) ? $request->string('category') : '',
            'from' => self::date($request->string('from')),
            'to' => self::date($request->string('to')),
        ];
        $feed = ActivityService::feed(['user' => (int) $account['id'], 'direction' => $dir, 'category' => $filters['category'], 'from' => self::utc($filters['from'], 0), 'to' => self::utc($filters['to'], 1)], $page, self::PER_PAGE);
        Audit::record('activity.account_viewed', 'user', (int) $account['id'], [], [], [], $this->actorId());

        return $this->page('admin/activity/show', ['title' => $account['name'] . ' · activity', 'account' => $account, 'filters' => $filters, 'categories' => ActivityService::CATEGORIES, 'page' => $page, 'perPage' => self::PER_PAGE] + $feed);
    }

    public static function date(string $s): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : '';
    }

    /** Start of the given local date (or of the day after, when $plusDays = 1) in UTC. */
    public static function utc(string $date, int $plusDays): string
    {
        if ($date === '') {
            return '';
        }
        $user = \Vertex\Services\Auth::user();
        $tz = new \DateTimeZone((string) ($user['timezone'] ?? '') ?: (string) config('app.timezone', 'UTC'));

        return (new \DateTimeImmutable($date . ' 00:00:00', $tz))->modify("+{$plusDays} day")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
