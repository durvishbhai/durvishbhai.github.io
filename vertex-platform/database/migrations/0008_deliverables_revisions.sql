-- 0008 Deliverables, secure file metadata, versions, internal QA, client approvals, revision requests, revision policy,
-- change requests (pending extra-cost proposals) and in-app notifications.
-- Additive only: no existing table is altered or dropped. Nothing here cascades from projects/clients/invoices.

-- Metadata of privately stored files. The bytes live OUTSIDE the web root (storage/uploads/YYYY/MM/<random>); there is
-- deliberately no route that takes a stored_files id: files are reached only through an owner record's download route.
CREATE TABLE stored_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_path VARCHAR(80) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    scan_status ENUM('not_scanned','clean') NOT NULL DEFAULT 'not_scanned',
    scanner VARCHAR(40) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stored_files_public_id (public_id),
    UNIQUE KEY uq_stored_files_path (stored_path),
    KEY idx_stored_files_sha (sha256),
    CONSTRAINT fk_stored_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deliverables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    code VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    category ENUM('video','thumbnail','graphic_design','website_files','document','source_files','other') NOT NULL DEFAULT 'other',
    task_id BIGINT UNSIGNED NULL,
    milestone_id BIGINT UNSIGNED NULL,
    -- Visibility/lifecycle of the DELIVERABLE as a whole. Each version also has its own status (see below).
    status ENUM('draft','pending_review','approved_internal','published','archived') NOT NULL DEFAULT 'draft',
    published_version_id BIGINT UNSIGNED NULL,
    first_published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    archived_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deliverables_public_id (public_id),
    UNIQUE KEY uq_deliverables_code (code),
    KEY idx_deliverables_project (project_id, status, archived_at),
    KEY idx_deliverables_status (status),
    CONSTRAINT fk_deliverables_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_deliverables_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliverables_milestone FOREIGN KEY (milestone_id) REFERENCES milestones (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliverables_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_deliverables_archived_by FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per version; rows are never overwritten or deleted (a new upload is a new row). The client sees a version only when
-- published_at IS NOT NULL and its status is published/superseded (withdrawn versions disappear from the client portal).
CREATE TABLE deliverable_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    deliverable_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    label VARCHAR(30) NULL,
    file_id BIGINT UNSIGNED NULL,
    external_url VARCHAR(500) NULL,
    change_notes TEXT NULL,
    status ENUM('draft','pending_review','changes_requested','approved_internal','published','superseded','withdrawn') NOT NULL DEFAULT 'draft',
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    withdrawn_by BIGINT UNSIGNED NULL,
    withdrawn_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dv_public_id (public_id),
    UNIQUE KEY uq_dv_number (deliverable_id, version_no),
    KEY idx_dv_status (status),
    CONSTRAINT chk_dv_has_content CHECK (file_id IS NOT NULL OR external_url IS NOT NULL),
    CONSTRAINT fk_dv_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dv_file FOREIGN KEY (file_id) REFERENCES stored_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dv_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dv_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dv_published_by FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dv_withdrawn_by FOREIGN KEY (withdrawn_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE deliverables
    ADD CONSTRAINT fk_deliverables_published_version FOREIGN KEY (published_version_id) REFERENCES deliverable_versions (id) ON DELETE RESTRICT;

-- Internal QA decisions (staff only; never shown to clients).
CREATE TABLE deliverable_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NULL,
    decision ENUM('approved','changes_requested') NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dr_version (version_id, id),
    CONSTRAINT fk_dr_version FOREIGN KEY (version_id) REFERENCES deliverable_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dr_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('default','service','project') NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    service_category VARCHAR(40) NULL,
    project_id BIGINT UNSIGNED NULL,
    included_revisions INT UNSIGNED NULL,
    terms TEXT NULL,
    source_reference VARCHAR(120) NULL,
    version INT UNSIGNED NOT NULL,
    current_flag TINYINT NULL,
    reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    superseded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_version (scope_key, version),
    UNIQUE KEY uq_rp_current (scope_key, current_flag),
    CONSTRAINT fk_rp_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rp_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deliverable_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NULL,
    description TEXT NOT NULL,
    status ENUM('submitted','under_review','included_revision','potentially_chargeable','awaiting_cost_approval','approved_for_work','in_progress','ready_for_client_review','resolved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
    assigned_to BIGINT UNSIGNED NULL,
    scope_decision ENUM('unclassified','included','chargeable') NOT NULL DEFAULT 'unclassified',
    scope_reason TEXT NULL,
    policy_snapshot_json LONGTEXT NULL,
    client_response TEXT NULL,
    resolution TEXT NULL,
    resolved_version_id BIGINT UNSIGNED NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rr_public_id (public_id),
    UNIQUE KEY uq_rr_reference (reference),
    UNIQUE KEY uq_rr_token (requested_by, submit_token),
    KEY idx_rr_project (project_id, status),
    KEY idx_rr_assignee (assigned_to, status),
    KEY idx_rr_deliverable (deliverable_id),
    CONSTRAINT fk_rr_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rr_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rr_version FOREIGN KEY (version_id) REFERENCES deliverable_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rr_resolved_version FOREIGN KEY (resolved_version_id) REFERENCES deliverable_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rr_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rr_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rf (revision_id, file_id),
    CONSTRAINT fk_rf_revision FOREIGN KEY (revision_id) REFERENCES revision_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rf_file FOREIGN KEY (file_id) REFERENCES stored_files (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only status history. visibility='internal' rows (e.g. classification steps) are never shown to the client.
CREATE TABLE revision_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    note TEXT NULL,
    visibility ENUM('internal','client') NOT NULL DEFAULT 'internal',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rsh_revision (revision_id, id),
    CONSTRAINT fk_rsh_revision FOREIGN KEY (revision_id) REFERENCES revision_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rsh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rn_revision (revision_id, id),
    CONSTRAINT fk_rn_revision FOREIGN KEY (revision_id) REFERENCES revision_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rn_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending extra-cost proposals. Safe integration point: when quotation revisions exist (Phase 4) an approved row is
-- turned into a quotation revision; until then nothing is charged or invoiced and the original approved price is untouched.
CREATE TABLE change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    revision_request_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    scope_description TEXT NOT NULL,
    reason TEXT NOT NULL,
    proposed_amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    proposed_days INT UNSIGNED NULL,
    proposed_due_date DATE NULL,
    baseline_json LONGTEXT NULL,
    quotation_ref VARCHAR(60) NULL,
    status ENUM('draft','pending_client','approved','rejected','withdrawn','superseded') NOT NULL DEFAULT 'draft',
    proposed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cr_public_id (public_id),
    UNIQUE KEY uq_cr_reference (reference),
    KEY idx_cr_project (project_id, status),
    KEY idx_cr_revision (revision_request_id),
    CONSTRAINT fk_cr_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_revision FOREIGN KEY (revision_request_id) REFERENCES revision_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_proposed_by FOREIGN KEY (proposed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cr_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The client's own, append-only acceptance record for an EXACT version.
CREATE TABLE client_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    deliverable_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action ENUM('approved','rejected','revision_requested') NOT NULL,
    feedback TEXT NULL,
    revision_request_id BIGINT UNSIGNED NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ca_public_id (public_id),
    UNIQUE KEY uq_ca_token (user_id, submit_token),
    KEY idx_ca_version (version_id, id),
    KEY idx_ca_deliverable (deliverable_id, id),
    CONSTRAINT fk_ca_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ca_deliverable FOREIGN KEY (deliverable_id) REFERENCES deliverables (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ca_version FOREIGN KEY (version_id) REFERENCES deliverable_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ca_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ca_revision FOREIGN KEY (revision_request_id) REFERENCES revision_requests (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notification events. email_status stays 'not_sent': no email is sent by this phase and no row claims otherwise.
-- (user_id, dedupe_key) is unique so a retried request cannot notify the same person twice.
CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    subject_type VARCHAR(30) NULL,
    subject_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    body VARCHAR(255) NULL,
    link VARCHAR(200) NULL,
    dedupe_key CHAR(64) NOT NULL,
    email_status ENUM('not_sent','sent','failed') NOT NULL DEFAULT 'not_sent',
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
    KEY idx_notifications_user (user_id, read_at, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_notifications_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
