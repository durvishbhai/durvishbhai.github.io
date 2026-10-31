<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Single-use, expiring tokens for email verification, password reset and invitations.
 * The raw token exists only in the email link; the database stores its SHA-256.
 */
final class TokenService
{
    public const VERIFY = 'email_verify';
    public const RESET = 'password_reset';
    public const INVITE = 'invite';

    /** Issues a new token and invalidates earlier unused tokens of the same purpose for the user. */
    public static function issue(int $userId, string $purpose, int $ttlMinutes, ?string $ip = null): string
    {
        $raw = bin2hex(random_bytes(32));
        Database::transaction(function () use ($userId, $purpose, $ttlMinutes, $ip, $raw): void {
            Database::run(
                'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
                [$userId, $purpose],
            );
            Database::run(
                'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, created_ip, created_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?, UTC_TIMESTAMP())',
                [$userId, $purpose, hash('sha256', $raw), $ttlMinutes, $ip],
            );
        });

        return $raw;
    }

    /**
     * Looks a token up without consuming it.
     *
     * @param list<string> $purposes
     * @return array{id:int,user_id:int,purpose:string}|null null when unknown, used, expired or wrong purpose
     */
    public static function peek(string $raw, array $purposes): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $raw) || $purposes === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($purposes), '?'));
        $row = Database::one(
            "SELECT id, user_id, purpose FROM auth_tokens
              WHERE token_hash = ? AND purpose IN ($in) AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()",
            [hash('sha256', $raw), ...$purposes],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'purpose' => (string) $row['purpose']];
    }

    /** Atomically marks the token used. Returns false if someone else (or a replay) already consumed it. */
    public static function consume(int $tokenId): bool
    {
        return Database::run('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL', [$tokenId])->rowCount() === 1;
    }
}
