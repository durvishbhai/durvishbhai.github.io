-- 0005 CRM and client records.
-- Extends the Phase 1 `clients` table in place (existing rows and their user links are preserved and back-filled).
-- Nothing here is deleted by the application: enquiries/clients are archived, never removed.

CREATE TABLE crm_stages (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(60) NOT NULL,
    kind ENUM('open','won','lost') NOT NULL DEFAULT 'open',
    position SMALLINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_stages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crm_stages (code, name, kind, position, is_active, updated_at) VALUES
    ('new_enquiry', 'New Enquiry', 'open', 10, 1, UTC_TIMESTAMP()),
    ('contacted', 'Contacted', 'open', 20, 1, UTC_TIMESTAMP()),
    ('requirement_review', 'Requirement Review', 'open', 30, 1, UTC_TIMESTAMP()),
    ('quote_in_preparation', 'Quote in Preparation', 'open', 40, 1, UTC_TIMESTAMP()),
    ('quote_sent', 'Quote Sent', 'open', 50, 1, UTC_TIMESTAMP()),
    ('negotiation', 'Negotiation', 'open', 60, 1, UTC_TIMESTAMP()),
    ('won', 'Won', 'won', 70, 1, UTC_TIMESTAMP()),
    ('lost', 'Lost', 'lost', 80, 1, UTC_TIMESTAMP());

-- Human-readable reference numbers (ENQ-2026-000123, VIS-2026-0042). One row per (scope, year); incremented atomically.
CREATE TABLE reference_sequences (
    scope VARCHAR(20) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_value INT UNSIGNED NOT NULL,
    PRIMARY KEY (scope, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clients
    MODIFY user_id BIGINT UNSIGNED NULL,
    ADD COLUMN client_type ENUM('individual','business') NOT NULL DEFAULT 'individual' AFTER user_id,
    ADD COLUMN contact_name VARCHAR(120) NULL AFTER client_type,
    ADD COLUMN email VARCHAR(190) NULL AFTER contact_name,
    ADD COLUMN phone VARCHAR(32) NULL AFTER email,
    ADD COLUMN phone_digits VARCHAR(15) NULL AFTER phone,
    ADD COLUMN company_norm VARCHAR(160) NULL AFTER company_name,
    ADD COLUMN website VARCHAR(190) NULL AFTER company_norm,
    ADD COLUMN tax_id VARCHAR(40) NULL AFTER website,
    ADD COLUMN address VARCHAR(500) NULL AFTER tax_id,
    ADD COLUMN status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active' AFTER address,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN merged_into_client_id BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN archived_at DATETIME NULL AFTER updated_at;

UPDATE clients c JOIN users u ON u.id = c.user_id
   SET c.contact_name = u.name, c.email = u.email, c.phone = u.phone,
       c.phone_digits = RIGHT(REGEXP_REPLACE(COALESCE(u.phone, ''), '[^0-9]', ''), 10),
       c.client_type = IF(c.company_name IS NULL OR c.company_name = '', 'individual', 'business'),
       c.company_norm = LOWER(TRIM(c.company_name));

ALTER TABLE clients
    MODIFY contact_name VARCHAR(120) NOT NULL,
    MODIFY email VARCHAR(190) NOT NULL,
    ADD KEY idx_clients_email (email),
    ADD KEY idx_clients_phone (phone_digits),
    ADD KEY idx_clients_company (company_norm),
    ADD KEY idx_clients_status (status),
    ADD CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_clients_merged_into FOREIGN KEY (merged_into_client_id) REFERENCES clients (id) ON DELETE RESTRICT;

-- Internal notes about a client. Never shown to the client (visibility is by permission `clients.notes_view`).
CREATE TABLE client_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_client_notes_client (client_id, id),
    CONSTRAINT fk_client_notes_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_notes_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    source ENUM('website_requirements','contact_form','appointment_request','manual','referral_partner') NOT NULL,
    contact_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(32) NULL,
    phone_digits VARCHAR(15) NULL,
    company_name VARCHAR(160) NULL,
    company_norm VARCHAR(160) NULL,
    service VARCHAR(40) NOT NULL,
    description TEXT NOT NULL,
    budget_range VARCHAR(40) NULL,
    expected_deadline DATE NULL,
    preferred_slot VARCHAR(120) NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    stage_id TINYINT UNSIGNED NOT NULL,
    stage_changed_at DATETIME NOT NULL,
    owner_id BIGINT UNSIGNED NULL,
    referral_partner_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    converted_project_id BIGINT UNSIGNED NULL,
    converted_at DATETIME NULL,
    lost_reason VARCHAR(255) NULL,
    won_at DATETIME NULL,
    lost_at DATETIME NULL,
    next_followup_date DATE NULL,
    possible_duplicate_of BIGINT UNSIGNED NULL,
    duplicate_status ENUM('none','suspected','dismissed','confirmed') NOT NULL DEFAULT 'none',
    consent_at DATETIME NULL,
    submitted_ip_hash CHAR(64) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enquiries_public_id (public_id),
    UNIQUE KEY uq_enquiries_reference (reference),
    KEY idx_enquiries_stage (stage_id, archived_at),
    KEY idx_enquiries_owner (owner_id),
    KEY idx_enquiries_partner (referral_partner_id),
    KEY idx_enquiries_email (email),
    KEY idx_enquiries_phone (phone_digits),
    KEY idx_enquiries_company (company_norm),
    KEY idx_enquiries_followup (next_followup_date),
    KEY idx_enquiries_created (created_at),
    CONSTRAINT fk_enquiries_stage FOREIGN KEY (stage_id) REFERENCES crm_stages (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enquiries_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_enquiries_partner FOREIGN KEY (referral_partner_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enquiries_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_enquiries_project FOREIGN KEY (converted_project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_enquiries_duplicate FOREIGN KEY (possible_duplicate_of) REFERENCES enquiries (id) ON DELETE SET NULL,
    CONSTRAINT fk_enquiries_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity timeline AND contact history AND internal notes (type = created|stage_changed|note|contact|assigned|
-- followup|edited|duplicate|converted|attachment|external).
CREATE TABLE enquiry_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    type VARCHAR(30) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    body TEXT NULL,
    details_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_enquiry_activity (enquiry_id, id),
    CONSTRAINT fk_enq_act_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enq_act_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiry_followups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    note VARCHAR(255) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    completed_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_followups_open (enquiry_id, completed_at, due_date),
    KEY idx_followups_due (completed_at, due_date),
    CONSTRAINT fk_followups_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_followups_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_followups_completed_by FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files live OUTSIDE the web root (storage/uploads) under random names; this table holds the metadata.
-- parent_type/parent_id are polymorphic, so authorization is done by the parent's policy at download time.
CREATE TABLE attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    parent_type ENUM('enquiry','task') NOT NULL,
    parent_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    stored_path VARCHAR(80) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_public_id (public_id),
    KEY idx_attachments_parent (parent_type, parent_id, deleted_at),
    CONSTRAINT fk_attachments_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
