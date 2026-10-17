-- 0014 Phase 9: optional two-factor login (TOTP), data import log, recycle bin support.
-- The TOTP secret is stored sealed (SecretBox, AES-256-GCM); recovery codes are stored only as SHA-256 hashes.
ALTER TABLE users
    ADD COLUMN totp_secret_enc VARCHAR(400) NULL AFTER password_changed_at,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret_enc,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at;

CREATE TABLE user_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_recovery_code (user_id, code_hash),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per CSV import (clients, enquiries): who, what file, how many rows were accepted or rejected.
CREATE TABLE data_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    kind VARCHAR(30) NOT NULL,
    filename VARCHAR(190) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    total_rows INT UNSIGNED NOT NULL,
    imported_rows INT UNSIGNED NOT NULL,
    failed_rows INT UNSIGNED NOT NULL,
    error_summary TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_data_imports_public (public_id),
    KEY idx_data_imports_created (created_at),
    CONSTRAINT fk_data_imports_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
