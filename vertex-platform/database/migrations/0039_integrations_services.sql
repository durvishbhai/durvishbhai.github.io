-- The Owner's register of third-party services (what is disclosed on the public page), and how available each service is.
CREATE TABLE compliance_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category ENUM('hosting','payment','email','security','authentication','analytics','marketing','ai','notifications','other') NOT NULL DEFAULT 'other',
    state ENUM('active','planned','disabled') NOT NULL DEFAULT 'planned',   -- active = in use today; planned = possible in future, NOT used; disabled = stopped
    show_public TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 0,                                -- mirrors state = 'active' (kept for fast queries)
    purpose TEXT NULL,
    data_shared TEXT NULL,
    location_note VARCHAR(255) NULL,
    provider_url VARCHAR(255) NULL,
    privacy_url VARCHAR(255) NULL,
    settings_link VARCHAR(120) NULL,                                        -- where the REAL technical settings live (secrets are never stored here)
    cookie_category ENUM('none','necessary','preferences','analytics','marketing') NOT NULL DEFAULT 'none',
    cookie_details TEXT NULL,                                               -- lines "name | purpose | lasts"
    script_url VARCHAR(255) NULL,                                           -- an https script loaded only after consent for its category
    consent_epoch SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    last_reviewed DATE NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ci_slug (slug),
    KEY idx_ci_state (state, show_public),
    CONSTRAINT fk_ci_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE site_services
    ADD COLUMN availability ENUM('active','suspended','coming_soon','discontinued') NOT NULL DEFAULT 'active' AFTER status,
    ADD COLUMN availability_note VARCHAR(300) NULL AFTER availability;
