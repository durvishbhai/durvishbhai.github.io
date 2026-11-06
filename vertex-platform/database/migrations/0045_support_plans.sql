-- Bug support after the free support period: the client's choice per project, the subscription periods they pay for, coupon scope, and what an invoice is for.
-- Additive. Safe to run twice (IF NOT EXISTS).

ALTER TABLE coupons ADD COLUMN IF NOT EXISTS applies_to ENUM('all','project','subscription') NOT NULL DEFAULT 'all' AFTER eligible_services;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS purpose ENUM('standard','support_subscription') NOT NULL DEFAULT 'standard';

-- How a project pays for bug support once its free period is over (set when the client chooses; a paid subscription sets it to 'subscription').
CREATE TABLE IF NOT EXISTS project_support (
    project_id BIGINT UNSIGNED NOT NULL,
    mode ENUM('subscription','per_bug') NOT NULL,
    chosen_by BIGINT UNSIGNED NULL,
    chosen_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_psupport_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_psupport_user FOREIGN KEY (chosen_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per subscription purchase. It is 'awaiting_payment' until its invoice is paid, then 'active' for [starts_on, ends_on].
CREATE TABLE IF NOT EXISTS support_subscription_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    plan ENUM('monthly','yearly') NOT NULL,
    price_minor BIGINT UNSIGNED NOT NULL,
    status ENUM('awaiting_payment','active','cancelled') NOT NULL DEFAULT 'awaiting_payment',
    starts_on DATE NULL,
    ends_on DATE NULL,
    created_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ssp_invoice (invoice_id),
    KEY idx_ssp_project (project_id, status, ends_on),
    CONSTRAINT fk_ssp_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_ssp_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_ssp_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
