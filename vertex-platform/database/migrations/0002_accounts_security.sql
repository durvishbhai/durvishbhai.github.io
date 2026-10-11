-- 0002 Accounts & security: richer account lifecycle, single-use auth tokens, generic rate limiter.
-- Data-preserving: existing users keep their rows; legacy status 'suspended' becomes 'deactivated'.

-- Two-step enum change so no existing row ever holds an invalid value.
ALTER TABLE users MODIFY status ENUM('pending','active','suspended','deactivated','archived') NOT NULL DEFAULT 'pending';

UPDATE users SET status = 'deactivated' WHERE status = 'suspended';

ALTER TABLE users MODIFY status ENUM('pending','active','deactivated','archived') NOT NULL DEFAULT 'pending';

ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_changed_at,
    ADD COLUMN timezone VARCHAR(64) NULL AFTER phone,
    ADD COLUMN deactivated_at DATETIME NULL AFTER last_login_ip,
    ADD COLUMN deactivated_by BIGINT UNSIGNED NULL AFTER deactivated_at,
    ADD COLUMN deactivation_reason VARCHAR(255) NULL AFTER deactivated_by,
    ADD CONSTRAINT fk_users_deactivated_by FOREIGN KEY (deactivated_by) REFERENCES users (id) ON DELETE SET NULL;

-- Single-use, expiring tokens. Only the SHA-256 of the token is stored, never the token itself.
CREATE TABLE auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('email_verify','password_reset','invite') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id, purpose, used_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic fixed-window rate limiter (bucket = what is limited, rkey = SHA-256 of the subject, e.g. ip or email).
CREATE TABLE rate_limits (
    bucket VARCHAR(60) NOT NULL,
    rkey CHAR(64) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    window_started_at DATETIME NOT NULL,
    PRIMARY KEY (bucket, rkey),
    KEY idx_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
