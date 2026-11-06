-- 0009 Support Centre: bug reports, support tickets, conversations, internal notes, warranty tracking.
-- Additive. The only change to an existing table is one nullable column on change_requests (so a chargeable BUG proposal can use the
-- same pending change-request record as a chargeable revision; no existing row is modified). Nothing here cascades from projects/clients.

-- Versioned warranty terms text. Nothing is shipped: the business records the wording of its agreement here.
CREATE TABLE warranty_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    terms TEXT NOT NULL,
    default_months SMALLINT UNSIGNED NULL,
    source_reference VARCHAR(120) NULL,
    current_flag TINYINT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warranty_terms_version (version),
    UNIQUE KEY uq_warranty_terms_current (current_flag),
    CONSTRAINT fk_warranty_terms_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per project that has a recorded warranty decision. Status is DERIVED at read time from these facts and today's date in the
-- application time zone (no cron needed). start_date/end_date are only ever set from an explicit, recorded start date.
CREATE TABLE project_warranties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    eligibility ENUM('eligible','not_applicable') NOT NULL,
    duration_months SMALLINT UNSIGNED NOT NULL,
    start_basis ENUM('final_delivery','client_acceptance','contract_date') NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    quotation_ref VARCHAR(120) NULL,
    terms_id BIGINT UNSIGNED NULL,
    project_terms TEXT NULL,
    admin_notes TEXT NULL,
    suspended TINYINT(1) NOT NULL DEFAULT 0,
    suspended_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_warranties_project (project_id),
    KEY idx_project_warranties_end (end_date),
    CONSTRAINT fk_pw_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pw_terms FOREIGN KEY (terms_id) REFERENCES warranty_terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pw_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pw_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every warranty change (before/after JSON of the contractual fields, who, why).
CREATE TABLE warranty_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    event VARCHAR(40) NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_warranty_history_project (project_id, id),
    CONSTRAINT fk_wh_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_wh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared ticket infrastructure. kind = 'bug' (technical bug workflow + warranty review) or 'support' (general ticket workflow).
CREATE TABLE tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    kind ENUM('bug','support') NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    requester_id BIGINT UNSIGNED NULL,
    category ENUM('bug_report','technical_support','project_query','billing','payment','account_access','domain_hosting','general_enquiry','other') NOT NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    severity ENUM('low','normal','high','critical') NULL,
    status ENUM('draft','open','triaged','confirmed','in_progress','fixed','client_verification','closed','need_more_information','unable_to_reproduce','rejected',
                'assigned','awaiting_client_response','awaiting_internal_team','resolved','reopened') NOT NULL DEFAULT 'open',
    assigned_to BIGINT UNSIGNED NULL,
    resolution TEXT NULL,
    reopen_count INT UNSIGNED NOT NULL DEFAULT 0,
    escalated_at DATETIME NULL,
    escalated_by BIGINT UNSIGNED NULL,
    escalation_reason VARCHAR(255) NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    first_staff_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tickets_public_id (public_id),
    UNIQUE KEY uq_tickets_reference (reference),
    UNIQUE KEY uq_tickets_token (requester_id, submit_token),
    KEY idx_tickets_client (client_id, status),
    KEY idx_tickets_project (project_id, status),
    KEY idx_tickets_assignee (assigned_to, status),
    KEY idx_tickets_status (kind, status),
    CONSTRAINT fk_tickets_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_requester FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_escalated_by FOREIGN KEY (escalated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bug-only facts (1:1 with a kind='bug' ticket), including the warranty review.
CREATE TABLE bug_reports (
    ticket_id BIGINT UNSIGNED NOT NULL,
    affected_url VARCHAR(500) NULL,
    steps_to_reproduce TEXT NULL,
    expected_behaviour TEXT NULL,
    actual_behaviour TEXT NULL,
    browser_details VARCHAR(255) NULL,
    device_details VARCHAR(160) NULL,
    captured_user_agent VARCHAR(255) NULL,
    warranty_snapshot_json LONGTEXT NULL,
    eligibility ENUM('awaiting_technical_review','submitted_for_assessment','covered','not_covered','potentially_chargeable') NULL,
    eligibility_reason TEXT NULL,
    eligibility_terms VARCHAR(255) NULL,
    considerations_json LONGTEXT NULL,
    quotation_required TINYINT(1) NOT NULL DEFAULT 0,
    eligibility_reviewer_id BIGINT UNSIGNED NULL,
    eligibility_reviewed_at DATETIME NULL,
    eligibility_overridden TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (ticket_id),
    CONSTRAINT fk_bug_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bug_reviewer FOREIGN KEY (eligibility_reviewer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CLIENT-VISIBLE conversation. Internal notes live in a different table on purpose, so no query for the conversation can return them.
CREATE TABLE ticket_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    author_role ENUM('client','staff') NOT NULL,
    body TEXT NOT NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tm_token (author_id, submit_token),
    KEY idx_tm_ticket (ticket_id, id),
    CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tm_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STAFF-ONLY notes and technical findings. Never joined into anything a client can reach.
CREATE TABLE ticket_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    kind ENUM('note','technical_finding','decision') NOT NULL DEFAULT 'note',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tn_ticket (ticket_id, id),
    CONSTRAINT fk_tn_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tn_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments (bytes in private storage via the Phase 3 stored_files/FileStore). visibility='internal' files belong to staff only.
CREATE TABLE ticket_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    visibility ENUM('client','internal') NOT NULL DEFAULT 'client',
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tf (ticket_id, file_id),
    KEY idx_tf_message (message_id),
    CONSTRAINT fk_tf_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tf_message FOREIGN KEY (message_id) REFERENCES ticket_messages (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tf_file FOREIGN KEY (file_id) REFERENCES stored_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tf_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only activity/status history. visibility='internal' rows are never shown to clients.
CREATE TABLE ticket_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    event VARCHAR(40) NOT NULL,
    from_value VARCHAR(60) NULL,
    to_value VARCHAR(60) NULL,
    note TEXT NULL,
    visibility ENUM('client','internal') NOT NULL DEFAULT 'internal',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ta_ticket (ticket_id, id),
    CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ta_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read/unread markers for the conversation, per person.
CREATE TABLE ticket_reads (
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (ticket_id, user_id),
    CONSTRAINT fk_tr_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A chargeable bug can carry the same pending change request as a chargeable revision.
ALTER TABLE change_requests
    ADD COLUMN ticket_id BIGINT UNSIGNED NULL AFTER revision_request_id,
    ADD KEY idx_cr_ticket (ticket_id),
    ADD CONSTRAINT fk_cr_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT;

-- `warranty.manage` (contractual warranty settings) had no function before this phase and is now Administrator-only. Remove the
-- earlier default grants from Manager and Support so a deployment that synced the Phase 0 defaults does not keep them.
DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id AND r.slug IN ('manager','support_staff')
  JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = 'warranty.manage';
