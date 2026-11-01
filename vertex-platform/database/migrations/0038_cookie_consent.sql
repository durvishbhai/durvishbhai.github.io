-- Cookie consent log: one row per choice (append only). The visitor is identified only by a random id kept in their own consent cookie; the IP is stored as a salted hash.
CREATE TABLE cookie_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_id CHAR(32) NOT NULL,
    consent_version INT UNSIGNED NOT NULL,
    action ENUM('accept_all','reject_all','custom','withdraw') NOT NULL,
    preferences TINYINT(1) NOT NULL DEFAULT 0,
    analytics TINYINT(1) NOT NULL DEFAULT 0,
    marketing TINYINT(1) NOT NULL DEFAULT 0,
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(160) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_visitor (visitor_id, id),
    KEY idx_cc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
