-- Our Clients page: logos and names of clients (with their country) that the business is allowed to show. Starts empty; edited in Administration -> Website -> Clients.
CREATE TABLE site_clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    country VARCHAR(80) NULL,
    logo_media_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    website_url VARCHAR(300) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_clients_public (public_id),
    KEY idx_site_clients_list (status, position),
    CONSTRAINT fk_site_clients_media FOREIGN KEY (logo_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_clients_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
