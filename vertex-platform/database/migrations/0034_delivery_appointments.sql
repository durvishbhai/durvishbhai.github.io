-- Project delivery appointments: a client books a delivery date once the project's preparation period is over.
-- Capacity is enforced in the service under a row lock on delivery_date_capacity (one row per date), never only in the browser.

-- One row per configured project. No row = delivery booking is not set up for the project yet (clients cannot book).
CREATE TABLE project_delivery_settings (
    project_id BIGINT UNSIGNED NOT NULL,
    preparation_days SMALLINT UNSIGNED NOT NULL,
    prep_start_date DATE NOT NULL,
    eligibility_date DATE NOT NULL,           -- prep_start_date + preparation_days, recalculated on every save
    earliest_date DATE NULL,                  -- optional later "earliest selectable delivery date" chosen by the team
    latest_date DATE NULL,                    -- optional last selectable date
    booking_enabled TINYINT(1) NOT NULL DEFAULT 1,
    allow_multiple TINYINT(1) NOT NULL DEFAULT 0, -- Super Admin / Owner may allow a second active appointment for the project
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_pds_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_pds_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per date that has ever been touched. It is the mutex that serialises bookings of that date (SELECT ... FOR UPDATE).
CREATE TABLE delivery_date_capacity (
    appointment_date DATE NOT NULL,
    max_capacity SMALLINT UNSIGNED NULL,      -- NULL = use the default daily capacity
    blocked TINYINT(1) NOT NULL DEFAULT 0,
    block_reason VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (appointment_date),
    CONSTRAINT fk_ddc_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Capacity the team keeps back for internal planning. It lowers what clients can book; it is not a client booking.
CREATE TABLE delivery_capacity_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_date DATE NOT NULL,
    reserved_slots SMALLINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    released_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_dcr_date (appointment_date, released_at),
    CONSTRAINT fk_dcr_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dcr_released FOREIGN KEY (released_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_reference VARCHAR(24) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    appointment_date DATE NOT NULL,
    booking_source ENUM('client_dashboard','public_link','manual','admin_reserved') NOT NULL,
    status ENUM('confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed',
    client_notes VARCHAR(500) NULL,
    internal_notes TEXT NULL,
    reschedule_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    change_request_kind ENUM('reschedule','cancel') NULL,   -- a client request the team still has to answer
    change_request_note VARCHAR(500) NULL,
    change_requested_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,                          -- the staff member for manual bookings; NULL for the client's own
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    cancelled_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_da_reference (booking_reference),
    KEY idx_da_date_status (appointment_date, status),
    KEY idx_da_project (project_id, status),
    KEY idx_da_client (client_id),
    CONSTRAINT fk_da_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_da_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_da_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_appointment_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    appointment_date DATE NULL,
    action VARCHAR(40) NOT NULL,
    previous_value VARCHAR(500) NULL,
    new_value VARCHAR(500) NULL,
    performed_by BIGINT UNSIGNED NULL,
    performer_label VARCHAR(120) NULL,   -- "Client", "Public booking link", or the staff member's name at the time
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dal_appointment (appointment_id, id),
    KEY idx_dal_date (appointment_date),
    CONSTRAINT fk_dal_appointment FOREIGN KEY (appointment_id) REFERENCES delivery_appointments (id) ON DELETE SET NULL,
    CONSTRAINT fk_dal_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
    CONSTRAINT fk_dal_user FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Direct booking links (no sign-in). Only the SHA-256 of the token is stored; the token itself is shown once.
CREATE TABLE delivery_booking_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dbt_hash (token_hash),
    KEY idx_dbt_project (project_id),
    CONSTRAINT fk_dbt_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_dbt_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes emailed to the client's registered address for the public flow. Only an HMAC of the code is stored.
CREATE TABLE delivery_otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dotp_project (project_id, used_at),
    CONSTRAINT fk_dotp_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
