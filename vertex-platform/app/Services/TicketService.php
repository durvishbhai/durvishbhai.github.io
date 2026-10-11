<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Bug reports and support tickets on ONE ticket infrastructure with TWO distinguishable workflows (config/workflow.php):
 * kind='bug' has the technical workflow and the warranty review; kind='support' has the general ticket workflow.
 *
 *  - The client reports and answers; staff triage and decide; a client can never mark an issue confirmed or fixed.
 *  - The conversation (ticket_messages) and internal notes (ticket_notes) are different tables; the author and the author's role are
 *    set by the server from the session, never from the request.
 *  - Nothing closes automatically: not a reply, not an upload, not a (future) email.
 *  - Every mutation: access check, validation, one transaction holding the change, its history row, notification events and the audit
 *    entry; the ticket row is locked so concurrent edits cannot interleave.
 */
final class TicketService
{
    // ------------------------------------------------------------------------------------------------------- creation

    /**
     * A client reports a bug on one of THEIR projects (as a draft or submitted).
     *
     * @param array<string,mixed> $in project (public id), title, description, affected_url, steps, expected, actual, browser, device, severity, token
     * @param list<array<string,mixed>> $files up to 3 entries of $_FILES
     * @return array{public_id:string,reference:string,created:bool}
     */
    public static function createBug(int $actorId, array $in, array $files, bool $asDraft, string $userAgent = '', bool $requireHttpUpload = true): array
    {
        $client = self::clientOf($actorId);
        if (!Gate::can('bugs.report', $actorId)) {
            throw new HttpException(403);
        }
        $existing = self::existingByToken($actorId, (string) ($in['token'] ?? ''));
        if ($existing !== null) {
            return $existing + ['created' => false];
        }
        $project = self::ownProject($client, (string) ($in['project'] ?? ''), true);
        $d = self::validateBug($in, $asDraft);
        $metas = self::acceptFiles($actorId, $files, $requireHttpUpload, 'ticket_create_user');
        $warranty = WarrantyService::forProject($project);
        $snapshot = json_encode(self::snapshot($warranty, $project), JSON_THROW_ON_ERROR);
        $eligibility = $asDraft ? null : ($warranty['status'] === 'active' ? 'awaiting_technical_review' : 'submitted_for_assessment');

        try {
            return Database::transaction(function () use ($actorId, $client, $project, $d, $in, $asDraft, $metas, $userAgent, $snapshot, $eligibility): array {
                $pub = uuid_hex();
                $ref = CrmReference::next('bug');
                $token = preg_match('/^[a-f0-9]{32}$/', (string) ($in['token'] ?? '')) ? (string) $in['token'] : null;
                Database::run(
                    "INSERT INTO tickets (public_id, reference, kind, client_id, project_id, requester_id, category, subject, description, priority, severity, status, submit_token, created_at, submitted_at, updated_at)
                     VALUES (?, ?, 'bug', ?, ?, ?, 'bug_report', ?, ?, 'normal', ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())",
                    [$pub, $ref, $client['id'], $project['id'], $actorId, $d['title'], $d['description'] ?? '', $d['severity'], $asDraft ? 'draft' : 'open', $token, $asDraft ? null : gmdate('Y-m-d H:i:s')],
                );
                $id = Database::insertId();
                Database::run(
                    'INSERT INTO bug_reports (ticket_id, affected_url, steps_to_reproduce, expected_behaviour, actual_behaviour, browser_details, device_details, captured_user_agent, warranty_snapshot_json, eligibility)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $d['url'], $d['steps'], $d['expected'], $d['actual'], $d['browser'], $d['device'], $userAgent === '' ? null : mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent), 0, 255), $asDraft ? null : $snapshot, $eligibility],
                );
                self::attach($id, null, $metas, 'client', $actorId);
                self::activity($id, $actorId, $asDraft ? 'draft_saved' : 'created', null, $asDraft ? 'draft' : 'open', null, 'client');
                Audit::record($asDraft ? 'ticket.draft_saved' : 'ticket.created', 'ticket', $id, [], ['reference' => $ref, 'kind' => 'bug', 'severity' => $d['severity'], 'eligibility' => $eligibility], ['project_id' => (int) $project['id']], $actorId);
                if (!$asDraft) {
                    self::notifyNew($id, $pub, $ref, 'bug', (int) $project['id'], 'bug.submitted', 'New bug report', $d['title'], $actorId);
                }

                return ['public_id' => $pub, 'reference' => $ref];
            }) + ['created' => true];
        } catch (\Throwable $e) {
            self::discard($metas);
            throw $e;
        }
    }

