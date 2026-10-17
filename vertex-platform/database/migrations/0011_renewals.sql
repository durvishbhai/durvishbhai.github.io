-- Phase 7: domain / hosting / SSL renewals.
--
-- A renewal_service is the thing the client owns (a domain, a hosting plan, an SSL certificate). Each renewal attempt is a renewal_cycle with its own
-- approved price, quotation/invoice references, payments and the provider's confirmation, so a completed cycle is never rewritten by the next one.
-- Payment and provider renewal are SEPARATE facts: a service becomes Renewed only when a person with the provider-confirmation permission records the
-- provider's confirmation and the new expiry date. No provider password or credential has a column anywhere in this schema.

CREATE TABLE renewal_services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    service_type ENUM('domain','hosting','ssl','other') NOT NULL,
    identifier VARCHAR(190) NOT NULL,
    provider VARCHAR(120) NOT NULL,
    activated_on DATE NOT NULL,
    expires_on DATE NOT NULL,
    period_months SMALLINT UNSIGNED NOT NULL,
    status ENUM('active','renewal_approaching','awaiting_admin_review','approved_for_renewal','invoice_issued','awaiting_payment','payment_received','renewal_in_progress','renewed','expired','cancelled','on_hold') NOT NULL DEFAULT 'active',
    held_from VARCHAR(30) NULL,
    status_reason VARCHAR(255) NULL,
    price_override_minor BIGINT UNSIGNED NULL,
    price_override_reason VARCHAR(255) NULL,
    internal_notes TEXT NULL,
    client_notes VARCHAR(1000) NULL,
    client_reminders TINYINT(1) NOT NULL DEFAULT 1,
    cycle_no INT UNSIGNED NOT NULL DEFAULT 1,
    last_renewed_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rnw_public_id (public_id),
    UNIQUE KEY uq_rnw_reference (reference),
    UNIQUE KEY uq_rnw_identity (client_id, service_type, identifier),
    KEY idx_rnw_status (status, expires_on),
    KEY idx_rnw_expiry (expires_on),
    KEY idx_rnw_client (client_id),
    CONSTRAINT fk_rnw_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rnw_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_rnw_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_cycles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    cycle_no INT UNSIGNED NOT NULL,
    from_expiry DATE NOT NULL,
    period_months SMALLINT UNSIGNED NULL,
    price_source ENUM('price_list','override','waived') NULL,
    net_minor BIGINT UNSIGNED NULL,
    tax_minor BIGINT UNSIGNED NULL,
    total_minor BIGINT UNSIGNED NULL,
    pricing_json TEXT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    quotation_ref VARCHAR(80) NULL,
    invoice_ref VARCHAR(80) NULL,
    invoice_amount_minor BIGINT UNSIGNED NULL,
    invoice_recorded_by BIGINT UNSIGNED NULL,
    invoice_at DATETIME NULL,
    invoice_sent_at DATETIME NULL,
    paid_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payment_complete_at DATETIME NULL,
    waiver_reason VARCHAR(255) NULL,
    in_progress_at DATETIME NULL,
    provider_confirmation_ref VARCHAR(190) NULL,
    provider_confirmed_on DATE NULL,
    provider_confirmed_by BIGINT UNSIGNED NULL,
    provider_confirmed_at DATETIME NULL,
    new_expiry DATE NULL,
    closed_at DATETIME NULL,
    closed_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rnc_cycle (service_id, cycle_no),
    KEY idx_rnc_open (service_id, closed_at),
    CONSTRAINT fk_rnc_service FOREIGN KEY (service_id) REFERENCES renewal_services (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rnc_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rnc_invoice_by FOREIGN KEY (invoice_recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rnc_confirmed_by FOREIGN KEY (provider_confirmed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cycle_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    method VARCHAR(40) NOT NULL,
    reference VARCHAR(120) NOT NULL,
    payment_key CHAR(64) NOT NULL,
    received_on DATE NOT NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rnp_key (payment_key),
    KEY idx_rnp_cycle (cycle_id),
    CONSTRAINT fk_rnp_cycle FOREIGN KEY (cycle_id) REFERENCES renewal_cycles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rnp_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    cycle_no INT UNSIGNED NULL,
    event VARCHAR(40) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    note VARCHAR(500) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_rnh_service (service_id, id),
    CONSTRAINT fk_rnh_service FOREIGN KEY (service_id) REFERENCES renewal_services (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rnh_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per reminder that was due: the UNIQUE key is what stops a second cron run (or two overlapping runs) from reminding twice.
CREATE TABLE renewal_reminders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    expires_on DATE NOT NULL,
    kind VARCHAR(30) NOT NULL,
    offset_days SMALLINT NOT NULL,
    audience ENUM('staff','client') NOT NULL,
    channel ENUM('in_app','email') NOT NULL,
    result ENUM('created','sent','failed','not_configured') NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    recipients SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rnr_once (service_id, expires_on, kind, offset_days, audience, channel),
    CONSTRAINT fk_rnr_service FOREIGN KEY (service_id) REFERENCES renewal_services (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The renewal price list. Append-only: a new row with a later effective date supersedes an older one; cycles snapshot the price they were approved at.
CREATE TABLE renewal_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_type ENUM('domain','hosting','ssl','other') NOT NULL,
    period_months SMALLINT UNSIGNED NOT NULL,
    base_minor BIGINT UNSIGNED NOT NULL,
    markup_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tax_bps INT UNSIGNED NOT NULL DEFAULT 0,
    effective_from DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rnpr_effective (service_type, period_months, effective_from),
    CONSTRAINT fk_rnpr_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every run of the scheduled task. The admin dashboard reads this so nobody is told reminders are running when the cron job is not configured.
CREATE TABLE scheduler_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job VARCHAR(40) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    summary VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_sch_job (job, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
