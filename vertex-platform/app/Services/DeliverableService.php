<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Deliverables and versions. Every mutation: permission + staff access to the project (DeliverablePolicy), validation, ONE
 * transaction holding the change, the project-timeline entry, the notification events and the audit entry.
 *
 * Lifecycle of a VERSION:  draft -> pending_review -> approved_internal -> published -> superseded | withdrawn
 *                          pending_review -> changes_requested -> (upload a new version)
 * Uploading never publishes. Internal approval is not client acceptance. A new version never inherits the review, publication or
 * client approval of an older one.
 */
final class DeliverableService
{
    /**
     * Creates a deliverable and its version 1 (a draft) from an uploaded file or an external link.
     *
     * @param array<string,mixed> $project ProjectPolicy row
     * @param array<string,mixed> $in title, description, category, task, milestone, label, change_notes, external_url
     * @param array<string,mixed>|null $file one entry of $_FILES, or null when an external link is used
     * @return array{id:int,public_id:string,code:string,version_public_id:string}
     */
    public static function create(int $actorId, array $project, array $in, ?array $file, bool $requireHttpUpload = true): array
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'deliverables.upload');
        if (!Gate::can('deliverables.view', $actorId)) {
            throw new HttpException(403);
        }
        self::assertProjectOpen($project);
        $d = self::validateMeta($in, $project);
        $content = self::content($actorId, $in, $file, $requireHttpUpload);

        try {
            return Database::transaction(function () use ($actorId, $project, $d, $in, $content): array {
                $pub = uuid_hex();
                $code = CrmReference::next('deliverable');
                Database::run(
                    "INSERT INTO deliverables (public_id, code, project_id, title, description, category, task_id, milestone_id, status, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                    [$pub, $code, $project['id'], $d['title'], $d['description'], $d['category'], $d['task_id'], $d['milestone_id'], $actorId],
                );
                $id = Database::insertId();
                $v = self::insertVersion($actorId, $id, 1, $in, $content);
                ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.created', "Deliverable created: {$d['title']} ({$code})", 'internal', 'deliverable', $id);
                Audit::record('deliverable.created', 'deliverable', $id, [], ['code' => $code, 'category' => $d['category'], 'status' => 'draft'], ['project_id' => (int) $project['id']], $actorId);
                Audit::record('deliverable.version_created', 'deliverable', $id, [], ['version_no' => 1, 'version_id' => $v['id'], 'size' => $content['size']], ['project_id' => (int) $project['id']], $actorId);

                return ['id' => $id, 'public_id' => $pub, 'code' => $code, 'version_public_id' => $v['public_id']];
            });
        } catch (\Throwable $e) {
            self::discard($content);
            throw $e;
        }
    }

    /**
     * Adds a NEW version (never replaces one). @param array<string,mixed> $deliverable
     *
     * @param array<string,mixed> $in label, change_notes, external_url
     * @param array<string,mixed>|null $file
     * @return array{id:int,public_id:string,version_no:int}
     */
    public static function addVersion(int $actorId, array $deliverable, array $in, ?array $file, bool $requireHttpUpload = true): array
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.upload');
        $project = DeliverablePolicy::project($deliverable);
        self::assertProjectOpen($project);
        self::assertNotArchived($deliverable);
        $content = self::content($actorId, $in, $file, $requireHttpUpload);

        try {
            return Database::transaction(function () use ($actorId, $deliverable, $project, $in, $content): array {
                Database::one('SELECT id FROM deliverables WHERE id = ? FOR UPDATE', [$deliverable['id']]);
                $next = (int) Database::one('SELECT COALESCE(MAX(version_no), 0) + 1 AS n FROM deliverable_versions WHERE deliverable_id = ?', [$deliverable['id']])['n'];
                $v = self::insertVersion($actorId, (int) $deliverable['id'], $next, $in, $content);
                self::recompute((int) $deliverable['id']);
                ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.version_added', "New version V{$next} of {$deliverable['title']} (draft)", 'internal', 'deliverable', (int) $deliverable['id']);
                Audit::record('deliverable.version_created', 'deliverable', (int) $deliverable['id'], [], ['version_no' => $next, 'version_id' => $v['id'], 'size' => $content['size']], ['project_id' => (int) $project['id']], $actorId);

                return ['id' => $v['id'], 'public_id' => $v['public_id'], 'version_no' => $next];
            });
        } catch (\Throwable $e) {
            self::discard($content);
            throw $e;
        }
    }

    /** Edits descriptive fields only (never files or versions). @param array<string,mixed> $deliverable @param array<string,mixed> $in */
    public static function updateMeta(int $actorId, array $deliverable, array $in): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.upload');
        $project = DeliverablePolicy::project($deliverable);
        self::assertProjectOpen($project);
        self::assertNotArchived($deliverable);
        $d = self::validateMeta($in, $project);
        $changed = [];
        foreach (['title', 'description', 'category', 'task_id', 'milestone_id'] as $f) {
            if ((string) $d[$f] !== (string) $deliverable[$f]) {
                $changed[] = $f;
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $deliverable, $project, $d, $changed): void {
            Database::run('UPDATE deliverables SET title = ?, description = ?, category = ?, task_id = ?, milestone_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$d['title'], $d['description'], $d['category'], $d['task_id'], $d['milestone_id'], $deliverable['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.updated', "Deliverable details updated: {$d['title']}", 'internal', 'deliverable', (int) $deliverable['id']);
            Audit::record('deliverable.updated', 'deliverable', (int) $deliverable['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    /** draft | changes_requested -> pending_review. Anyone allowed to upload to the project may submit its versions. @param array<string,mixed> $deliverable @param array<string,mixed> $version */
    public static function submitForReview(int $actorId, array $deliverable, array $version): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.upload');
        $project = DeliverablePolicy::project($deliverable);
        self::assertProjectOpen($project);
        self::assertNotArchived($deliverable);
        self::transitionVersion($deliverable, $version, ['draft', 'changes_requested'], function () use ($actorId, $deliverable, $version, $project): void {
            Database::run("UPDATE deliverable_versions SET status = 'pending_review', submitted_at = UTC_TIMESTAMP() WHERE id = ?", [$version['id']]);
            self::recompute((int) $deliverable['id']);
            ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.submitted', "V{$version['version_no']} of {$deliverable['title']} submitted for internal review", 'internal', 'deliverable', (int) $deliverable['id']);
            Audit::record('deliverable.submitted_for_review', 'deliverable', (int) $deliverable['id'], ['status' => $version['status']], ['status' => 'pending_review'], ['version_id' => (int) $version['id'], 'project_id' => (int) $project['id']], $actorId);
            Notifier::send(Notifier::staffWith('deliverables.review', (int) $project['id']), 'deliverable.review_required', 'Deliverable needs internal review',
                "{$deliverable['code']} V{$version['version_no']}: {$deliverable['title']}", '/deliverables/' . $deliverable['public_id'], (int) $project['id'], 'deliverable_version', (int) $version['id'],
                "version:{$version['id']}:review_required:" . (int) Database::one('SELECT COUNT(*) AS c FROM deliverable_reviews WHERE version_id = ?', [$version['id']])['c'], $actorId);
        });
    }

    /**
     * Internal QA decision. Needs `deliverables.review`; the uploader cannot approve their own upload unless the deployment
     * enables DELIVERABLE_ALLOW_SELF_APPROVAL. Internal approval is NOT client acceptance and does not publish anything.
     *
     * @param array<string,mixed> $deliverable @param array<string,mixed> $version
     */
    public static function review(int $actorId, array $deliverable, array $version, string $decision, string $notes = ''): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.review');
        $project = DeliverablePolicy::project($deliverable);
        self::assertProjectOpen($project);
        self::assertNotArchived($deliverable);
        if (!in_array($decision, ['approved', 'changes_requested'], true)) {
            throw new ValidationException(['Choose approve or request changes.']);
        }
        $notes = (string) FieldRules::text($notes, 'Review notes', 3, 3000, $decision === 'changes_requested');
        if ($decision === 'approved' && $version['uploaded_by'] !== null && (int) $version['uploaded_by'] === $actorId && !(bool) Config::get('uploads.allow_self_approval', false)) {
            throw new ValidationException(['You uploaded this version, so someone else must approve it.']);
        }
        self::transitionVersion($deliverable, $version, ['pending_review'], function () use ($actorId, $deliverable, $version, $project, $decision, $notes): void {
            $approved = $decision === 'approved';
            Database::run(
                'UPDATE deliverable_versions SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?',
                [$approved ? 'approved_internal' : 'changes_requested', $approved ? $actorId : null, $approved ? gmdate('Y-m-d H:i:s') : null, $version['id']],
            );
            Database::run('INSERT INTO deliverable_reviews (version_id, reviewer_id, decision, notes, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$version['id'], $actorId, $decision, $notes === '' ? null : $notes]);
            self::recompute((int) $deliverable['id']);
            ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.reviewed', "V{$version['version_no']} of {$deliverable['title']}: " . ($approved ? 'approved internally' : 'changes requested'), 'internal', 'deliverable', (int) $deliverable['id']);
            Audit::record($approved ? 'deliverable.approved_internal' : 'deliverable.changes_requested', 'deliverable', (int) $deliverable['id'], ['status' => 'pending_review'], ['status' => $approved ? 'approved_internal' : 'changes_requested'], ['version_id' => (int) $version['id'], 'project_id' => (int) $project['id']], $actorId);
            if ($version['uploaded_by'] !== null) {
                Notifier::send([(int) $version['uploaded_by']], 'deliverable.review_decision', $approved ? 'Deliverable approved internally' : 'Changes requested on your deliverable',
                    "{$deliverable['code']} V{$version['version_no']}: {$deliverable['title']}", '/deliverables/' . $deliverable['public_id'], (int) $project['id'], 'deliverable_version', (int) $version['id'], "version:{$version['id']}:review_decision:" . (int) Database::one('SELECT COUNT(*) AS c FROM deliverable_reviews WHERE version_id = ?', [$version['id']])['c'], $actorId);
            }
        });
    }

    /**
     * Makes an internally approved version the client-facing one. Needs `deliverables.publish`. Workers do not hold it by default.
     * The previously published version becomes `superseded` (kept, with its history). A superseded version may be re-published.
     *
     * @param array<string,mixed> $deliverable @param array<string,mixed> $version
     */
    public static function publish(int $actorId, array $deliverable, array $version): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.publish');
        $project = DeliverablePolicy::project($deliverable);
        self::assertProjectOpen($project);
        self::assertNotArchived($deliverable);
        if ($version['status'] === 'superseded' && $version['approved_at'] === null) {
            throw new ValidationException(['That version was never approved internally.']);
        }
        self::transitionVersion($deliverable, $version, ['approved_internal', 'superseded'], function () use ($actorId, $deliverable, $version, $project): void {
            $locked = Database::one('SELECT published_version_id, first_published_at FROM deliverables WHERE id = ? FOR UPDATE', [$deliverable['id']]);
            $revised = $locked['first_published_at'] !== null;
            if ($locked['published_version_id'] !== null) {
                Database::run("UPDATE deliverable_versions SET status = 'superseded' WHERE id = ? AND status = 'published'", [$locked['published_version_id']]);
            }
            Database::run("UPDATE deliverable_versions SET status = 'published', published_by = ?, published_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $version['id']]);
            Database::run("UPDATE deliverables SET published_version_id = ?, first_published_at = COALESCE(first_published_at, UTC_TIMESTAMP()), status = 'published', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$version['id'], $deliverable['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.published', ($revised ? 'Revised deliverable published: ' : 'Deliverable published: ') . $deliverable['title'], 'client', 'deliverable', (int) $deliverable['id']);
            Audit::record('deliverable.published', 'deliverable', (int) $deliverable['id'], ['published_version_id' => $locked['published_version_id'] === null ? null : (int) $locked['published_version_id']], ['published_version_id' => (int) $version['id'], 'version_no' => (int) $version['version_no']], ['project_id' => (int) $project['id']], $actorId);
            $n = (int) Database::one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'deliverable.published' AND entity_id = ?", [$deliverable['id']])['c'];
            Notifier::send(Notifier::clientOf((int) $project['id']), $revised ? 'deliverable.revised_version_published' : 'deliverable.published',
                $revised ? 'A revised version is ready for your review' : 'A deliverable is ready for your review', $deliverable['title'] . ' (' . $deliverable['code'] . ')',
                '/deliverables/' . $deliverable['public_id'], (int) $project['id'], 'deliverable_version', (int) $version['id'], "version:{$version['id']}:published:{$n}");
        });
    }

    /** Pulls a published version back from the client (wrong file, premature publication). Needs `deliverables.publish` and a reason. */
    public static function withdraw(int $actorId, array $deliverable, array $version, string $reason): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.publish');
        $project = DeliverablePolicy::project($deliverable);
        self::assertNotArchived($deliverable);
        $reason = (string) FieldRules::text($reason, 'Reason', 5, 500);
        self::transitionVersion($deliverable, $version, ['published'], function () use ($actorId, $deliverable, $version, $project, $reason): void {
            Database::run("UPDATE deliverable_versions SET status = 'withdrawn', withdrawn_by = ?, withdrawn_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $version['id']]);
            Database::run('UPDATE deliverables SET published_version_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND published_version_id = ?', [$deliverable['id'], $version['id']]);
            self::recompute((int) $deliverable['id']);
            ProjectActivity::log((int) $project['id'], $actorId, 'deliverable.withdrawn', "V{$version['version_no']} of {$deliverable['title']} withdrawn from the client", 'internal', 'deliverable', (int) $deliverable['id']);
            Audit::record('deliverable.withdrawn', 'deliverable', (int) $deliverable['id'], ['status' => 'published'], ['status' => 'withdrawn'], ['version_id' => (int) $version['id'], 'reason_length' => mb_strlen($reason), 'project_id' => (int) $project['id']], $actorId);
        });
    }

    /** @param array<string,mixed> $deliverable */
    public static function setArchived(int $actorId, array $deliverable, bool $archive): void
    {
        DeliverablePolicy::authorize($actorId, $deliverable, 'deliverables.archive');
        $project = DeliverablePolicy::project($deliverable);
        if (($deliverable['archived_at'] !== null) === $archive) {
            return;
        }
        Database::transaction(function () use ($actorId, $deliverable, $project, $archive): void {
            Database::run('SELECT id FROM deliverables WHERE id = ? FOR UPDATE', [$deliverable['id']]);
            Database::run('UPDATE deliverables SET archived_at = ' . ($archive ? 'UTC_TIMESTAMP()' : 'NULL') . ', archived_by = ' . ($archive ? (string) $actorId : 'NULL') . ', updated_at = UTC_TIMESTAMP() WHERE id = ?', [$deliverable['id']]);
            self::recompute((int) $deliverable['id']);
            $visible = $deliverable['first_published_at'] !== null;
            ProjectActivity::log((int) $project['id'], $actorId, $archive ? 'deliverable.archived' : 'deliverable.restored', ($archive ? 'Deliverable archived: ' : 'Deliverable restored: ') . $deliverable['title'], $visible ? 'client' : 'internal', 'deliverable', (int) $deliverable['id']);
            Audit::record($archive ? 'deliverable.archived' : 'deliverable.restored', 'deliverable', (int) $deliverable['id'], [], [], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /** Recomputes deliverables.status from archive flag, the live version and the newest version. Call inside the transaction. */
    public static function recompute(int $deliverableId): void
    {
        $d = Database::one('SELECT archived_at, published_version_id FROM deliverables WHERE id = ?', [$deliverableId]);
        if ($d === null) {
            return;
        }
        if ($d['archived_at'] !== null) {
            $status = 'archived';
        } elseif ($d['published_version_id'] !== null) {
            $status = 'published';
        } else {
            $latest = Database::one("SELECT status FROM deliverable_versions WHERE deliverable_id = ? AND status <> 'withdrawn' AND status <> 'superseded' ORDER BY version_no DESC LIMIT 1", [$deliverableId]);
            $status = match ($latest['status'] ?? 'draft') {
                'pending_review' => 'pending_review',
                'approved_internal' => 'approved_internal',
                default => 'draft',
            };
        }
        Database::run('UPDATE deliverables SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $deliverableId]);
    }

    /**
     * Actions the user may take on a version right now (for showing buttons; every action re-checks on the server).
     *
     * @param array<string,mixed> $d @param array<string,mixed> $v
     * @return list<string> submit, review, publish, withdraw
     */
    public static function actionsFor(int $userId, array $d, array $v): array
    {
        if ($d['archived_at'] !== null || $d['project_archived_at'] !== null || in_array($d['project_status'], ['cancelled'], true)) {
            return [];
        }
        $out = [];
        if (in_array($v['status'], ['draft', 'changes_requested'], true) && DeliverablePolicy::can($userId, $d, 'deliverables.upload')) {
            $out[] = 'submit';
        }
        if ($v['status'] === 'pending_review' && DeliverablePolicy::can($userId, $d, 'deliverables.review')) {
            $out[] = 'review';
        }
        if (($v['status'] === 'approved_internal' || ($v['status'] === 'superseded' && $v['approved_at'] !== null)) && DeliverablePolicy::can($userId, $d, 'deliverables.publish')) {
            $out[] = 'publish';
        }
        if ($v['status'] === 'published' && DeliverablePolicy::can($userId, $d, 'deliverables.publish')) {
            $out[] = 'withdraw';
        }

        return $out;
    }

    /** @param list<string> $from */
    private static function transitionVersion(array $deliverable, array $version, array $from, callable $apply): void
    {
        if ((int) $version['deliverable_id'] !== (int) $deliverable['id']) {
            throw new HttpException(404);
        }
        if (!in_array($version['status'], $from, true)) {
            throw new ValidationException(['This version is ' . strtolower((string) Config::get("workflow.version_statuses.{$version['status']}", $version['status'])) . ' and cannot take that action.']);
        }
        Database::transaction(function () use ($version, $from, $apply): void {
            $locked = Database::one('SELECT status FROM deliverable_versions WHERE id = ? FOR UPDATE', [$version['id']]);
            if ($locked === null || $locked['status'] !== $version['status'] || !in_array($locked['status'], $from, true)) {
                throw new ValidationException(['The version changed while you were working. Reload and try again.']);
            }
            $apply();
        });
    }

    /** @param array<string,mixed> $in @param array<string,mixed> $project @return array<string,mixed> */
    private static function validateMeta(array $in, array $project): array
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
        $title = $try(static fn () => (string) FieldRules::line($in['title'] ?? '', 'Title', 3, 200));
        $description = $try(static fn () => FieldRules::text($in['description'] ?? '', 'Description', 3, 5000, false));
        $category = $try(static fn () => (string) FieldRules::choice($in['category'] ?? '', (array) Config::get('workflow.deliverable_categories'), 'category'));
        $taskId = $try(static function () use ($in, $project): ?int {
            $t = FieldRules::clean($in['task'] ?? '');
            if ($t === '') {
                return null;
            }
            $row = preg_match('/^[a-f0-9]{32}$/', $t) ? Database::one('SELECT id FROM tasks WHERE public_id = ? AND project_id = ? AND archived_at IS NULL', [$t, $project['id']]) : null;
            if ($row === null) {
                throw new ValidationException(['Choose a task from this project.']);
            }

            return (int) $row['id'];
        });
        $milestoneId = $try(static function () use ($in, $project): ?int {
            $m = FieldRules::clean($in['milestone'] ?? '');
            if ($m === '') {
                return null;
            }
            $row = MilestoneService::findInProject($m, (int) $project['id']);
            if ($row === null || $row['archived_at'] !== null) {
                throw new ValidationException(['Choose a milestone from this project.']);
            }

            return (int) $row['id'];
        });
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'description' => $description, 'category' => $category, 'task_id' => $taskId, 'milestone_id' => $milestoneId];
    }

    /**
     * Validates the version content: exactly one of an uploaded file or an https link. Returns file metadata (already moved to
     * private storage) or the link. Throws before anything is stored when the input is invalid.
     *
     * @param array<string,mixed> $in @param array<string,mixed>|null $file
     * @return array{meta:?array<string,mixed>,url:?string,label:?string,notes:?string,size:int}
     */
    private static function content(int $actorId, array $in, ?array $file, bool $requireHttpUpload): array
    {
        $label = null;
        $notes = null;
        $errors = [];
        try {
            $label = FieldRules::line($in['label'] ?? '', 'Version label', 1, 30, false);
            $notes = FieldRules::text($in['change_notes'] ?? '', 'Change notes', 3, 3000, false);
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
        $url = self::validateLink($in['external_url'] ?? '');
        $hasFile = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($hasFile && $url !== null) {
            throw new ValidationException(['Provide either a file or a link, not both.']);
        }
        if (!$hasFile && $url === null) {
            throw new ValidationException(['Choose a file to upload, or paste an https link to the resource.']);
        }
        if ($url !== null) {
            return ['meta' => null, 'url' => $url, 'label' => $label, 'notes' => $notes, 'size' => 0];
        }
        if (!RateLimiter::hit('upload_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $meta = FileStore::accept((array) $file, 'deliverable', $requireHttpUpload);

        return ['meta' => $meta, 'url' => null, 'label' => $label, 'notes' => $notes, 'size' => (int) $meta['size']];
    }

    /** https-only link to an external resource; never fetched by the server. @return string|null normalised URL, null when empty */
    public static function validateLink(mixed $raw): ?string
    {
        $u = FieldRules::clean($raw);
        if ($u === '') {
            return null;
        }
        $parts = parse_url($u);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (mb_strlen($u) > 500 || preg_match('/\s/', $u) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException(['The link must be a plain https:// address (no spaces, no embedded login), up to 500 characters.']);
        }
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ValidationException(['The link must point to a public website address, not an IP address or an internal host.']);
        }
        $allowed = (array) Config::get('uploads.external_hosts', []);
        if ($allowed !== []) {
            $ok = false;
            foreach ($allowed as $a) {
                $a = strtolower($a);
                if ($host === $a || str_ends_with($host, '.' . $a)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                throw new ValidationException(['Links are only accepted from: ' . implode(', ', $allowed) . '.']);
            }
        }

        return $u;
    }

    /** @param array<string,mixed> $content @return array{id:int,public_id:string} */
    private static function insertVersion(int $actorId, int $deliverableId, int $no, array $in, array $content): array
    {
        $fileId = $content['meta'] === null ? null : FileStore::insert($content['meta'], $actorId);
        $pub = uuid_hex();
        Database::run(
            "INSERT INTO deliverable_versions (public_id, deliverable_id, version_no, label, file_id, external_url, change_notes, status, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, UTC_TIMESTAMP())",
            [$pub, $deliverableId, $no, $content['label'], $fileId, $content['url'], $content['notes'], $actorId],
        );

        return ['id' => Database::insertId(), 'public_id' => $pub];
    }

    /** @param array<string,mixed> $content */
    private static function discard(array $content): void
    {
        if ($content['meta'] !== null) {
            FileStore::discard($content['meta']);
        }
    }

    /** @param array<string,mixed> $project */
    private static function assertProjectOpen(array $project): void
    {
        if ($project['archived_at'] !== null || $project['status'] === 'cancelled') {
            throw new ValidationException(['This project is closed; deliverables cannot be changed.']);
        }
    }

    /** @param array<string,mixed> $deliverable */
    private static function assertNotArchived(array $deliverable): void
    {
        if ($deliverable['archived_at'] !== null) {
            throw new ValidationException(['This deliverable is archived. Restore it first.']);
        }
    }
}
