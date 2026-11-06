-- Clients accept materially updated policies. Nothing here deletes or changes a client's records: a restriction is computed from these rows (no accepted row for a
-- material live version after its deadline, or a recorded refusal) and lifts the moment a valid acceptance is recorded.
ALTER TABLE legal_document_versions
    ADD COLUMN accept_by DATE NULL AFTER acceptance_days;

CREATE TABLE legal_acceptances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('accepted','declined') NOT NULL,
    method ENUM('portal','signup','admin_record') NOT NULL DEFAULT 'portal',
    decided_at DATETIME NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    recorded_by BIGINT UNSIGNED NULL,             -- the Owner, for an acceptance recorded on a client's behalf (e.g. signed on paper)
    note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_la_user_version (user_id, version_id),
    KEY idx_la_version (version_id, decision),
    CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_la_doc FOREIGN KEY (document_id) REFERENCES legal_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_la_ver FOREIGN KEY (version_id) REFERENCES legal_document_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_la_rec FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (version, client): who was told, when, and which follow-up emails went out.
CREATE TABLE legal_notices (
    version_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notified_at DATETIME NOT NULL,
    reminded_at DATETIME NULL,
    restricted_notified_at DATETIME NULL,
    PRIMARY KEY (version_id, user_id),
    KEY idx_ln_user (user_id),
    CONSTRAINT fk_ln_ver FOREIGN KEY (version_id) REFERENCES legal_document_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_ln_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
