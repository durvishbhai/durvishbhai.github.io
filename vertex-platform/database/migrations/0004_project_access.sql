-- 0004 Project access foundation: clients, projects, Admin-made assignments, referral records.
-- Deliberately minimal: only what the authorization rules need. Phase 2 extends `projects`
-- (milestones, tasks, files, ...) without changing the access model below.

CREATE TABLE clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(160) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_public_id (public_id),
    UNIQUE KEY uq_clients_user (user_id),
    CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('draft','active','on_hold','completed','cancelled','archived') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_public_id (public_id),
    KEY idx_projects_client (client_id),
    KEY idx_projects_status (status),
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (project, user). Revoking sets revoked_at (history is also in audit_logs); re-assigning clears it.
CREATE TABLE project_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    project_role VARCHAR(40) NOT NULL DEFAULT 'member',
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_project_user (project_id, user_id),
    KEY idx_assignment_user (user_id, revoked_at),
    CONSTRAINT fk_pa_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pa_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pa_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minimal referral record so ownership isolation can be enforced now. Commission logic is Phase 6.
CREATE TABLE referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    partner_user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    referred_name VARCHAR(160) NOT NULL,
    status ENUM('lead','converted','closed') NOT NULL DEFAULT 'lead',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referrals_public_id (public_id),
    KEY idx_referrals_partner (partner_user_id),
    CONSTRAINT fk_referrals_partner FOREIGN KEY (partner_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_referrals_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
