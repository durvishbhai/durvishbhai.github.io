-- Mobile and browser push notifications (Web Push). One row per device that allowed notifications; the server key pair (VAPID) is created on first use.
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(120) NOT NULL,
    auth_secret VARCHAR(40) NOT NULL,
    user_agent VARCHAR(255) NULL,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_success_at DATETIME NULL,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_vapid (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    public_key VARCHAR(120) NOT NULL,
    private_sealed TEXT NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
