-- Phase 8: quotations, invoices (no tax: the business is not GST-registered), the business profile, and the Google Pay settings.
--
-- Invoices never carry a tax column. An invoice states the business's tax statement ("Tax is not applicable.") as it was when the invoice was issued.
-- Payments against an invoice are rows of client_payments (the single source of verified payments, also read by the commission engine): there is no
-- second payments table, so no financial record is duplicated. Invoice numbers are assigned at issue time, are sequential and are never reused.

CREATE TABLE business_profile (
    id TINYINT UNSIGNED NOT NULL,
    trade_name VARCHAR(160) NOT NULL DEFAULT '',
    legal_name VARCHAR(160) NULL,
    address TEXT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    website VARCHAR(190) NULL,
    tax_statement VARCHAR(255) NOT NULL DEFAULT 'Tax is not applicable.',
    payment_instructions TEXT NULL,
    invoice_footer VARCHAR(500) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_bp_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO business_profile (id, updated_at) VALUES (1, UTC_TIMESTAMP());

CREATE TABLE quotations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('draft','sent','accepted','rejected','expired','withdrawn') NOT NULL DEFAULT 'draft',
    valid_until DATE NULL,
    total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_notes TEXT NULL,
    internal_notes TEXT NULL,
    sent_at DATETIME NULL,
    sent_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    decision_channel ENUM('portal','offline') NULL,
    decision_note VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quo_public_id (public_id),
    UNIQUE KEY uq_quo_reference (reference),
    KEY idx_quo_client (client_id, status),
    CONSTRAINT fk_quo_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_quo_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_quo_sent_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_quo_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_quo_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quotation_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quotation_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(300) NOT NULL,
    quantity_x100 INT UNSIGNED NOT NULL,
    unit_minor BIGINT UNSIGNED NOT NULL,
    total_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qi_position (quotation_id, position),
    CONSTRAINT fk_qi_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    quotation_id BIGINT UNSIGNED NULL,
    status ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
    title VARCHAR(200) NOT NULL,
    issue_date DATE NULL,
    due_date DATE NULL,
    subtotal_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_notes TEXT NULL,
    internal_notes TEXT NULL,
    business_snapshot TEXT NULL,
    issued_by BIGINT UNSIGNED NULL,
    issued_at DATETIME NULL,
    voided_by BIGINT UNSIGNED NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inv_public_id (public_id),
    UNIQUE KEY uq_inv_reference (reference),
    KEY idx_inv_client (client_id, status),
    KEY idx_inv_status (status, due_date),
    CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_issued_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_voided_by FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_inv_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(300) NOT NULL,
    quantity_x100 INT UNSIGNED NOT NULL,
    unit_minor BIGINT UNSIGNED NOT NULL,
    total_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ii_position (invoice_id, position),
    CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments may now belong to an invoice instead of (or as well as) a project, and may be submitted by the client for staff to verify against the bank.
ALTER TABLE client_payments MODIFY project_id BIGINT UNSIGNED NULL;
ALTER TABLE client_payments ADD COLUMN invoice_id BIGINT UNSIGNED NULL AFTER client_id;
ALTER TABLE client_payments ADD COLUMN source ENUM('staff','client_claim','gateway') NOT NULL DEFAULT 'staff' AFTER method;
ALTER TABLE client_payments MODIFY status ENUM('pending_verification','verified','reversed','rejected') NOT NULL DEFAULT 'pending_verification';
ALTER TABLE client_payments ADD KEY idx_cp_invoice (invoice_id, status);
ALTER TABLE client_payments ADD CONSTRAINT fk_cpay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT;

-- Payment gateway settings: one row per integration. Non-secret values in config_json; secrets (API secret, webhook secret) sealed with SecretBox
-- (AES-256-GCM, key derived from APP_KEY) in secrets_enc and never shown again. A row being "enabled" does not mean payments are being processed:
-- the admin screen states what each integration can actually do.
CREATE TABLE payment_gateway_config (
    provider VARCHAR(30) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json TEXT NULL,
    secrets_enc TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (provider),
    CONSTRAINT fk_pgc_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
