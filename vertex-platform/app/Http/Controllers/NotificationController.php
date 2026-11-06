<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Notifier;

/** The signed-in user's own in-app notifications. No email is involved. */
final class NotificationController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $unread = $request->string('unread') === '1';
        $page = $request->page();
        $res = Notifier::paginate($uid, $unread, $page, 25);

        return $this->page('pages/notifications', ['title' => 'Notifications', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => 25, 'unread' => $unread, 'qs' => $unread ? ['unread' => '1'] : []]);
    }

    /** Marks the user's OWN notifications read (one by id, or all). @param array<string,string> $params */
    public function read(Request $request, array $params = []): Response
    {
        $id = $request->string('id');
        Notifier::markRead($this->actorId(), ctype_digit($id) ? (int) $id : null);

        return Response::redirect($request->string('return') === 'list' ? '/notifications' : '/notifications');
    }
}
