<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Revision requests. The client asks; staff review, classify (included vs potentially chargeable) and do the work.
 *
 *  - Only the owning client can submit, only against the CURRENT PUBLISHED version of one of their own deliverables.
 *  - Moves follow config/workflow.php `revision_transitions` exactly; each move is recorded in revision_status_history and audited.
 *  - Classification is advisory-assisted by RevisionTerms but never silent: a reason is required, and contradicting a configured
 *    numeric allowance needs `revisions.override` (administrators).
 *  - Marking a request chargeable creates a DRAFT change request. NOTHING is charged: the client must approve the proposal
 *    (ChangeRequestService) before the request can reach "Approved for Work", and work cannot start from any other state.
 */
final class RevisionService
{
    /**
     * @param array<string,mixed> $deliverable DeliverablePolicy row
     * @param array<string,mixed> $in description, version (public id), token
     * @param list<array<string,mixed>> $files entries of $_FILES (optional attachments, max 3)
     * @return array{public_id:string,reference:string,created:bool}
     */
    public static function submit(int $actorId, array $deliverable, array $in, array $files = [], bool $requireHttpUpload = true): array
    {
        $project = DeliverablePolicy::project($deliverable);
        if (ProjectPolicy::level($actorId, $project) !== 'own' || !Gate::can('revisions.request', $actorId) || !DeliverablePolicy::canView($actorId, $deliverable)) {
            throw new HttpException(403);
        }
        if ($project['archived_at'] !== null || $project['status'] === 'cancelled' || $deliverable['archived_at'] !== null) {
            throw new ValidationException(['This deliverable is no longer open for revision requests.']);
        }
        $description = (string) FieldRules::text($in['description'] ?? '', 'Describe the change you need', 10, 3000);
        $token = preg_match('/^[a-f0-9]{32}$/', (string) ($in['token'] ?? '')) ? (string) $in['token'] : null;
        if ($token !== null) {
            $existing = Database::one('SELECT public_id, reference FROM revision_requests WHERE requested_by = ? AND submit_token = ?', [$actorId, $token]);
            if ($existing !== null) {
                return ['public_id' => (string) $existing['public_id'], 'reference' => (string) $existing['reference'], 'created' => false]; // same form sent twice
            }
        }
        $version = $deliverable['published_version_id'] === null ? null : Database::one('SELECT * FROM deliverable_versions WHERE id = ?', [$deliverable['published_version_id']]);
        if ($version === null || $version['status'] !== 'published' || (($in['version'] ?? '') !== '' && $in['version'] !== $version['public_id'])) {
            throw new ValidationException(['Revisions can be requested on the current published version only. Reload the page to see it.']);
        }
        if (!RateLimiter::hit('revision_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $files = array_values(array_filter($files, static fn (array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (count($files) > 3) {
            throw new ValidationException(['You can attach up to 3 files.']);
        }
        $metas = [];
        try {
            foreach ($files as $f) {
                $metas[] = FileStore::accept($f, 'revision', $requireHttpUpload);
            }

            return Database::transaction(function () use ($actorId, $deliverable, $project, $version, $description, $token, $metas): array {
                $pub = uuid_hex();
                $ref = CrmReference::next('revision');
                Database::run(
                    "INSERT INTO revision_requests (public_id, reference, project_id, deliverable_id, version_id, requested_by, description, status, submit_token, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [$pub, $ref, $project['id'], $deliverable['id'], $version['id'], $actorId, $description, $token],
                );
                $id = Database::insertId();
                foreach ($metas as $m) {
                    Database::run('INSERT INTO revision_files (revision_id, file_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$id, FileStore::insert($m, $actorId)]);
                }
                self::history($id, null, 'submitted', $actorId, null, 'client');
                Database::run('INSERT INTO client_approvals (public_id, project_id, deliverable_id, version_id, user_id, action, feedback, revision_request_id, created_at) VALUES (?, ?, ?, ?, ?, \'revision_requested\', ?, ?, UTC_TIMESTAMP())',
                    [uuid_hex(), $project['id'], $deliverable['id'], $version['id'], $actorId, mb_substr($description, 0, 3000), $id]);
                ProjectActivity::log((int) $project['id'], $actorId, 'revision.submitted', "Revision requested on {$deliverable['title']} (V{$version['version_no']}): {$ref}", 'client', 'revision', $id);
                Audit::record('revision.submitted', 'revision', $id, [], ['reference' => $ref, 'deliverable_id' => (int) $deliverable['id'], 'version_id' => (int) $version['id'], 'attachments' => count($metas)], ['project_id' => (int) $project['id']], $actorId);
                Notifier::send(Notifier::staffWith('revisions.manage', (int) $project['id']), 'revision.received', 'Client revision request received', "{$ref}: {$deliverable['title']} (V{$version['version_no']})",
                    '/revisions/' . $pub, (int) $project['id'], 'revision', $id, "revision:{$id}:received");

                return ['public_id' => $pub, 'reference' => $ref, 'created' => true];
            });
        } catch (\Throwable $e) {
            foreach ($metas as $m) {
                FileStore::discard($m);
            }
            throw $e;
        }
    }

    /**
     * Moves between statuses that need no special workflow. Letters in the transition table decide who may: S staff reviewer,
     * K classifier, O override, C the owning client. X moves are refused here (they belong to ChangeRequestService).
     *
     * @param array<string,mixed> $r
     * @param string $clientNote shown to the client (rejection explanation, resolution, why it is not fixed)
     * @param string $internalNote internal only
     * @param string|null $resolvedVersion public id of the version that addresses the request (optional, for ready_for_client_review)
     */
    public static function transition(int $actorId, array $r, string $to, string $clientNote = '', string $internalNote = '', ?string $resolvedVersion = null): void
    {
        $role = RevisionAccess::role($actorId, $r) ?? throw new HttpException(404);
        $from = (string) $r['status'];
        $letters = Config::get("workflow.revision_transitions.{$from}.{$to}");
        if ($letters === null) {
            throw new ValidationException(['A request that is ' . strtolower((string) Config::get("workflow.revision_statuses.{$from}", $from)) . ' cannot move to ' . strtolower((string) Config::get("workflow.revision_statuses.{$to}", $to)) . '.']);
        }
        if ($letters === 'X') {
            throw new ValidationException(['That step happens through the cost proposal, not directly.']);
        }
        if (in_array($to, ['included_revision', 'potentially_chargeable'], true) && $from !== 'potentially_chargeable') {
            throw new ValidationException(['Use the classification form so the reason is recorded.']);
        }
        self::assertMayMove($actorId, $r, $role, (string) $letters);
        self::assertOpen($r);
        $clientNote = (string) FieldRules::text($clientNote, 'Explanation', 3, 3000, in_array($to, ['rejected'], true) || ($to === 'resolved' && $role === 'staff') || ($from === 'ready_for_client_review' && $to === 'in_progress'));
        $internalNote = (string) FieldRules::text($internalNote, 'Internal note', 2, 3000, false);
        if ($to === 'in_progress' && $r['scope_decision'] === 'chargeable') {
            $cr = Database::one("SELECT status FROM change_requests WHERE revision_request_id = ? ORDER BY id DESC LIMIT 1", [$r['id']]);
            if ($cr === null || $cr['status'] !== 'approved') {
                throw new ValidationException(['Chargeable work cannot start until the client has approved the additional cost.']);
            }
        }
        $resolvedId = null;
        if ($resolvedVersion !== null && $resolvedVersion !== '') {
            $rv = preg_match('/^[a-f0-9]{32}$/', $resolvedVersion) ? Database::one('SELECT id, version_no FROM deliverable_versions WHERE public_id = ? AND deliverable_id = ?', [$resolvedVersion, $r['deliverable_id']]) : null;
            if ($rv === null || (int) $rv['id'] === (int) $r['version_id']) {
                throw new ValidationException(['Choose a different version of this deliverable.']);
            }
            $resolvedId = (int) $rv['id'];
        }

        Database::transaction(function () use ($actorId, $r, $role, $from, $to, $clientNote, $internalNote, $resolvedId): void {
            $locked = Database::one('SELECT status, assigned_to FROM revision_requests WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($locked === null || $locked['status'] !== $from) {
                throw new ValidationException(['This request changed while you were working. Reload and try again.']);
            }
            $sets = ['status = ?', 'updated_at = UTC_TIMESTAMP()'];
            $args = [$to];
            if ($to === 'resolved') {
                $sets[] = 'completed_at = UTC_TIMESTAMP()';
                if ($clientNote !== '') {
                    $sets[] = 'resolution = ?';
                    $args[] = $clientNote;
                }
            }
            if ($to === 'rejected' || ($to === 'in_progress' && $from === 'ready_for_client_review' && $role === 'client')) {
                $sets[] = 'client_response = ?';
                $args[] = $clientNote;
            }
            if ($to === 'under_review' && $locked['assigned_to'] === null && $role === 'staff') {
                $sets[] = 'assigned_to = ?';
                $args[] = $actorId;
            }
            if ($to === 'under_review') {
                $sets[] = "scope_decision = 'unclassified'";
            }
            if ($resolvedId !== null) {
                $sets[] = 'resolved_version_id = ?';
                $args[] = $resolvedId;
            }
            $args[] = $r['id'];
            Database::run('UPDATE revision_requests SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
            self::history((int) $r['id'], $from, $to, $actorId, $clientNote !== '' ? $clientNote : null, self::visibility($from, $to));
            if (in_array($to, ['cancelled', 'rejected'], true)) { // a closed request cannot leave a live cost proposal behind
                Database::run("UPDATE change_requests SET status = 'withdrawn', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = 'Request closed' WHERE revision_request_id = ? AND status IN ('draft','pending_client')", [$actorId, $r['id']]);
            }
            if ($internalNote !== '') {
                Database::run('INSERT INTO revision_notes (revision_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$r['id'], $actorId, $internalNote]);
            }
            $labels = (array) Config::get('workflow.revision_statuses');
            ProjectActivity::log((int) $r['project_id'], $actorId, 'revision.status_changed', "{$r['reference']}: " . ($role === 'client' ? (string) Config::get("workflow.revision_client_labels.{$to}") : $labels[$to]),
                self::visibility($from, $to) === 'client' ? 'client' : 'internal', 'revision', (int) $r['id'], ['from' => $from, 'to' => $to]);
            Audit::record('revision.status_changed', 'revision', (int) $r['id'], ['status' => $from], ['status' => $to], ['project_id' => (int) $r['project_id'], 'by' => $role], $actorId);
            if ($role === 'staff' && in_array($to, ['ready_for_client_review', 'rejected', 'resolved', 'in_progress', 'approved_for_work'], true) && $r['requested_by'] !== null) {
                Notifier::send([(int) $r['requested_by']], 'revision.update', 'Update on your revision request', "{$r['reference']}: " . (string) Config::get("workflow.revision_client_labels.{$to}"),
                    '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:status:{$to}:" . self::countHistory((int) $r['id']));
            }
            if ($role === 'client') {
                Notifier::send(Notifier::staffWith('revisions.manage', (int) $r['project_id']), 'revision.client_update', 'Client updated a revision request', "{$r['reference']}: " . $labels[$to],
                    '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:client_status:{$to}:" . self::countHistory((int) $r['id']), $actorId);
            }
        });
    }

    /**
     * Classifies a request that is Under Review.
     *  - 'included': it is covered by the agreed scope. status -> Included Revision.
     *  - 'chargeable': it exceeds it. status -> Potentially Chargeable Change and a DRAFT change request (proposed scope, reason,
     *    extra cost, timeline) is created. Nothing is charged and the client is not contacted until a proposal is sent.
     * A numeric allowance from RevisionTerms is advice, except that contradicting it needs `revisions.override`.
     *
     * @param array<string,mixed> $r
     * @param array<string,mixed> $in reason (internal), plus for chargeable: scope_description, client_reason, amount (rupees), days, due_date
     */
    public static function classify(int $actorId, array $r, string $decision, array $in): void
    {
        RevisionAccess::authorizeStaff($actorId, $r, 'revisions.classify');
        self::assertOpen($r);
        if ($r['status'] !== 'under_review') {
            throw new ValidationException(['Only a request that is Under Review can be classified. Start the review first.']);
        }
        if (!in_array($decision, ['included', 'chargeable'], true)) {
            throw new ValidationException(['Choose included or chargeable.']);
        }
        $reason = (string) FieldRules::text($in['reason'] ?? '', 'Why it is ' . $decision, 10, 3000);
        $project = RevisionAccess::project($r);
        $terms = RevisionTerms::forProject($project, (int) $r['id']);
        $contradicts = $terms['limit'] !== null && (($decision === 'included' && $terms['used'] >= $terms['limit']) || ($decision === 'chargeable' && $terms['used'] < $terms['limit']));
        if ($contradicts && !Gate::can('revisions.override', $actorId)) {
            throw new ValidationException([$decision === 'included'
                ? "The agreed allowance ({$terms['limit']}) is already used up. Only an administrator can include another revision."
                : "The agreed allowance ({$terms['limit']}) is not used up yet ({$terms['used']} used). Only an administrator can classify this as chargeable."]);
        }
        $cr = null;
        if ($decision === 'chargeable') {
            $cr = ChangeRequestService::validateProposal($in);
        }
        $snapshot = json_encode([
            'source' => $terms['source'], 'limit' => $terms['limit'], 'used_before' => $terms['used'], 'policy_version' => $terms['policy']['version'] ?? null,
            'reference' => $terms['policy']['source_reference'] ?? null, 'contradicts_allowance' => $contradicts, 'overridden' => $contradicts,
        ], JSON_THROW_ON_ERROR);
        $to = $decision === 'included' ? 'included_revision' : 'potentially_chargeable';

        Database::transaction(function () use ($actorId, $r, $project, $reason, $decision, $to, $cr, $snapshot, $contradicts): void {
            $locked = Database::one('SELECT status FROM revision_requests WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($locked === null || $locked['status'] !== 'under_review') {
                throw new ValidationException(['This request changed while you were working. Reload and try again.']);
            }
            Database::run('UPDATE revision_requests SET status = ?, scope_decision = ?, scope_reason = ?, policy_snapshot_json = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $decision, $reason, $snapshot, $r['id']]);
            self::history((int) $r['id'], 'under_review', $to, $actorId, null, $decision === 'included' ? 'client' : 'internal');
            Database::run('INSERT INTO revision_notes (revision_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$r['id'], $actorId, "Classified as {$decision}: {$reason}"]);
            $changeId = null;
            if ($cr !== null) {
                $changeId = ChangeRequestService::createDraft($actorId, $r, $project, $cr);
            }
            ProjectActivity::log((int) $r['project_id'], $actorId, 'revision.classified', "{$r['reference']} classified as {$decision}", 'internal', 'revision', (int) $r['id']);
            Audit::record('revision.classified', 'revision', (int) $r['id'], ['scope_decision' => 'unclassified'], ['scope_decision' => $decision, 'status' => $to], ['project_id' => (int) $r['project_id'], 'override' => $contradicts, 'change_request_id' => $changeId], $actorId);
            if ($decision === 'chargeable') {
                Notifier::send(Notifier::staffWith('revisions.override', (int) $r['project_id']), 'revision.potentially_chargeable', 'Revision classified as potentially chargeable', "{$r['reference']}: {$r['deliverable_title']}",
                    '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:chargeable:" . self::countHistory((int) $r['id']), $actorId);
            } elseif ($r['requested_by'] !== null) {
                Notifier::send([(int) $r['requested_by']], 'revision.update', 'Update on your revision request', "{$r['reference']}: " . (string) Config::get('workflow.revision_client_labels.included_revision'),
                    '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:included:" . self::countHistory((int) $r['id']));
            }
        });
    }

    /** Administrator override: potentially chargeable -> included. Withdraws any draft/pending proposal. Audited with before/after. @param array<string,mixed> $r */
    public static function overrideToIncluded(int $actorId, array $r, string $reason): void
    {
        RevisionAccess::authorizeStaff($actorId, $r, 'revisions.override');
        self::assertOpen($r);
        if ($r['status'] !== 'potentially_chargeable') {
            throw new ValidationException(['Only a potentially chargeable request can be overridden to included.']);
        }
        $reason = (string) FieldRules::text($reason, 'Reason for the override', 10, 3000);
        Database::transaction(function () use ($actorId, $r, $reason): void {
            $locked = Database::one('SELECT status FROM revision_requests WHERE id = ? FOR UPDATE', [$r['id']]);
            if ($locked === null || $locked['status'] !== 'potentially_chargeable') {
                throw new ValidationException(['This request changed while you were working. Reload and try again.']);
            }
            Database::run("UPDATE change_requests SET status = 'withdrawn', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = 'Overridden: included in scope' WHERE revision_request_id = ? AND status IN ('draft','pending_client')", [$actorId, $r['id']]);
            Database::run("UPDATE revision_requests SET status = 'included_revision', scope_decision = 'included', scope_reason = CONCAT(COALESCE(scope_reason, ''), '\n[Override] ', ?), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $r['id']]);
            self::history((int) $r['id'], 'potentially_chargeable', 'included_revision', $actorId, null, 'client');
            Database::run('INSERT INTO revision_notes (revision_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$r['id'], $actorId, "Override to included: {$reason}"]);
            Audit::record('revision.classification_overridden', 'revision', (int) $r['id'], ['scope_decision' => 'chargeable', 'status' => 'potentially_chargeable'], ['scope_decision' => 'included', 'status' => 'included_revision'], ['project_id' => (int) $r['project_id']], $actorId);
            ProjectActivity::log((int) $r['project_id'], $actorId, 'revision.overridden', "{$r['reference']}: classification overridden to included", 'internal', 'revision', (int) $r['id']);
        });
    }

    /** @param array<string,mixed> $r */
    public static function assign(int $actorId, array $r, ?string $userPublicId): void
    {
        RevisionAccess::authorizeStaff($actorId, $r, 'revisions.manage');
        self::assertOpen($r);
        $new = null;
        if ($userPublicId !== null && $userPublicId !== '') {
            $u = preg_match('/^[a-f0-9]{32}$/', $userPublicId) ? Database::one(
                "SELECT u.id FROM users u WHERE u.public_id = ? AND u.status = 'active'", [$userPublicId]) : null;
            if ($u === null || !in_array((int) $u['id'], Notifier::staffWith('revisions.manage', (int) $r['project_id']), true)) {
                throw new ValidationException(['Choose someone who works on this project and handles revisions.']);
            }
            $new = (int) $u['id'];
        }
        if ($new === ($r['assigned_to'] === null ? null : (int) $r['assigned_to'])) {
            return;
        }
        Database::transaction(function () use ($actorId, $r, $new): void {
            Database::run('UPDATE revision_requests SET assigned_to = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$new, $r['id']]);
            Audit::record('revision.assigned', 'revision', (int) $r['id'], ['assigned_to' => $r['assigned_to'] === null ? null : (int) $r['assigned_to']], ['assigned_to' => $new], ['project_id' => (int) $r['project_id']], $actorId);
            ProjectActivity::log((int) $r['project_id'], $actorId, 'revision.assigned', "{$r['reference']} " . ($new === null ? 'unassigned' : 'assigned to a reviewer'), 'internal', 'revision', (int) $r['id']);
            if ($new !== null) {
                Notifier::send([$new], 'revision.assigned', 'A revision request was assigned to you', "{$r['reference']}: {$r['deliverable_title']}", '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:assigned:{$new}:" . self::countHistory((int) $r['id']), $actorId);
            }
        });
    }

    /** Internal note: never shown to the client. @param array<string,mixed> $r */
    public static function addNote(int $actorId, array $r, string $body): void
    {
        RevisionAccess::authorizeStaff($actorId, $r, 'revisions.manage');
        $body = (string) FieldRules::text($body, 'Note', 2, 3000);
        Database::transaction(function () use ($actorId, $r, $body): void {
            Database::run('INSERT INTO revision_notes (revision_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$r['id'], $actorId, $body]);
            Audit::record('revision.note_added', 'revision', (int) $r['id'], [], [], ['project_id' => (int) $r['project_id']], $actorId);
        });
    }

    /** The response the CLIENT can read. @param array<string,mixed> $r */
    public static function setClientResponse(int $actorId, array $r, string $text): void
    {
        RevisionAccess::authorizeStaff($actorId, $r, 'revisions.manage');
        $text = (string) FieldRules::text($text, 'Response', 3, 3000);
        Database::transaction(function () use ($actorId, $r, $text): void {
            Database::run('UPDATE revision_requests SET client_response = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$text, $r['id']]);
            Audit::record('revision.client_response_set', 'revision', (int) $r['id'], [], [], ['project_id' => (int) $r['project_id']], $actorId);
            if ($r['requested_by'] !== null) {
                Notifier::send([(int) $r['requested_by']], 'revision.update', 'Response to your revision request', "{$r['reference']}: you have a new response", '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision', (int) $r['id'], "revision:{$r['id']}:response:" . self::countHistory((int) $r['id']) . ':' . substr(hash('sha256', $text), 0, 12));
            }
        });
    }

    /** Statuses the user may move this request to right now. @param array<string,mixed> $r @return array<string,string> status => label */
    public static function allowedMoves(int $userId, array $r): array
    {
        $role = RevisionAccess::role($userId, $r);
        if ($role === null || $r['project_archived_at'] !== null || $r['project_status'] === 'cancelled') {
            return [];
        }
        $out = [];
        foreach ((array) Config::get("workflow.revision_transitions.{$r['status']}", []) as $to => $letters) {
            if ($letters === 'X' || (in_array($to, ['included_revision', 'potentially_chargeable'], true) && $r['status'] !== 'potentially_chargeable')) {
                continue;
            }
            if (self::mayMove($userId, $r, $role, (string) $letters)) {
                $out[$to] = (string) Config::get($role === 'client' ? "workflow.revision_client_labels.{$to}" : "workflow.revision_statuses.{$to}", $to);
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $r */
    private static function mayMove(int $userId, array $r, string $role, string $letters): bool
    {
        if ($role === 'client') {
            return str_contains($letters, 'C');
        }

        return (str_contains($letters, 'S') && Gate::can('revisions.manage', $userId))
            || (str_contains($letters, 'K') && Gate::can('revisions.classify', $userId))
            || (str_contains($letters, 'O') && Gate::can('revisions.override', $userId));
    }

    /** @param array<string,mixed> $r */
    private static function assertMayMove(int $userId, array $r, string $role, string $letters): void
    {
        if (!self::mayMove($userId, $r, $role, $letters)) {
            \Vertex\Core\Logger::security('Revision transition denied', ['user' => $userId, 'revision' => (int) $r['id']]);
            throw new HttpException(403);
        }
    }

    /** @param array<string,mixed> $r */
    private static function assertOpen(array $r): void
    {
        if ($r['project_archived_at'] !== null || $r['project_status'] === 'cancelled') {
            throw new ValidationException(['This project is closed; revision requests cannot be changed.']);
        }
    }

    private static function visibility(string $from, string $to): string
    {
        if ($from === 'potentially_chargeable' || $to === 'potentially_chargeable' || ($to === 'under_review' && in_array($from, ['included_revision'], true))) {
            return 'internal';
        }

        return 'client';
    }

    public static function history(int $revisionId, ?string $from, string $to, ?int $actorId, ?string $note, string $visibility): void
    {
        Database::run('INSERT INTO revision_status_history (revision_id, from_status, to_status, actor_id, note, visibility, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$revisionId, $from, $to, $actorId, $note, $visibility]);
    }

    private static function countHistory(int $revisionId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM revision_status_history WHERE revision_id = ?', [$revisionId])['c'];
    }
}
