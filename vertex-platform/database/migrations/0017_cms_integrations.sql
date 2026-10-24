-- 0017 Phase 12: website CMS (pages, versions, media, menus, redirects, reusable blocks), settings store, AI request log, PhonePe orders and webhook log.

CREATE TABLE app_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    value_text TEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    file_name VARCHAR(80) NOT NULL,
    mime VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    alt_text VARCHAR(200) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_media_public (public_id),
    UNIQUE KEY uq_cms_media_file (file_name),
    CONSTRAINT fk_cms_media_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
    publish_at DATETIME NULL,
    published_at DATETIME NULL,
    blocks_json MEDIUMTEXT NOT NULL,
    seo_title VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    og_media_id BIGINT UNSIGNED NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    canonical_url VARCHAR(255) NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY uq_cms_pages_public (public_id),
    UNIQUE KEY uq_cms_pages_slug (slug),
    KEY idx_cms_pages_status (status, publish_at),
    CONSTRAINT fk_cms_pages_media FOREIGN KEY (og_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_cms_pages_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_page_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    blocks_json MEDIUMTEXT NOT NULL,
    seo_json TEXT NULL,
    note VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_ver (page_id, version_no),
    CONSTRAINT fk_cms_ver_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_ver_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    block_type VARCHAR(30) NOT NULL,
    data_json TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_blocks_public (public_id),
    UNIQUE KEY uq_cms_blocks_name (name),
    CONSTRAINT fk_cms_blocks_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_menus (
    location ENUM('header','footer') NOT NULL PRIMARY KEY,
    items_json TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_cms_menus_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_redirects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    from_path VARCHAR(190) NOT NULL,
    to_url VARCHAR(500) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_cms_redirect_from (from_path),
    CONSTRAINT fk_cms_redirect_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    feature VARCHAR(40) NOT NULL,
    subject_type VARCHAR(30) NULL,
    subject_id BIGINT UNSIGNED NULL,
    prompt_chars INT UNSIGNED NOT NULL DEFAULT 0,
    response_chars INT UNSIGNED NULL,
    latency_ms INT UNSIGNED NULL,
    status ENUM('started','ok','error') NOT NULL DEFAULT 'started',
    error VARCHAR(250) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_ai_user (user_id, created_at),
    KEY idx_ai_created (created_at),
    CONSTRAINT fk_ai_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    merchant_order_id VARCHAR(64) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    status ENUM('created','pending','completed','failed','expired') NOT NULL DEFAULT 'created',
    provider_order_id VARCHAR(80) NULL,
    provider_txn_id VARCHAR(80) NULL,
    payment_id BIGINT UNSIGNED NULL,
    last_checked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_gateway_orders_public (public_id),
    UNIQUE KEY uq_gateway_orders_merchant (merchant_order_id),
    KEY idx_gateway_orders_status (status, created_at),
    CONSTRAINT fk_gateway_orders_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_gateway_orders_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_gateway_orders_payment FOREIGN KEY (payment_id) REFERENCES client_payments (id) ON DELETE SET NULL,
    CONSTRAINT fk_gateway_orders_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(20) NOT NULL,
    event_key CHAR(64) NOT NULL,
    merchant_order_id VARCHAR(64) NULL,
    event VARCHAR(60) NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    outcome VARCHAR(60) NULL,
    UNIQUE KEY uq_gateway_webhook_key (event_key),
    KEY idx_gateway_webhook_order (merchant_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
