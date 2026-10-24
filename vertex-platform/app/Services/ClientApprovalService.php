<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The CLIENT's own, append-only approval record for an EXACT version of a deliverable.
 *  - Only the owning client (level 'own', `deliverables.respond`) can approve or reject; no staff role can do it for them and an
 *    internal approval is a different record (deliverable_reviews).
 *  - It applies to the current published version only; approving V2 says nothing about V3.
 *  - Opening or downloading a file records nothing here.
 *  - A retried submit (same form token) is a no-op; approving twice is refused.
 */
final class ClientApprovalService
{
    /**
     * @param array<string,mixed> $deliverable
     * @return array{label:string,key:string,version:?array<string,mixed>,latest:?array<string,mixed>}
     */
    public static function stateFor(array $deliverable): array
    {
        if ($deliverable['archived_at'] !== null) {
            return ['label' => 'Archived', 'key' => 'archived', 'version' => null, 'latest' => null];
        }
        $version = $deliverable['published_version_id'] === null ? null : Database::one(
            'SELECT v.*, f.original_name, f.size_bytes FROM deliverable_versions v LEFT JOIN stored_files f ON f.id = v.file_id WHERE v.id = ?',
            [$deliverable['published_version_id']],
        );
        if ($version === null) {
            return ['label' => $deliverable['first_published_at'] === null ? 'No deliverables published yet' : 'Not currently available', 'key' => 'none', 'version' => null, 'latest' => null];
        }
        $latest = Database::one('SELECT * FROM client_approvals WHERE version_id = ? ORDER BY id DESC LIMIT 1', [$version['id']]);
        $key = $latest === null ? 'awaiting' : $latest['action'];

        return ['label' => self::label($key), 'key' => $key, 'version' => $version, 'latest' => $latest];
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'awaiting' => 'Ready for your review',
            'approved' => 'Approved',
            'revision_requested' => 'Revision requested',
            'rejected' => 'Rejected: awaiting a new version',
            'archived' => 'Archived',
            default => 'Not currently available',
        };
    }

    /**
     * @param array<string,mixed> $deliverable
     * @param string $versionPublicId the version the client was looking at; refused when it is no longer the current published one
     * @return bool true when a new record was written (false for a harmless retry)
     */
    public static function respond(int $actorId, array $deliverable, string $versionPublicId, string $action, string $feedback, string $token): bool
    {
        $project = DeliverablePolicy::project($deliverable);
        if (ProjectPolicy::level($actorId, $project) !== 'own' || !Gate::can('deliverables.respond', $actorId) || !DeliverablePolicy::canView($actorId, $deliverable)) {
            throw new HttpException(403);
        }
        if (!in_array($action, ['approved', 'rejected'], true)) {
            throw new ValidationException(['Choose approve or reject.']);
        }
        if ($project['archived_at'] !== null || $project['status'] === 'cancelled' || $deliverable['archived_at'] !== null) {
            throw new ValidationException(['This deliverable is no longer open for a response.']);
        }
        $feedback = (string) FieldRules::text($feedback, $action === 'rejected' ? 'Reason' : 'Feedback', $action === 'rejected' ? 5 : 2, 3000, $action === 'rejected');
        $token = preg_match('/^[a-f0-9]{32}$/', $token) ? $token : null;

        return Database::transaction(function () use ($actorId, $deliverable, $project, $versionPublicId, $action, $feedback, $token): bool {
            $d = Database::one('SELECT id, published_version_id FROM deliverables WHERE id = ? FOR UPDATE', [$deliverable['id']]);
            if ($token !== null && Database::one('SELECT 1 AS x FROM client_approvals WHERE user_id = ? AND submit_token = ?', [$actorId, $token]) !== null) {
                return false; // the same form was submitted twice
            }
            $v = DeliverablePolicy::findVersion($deliverable, $versionPublicId);
            if ($v === null || $d['published_version_id'] === null || (int) $d['published_version_id'] !== (int) $v['id'] || $v['status'] !== 'published') {
                throw new ValidationException(['That version is no longer the current one. Reload the page to see the latest version.']);
            }
            $latest = Database::one('SELECT action FROM client_approvals WHERE version_id = ? ORDER BY id DESC LIMIT 1', [$v['id']]);
            if ($latest !== null && $latest['action'] === 'approved') {
                throw new ValidationException(['You have already approved this version.']);
            }
            Database::run(
                'INSERT INTO client_approvals (public_id, project_id, deliverable_id, version_id, user_id, action, feedback, submit_token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [uuid_hex(), $project['id'], $deliverable['id'], $v['id'], $actorId, $action, $feedback === '' ? null : $feedback, $token],
            );
            $id = Database::insertId();
            $approved = $action === 'approved';
            ProjectActivity::log((int) $project['id'], $actorId, $approved ? 'deliverable.client_approved' : 'deliverable.client_rejected',
                ($approved ? 'Client approved V' : 'Client rejected V') . "{$v['version_no']} of {$deliverable['title']}", 'client', 'deliverable', (int) $deliverable['id']);
            Audit::record($approved ? 'deliverable.client_approved' : 'deliverable.client_rejected', 'deliverable', (int) $deliverable['id'], [], ['version_id' => (int) $v['id'], 'version_no' => (int) $v['version_no'], 'approval_id' => $id], ['project_id' => (int) $project['id']], $actorId);
            $staff = array_unique([...Notifier::staffWith('deliverables.publish', (int) $project['id']), ...Notifier::staffWith('deliverables.review', (int) $project['id'])]);
            Notifier::send($staff, 'deliverable.client_response', $approved ? 'Client approved a deliverable' : 'Client rejected a deliverable', "{$deliverable['code']} V{$v['version_no']}: {$deliverable['title']}",
                '/deliverables/' . $deliverable['public_id'], (int) $project['id'], 'client_approval', $id, "approval:{$id}");

            return true;
        });
    }

    /**
     * Approval/feedback history. Staff see every client response on the deliverable; a client sees only their own.
     *
     * @param array<string,mixed> $deliverable
     * @return list<array<string,mixed>>
     */
    public static function history(int $userId, array $deliverable): array
    {
        if (!DeliverablePolicy::canView($userId, $deliverable)) {
            return [];
        }
        $own = DeliverablePolicy::level($userId, $deliverable) === 'own';

        return Database::all(
            'SELECT a.public_id, a.action, a.feedback, a.created_at, v.version_no, v.label AS version_label, u.name AS client_name, r.reference AS revision_reference
               FROM client_approvals a JOIN deliverable_versions v ON v.id = a.version_id LEFT JOIN users u ON u.id = a.user_id LEFT JOIN revision_requests r ON r.id = a.revision_request_id
              WHERE a.deliverable_id = ?' . ($own ? ' AND a.user_id = ?' : '') . ' ORDER BY a.id DESC',
            $own ? [$deliverable['id'], $userId] : [$deliverable['id']],
        );
    }
}
