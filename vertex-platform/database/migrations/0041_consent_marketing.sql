-- Evidence of what each form submitter agreed to, and the marketing-email preference list (used only if marketing email is ever switched on).
CREATE TABLE consent_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_key VARCHAR(40) NOT NULL,
    subject_email VARCHAR(190) NULL,
    user_id BIGINT UNSIGNED NULL,
    privacy_version INT UNSIGNED NULL,            -- the live privacy policy version at the time
    text_hash CHAR(64) NOT NULL,                  -- SHA-256 of the consent wording the person saw
    privacy_ack TINYINT(1) NOT NULL DEFAULT 0,
    age_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cr_email (subject_email),
    KEY idx_cr_created (created_at),
    CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_preferences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    opted_in_at DATETIME NULL,
    opted_out_at DATETIME NULL,
    source VARCHAR(40) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ep_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
