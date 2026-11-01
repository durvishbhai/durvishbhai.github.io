-- Compliance dashboard (Owner), asset and licence register, and permission records for portfolio and client logos.

ALTER TABLE site_portfolio
    ADD COLUMN permission_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN permission_note VARCHAR(255) NULL;

ALTER TABLE site_clients
    ADD COLUMN permission_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN permission_note VARCHAR(255) NULL;

CREATE TABLE compliance_items (
    item_key VARCHAR(60) NOT NULL PRIMARY KEY,
    status ENUM('pending','complete','needs_attention','legal_review','not_applicable') NOT NULL DEFAULT 'pending',
    responsible VARCHAR(120) NULL,
    next_action VARCHAR(255) NULL,
    notes TEXT NULL,
    last_reviewed_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_compliance_items_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_register (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asset_key VARCHAR(190) NULL,
    name VARCHAR(190) NOT NULL,
    kind ENUM('image','icon','font','script','stylesheet','other') NOT NULL DEFAULT 'other',
    location VARCHAR(255) NULL,
    source VARCHAR(255) NULL,
    licence VARCHAR(190) NULL,
    evidence VARCHAR(255) NULL,
    status ENUM('unverified','confirmed','replace','not_needed') NOT NULL DEFAULT 'unverified',
    notes TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_asset_key (asset_key),
    CONSTRAINT fk_asset_register_user FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
