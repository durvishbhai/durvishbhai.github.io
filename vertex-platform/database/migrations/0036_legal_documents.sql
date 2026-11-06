-- Compliance & legal management: versioned legal documents (draft -> scheduled -> published), and the Owner's compliance settings.
CREATE TABLE legal_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    doc_key VARCHAR(60) NOT NULL,                 -- also the public path: /privacy-policy
    title VARCHAR(160) NOT NULL,
    kind ENUM('policy','statement') NOT NULL DEFAULT 'policy',
    requires_acceptance TINYINT(1) NOT NULL DEFAULT 0,   -- clients must accept a materially updated version
    unpublishable TINYINT(1) NOT NULL DEFAULT 1,         -- 0 = a mandatory document that cannot be taken offline
    in_footer TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    required_sections TEXT NULL,                  -- JSON list of heading ids every version must keep
    review_flags TEXT NULL,                       -- JSON list of clauses to be reviewed by an Indian lawyer
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_legal_doc_key (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,                -- sanitised HTML; {{tokens}} are filled in when shown
    status ENUM('draft','scheduled','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
    is_material TINYINT(1) NOT NULL DEFAULT 0,    -- a material change asks clients to accept again (when the document requires acceptance)
    change_summary VARCHAR(500) NULL,
    effective_date DATE NULL,
    publish_at DATETIME NULL,                     -- UTC; a scheduled version goes live when this time has passed
    published_at DATETIME NULL,
    published_by BIGINT UNSIGNED NULL,
    acceptance_days SMALLINT UNSIGNED NULL,       -- days clients get to accept (material versions)
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ldv_doc_no (document_id, version_no),
    KEY idx_ldv_status (document_id, status, published_at),
    CONSTRAINT fk_ldv_doc FOREIGN KEY (document_id) REFERENCES legal_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_ldv_pub FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ldv_cre FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The Owner's compliance settings (business identity, cookie banner text, consent wording, age, deletion, marketing ...). Plain text only, never secrets.
CREATE TABLE compliance_settings (
    setting_key VARCHAR(80) NOT NULL,
    value_text TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_cset_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
