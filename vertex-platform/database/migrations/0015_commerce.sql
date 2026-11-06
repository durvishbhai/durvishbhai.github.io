-- 0015 Phase 10: campaigns, coupons, coupon redemptions, credit notes.
-- Money is integer paise. A coupon is applied to a DRAFT invoice; the redemption is recorded when the invoice is issued and released if it is voided.
CREATE TABLE campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    status ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_campaigns_public (public_id),
    UNIQUE KEY uq_campaigns_name (name),
    CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    code VARCHAR(30) NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    discount_type ENUM('percent','fixed') NOT NULL,
    percent_bps INT UNSIGNED NULL,
    amount_minor BIGINT UNSIGNED NULL,
    max_discount_minor BIGINT UNSIGNED NULL,
    min_spend_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    eligible_services VARCHAR(255) NULL,
    valid_from DATE NULL,
    valid_until DATE NULL,
    max_uses INT UNSIGNED NULL,
    max_uses_per_client INT UNSIGNED NULL,
    status ENUM('active','paused') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_coupons_public (public_id),
    UNIQUE KEY uq_coupons_code (code),
    KEY idx_coupons_campaign (campaign_id),
    CONSTRAINT fk_coupons_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_coupons_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices
    ADD COLUMN coupon_id BIGINT UNSIGNED NULL AFTER discount_minor,
    ADD COLUMN coupon_code VARCHAR(30) NULL AFTER coupon_id,
    ADD COLUMN coupon_discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER coupon_code,
    ADD COLUMN credited_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_minor;

ALTER TABLE invoices ADD CONSTRAINT fk_inv_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE SET NULL;

CREATE TABLE coupon_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    discount_minor BIGINT UNSIGNED NOT NULL,
    redeemed_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(120) NULL,
    UNIQUE KEY uq_redemption_invoice (invoice_id),
    KEY idx_redemption_coupon (coupon_id, released_at),
    KEY idx_redemption_client (coupon_id, client_id),
    CONSTRAINT fk_redemption_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id),
    CONSTRAINT fk_redemption_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_redemption_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A credit note reduces what is still owed on an ISSUED invoice (a correction without voiding it). It can never exceed the unpaid balance;
-- money already received is returned through a refund, not a credit note.
CREATE TABLE credit_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('issued','void') NOT NULL DEFAULT 'issued',
    business_snapshot TEXT NULL,
    issued_by BIGINT UNSIGNED NULL,
    issued_at DATETIME NOT NULL,
    voided_by BIGINT UNSIGNED NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_cn_public (public_id),
    UNIQUE KEY uq_cn_reference (reference),
    KEY idx_cn_invoice (invoice_id, status),
    CONSTRAINT fk_cn_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_cn_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_cn_issued_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cn_voided_by FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
