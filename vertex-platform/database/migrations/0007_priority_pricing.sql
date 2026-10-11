-- 0007 Priority/urgency pricing foundation. Money is stored as integer minor units (paise); rates as basis points.
-- No invoice or quotation table is touched (neither exists yet); these tables only prepare the integration points.

-- Versioned rules. A change inserts a NEW version and supersedes the old row; rows are never updated in place
-- apart from flipping the superseded marker. service_category '*' is the default rule for all services.
CREATE TABLE priority_pricing_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_category VARCHAR(40) NOT NULL DEFAULT '*',
    priority ENUM('low','normal','high','urgent') NOT NULL,
    surcharge_type ENUM('none','fixed','percent') NOT NULL DEFAULT 'none',
    fixed_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    percent_bps INT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL,
    current_flag TINYINT NULL,
    reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    superseded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pricing_version (service_category, priority, version),
    UNIQUE KEY uq_pricing_current (service_category, priority, current_flag),
    CONSTRAINT fk_pricing_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shipped defaults: NO surcharge until an authorised Admin configures one (the business's real rates are not invented).
INSERT INTO priority_pricing_rules (service_category, priority, surcharge_type, fixed_minor, percent_bps, version, current_flag, reason, created_at) VALUES
    ('*', 'low', 'none', 0, 0, 1, 1, 'Initial default', UTC_TIMESTAMP()),
    ('*', 'normal', 'none', 0, 0, 1, 1, 'Initial default', UTC_TIMESTAMP()),
    ('*', 'high', 'none', 0, 0, 1, 1, 'Initial default', UTC_TIMESTAMP()),
    ('*', 'urgent', 'none', 0, 0, 1, 1, 'Initial default', UTC_TIMESTAMP());

-- The approved quotation baseline of a project. Set once (by an Admin recording an approved quotation) and then
-- immutable in application code; later changes only ever ADD price_revisions rows.
ALTER TABLE projects
    ADD COLUMN approved_quotation_ref VARCHAR(60) NULL AFTER currency,
    ADD COLUMN approved_amount_minor BIGINT UNSIGNED NULL AFTER approved_quotation_ref,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_amount_minor,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER approved_at,
    ADD CONSTRAINT fk_projects_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL;

-- Append-only price history: revision 1 = original approved quotation, later revisions = client-approved changes.
-- amount_minor = base_minor + surcharge_minor. No code path updates or deletes these rows.
CREATE TABLE price_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    kind ENUM('original','priority_change') NOT NULL,
    base_minor BIGINT UNSIGNED NOT NULL,
    surcharge_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    amount_minor BIGINT UNSIGNED NOT NULL,
    quotation_ref VARCHAR(60) NULL,
    source_request_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_revisions (project_id, revision_no),
    CONSTRAINT fk_price_rev_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_price_rev_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A proposed price change awaiting the client's decision. Nothing is charged by this table.
CREATE TABLE price_change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    from_priority ENUM('low','normal','high','urgent') NOT NULL,
    to_priority ENUM('low','normal','high','urgent') NOT NULL,
    service_category VARCHAR(40) NULL,
    base_minor BIGINT UNSIGNED NOT NULL,
    current_surcharge_minor BIGINT UNSIGNED NOT NULL,
    proposed_surcharge_minor BIGINT UNSIGNED NOT NULL,
    adjustment_minor BIGINT NOT NULL,
    rule_snapshot_json LONGTEXT NOT NULL,
    status ENUM('pending_client','approved','rejected','withdrawn','superseded') NOT NULL DEFAULT 'pending_client',
    requested_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    applied_revision_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_change_public_id (public_id),
    KEY idx_price_change_project (project_id, status),
    CONSTRAINT fk_price_change_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_price_change_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_price_change_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_price_change_revision FOREIGN KEY (applied_revision_id) REFERENCES price_revisions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