    /** Submits (or edits) the client's own DRAFT bug report. Uses the same validation as a direct submission. @param array<string,mixed> $t @param array<string,mixed> $in */
    public static function submitDraft(int $actorId, array $t, array $in, bool $submit, string $userAgent = ''): void
    {
        if (TicketAccess::role($actorId, $t) !== 'client' || $t['status'] !== 'draft' || (int) $t['requester_id'] !== $actorId) {
            throw new HttpException(404);
        }
        $client = self::clientOf($actorId);
        $project = self::ownProject($client, (string) ($in['project'] ?? $t['project_public_id']), true);
        $d = self::validateBug($in, !$submit);
        $warranty = WarrantyService::forProject($project);
        $snapshot = json_encode(self::snapshot($warranty, $project), JSON_THROW_ON_ERROR);
        $eligibility = $warranty['status'] === 'active' ? 'awaiting_technical_review' : 'submitted_for_assessment';
        Database::transaction(function () use ($actorId, $t, $project, $d, $submit, $snapshot, $eligibility, $userAgent): void {
            $locked = Database::one('SELECT status FROM tickets WHERE id = ? FOR UPDATE', [$t['id']]);
            if ($locked === null || $locked['status'] !== 'draft') {
                throw new ValidationException(['This report was already submitted.']);
            }
            Database::run('UPDATE tickets SET project_id = ?, subject = ?, description = ?, severity = ?, status = ?, submitted_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$project['id'], $d['title'], $d['description'] ?? '', $d['severity'], $submit ? 'open' : 'draft', $submit ? gmdate('Y-m-d H:i:s') : null, $t['id']]);
            Database::run('UPDATE bug_reports SET affected_url = ?, steps_to_reproduce = ?, expected_behaviour = ?, actual_behaviour = ?, browser_details = ?, device_details = ?, captured_user_agent = COALESCE(?, captured_user_agent),
                           warranty_snapshot_json = ?, eligibility = ? WHERE ticket_id = ?',
                [$d['url'], $d['steps'], $d['expected'], $d['actual'], $d['browser'], $d['device'], $userAgent === '' ? null : mb_substr($userAgent, 0, 255), $submit ? $snapshot : null, $submit ? $eligibility : null, $t['id']]);
            if ($submit) {
                self::activity((int) $t['id'], $actorId, 'created', 'draft', 'open', null, 'client');
                Audit::record('ticket.created', 'ticket', (int) $t['id'], ['status' => 'draft'], ['status' => 'open', 'kind' => 'bug', 'severity' => $d['severity'], 'eligibility' => $eligibility], ['project_id' => (int) $project['id']], $actorId);
                self::notifyNew((int) $t['id'], (string) $t['public_id'], (string) $t['reference'], 'bug', (int) $project['id'], 'bug.submitted', 'New bug report', $d['title'], $actorId);
            }
        });
    }

    /**
     * A general support ticket. Priority is set by staff, never by the client.
     *
     * @param array<string,mixed> $in category, subject, description, project (optional public id), token
     * @param list<array<string,mixed>> $files
     * @return array{public_id:string,reference:string,created:bool}
     */
    public static function createSupport(int $actorId, array $in, array $files, bool $requireHttpUpload = true): array
    {
        $client = self::clientOf($actorId);
        if (!Gate::can('tickets.create', $actorId)) {
            throw new HttpException(403);
        }
        $existing = self::existingByToken($actorId, (string) ($in['token'] ?? ''));
        if ($existing !== null) {
            return $existing + ['created' => false];
        }
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $cats = array_diff_key((array) Config::get('workflow.ticket_categories'), ['bug_report' => 1]);
        $category = $try(static fn () => (string) FieldRules::choice($in['category'] ?? '', $cats, 'category'));
        $subject = $try(static fn () => (string) FieldRules::line($in['subject'] ?? '', 'Subject', 5, 200));
        $description = $try(static fn () => (string) FieldRules::text($in['description'] ?? '', 'Description', 10, 5000));
        $project = null;
        if (FieldRules::clean($in['project'] ?? '') !== '') {
            $project = $try(fn () => self::ownProject($client, (string) $in['project'], false));
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $metas = self::acceptFiles($actorId, $files, $requireHttpUpload, 'ticket_create_user');

        try {
            return Database::transaction(function () use ($actorId, $client, $project, $category, $subject, $description, $in, $metas): array {
                $pub = uuid_hex();
                $ref = CrmReference::next('ticket');
                $token = preg_match('/^[a-f0-9]{32}$/', (string) ($in['token'] ?? '')) ? (string) $in['token'] : null;
                Database::run(
                    "INSERT INTO tickets (public_id, reference, kind, client_id, project_id, requester_id, category, subject, description, priority, status, submit_token, created_at, submitted_at, updated_at)
                     VALUES (?, ?, 'support', ?, ?, ?, ?, ?, ?, 'normal', 'open', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [$pub, $ref, $client['id'], $project['id'] ?? null, $actorId, $category, $subject, $description, $token],
                );
                $id = Database::insertId();
                self::attach($id, null, $metas, 'client', $actorId);
                self::activity($id, $actorId, 'created', null, 'open', null, 'client');
                Audit::record('ticket.created', 'ticket', $id, [], ['reference' => $ref, 'kind' => 'support', 'category' => $category], ['project_id' => $project['id'] ?? null], $actorId);
                self::notifyNew($id, $pub, $ref, 'support', $project === null ? null : (int) $project['id'], 'ticket.submitted', 'New support ticket', $subject, $actorId);

                return ['public_id' => $pub, 'reference' => $ref];
            }) + ['created' => true];
        } catch (\Throwable $e) {
            self::discard($metas);
            throw $e;
        }
    }

    // ------------------------------------------------------------------------------------------------------- conversation

    /**
     * A message in the client-visible conversation. The author is the signed-in user and the role is derived from their access to
     * this ticket: a client cannot post as staff and staff cannot post as the client. Workers cannot post to clients.
     *
     * @param array<string,mixed> $t
     * @param list<array<string,mixed>> $files
     * @return bool true when a new message was written (false for a retried submission)
     */
    public static function reply(int $actorId, array $t, string $body, array $files = [], string $token = '', bool $requireHttpUpload = true): bool
    {
        $role = TicketAccess::role($actorId, $t) ?? throw new HttpException(404);
        if ($role === 'client' ? !Gate::can('tickets.reply_own', $actorId) : !($role === 'staff' && Gate::can('tickets.reply', $actorId))) {
            throw new HttpException(403);
        }
        if ($t['status'] === 'draft') {
            throw new ValidationException(['Submit the report before adding messages.']);
        }
        if ($t['status'] === 'closed') {
            throw new ValidationException(['This ticket is closed. Reopen it to continue the conversation.']);
        }
        $body = (string) FieldRules::text($body, 'Message', 1, 5000);
        $token = preg_match('/^[a-f0-9]{32}$/', $token) ? $token : null;
        if ($token !== null && Database::one('SELECT 1 AS x FROM ticket_messages WHERE author_id = ? AND submit_token = ?', [$actorId, $token]) !== null) {
            return false;
        }
        if (!RateLimiter::hit('ticket_message_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $metas = self::acceptFiles($actorId, $files, $requireHttpUpload, null);
        $authorRole = $role === 'client' ? 'client' : 'staff';

        try {
            return Database::transaction(function () use ($actorId, $t, $body, $token, $metas, $authorRole): bool {
                $locked = Database::one('SELECT status, first_staff_response_at FROM tickets WHERE id = ? FOR UPDATE', [$t['id']]);
                if ($locked === null || $locked['status'] === 'closed') {
                    throw new ValidationException(['This ticket is closed.']);
                }
                Database::run('INSERT INTO ticket_messages (ticket_id, author_id, author_role, body, submit_token, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$t['id'], $actorId, $authorRole, $body, $token]);
                $mid = Database::insertId();
                self::attach((int) $t['id'], $mid, $metas, 'client', $actorId);
                $sets = ['updated_at = UTC_TIMESTAMP()'];
                $newStatus = null;
                if ($authorRole === 'staff' && $locked['first_staff_response_at'] === null) {
                    $sets[] = 'first_staff_response_at = UTC_TIMESTAMP()';
                }
                if ($authorRole === 'client') {
                    // the client answering a question puts the ticket back in the team's hands; nothing is ever closed by a reply
                    if ($t['kind'] === 'bug' && $locked['status'] === 'need_more_information') {
                        $newStatus = 'open';
                    } elseif ($t['kind'] === 'support' && $locked['status'] === 'awaiting_client_response') {
                        $newStatus = $t['assigned_to'] !== null ? 'in_progress' : 'open';
                    }
                }
                if ($newStatus !== null) {
                    $sets[] = 'status = ' . "'" . $newStatus . "'";
                }
                Database::run('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?', [$t['id']]);
                if ($newStatus !== null) {
                    self::activity((int) $t['id'], $actorId, 'status', (string) $locked['status'], $newStatus, 'The client replied.', 'client');
                }
                self::activity((int) $t['id'], $actorId, 'message', null, null, null, 'client');
                Audit::record($authorRole === 'client' ? 'ticket.client_reply' : 'ticket.staff_reply', 'ticket', (int) $t['id'], [], ['message_id' => $mid], ['project_id' => $t['project_id'] === null ? null : (int) $t['project_id']], $actorId);
                if ($authorRole === 'staff') {
                    Notifier::send([(int) $t['client_user_id']], 'ticket.staff_reply', 'New reply to your ' . ($t['kind'] === 'bug' ? 'bug report' : 'ticket'), "{$t['reference']}: {$t['subject']}",
                        '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket_message', $mid, "ticket:{$t['id']}:message:{$mid}");
                } else {
                    Notifier::send(self::staffRecipients($t), 'ticket.client_reply', 'Client replied on a ticket', "{$t['reference']}: {$t['subject']}", '/tickets/' . $t['public_id'],
                        $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket_message', $mid, "ticket:{$t['id']}:message:{$mid}", $actorId);
                }

                return true;
            });
        } catch (\Throwable $e) {
            self::discard($metas);
            throw $e;
        }
    }

    /** Internal note / technical finding / decision note. Staff-only table; never joined into anything a client can reach. @param array<string,mixed> $t */
    public static function addNote(int $actorId, array $t, string $body, string $kind = 'note'): void
    {
        TicketAccess::authorize($actorId, $t, 'tickets.note');
        $kind = (string) FieldRules::choice($kind, ['note' => 'Note', 'technical_finding' => 'Technical finding', 'decision' => 'Decision'], 'note type', false, 'note');
        $body = (string) FieldRules::text($body, 'Note', 2, 5000);
        Database::transaction(function () use ($actorId, $t, $body, $kind): void {
            Database::run('INSERT INTO ticket_notes (ticket_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$t['id'], $actorId, $kind, $body]);
            Database::run('UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$t['id']]);
            self::activity((int) $t['id'], $actorId, $kind === 'technical_finding' ? 'finding' : 'note', null, null, null, 'internal');
            Audit::record($kind === 'technical_finding' ? 'ticket.technical_finding' : 'ticket.internal_note', 'ticket', (int) $t['id'], [], ['kind' => $kind, 'length' => mb_strlen($body)], [], $actorId);
        });
    }

    /** A file only staff can see (screenshots from a reproduction, logs). @param array<string,mixed> $t @param array<string,mixed> $file */
    public static function attachInternal(int $actorId, array $t, array $file, bool $requireHttpUpload = true): void
    {
        TicketAccess::authorize($actorId, $t, 'tickets.note');
        $metas = self::acceptFiles($actorId, [$file], $requireHttpUpload, null);
        try {
            Database::transaction(function () use ($actorId, $t, $metas): void {
                self::attach((int) $t['id'], null, $metas, 'internal', $actorId);
                self::activity((int) $t['id'], $actorId, 'internal_file', null, null, null, 'internal');
                Audit::record('ticket.internal_file', 'ticket', (int) $t['id'], [], [], [], $actorId);
            });
        } catch (\Throwable $e) {
            self::discard($metas);
            throw $e;
        }
    }

    // ------------------------------------------------------------------------------------------------------- workflow

    /** @param array<string,mixed> $t @return array<string,string> status => label of the moves THIS user may make now */
    public static function allowedMoves(int $userId, array $t): array
    {
        $role = TicketAccess::role($userId, $t);
        if ($role === null || $t['status'] === 'draft' || ($t['project_archived_at'] ?? null) !== null) {
            return [];
        }
        $out = [];
        foreach (self::transitionsFrom($t) as $to => $letters) {
            if ($letters === 'X' || !self::mayMove($userId, $t, $role, (string) $letters, $to)) {
                continue;
            }
            $out[$to] = TicketAccess::statusLabel($t, $to);
        }

        return $out;
    }

    /**
     * Moves a ticket along its workflow. Staff moves need the matching permission; the client can only confirm, reopen, close their
     * own resolved ticket or answer requests. A written note is required where the client needs to understand why.
     *
     * @param array<string,mixed> $t as the user saw it (the status is compared under lock)
     * @param string $note visible to the client
     * @param string $internalNote staff only
     */
    public static function transition(int $actorId, array $t, string $to, string $note = '', string $internalNote = ''): void
    {
        $role = TicketAccess::role($actorId, $t) ?? throw new HttpException(404);
        $from = (string) $t['status'];
        $letters = self::transitionsFrom($t)[$to] ?? null;
        if ($letters === null) {
            throw new ValidationException(['A ' . strtolower(TicketAccess::statusLabel($t)) . ' ' . ($t['kind'] === 'bug' ? 'bug report' : 'ticket') . ' cannot move to ' . strtolower(TicketAccess::statusLabel($t, $to)) . '.']);
        }
        if ($letters === 'X') {
            throw new ValidationException(['That step happens through its own action.']);
        }
        if (!self::mayMove($actorId, $t, $role, $letters, $to)) {
            \Vertex\Core\Logger::security('Ticket transition denied', ['user' => $actorId, 'ticket' => (int) $t['id'], 'from' => $from, 'to' => $to]);
            throw new HttpException(403);
        }
        if (($t['project_archived_at'] ?? null) !== null) {
            throw new ValidationException(['This project is archived.']);
        }
        $clientMove = $role === 'client';
        $needNote = in_array($to, ['rejected', 'unable_to_reproduce', 'need_more_information'], true)
            || ($clientMove && in_array($to, ['open', 'reopened', 'in_progress'], true))
            || (!$clientMove && $t['kind'] === 'support' && $to === 'reopened');
        $note = (string) FieldRules::text($note, $clientMove ? 'Your explanation' : 'Explanation for the client', $clientMove ? 10 : 5, 3000, $needNote);
        $internalNote = (string) FieldRules::text($internalNote, 'Internal note', 2, 5000, false);
        if ($clientMove && $to === 'open' && $from === 'closed' && !self::withinReopenWindow($t)) {
            throw new ValidationException(['This ticket was closed more than ' . (int) Config::get('support.client_reopen_days', 30) . ' days ago. Please open a new ticket and mention ' . $t['reference'] . '.']);
        }
        if ($t['kind'] === 'bug' && $to === 'in_progress' && $role !== 'client') {
            self::assertChargeableApproved($t);
        }

        Database::transaction(function () use ($actorId, $t, $from, $to, $note, $internalNote, $clientMove): void {
            $locked = Database::one('SELECT status FROM tickets WHERE id = ? FOR UPDATE', [$t['id']]);
            if ($locked === null || $locked['status'] !== $from) {
                throw new ValidationException(['This ticket was changed by someone else. Reload and try again.']);
            }
            $sets = ['status = ?', 'updated_at = UTC_TIMESTAMP()'];
            $args = [$to];
            if (in_array($to, ['resolved', 'fixed'], true)) {
                $sets[] = 'resolved_at = UTC_TIMESTAMP()';
                if ($note !== '' && !$clientMove) {
                    $sets[] = 'resolution = ?';
                    $args[] = $note;
                }
            }
            if ($to === 'closed') {
                $sets[] = 'closed_at = UTC_TIMESTAMP()';
                if ($note !== '' && $t['resolution'] === null && !$clientMove) {
                    $sets[] = 'resolution = ?';
                    $args[] = $note;
                }
            }
            if (in_array($to, ['open', 'reopened'], true) && in_array($from, ['closed', 'resolved', 'client_verification', 'rejected', 'unable_to_reproduce'], true)) {
                $sets[] = 'reopen_count = reopen_count + 1';
                $sets[] = 'closed_at = NULL';
                $sets[] = 'resolved_at = NULL';
            }
            if ($t['kind'] === 'support' && $to === 'open') {
                $sets[] = 'assigned_to = NULL';
            }
            $args[] = $t['id'];
            Database::run('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
            self::activity((int) $t['id'], $actorId, 'status', $from, $to, $note === '' ? null : $note, 'client');
            if ($internalNote !== '') {
                Database::run("INSERT INTO ticket_notes (ticket_id, author_id, kind, body, created_at) VALUES (?, ?, 'note', ?, UTC_TIMESTAMP())", [$t['id'], $actorId, $internalNote]);
            }
            if (!$clientMove && $note !== '' && in_array($to, ['need_more_information', 'rejected', 'unable_to_reproduce'], true)) {
                Database::run("INSERT INTO ticket_messages (ticket_id, author_id, author_role, body, created_at) VALUES (?, ?, 'staff', ?, UTC_TIMESTAMP())", [$t['id'], $actorId, $note]);
            }
            Audit::record('ticket.status_changed', 'ticket', (int) $t['id'], ['status' => $from], ['status' => $to], ['project_id' => $t['project_id'] === null ? null : (int) $t['project_id'], 'by' => $clientMove ? 'client' : 'staff'], $actorId);
            if (in_array($to, ['closed', 'reopened'], true) || ($to === 'open' && $from === 'closed')) {
                Audit::record($to === 'closed' ? 'ticket.closed' : 'ticket.reopened', 'ticket', (int) $t['id'], ['status' => $from], ['status' => $to], [], $actorId);
            }
            $n = (int) Database::one('SELECT COUNT(*) AS c FROM ticket_activity WHERE ticket_id = ?', [$t['id']])['c'];
            self::notifyStatus($t, $from, $to, $clientMove, $n, $actorId);
        });
    }

    /** Assigns (or, with null, unassigns) a ticket. Support tickets become Assigned/Open accordingly. @param array<string,mixed> $t */
    public static function assign(int $actorId, array $t, ?string $userPublicId): void
    {
        TicketAccess::authorize($actorId, $t, 'tickets.assign');
        $new = null;
        if ($userPublicId !== null && $userPublicId !== '') {
            $u = preg_match('/^[a-f0-9]{32}$/', $userPublicId) ? Database::one("SELECT id FROM users WHERE public_id = ? AND status = 'active'", [$userPublicId]) : null;
            $candidate = $u === null ? null : (int) $u['id'];
            if ($candidate === null || !in_array(TicketAccess::role($candidate, $t), ['staff', 'worker'], true) || !Gate::canAny(['tickets.reply', 'tickets.note', 'bugs.fix', 'tickets.update_status'], $candidate)) {
                throw new ValidationException(['Choose someone who is allowed to work on this ticket.']);
            }
            $new = $candidate;
        }
        $old = $t['assigned_to'] === null ? null : (int) $t['assigned_to'];
        if ($new === $old) {
            return;
        }
        Database::transaction(function () use ($actorId, $t, $new, $old): void {
            $locked = Database::one('SELECT status FROM tickets WHERE id = ? FOR UPDATE', [$t['id']]);
            $status = (string) $locked['status'];
            $to = $status;
            if ($t['kind'] === 'support') {
                if ($new !== null && in_array($status, ['open', 'reopened'], true)) {
                    $to = 'assigned';
                } elseif ($new === null && $status === 'assigned') {
                    $to = 'open';
                }
            }
            Database::run('UPDATE tickets SET assigned_to = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$new, $to, $t['id']]);
            self::activity((int) $t['id'], $actorId, 'assigned', $old === null ? null : (string) $old, $new === null ? null : (string) $new, null, 'internal');
            if ($to !== $status) {
                self::activity((int) $t['id'], $actorId, 'status', $status, $to, null, 'client');
            }
            Audit::record($new === null ? 'ticket.unassigned' : 'ticket.assigned', 'ticket', (int) $t['id'], ['assigned_to' => $old], ['assigned_to' => $new, 'status' => $to], ['project_id' => $t['project_id'] === null ? null : (int) $t['project_id']], $actorId);
            if ($new !== null) {
                Notifier::send([$new], 'ticket.assigned', 'A ticket was assigned to you', "{$t['reference']}: {$t['subject']}", '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'],
                    'ticket', (int) $t['id'], "ticket:{$t['id']}:assigned:{$new}:" . (int) Database::one('SELECT COUNT(*) AS c FROM ticket_activity WHERE ticket_id = ?', [$t['id']])['c'], $actorId);
            }
        });
    }

    /** Severity (how serious the problem is) is separate from priority (how urgently staff schedule it). Both need a reason. @param array<string,mixed> $t */
    public static function setSeverity(int $actorId, array $t, string $severity, string $reason): void
    {
        if ($t['kind'] !== 'bug') {
            throw new ValidationException(['Only bug reports have a severity.']);
        }
        TicketAccess::authorize($actorId, $t, 'bugs.manage');
        $severity = (string) FieldRules::choice($severity, (array) Config::get('workflow.bug_severities'), 'severity');
        $reason = (string) FieldRules::text($reason, 'Reason', 5, 1000);
        if ($severity === $t['severity']) {
            return;
        }
        Database::transaction(function () use ($actorId, $t, $severity, $reason): void {
            Database::run('UPDATE tickets SET severity = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$severity, $t['id']]);
            self::activity((int) $t['id'], $actorId, 'severity', (string) $t['severity'], $severity, null, 'client');
            Database::run("INSERT INTO ticket_notes (ticket_id, author_id, kind, body, created_at) VALUES (?, ?, 'decision', ?, UTC_TIMESTAMP())", [$t['id'], $actorId, "Severity {$t['severity']} → {$severity}: {$reason}"]);
            Audit::record('ticket.severity_changed', 'ticket', (int) $t['id'], ['severity' => $t['severity']], ['severity' => $severity], ['reason_recorded' => true], $actorId);
        });
    }

    /** @param array<string,mixed> $t */
    public static function setPriority(int $actorId, array $t, string $priority, string $reason): void
    {
        TicketAccess::authorize($actorId, $t, $t['kind'] === 'bug' ? 'bugs.manage' : 'tickets.update_status');
        $priority = (string) FieldRules::choice($priority, (array) Config::get('workflow.priorities'), 'priority');
        $reason = (string) FieldRules::text($reason, 'Reason', 5, 1000);
        if ($priority === $t['priority']) {
            return;
        }
        Database::transaction(function () use ($actorId, $t, $priority, $reason): void {
            Database::run('UPDATE tickets SET priority = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$priority, $t['id']]);
            self::activity((int) $t['id'], $actorId, 'priority', (string) $t['priority'], $priority, $reason, 'internal');
            Audit::record('ticket.priority_changed', 'ticket', (int) $t['id'], ['priority' => $t['priority']], ['priority' => $priority], ['reason_recorded' => true], $actorId);
        });
    }

    /** @param array<string,mixed> $t */
    public static function escalate(int $actorId, array $t, string $reason): void
    {
        TicketAccess::authorize($actorId, $t, 'tickets.escalate');
        $reason = (string) FieldRules::line($reason, 'Reason for escalation', 5, 255);
        if ($t['escalated_at'] !== null) {
            throw new ValidationException(['This ticket is already escalated.']);
        }
        Database::transaction(function () use ($actorId, $t, $reason): void {
            Database::run('UPDATE tickets SET escalated_at = UTC_TIMESTAMP(), escalated_by = ?, escalation_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$actorId, $reason, $t['id']]);
            self::activity((int) $t['id'], $actorId, 'escalated', null, null, $reason, 'internal');
            Audit::record('ticket.escalated', 'ticket', (int) $t['id'], [], ['escalated' => true], ['reason_recorded' => true], $actorId);
            $admins = array_values(array_unique([...Notifier::withPermission('tickets.assign'), ...Notifier::withPermission('tickets.view_all')]));
            Notifier::send($admins, 'ticket.escalated', 'A ticket was escalated', "{$t['reference']}: {$t['subject']}", '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket', (int) $t['id'], "ticket:{$t['id']}:escalated", $actorId);
        });
    }

    /** Marks the conversation read for this person. @param array<string,mixed> $t */
    public static function markRead(int $userId, array $t): void
    {
        $max = (int) Database::one('SELECT COALESCE(MAX(id), 0) AS m FROM ticket_messages WHERE ticket_id = ?', [$t['id']])['m'];
        Database::run('INSERT INTO ticket_reads (ticket_id, user_id, last_read_message_id, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                       ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), updated_at = UTC_TIMESTAMP()', [$t['id'], $userId, $max]);
    }

    // ------------------------------------------------------------------------------------------------------- helpers

    /** @param array<string,mixed> $t @return array<string,string> */
    public static function transitionsFrom(array $t): array
    {
        $key = $t['kind'] === 'bug' ? 'workflow.bug_transitions' : 'workflow.support_transitions';

        return (array) Config::get("{$key}.{$t['status']}", []);
    }

    /** @param array<string,mixed> $t */
    private static function mayMove(int $userId, array $t, string $role, string $letters, string $to): bool
    {
        if ($role === 'client') {
            return str_contains($letters, 'C') && Gate::can('tickets.reply_own', $userId);
        }
        $staffPerm = $t['kind'] === 'bug' ? 'bugs.manage' : 'tickets.update_status';
        if (str_contains($letters, 'S') && $role === 'staff' && Gate::can($staffPerm, $userId)) {
            return true;
        }
        if (str_contains($letters, 'F') && Gate::can('bugs.fix', $userId) && ($t['assigned_to'] !== null && (int) $t['assigned_to'] === $userId || Gate::can('bugs.manage', $userId))) {
            return true;
        }

        return str_contains($letters, 'K') && $role === 'staff' && Gate::can('tickets.close', $userId);
    }

    /** @param array<string,mixed> $t */
    private static function withinReopenWindow(array $t): bool
    {
        $days = (int) Config::get('support.client_reopen_days', 30);
        if ($t['closed_at'] === null) {
            return true;
        }

        return strtotime((string) $t['closed_at'] . ' UTC') >= time() - $days * 86400;
    }

    /** Chargeable bugs cannot start until the client approved the additional cost. @param array<string,mixed> $t */
    private static function assertChargeableApproved(array $t): void
    {
        if (($t['eligibility'] ?? null) !== 'potentially_chargeable') {
            return;
        }
        $cr = Database::one('SELECT status FROM change_requests WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$t['id']]);
        if ($cr === null || $cr['status'] !== 'approved') {
            throw new ValidationException(['This issue is potentially chargeable: work cannot start until the client has approved the proposed additional cost.']);
        }
    }

    /** @return array<string,mixed> clients row of the signed-in user */
    private static function clientOf(int $userId): array
    {
        $c = Database::one("SELECT * FROM clients WHERE user_id = ? AND status = 'active' AND archived_at IS NULL", [$userId]);
        if ($c === null) {
            throw new HttpException(403);
        }

        return $c;
    }

    /** One of THIS client's projects (never another client's). Same message for "other client's" and "does not exist". @param array<string,mixed> $client @return array<string,mixed> */
    private static function ownProject(array $client, string $publicId, bool $required): array
    {
        $p = preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT * FROM projects WHERE public_id = ? AND client_id = ? AND archived_at IS NULL AND status <> 'cancelled'",
            [$publicId, $client['id']],
        ) : null;
        if ($p === null) {
            throw new ValidationException([$required ? 'Choose one of your projects.' : 'Choose one of your projects, or leave it empty.']);
        }

        return $p;
    }

    /** @return array{public_id:string,reference:string}|null */
    private static function existingByToken(int $actorId, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $r = Database::one('SELECT public_id, reference FROM tickets WHERE requester_id = ? AND submit_token = ?', [$actorId, $token]);

        return $r === null ? null : ['public_id' => (string) $r['public_id'], 'reference' => (string) $r['reference']];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function validateBug(array $in, bool $draft): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $title = $try(static fn () => (string) FieldRules::line($in['title'] ?? '', 'Issue title', $draft ? 3 : 5, 200));
        $desc = $try(static fn () => FieldRules::text($in['description'] ?? '', 'Description', 10, 5000, !$draft));
        $steps = $try(static fn () => FieldRules::text($in['steps'] ?? '', 'Steps to reproduce', 10, 3000, !$draft));
        $expected = $try(static fn () => FieldRules::text($in['expected'] ?? '', 'Expected behaviour', 3, 2000, !$draft));
        $actual = $try(static fn () => FieldRules::text($in['actual'] ?? '', 'Actual behaviour', 3, 2000, !$draft));
        $url = $try(static fn () => self::validateUrl($in['affected_url'] ?? ''));
        $browser = $try(static fn () => FieldRules::line($in['browser'] ?? '', 'Browser', 2, 255, false));
        $device = $try(static fn () => FieldRules::line($in['device'] ?? '', 'Device', 2, 160, false));
        $severity = $try(static fn () => (string) FieldRules::choice($in['severity'] ?? '', (array) Config::get('workflow.bug_severities'), 'severity', false, 'normal'));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'description' => $desc, 'steps' => $steps, 'expected' => $expected, 'actual' => $actual, 'url' => $url, 'browser' => $browser, 'device' => $device, 'severity' => $severity];
    }

    /** The affected page is untrusted text: http(s) only, no embedded login, no whitespace/control characters, bounded. Never fetched. */
    public static function validateUrl(mixed $raw): ?string
    {
        $u = FieldRules::clean($raw);
        if ($u === '') {
            return null;
        }
        $p = parse_url($u);
        if (mb_strlen($u) > 500 || preg_match('/\s/', $u) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass']) || preg_match('/[<>"\'`]/', $u)) {
            throw new ValidationException(['The affected page must be a web address starting with http:// or https:// (no spaces or embedded login).']);
        }

        return $u;
    }

    /** @param array<string,mixed> $warranty @param array<string,mixed> $project @return array<string,mixed> */
    private static function snapshot(array $warranty, array $project): array
    {
        $w = $warranty['row'];

        return ['status' => $warranty['status'], 'label' => $warranty['label'], 'start_date' => $w['start_date'] ?? null, 'end_date' => $w['end_date'] ?? null, 'duration_months' => $w['duration_months'] ?? null,
            'start_basis' => $w['start_basis'] ?? null, 'quotation_ref' => $w['quotation_ref'] ?? null, 'terms_version' => $w['terms_version'] ?? null, 'service' => $project['service_category'] ?? null,
            'recorded' => $warranty['recorded'], 'captured_on' => AppTime::today()];
    }

    /** @param list<array<string,mixed>> $files @return list<array<string,mixed>> */
    private static function acceptFiles(int $actorId, array $files, bool $requireHttpUpload, ?string $limiter): array
    {
        $files = array_values(array_filter($files, static fn (array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if ($files === []) {
            return [];
        }
        if (count($files) > 3) {
            throw new ValidationException(['You can attach up to 3 files at a time.']);
        }
        if (!RateLimiter::hit('upload_user', (string) $actorId) || ($limiter !== null && !RateLimiter::hit($limiter, (string) $actorId))) {
            throw new HttpException(429);
        }
        $metas = [];
        try {
            foreach ($files as $f) {
                $metas[] = FileStore::accept($f, 'ticket', $requireHttpUpload);
            }
        } catch (\Throwable $e) {
            self::discard($metas);
            throw $e;
        }

        return $metas;
    }

    /** @param list<array<string,mixed>> $metas */
    private static function discard(array $metas): void
    {
        foreach ($metas as $m) {
            FileStore::discard($m);
        }
    }

    /** Inserts stored_files + ticket_files rows inside the caller's transaction. @param list<array<string,mixed>> $metas */
    private static function attach(int $ticketId, ?int $messageId, array $metas, string $visibility, int $actorId): void
    {
        foreach ($metas as $m) {
            Database::run('INSERT INTO ticket_files (ticket_id, message_id, file_id, visibility, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$ticketId, $messageId, FileStore::insert($m, $actorId), $visibility, $actorId]);
        }
    }

    public static function activity(int $ticketId, ?int $actorId, string $event, ?string $from, ?string $to, ?string $note, string $visibility): void
    {
        Database::run('INSERT INTO ticket_activity (ticket_id, actor_id, event, from_value, to_value, note, visibility, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$ticketId, $actorId, $event, $from, $to, $note, $visibility]);
    }

    /** Who should hear about client activity: the assignee, else everyone who can work the queue. @param array<string,mixed> $t @return list<int> */
    public static function staffRecipients(array $t): array
    {
        if ($t['assigned_to'] !== null) {
            return [(int) $t['assigned_to']];
        }
        $ids = Notifier::withPermission('tickets.view_all');
        if ($t['project_id'] !== null) {
            $ids = [...$ids, ...Notifier::staffWith('tickets.view_assigned', (int) $t['project_id'])];
        }

        return array_values(array_unique($ids));
    }

    private static function notifyNew(int $id, string $pub, string $ref, string $kind, ?int $projectId, string $event, string $title, string $subject, int $actorId): void
    {
        $ids = Notifier::withPermission('tickets.view_all');
        if ($projectId !== null) {
            $ids = [...$ids, ...Notifier::staffWith('tickets.view_assigned', $projectId)];
        }
        Notifier::send(array_values(array_unique($ids)), $event, $title, "{$ref}: {$subject}", '/tickets/' . $pub, $projectId, 'ticket', $id, "ticket:{$id}:submitted", $actorId);
    }

    /** @param array<string,mixed> $t */
    private static function notifyStatus(array $t, string $from, string $to, bool $clientMove, int $n, int $actorId): void
    {
        $pid = $t['project_id'] === null ? null : (int) $t['project_id'];
        $link = '/tickets/' . $t['public_id'];
        $label = TicketAccess::statusLabel($t, $to);
        if ($clientMove) {
            $event = in_array($to, ['open', 'reopened', 'in_progress'], true) ? 'ticket.reopened' : 'ticket.client_update';
            Notifier::send(self::staffRecipients($t), $event, $event === 'ticket.reopened' ? 'A ticket was reopened by the client' : 'Client updated a ticket', "{$t['reference']}: {$label}", $link, $pid, 'ticket', (int) $t['id'], "ticket:{$t['id']}:status:{$n}", $actorId);

            return;
        }
        $title = match (true) {
            $to === 'need_more_information' => 'We need more information',
            $to === 'fixed' => 'Your issue was marked as fixed',
            $to === 'client_verification' => 'Please verify the fix',
            in_array($to, ['resolved', 'closed'], true) => 'Your ' . ($t['kind'] === 'bug' ? 'bug report' : 'ticket') . ' was ' . $to,
            default => 'Your ' . ($t['kind'] === 'bug' ? 'bug report' : 'ticket') . ' was updated',
        };
        Notifier::send([(int) $t['client_user_id']], 'ticket.status_' . $to, $title, "{$t['reference']}: {$label}", $link, $pid, 'ticket', (int) $t['id'], "ticket:{$t['id']}:status:{$n}");
    }
}
