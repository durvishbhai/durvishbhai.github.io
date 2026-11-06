<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Audit;
use Vertex\Services\AttachmentService;
use Vertex\Services\EnquiryPolicy;
use Vertex\Services\Gate;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\TaskPolicy;

/** The ONLY way to obtain an uploaded file. Authorization is the parent record's policy; unauthorised = 404. */
final class AttachmentController extends Controller
{
    /** @param array<string,string> $params */
    public function download(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $att = AttachmentService::find($params['attachment'] ?? '') ?? throw new HttpException(404);
        if (!$this->allowed($uid, $att)) {
            \Vertex\Core\Logger::security('Attachment access denied', ['user' => $uid, 'attachment' => (int) $att['id']]);
            throw new HttpException(404);
        }
        Audit::record('attachment.downloaded', (string) $att['parent_type'], (int) $att['parent_id'], [], [], ['attachment_id' => (int) $att['id']], $uid);

        return Response::download(AttachmentService::path($att), (string) $att['original_name'], (string) $att['mime']);
    }

    /** @param array<string,mixed> $att */
    private function allowed(int $uid, array $att): bool
    {
        if ($att['parent_type'] === 'enquiry') {
            $row = \Vertex\Core\Database::one('SELECT public_id FROM enquiries WHERE id = ?', [$att['parent_id']]);
            $enq = $row === null ? null : EnquiryPolicy::find((string) $row['public_id']);

            return $enq !== null && EnquiryPolicy::canStaff($uid, $enq, Gate::can('enquiries.view_all', $uid) ? 'enquiries.view_all' : 'enquiries.view_assigned');
        }
        if ($att['parent_type'] === 'task') {
            $task = TaskPolicy::findById((int) $att['parent_id']);

            return $task !== null && TaskPolicy::canView($uid, $task) && Gate::can('files.download', $uid);
        }

        return false;
    }
}
