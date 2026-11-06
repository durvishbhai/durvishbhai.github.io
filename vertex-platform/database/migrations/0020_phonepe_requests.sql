-- PhonePe payment requests created by staff: the exact amount, the QR payload shown, how it was made and when it stops being payable.
ALTER TABLE gateway_orders
    MODIFY status ENUM('created','pending','completed','failed','expired','cancelled') NOT NULL DEFAULT 'created',
    ADD COLUMN flow VARCHAR(20) NOT NULL DEFAULT 'checkout_link' AFTER provider,
    ADD COLUMN qr_payload VARCHAR(2000) NULL AFTER amount_minor,
    ADD COLUMN note VARCHAR(200) NULL AFTER qr_payload,
    ADD COLUMN expires_at DATETIME NULL AFTER note,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
    ADD CONSTRAINT fk_gateway_orders_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL;
