<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/** Module 33: timestamp-based feedback. A client attaches numbered points to a revision request ("1:05 - the logo is too small"); staff tick them off. */
final class FeedbackPointService
{
    public const MAX_POINTS = 100;

    /** Accepts "75", "1:15" or "0:01:15". @return ?int seconds */
    public static function parseTime(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (ctype_digit($text) && (int) $text <= 86400) {
            return (int) $text;
        }
        if (preg_match('/^(?:(\d{1,2}):)?(\d{1,3}):([0-5]\d)$/', $text, $m)) {
            $s = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
            if ($s <= 86400) {
                return $s;
            }
        }
        throw new ValidationException(['Time must look like 1:15 (minutes:seconds) or 0:01:15 (hours:minutes:seconds), within 24 hours.']);
    }

    public static function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** @return list<array<string,mixed>> */
    public static function forRevision(array $r): array
    {
        return Database::all('SELECT f.*, u.name AS resolved_by_name FROM revision_feedback_points f LEFT JOIN users u ON u.id = f.resolved_by WHERE f.revision_id = ? ORDER BY f.position', [$r['id']]);
    }

    public static function add(int $userId, array $r, string $at, string $note): void
    {
        Gate::authorize('feedback.add', $userId);
        if (RevisionAccess::role($userId, $r) !== 'client') {
            throw new HttpException(404);
        }
        if (in_array($r['status'], ['resolved', 'rejected', 'cancelled'], true)) {
            throw new ValidationException(['This request is closed.']);
        }
        if (!RateLimiter::hit('feedback_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $secs = self::parseTime($at);
        $note = (string) FieldRules::line($note, 'Feedback', 3, 1000);
        Database::transaction(function () use ($userId, $r, $secs, $note): void {
            Database::one('SELECT id FROM revision_requests WHERE id = ? FOR UPDATE', [$r['id']]);
            if ((int) Database::one('SELECT COUNT(*) AS n FROM revision_feedback_points WHERE revision_id = ?', [$r['id']])['n'] >= self::MAX_POINTS) {
                throw new ValidationException(['That is the maximum number of feedback points for one request.']);
            }
            $pos = (int) Database::one('SELECT COALESCE(MAX(position), 0) AS p FROM revision_feedback_points WHERE revision_id = ?', [$r['id']])['p'] + 1;
            Database::run('INSERT INTO revision_feedback_points (revision_id, position, at_seconds, note, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$r['id'], $pos, $secs, $note]);
            Audit::record('revision.feedback_added', 'revision_request', (int) $r['id'], [], ['position' => $pos], [], $userId);
            if ($r['assigned_to'] ?? null) {
                Notifier::send([(int) $r['assigned_to']], 'revision.feedback', 'New feedback on ' . $r['reference'], mb_substr($note, 0, 160), '/revisions/' . $r['public_id'], (int) $r['project_id'], 'revision_request', (int) $r['id'], 'fb:' . $r['id'] . ':' . $pos);
            }
        });
    }

    public static function resolve(int $userId, array $r, int $pointId, bool $done): void
    {
        Gate::authorize('feedback.resolve', $userId);
        if (RevisionAccess::role($userId, $r) !== 'staff') {
            throw new HttpException(404);
        }
        $n = Database::run('UPDATE revision_feedback_points SET resolved_at = ?, resolved_by = ? WHERE id = ? AND revision_id = ?', [$done ? gmdate('Y-m-d H:i:s') : null, $done ? $userId : null, $pointId, $r['id']])->rowCount();
        if ($n < 1) {
            throw new HttpException(404);
        }
        Audit::record($done ? 'revision.feedback_resolved' : 'revision.feedback_reopened', 'revision_request', (int) $r['id'], [], ['point' => $pointId], [], $userId);
    }
}
