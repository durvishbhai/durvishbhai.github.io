-- 0010 Referral Partner portal and commission management.
-- Additive: no existing table is altered and no existing row is changed. Every foreign key into projects, clients, users and files is
-- RESTRICT or SET NULL; money is integer paise; history tables are append-only (the application has no UPDATE/DELETE path for them).
--
-- INTEGRATION POINT: this repository has no quotation, invoice or payment module. `client_payments` is the verified-payment record the
-- commission engine reads. Today staff record and verify payments by hand (two different people); a future gateway/invoice module writes
-- the same rows. `external_key` (UNIQUE) makes a retried webhook or a re-entered receipt a no-op instead of a second payment.

-- Versioned Referral Partner Terms. Nothing is shipped: the business records its own wording; applications are closed until one exists.
CREATE TABLE partner_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    terms TEXT NOT NULL,
    current_flag TINYINT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_partner_terms_version (version),
    UNIQUE KEY uq_partner_terms_current (current_flag),
    CONSTRAINT fk_partner_terms_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The approved partner. One per login. The payout details are stored ENCRYPTED (AES-256-GCM, key derived from APP_KEY); only a masked hint is readable.
CREATE TABLE referral_partners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    suspended_reason VARCHAR(255) NULL,
    terms_version INT UNSIGNED NULL,
    payout_method VARCHAR(20) NULL,
    payout_details_enc TEXT NULL,
    payout_details_hint VARCHAR(60) NULL,
    payout_details_updated_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rp_public_id (public_id),
    UNIQUE KEY uq_rp_reference (reference),
    UNIQUE KEY uq_rp_user (user_id),
    CONSTRAINT fk_rpart_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rpart_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public applications. `active_key` is the lower-cased email while the application is Received / Under Review / Approved and NULL once
