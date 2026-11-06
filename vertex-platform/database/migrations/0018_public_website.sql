-- 0018 Public website: editable page sections, services, team, partner directory, portfolio, careers (openings + applications), FAQs, policies with versions,
-- structured extras and attachments for the public enquiry forms, and extra menu locations for the footer columns.

ALTER TABLE cms_menus MODIFY location VARCHAR(30) NOT NULL;

CREATE TABLE site_sections (
    page VARCHAR(30) NOT NULL,
    section_key VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    data_json MEDIUMTEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (page, section_key),
    CONSTRAINT fk_site_sections_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(30) NOT NULL DEFAULT 'spark',
    summary VARCHAR(300) NOT NULL,
    hero_heading VARCHAR(200) NULL,
    intro TEXT NULL,
    description TEXT NULL,
    deliverables TEXT NULL,
    use_cases TEXT NULL,
    faqs TEXT NULL,
    related TEXT NULL,
    cover_media_id BIGINT UNSIGNED NULL,
    gallery_json TEXT NULL,
    show_price TINYINT(1) NOT NULL DEFAULT 0,
    starting_price_minor BIGINT UNSIGNED NULL,
    seo_title VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_services_public (public_id),
    UNIQUE KEY uq_site_services_slug (slug),
    KEY idx_site_services_list (status, position),
    CONSTRAINT fk_site_services_media FOREIGN KEY (cover_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_services_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_team (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    designation VARCHAR(120) NOT NULL,
    department ENUM('leadership','creative','development','operations') NOT NULL DEFAULT 'operations',
    photo_media_id BIGINT UNSIGNED NULL,
    bio TEXT NULL,
    skills VARCHAR(400) NULL,
    linkedin_url VARCHAR(300) NULL,
    x_url VARCHAR(300) NULL,
    website_url VARCHAR(300) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_team_public (public_id),
    KEY idx_site_team_list (status, department, position),
    CONSTRAINT fk_site_team_media FOREIGN KEY (photo_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_team_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_partners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    logo_media_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    category VARCHAR(60) NULL,
    website_url VARCHAR(300) NULL,
    contact_url VARCHAR(300) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_partners_public (public_id),
    KEY idx_site_partners_list (status, position),
    CONSTRAINT fk_site_partners_media FOREIGN KEY (logo_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_partners_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_portfolio (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    category VARCHAR(60) NULL,
    summary VARCHAR(400) NOT NULL,
    case_study TEXT NULL,
    services VARCHAR(400) NULL,
    cover_media_id BIGINT UNSIGNED NULL,
    gallery_json TEXT NULL,
    seo_title VARCHAR(160) NULL,
    seo_description VARCHAR(320) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_portfolio_public (public_id),
    UNIQUE KEY uq_site_portfolio_slug (slug),
    KEY idx_site_portfolio_list (status, position),
    CONSTRAINT fk_site_portfolio_media FOREIGN KEY (cover_media_id) REFERENCES cms_media (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_portfolio_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    department VARCHAR(80) NULL,
    engagement_type VARCHAR(40) NULL,
    skills TEXT NULL,
    responsibilities TEXT NULL,
    deliverables TEXT NULL,
    deadline DATE NULL,
    compensation VARCHAR(200) NULL,
    instructions TEXT NULL,
    status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_jobs_public (public_id),
    UNIQUE KEY uq_site_jobs_slug (slug),
    KEY idx_site_jobs_list (status, position),
    CONSTRAINT fk_site_jobs_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_job_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    role_applied VARCHAR(160) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(32) NULL,
    skills_experience TEXT NOT NULL,
    portfolio_url VARCHAR(300) NULL,
    intro TEXT NOT NULL,
    file_id BIGINT UNSIGNED NULL,
    status ENUM('new','reviewing','shortlisted','not_selected','withdrawn','archived') NOT NULL DEFAULT 'new',
    internal_notes TEXT NULL,
    consent_at DATETIME NOT NULL,
    ip_hash CHAR(64) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_job_apps_public (public_id),
    UNIQUE KEY uq_site_job_apps_ref (reference),
    KEY idx_site_job_apps_status (status, created_at),
    KEY idx_site_job_apps_email (email),
    CONSTRAINT fk_site_job_apps_job FOREIGN KEY (job_id) REFERENCES site_jobs (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_job_apps_file FOREIGN KEY (file_id) REFERENCES stored_files (id) ON DELETE SET NULL,
    CONSTRAINT fk_site_job_apps_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_faqs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'General',
    question VARCHAR(300) NOT NULL,
    answer TEXT NOT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_faqs_public (public_id),
    KEY idx_site_faqs_list (status, category, position),
    CONSTRAINT fk_site_faqs_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(32) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    effective_date DATE NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_policies_public (public_id),
    UNIQUE KEY uq_site_policies_slug (slug),
    CONSTRAINT fk_site_policies_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_policy_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    policy_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    effective_date DATE NULL,
    note VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_policy_ver (policy_id, version_no),
    CONSTRAINT fk_site_policy_ver_policy FOREIGN KEY (policy_id) REFERENCES site_policies (id) ON DELETE CASCADE,
    CONSTRAINT fk_site_policy_ver_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiry_details (
    enquiry_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    details_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_enquiry_details_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enquiry_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_enquiry_files_enquiry (enquiry_id),
    CONSTRAINT fk_enquiry_files_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE CASCADE,
    CONSTRAINT fk_enquiry_files_file FOREIGN KEY (file_id) REFERENCES stored_files (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
