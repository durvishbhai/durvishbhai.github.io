-- 0016 Phase 11: dynamic forms, agreements and document vault, project templates and checklists, client scope requests, feedback points, SLA policy and automation rules.

-- ---------------------------------------------------------------------------------------------------------------- dynamic forms
CREATE TABLE forms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    access ENUM('public','private_link','login','client') NOT NULL DEFAULT 'public',
    access_token CHAR(32) NULL,
    success_message VARCHAR(500) NULL,
    submit_label VARCHAR(40) NOT NULL DEFAULT 'Submit',
    notify_permission VARCHAR(60) NULL,
    create_enquiry TINYINT(1) NOT NULL DEFAULT 0,
    max_submissions INT UNSIGNED NULL,
    closes_on DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY uq_forms_public (public_id),
    UNIQUE KEY uq_forms_slug (slug),
    CONSTRAINT fk_forms_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_fields (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    field_key VARCHAR(40) NOT NULL,
    label VARCHAR(200) NOT NULL,
    field_type ENUM('text','textarea','email','phone','number','date','select','radio','checkbox','multiselect','heading','info') NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    options_json TEXT NULL,
    placeholder VARCHAR(120) NULL,
    help_text VARCHAR(255) NULL,
    min_value INT NULL,
    max_value INT NULL,
    show_if_field VARCHAR(40) NULL,
    show_if_op ENUM('equals','not_equals','contains','filled') NULL,
    show_if_value VARCHAR(190) NULL,
    maps_to VARCHAR(30) NULL,
    UNIQUE KEY uq_form_field_key (form_id, field_key),
    KEY idx_form_field_pos (form_id, position),
    CONSTRAINT fk_form_fields_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    form_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(24) NOT NULL,
    submitted_by BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    data_json MEDIUMTEXT NOT NULL,
    status ENUM('new','reviewed','archived') NOT NULL DEFAULT 'new',
    enquiry_id BIGINT UNSIGNED NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_form_sub_public (public_id),
    UNIQUE KEY uq_form_sub_ref (reference),
    UNIQUE KEY uq_form_sub_token (submit_token),
    KEY idx_form_sub_form (form_id, created_at),
    CONSTRAINT fk_form_sub_form FOREIGN KEY (form_id) REFERENCES forms (id),
    CONSTRAINT fk_form_sub_user FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------- agreements
CREATE TABLE agreement_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('active','retired') NOT NULL DEFAULT 'active',
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_agr_tpl_public (public_id),
    UNIQUE KEY uq_agr_tpl_name (name),
    CONSTRAINT fk_agr_tpl_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agreement_template_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    body MEDIUMTEXT NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_agr_tpl_ver (template_id, version_no),
    CONSTRAINT fk_agr_tplver_tpl FOREIGN KEY (template_id) REFERENCES agreement_templates (id) ON DELETE CASCADE,
    CONSTRAINT fk_agr_tplver_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agreements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    template_id BIGINT UNSIGNED NULL,
    template_version INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    mode ENUM('online','physical') NOT NULL,
    body MEDIUMTEXT NULL,
    body_sha256 CHAR(64) NULL,
    status ENUM('draft','sent','partially_signed','executed','cancelled','expired') NOT NULL DEFAULT 'draft',
    sent_at DATETIME NULL,
    expires_on DATE NULL,
    executed_at DATETIME NULL,
    physical_signed_on DATE NULL,
    physical_note VARCHAR(500) NULL,
    cancelled_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_agreements_public (public_id),
    UNIQUE KEY uq_agreements_ref (reference),
    KEY idx_agreements_client (client_id, status),
    CONSTRAINT fk_agreements_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_agreements_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_agreements_template FOREIGN KEY (template_id) REFERENCES agreement_templates (id) ON DELETE SET NULL,
    CONSTRAINT fk_agreements_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agreement_signatures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agreement_id BIGINT UNSIGNED NOT NULL,
    party ENUM('company','client') NOT NULL,
    signer_user_id BIGINT UNSIGNED NULL,
    signer_name VARCHAR(160) NOT NULL,
    body_sha256 CHAR(64) NOT NULL,
    method ENUM('online','physical') NOT NULL,
    signed_at DATETIME NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    UNIQUE KEY uq_agr_sig (agreement_id, party),
    CONSTRAINT fk_agr_sig_agr FOREIGN KEY (agreement_id) REFERENCES agreements (id) ON DELETE CASCADE,
    CONSTRAINT fk_agr_sig_user FOREIGN KEY (signer_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agreement_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agreement_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    stored_file_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('signed_scan','final_document','supporting') NOT NULL,
    note VARCHAR(255) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_agr_file_ver (agreement_id, version_no),
    CONSTRAINT fk_agr_file_agr FOREIGN KEY (agreement_id) REFERENCES agreements (id) ON DELETE CASCADE,
    CONSTRAINT fk_agr_file_stored FOREIGN KEY (stored_file_id) REFERENCES stored_files (id),
    CONSTRAINT fk_agr_file_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agreement_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agreement_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    detail VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_agr_events (agreement_id, id),
    CONSTRAINT fk_agr_events_agr FOREIGN KEY (agreement_id) REFERENCES agreements (id) ON DELETE CASCADE,
    CONSTRAINT fk_agr_events_user FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------- project templates and checklists
CREATE TABLE project_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    service_category VARCHAR(40) NULL,
    description VARCHAR(500) NULL,
    status ENUM('active','retired') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_project_tpl_public (public_id),
    UNIQUE KEY uq_project_tpl_name (name),
    CONSTRAINT fk_project_tpl_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_template_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    kind ENUM('milestone','task','checklist') NOT NULL,
    title VARCHAR(200) NOT NULL,
    parent_position SMALLINT UNSIGNED NULL,
    offset_days SMALLINT UNSIGNED NULL,
    estimate_minutes INT UNSIGNED NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    KEY idx_tpl_items (template_id, position),
    CONSTRAINT fk_tpl_items_tpl FOREIGN KEY (template_id) REFERENCES project_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_checklist_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    in_scope TINYINT(1) NOT NULL DEFAULT 1,
    done_at DATETIME NULL,
    done_by BIGINT UNSIGNED NULL,
    delivered_in_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_checklist_project (project_id, position),
    CONSTRAINT fk_checklist_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_checklist_user FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------- client scope requests and timestamped feedback
CREATE TABLE scope_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    wanted_by DATE NULL,
    status ENUM('submitted','assessing','quoted','declined','withdrawn') NOT NULL DEFAULT 'submitted',
    assessed_by BIGINT UNSIGNED NULL,
    assessment_note VARCHAR(1000) NULL,
    est_amount_minor BIGINT UNSIGNED NULL,
    est_days SMALLINT UNSIGNED NULL,
    change_request_id BIGINT UNSIGNED NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_scope_req_public (public_id),
    UNIQUE KEY uq_scope_req_ref (reference),
    UNIQUE KEY uq_scope_req_token (submit_token),
    KEY idx_scope_req_project (project_id, status),
    CONSTRAINT fk_scope_req_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_scope_req_user FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_scope_req_assessor FOREIGN KEY (assessed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_feedback_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    revision_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    at_seconds INT UNSIGNED NULL,
    note VARCHAR(1000) NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_feedback_revision (revision_id, position),
    CONSTRAINT fk_feedback_revision FOREIGN KEY (revision_id) REFERENCES revision_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_user FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------- SLA policy and automation rules
CREATE TABLE sla_policies (
    priority ENUM('low','normal','high','urgent') NOT NULL PRIMARY KEY,
    first_response_hours SMALLINT UNSIGNED NOT NULL,
    resolution_hours SMALLINT UNSIGNED NOT NULL,
    escalate_after_hours SMALLINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sla_policies (priority, first_response_hours, resolution_hours, escalate_after_hours, updated_at) VALUES
    ('urgent', 2, 24, 4, UTC_TIMESTAMP()),
    ('high', 4, 48, 8, UTC_TIMESTAMP()),
    ('normal', 8, 120, 24, UTC_TIMESTAMP()),
    ('low', 24, 240, 72, UTC_TIMESTAMP());

ALTER TABLE tickets
    ADD COLUMN first_response_due_at DATETIME NULL AFTER first_staff_response_at,
    ADD COLUMN resolution_due_at DATETIME NULL AFTER first_response_due_at,
    ADD COLUMN sla_breached_at DATETIME NULL AFTER resolution_due_at;

CREATE TABLE automation_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    trigger_type VARCHAR(40) NOT NULL,
    threshold INT UNSIGNED NOT NULL DEFAULT 1,
    notify_target VARCHAR(80) NOT NULL,
    escalate_to VARCHAR(80) NULL,
    escalate_after INT UNSIGNED NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_run_at DATETIME NULL,
    last_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_automation_public (public_id),
    CONSTRAINT fk_automation_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