-- Rejected; its UNIQUE index makes a duplicate open application impossible even under concurrent submissions.
CREATE TABLE partner_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    organization VARCHAR(160) NULL,
    professional_info TEXT NOT NULL,
    referral_source TEXT NOT NULL,
    doc_file_id BIGINT UNSIGNED NULL,
    terms_version INT UNSIGNED NOT NULL,
    terms_accepted_at DATETIME NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    status ENUM('received','under_review','approved','rejected') NOT NULL DEFAULT 'received',
    active_key VARCHAR(190) NULL,
    decision_reason VARCHAR(500) NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    partner_id BIGINT UNSIGNED NULL,
    submitted_ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pa_public_id (public_id),
    UNIQUE KEY uq_pa_reference (reference),
    UNIQUE KEY uq_pa_active_email (active_key),
    KEY idx_pa_status (status, created_at),
    KEY idx_pa_user (user_id),
    CONSTRAINT fk_papp_doc FOREIGN KEY (doc_file_id) REFERENCES stored_files (id) ON DELETE RESTRICT,
    CONSTRAINT fk_papp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_papp_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_papp_partner FOREIGN KEY (partner_id) REFERENCES referral_partners (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE referral_partners
    ADD KEY idx_rp_application (application_id),
    ADD CONSTRAINT fk_rpart_application FOREIGN KEY (application_id) REFERENCES partner_applications (id) ON DELETE SET NULL;

-- A referral submitted by an approved partner. It never merges into a client record by itself; links to CRM records are explicit admin actions.
CREATE TABLE partner_referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    partner_id BIGINT UNSIGNED NOT NULL,
    prospect_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NULL,
    email_norm VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    phone_digits VARCHAR(15) NULL,
    business_name VARCHAR(160) NULL,
    company_norm VARCHAR(160) NULL,
    service VARCHAR(40) NOT NULL,
    description TEXT NOT NULL,
    estimated_budget_minor BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    status ENUM('received','under_review','approved','contacted','qualified','quote_sent','converted','not_converted','rejected') NOT NULL DEFAULT 'received',
    status_reason VARCHAR(500) NULL,
    attribution ENUM('pending','credited','conflict','duplicate','denied') NOT NULL DEFAULT 'pending',
    attribution_reason VARCHAR(500) NULL,
    attribution_by BIGINT UNSIGNED NULL,
    attribution_at DATETIME NULL,
    attribution_locked TINYINT(1) NOT NULL DEFAULT 0,
    duplicate_status ENUM('none','suspected','confirmed','dismissed') NOT NULL DEFAULT 'none',
    duplicate_of_id BIGINT UNSIGNED NULL,
    enquiry_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    credit_project_id BIGINT UNSIGNED NULL,
    converted_at DATETIME NULL,
    submit_token CHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_public_id (public_id),
    UNIQUE KEY uq_pr_reference (reference),
    UNIQUE KEY uq_pr_token (partner_id, submit_token),
    UNIQUE KEY uq_pr_credit_project (credit_project_id),
    KEY idx_pr_partner (partner_id, status),
    KEY idx_pr_email (email_norm),
    KEY idx_pr_phone (phone_digits),
    KEY idx_pr_company (company_norm),
    KEY idx_pr_status (status, created_at),
    KEY idx_pr_attr (attribution, duplicate_status),
    CONSTRAINT fk_pref_partner FOREIGN KEY (partner_id) REFERENCES referral_partners (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pref_attr_by FOREIGN KEY (attribution_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pref_dup FOREIGN KEY (duplicate_of_id) REFERENCES partner_referrals (id) ON DELETE SET NULL,
    CONSTRAINT fk_pref_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE SET NULL,
    CONSTRAINT fk_pref_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_pref_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_pref_credit_project FOREIGN KEY (credit_project_id) REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history. visibility='partner' rows (a status and the reason written for the partner) are shown to the partner; 'internal' rows never are.
CREATE TABLE referral_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(30) NOT NULL,
    from_value VARCHAR(40) NULL,
    to_value VARCHAR(40) NULL,
    note VARCHAR(500) NULL,
    visibility ENUM('partner','internal') NOT NULL DEFAULT 'internal',
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rh_referral (referral_id, id),
    CONSTRAINT fk_rhist_referral FOREIGN KEY (referral_id) REFERENCES partner_referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rhist_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff-only notes on applications, referrals, commission entries and payout requests.
CREATE TABLE partner_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type ENUM('application','referral','commission','payout') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pn_subject (subject_type, subject_id, id),
    CONSTRAINT fk_pnote_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every referral status email: previewed and confirmed by an admin. `sent_key` is set only on a successful send so a retry cannot send twice.
CREATE TABLE referral_email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    history_id BIGINT UNSIGNED NOT NULL,
    to_email VARCHAR(190) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body_hash CHAR(64) NOT NULL,
    result ENUM('sent','failed','not_configured') NOT NULL,
    sent_key VARCHAR(80) NULL,
    sent_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rel_sent (sent_key),
    KEY idx_rel_referral (referral_id),
    CONSTRAINT fk_remail_referral FOREIGN KEY (referral_id) REFERENCES partner_referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_remail_history FOREIGN KEY (history_id) REFERENCES referral_history (id) ON DELETE RESTRICT,
    CONSTRAINT fk_remail_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versioned commission rules (rates, base, policies). A calculation stores the version and a snapshot, so a later rule change never rewrites an earlier entry.
CREATE TABLE commission_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version INT UNSIGNED NOT NULL,
    rules_json TEXT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    current_flag TINYINT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cr_rules_version (version),
    UNIQUE KEY uq_cr_rules_current (current_flag),
    CONSTRAINT fk_crules_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verified client payments (the integration point described at the top). Recorded by one person, verified by another.
CREATE TABLE client_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    invoice_ref VARCHAR(120) NULL,
    payment_role ENUM('advance','balance','other') NOT NULL,
    gross_minor BIGINT UNSIGNED NOT NULL,
    tax_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refunded_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    method VARCHAR(40) NOT NULL,
    external_ref VARCHAR(120) NOT NULL,
    external_key CHAR(64) NOT NULL,
    received_on DATE NOT NULL,
    status ENUM('pending_verification','verified','reversed') NOT NULL DEFAULT 'pending_verification',
    notes VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cp_public_id (public_id),
    UNIQUE KEY uq_cp_reference (reference),
    UNIQUE KEY uq_cp_external (external_key),
    KEY idx_cp_project (project_id, status),
    CONSTRAINT fk_cpay_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpay_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpay_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cpay_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    event ENUM('recorded','verified','refund','chargeback','reversed') NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cpe_payment (payment_id, id),
    CONSTRAINT fk_cpayev_payment FOREIGN KEY (payment_id) REFERENCES client_payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpayev_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The commission ledger. `amount_minor` is the original calculation (positive earnings; negative adjustment lines). It is never edited after
-- the entry leaves Pending Eligibility / Eligible for Review; corrections are new adjustment lines (parent_entry_id) and every change is in commission_history.
CREATE TABLE commission_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    partner_id BIGINT UNSIGNED NOT NULL,
    referral_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    milestone ENUM('advance','full','adjustment') NOT NULL,
    seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    parent_entry_id BIGINT UNSIGNED NULL,
    status ENUM('pending_eligibility','eligible_for_review','approved','payable','payout_requested','paid','on_hold','adjusted','reversed') NOT NULL DEFAULT 'pending_eligibility',
    held_from VARCHAR(30) NULL,
    rate_bps INT UNSIGNED NOT NULL DEFAULT 0,
    base_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    amount_minor BIGINT NOT NULL DEFAULT 0,
    rules_version INT UNSIGNED NULL,
    formula_json LONGTEXT NULL,
    reason VARCHAR(500) NULL,
    partner_note VARCHAR(255) NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    payable_after DATE NULL,
    calculated_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ce_public_id (public_id),
    UNIQUE KEY uq_ce_reference (reference),
    UNIQUE KEY uq_ce_milestone (referral_id, milestone, seq),
    KEY idx_ce_partner (partner_id, status),
    KEY idx_ce_project (project_id),
    KEY idx_ce_status (status, created_at),
    CONSTRAINT fk_centry_partner FOREIGN KEY (partner_id) REFERENCES referral_partners (id) ON DELETE RESTRICT,
    CONSTRAINT fk_centry_referral FOREIGN KEY (referral_id) REFERENCES partner_referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_centry_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_centry_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_centry_parent FOREIGN KEY (parent_entry_id) REFERENCES commission_entries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_centry_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_centry_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which verified payments make up an entry's base, and how much of each counted. A payment is never counted twice within one entry.
CREATE TABLE commission_entry_payments (
    entry_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    eligible_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (entry_id, payment_id),
    KEY idx_cep_payment (payment_id),
    CONSTRAINT fk_centrypay_entry FOREIGN KEY (entry_id) REFERENCES commission_entries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_centrypay_payment FOREIGN KEY (payment_id) REFERENCES client_payments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(30) NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    reason VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ch_entry (entry_id, id),
    CONSTRAINT fk_chist_entry FOREIGN KEY (entry_id) REFERENCES commission_entries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_chist_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payout requests. Approval and payment are separate steps with separate people recorded. `details_enc` is a snapshot of the partner's
-- encrypted payout details at request time.
CREATE TABLE payout_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    partner_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    available_minor BIGINT NOT NULL,
    status ENUM('requested','under_review','approved','rejected','processing','paid','cancelled') NOT NULL DEFAULT 'requested',
    partner_notes VARCHAR(500) NULL,
    reason VARCHAR(500) NULL,
    details_enc TEXT NULL,
    details_hint VARCHAR(60) NULL,
    submit_token CHAR(32) NULL,
    requested_at DATETIME NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    processing_by BIGINT UNSIGNED NULL,
    processing_at DATETIME NULL,
    paid_by BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    paid_amount_minor BIGINT UNSIGNED NULL,
    payment_method VARCHAR(40) NULL,
    payment_reference VARCHAR(120) NULL,
    payment_date DATE NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_public_id (public_id),
    UNIQUE KEY uq_po_reference (reference),
    UNIQUE KEY uq_po_token (partner_id, submit_token),
    UNIQUE KEY uq_po_payment_ref (payment_reference),
    KEY idx_po_partner (partner_id, status),
    KEY idx_po_status (status, requested_at),
    CONSTRAINT fk_payout_partner FOREIGN KEY (partner_id) REFERENCES referral_partners (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payout_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payout_processing_by FOREIGN KEY (processing_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payout_paid_by FOREIGN KEY (paid_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A request reserves part of specific ledger entries (oldest first). released_at is set when the request is rejected or cancelled.
CREATE TABLE payout_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_id BIGINT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT NOT NULL,
    released_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_pal_entry (entry_id, released_at),
    KEY idx_pal_payout (payout_id),
    CONSTRAINT fk_palloc_payout FOREIGN KEY (payout_id) REFERENCES payout_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_palloc_entry FOREIGN KEY (entry_id) REFERENCES commission_entries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(500) NULL,
    visibility ENUM('partner','internal') NOT NULL DEFAULT 'internal',
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ph_payout (payout_id, id),
    CONSTRAINT fk_phist_payout FOREIGN KEY (payout_id) REFERENCES payout_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_phist_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
