<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Referral status emails to the partner. NEVER automatic: an administrator opens a preview of the exact message, then confirms. The confirmation
 * carries a hash of the previewed text, so the message that was shown is the message that is sent. One email per status change (referral_email_log.sent_key
 * is UNIQUE only for a successful send, so a double click or a retry cannot send twice, while a failed attempt can be retried). When no mail driver is
 * configured nothing is sent and the log says so; no screen claims a delivery that did not happen.
 */
final class ReferralMailService
{
    /**
     * @param array<string,mixed> $referral
     * @return array{history_id:int,to:string,subject:string,body:string,hash:string,already_sent:bool,mail_enabled:bool}
     */
    public static function preview(int $actorId, array $referral, int $historyId): array
    {
        Gate::authorize('referrals.manage', $actorId);
        $h = Database::one("SELECT * FROM referral_history WHERE id = ? AND referral_id = ? AND event = 'status' AND visibility = 'partner'", [$historyId, $referral['id']]);
        if ($h === null) {
            throw new HttpException(404);
        }
        $status = (string) \Vertex\Core\Config::get("referrals.referral_statuses.{$h['to_value']}", $h['to_value']);
        $name = (string) $referral['partner_name'];
        $subject = "Update on your referral {$referral['reference']}: {$status}";
        $tone = in_array((string) $h['to_value'], ['rejected', 'lost', 'closed_lost'], true) ? 'warning' : 'info';
        $spec = [
            'subject' => $subject, 'category' => 'Referral partner', 'tone' => $tone, 'heading' => "Your referral is now: {$status}", 'name' => $name,
            'paragraphs' => array_values(array_filter(["There is an update on a referral you submitted.", $h['note'] ? 'Message from our team: ' . $h['note'] : ''])),
            'details' => ['Referral' => (string) $referral['reference'], 'Prospect' => (string) $referral['prospect_name'], 'Status' => $status],
            'notes' => ['You can see the details in the Partner portal after signing in.'],
        ];
        $body = EmailTemplate::render($spec)['text'];

        return ['history_id' => (int) $h['id'], 'to' => (string) $referral['partner_email'], 'subject' => $subject, 'body' => $body, 'spec' => $spec, 'hash' => hash('sha256', $subject . "\n" . $body),
            'already_sent' => Database::one('SELECT 1 AS x FROM referral_email_log WHERE sent_key = ?', [self::key($referral, (int) $h['id'])]) !== null, 'mail_enabled' => Mailer::enabled()];
    }

    /** @param array<string,mixed> $referral @return 'sent'|'failed'|'not_configured' */
    public static function send(int $actorId, array $referral, int $historyId, string $confirmedHash): string
    {
        $p = self::preview($actorId, $referral, $historyId);
        if (!hash_equals($p['hash'], $confirmedHash)) {
            throw new ValidationException(['The message changed since you previewed it. Open the preview again.']);
        }
        if ($p['already_sent']) {
            throw new ValidationException(['This status email was already sent.']);
        }
        $result = 'not_configured';
        if (Mailer::enabled()) {
            $result = Mailer::sendTemplate($p['to'], $p['spec']) ? 'sent' : 'failed';
        }
        try {
            Database::transaction(function () use ($actorId, $referral, $p, $result): void {
                Database::run('INSERT INTO referral_email_log (referral_id, history_id, to_email, subject, body_hash, result, sent_key, sent_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$referral['id'], $p['history_id'], $p['to'], $p['subject'], $p['hash'], $result, $result === 'sent' ? self::key($referral, $p['history_id']) : null, $actorId]);
                Audit::record('referral.email_' . $result, 'partner_referral', (int) $referral['id'], [], ['history_id' => $p['history_id']], ['recipient_is_partner' => true], $actorId);
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['This status email was already sent.']);
            }
            throw $e;
        }

        return $result;
    }

    /** @param array<string,mixed> $referral */
    private static function key(array $referral, int $historyId): string
    {
        return 'ref:' . $referral['id'] . ':' . $historyId;
    }

    /** @param array<string,mixed> $referral @return list<array<string,mixed>> */
    public static function log(int $userId, array $referral): array
    {
        return Gate::can('referrals.review', $userId)
            ? Database::all('SELECT l.history_id, l.result, l.subject, l.created_at, u.name AS sent_by FROM referral_email_log l LEFT JOIN users u ON u.id = l.sent_by WHERE l.referral_id = ? ORDER BY l.id DESC', [$referral['id']]) : [];
    }
}
