-- Manual UPI payments: the business's own UPI ID and bank QR; the client pays from their UPI app and submits the transaction ID and a screenshot as proof;
-- the team approves it against the bank. Used only while Razorpay online payments are switched off.
CREATE TABLE manual_upi_settings (
    id TINYINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    upi_id VARCHAR(100) NULL,
    payee_name VARCHAR(120) NULL,
    instructions VARCHAR(600) NULL,
    require_proof TINYINT(1) NOT NULL DEFAULT 1,
    qr_file_id BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_manual_upi_qr FOREIGN KEY (qr_file_id) REFERENCES stored_files (id) ON DELETE SET NULL,
    CONSTRAINT fk_manual_upi_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO manual_upi_settings (id, enabled, require_proof, updated_at) VALUES (1, 0, 1, UTC_TIMESTAMP());

ALTER TABLE client_payments
    ADD COLUMN proof_file_id BIGINT UNSIGNED NULL AFTER notes,
    ADD CONSTRAINT fk_cpay_proof FOREIGN KEY (proof_file_id) REFERENCES stored_files (id) ON DELETE SET NULL;
