-- 0006 Project management: richer projects, activity timeline, notes, milestones, tasks, dependencies.
-- Existing projects are preserved. Legacy statuses are mapped: active -> in_progress, archived -> completed + archived_at.

ALTER TABLE projects MODIFY status ENUM('draft','active','on_hold','completed','cancelled','archived',
    'awaiting_client_approval','awaiting_payment','ready_to_start','in_progress','in_review','awaiting_client_feedback') NOT NULL DEFAULT 'draft';

UPDATE projects SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()), status = 'completed' WHERE status = 'archived';

UPDATE projects SET status = 'in_progress' WHERE status = 'active';

ALTER TABLE projects MODIFY status ENUM('draft','awaiting_client_approval','awaiting_payment','ready_to_start','in_progress',
    'on_hold','in_review','awaiting_client_feedback','completed','cancelled') NOT NULL DEFAULT 'draft';

ALTER TABLE projects
    ADD COLUMN code VARCHAR(24) NULL AFTER public_id,
    ADD COLUMN enquiry_id BIGINT UNSIGNED NULL AFTER client_id,
    ADD COLUMN service_category VARCHAR(40) NULL AFTER title,
    ADD COLUMN description TEXT NULL AFTER service_category,
    ADD COLUMN scope TEXT NULL AFTER description,
    ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER status,
    ADD COLUMN start_date DATE NULL AFTER priority,
    ADD COLUMN target_date DATE NULL AFTER start_date,
    ADD COLUMN budget_minor BIGINT UNSIGNED NULL AFTER target_date,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'INR' AFTER budget_minor,
    ADD COLUMN status_changed_at DATETIME NULL AFTER currency,
    ADD COLUMN completed_at DATETIME NULL AFTER status_changed_at,
    ADD COLUMN completed_by BIGINT UNSIGNED NULL AFTER completed_at;

UPDATE projects SET code = CONCAT('VIS-', YEAR(created_at), '-L', LPAD(id, 4, '0')) WHERE code IS NULL;

UPDATE projects SET status_changed_at = updated_at WHERE status_changed_at IS NULL;

ALTER TABLE projects
    MODIFY code VARCHAR(24) NOT NULL,
    ADD UNIQUE KEY uq_projects_code (code),
    ADD KEY idx_projects_target (target_date),
    ADD KEY idx_projects_priority (priority),
    ADD CONSTRAINT fk_projects_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_projects_completed_by FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL;

-- Project timeline (user-facing history). The security audit log remains separate; both are written in one transaction.
CREATE TABLE project_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    subject_type VARCHAR(20) NULL,
    subject_id BIGINT UNSIGNED NULL,
    summary VARCHAR(255) NOT NULL,
    details_json LONGTEXT NULL,
    visibility ENUM('internal','client') NOT NULL DEFAULT 'internal',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project_activity (project_id, id),
    CONSTRAINT fk_proj_act_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_proj_act_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    kind ENUM('internal','client_visible','client_feedback') NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project_notes (project_id, kind, id),
    CONSTRAINT fk_proj_notes_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_proj_notes_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    target_date DATE NULL,
    status ENUM('proposed','planned','in_progress','completed','cancelled','rejected') NOT NULL DEFAULT 'planned',
    visibility ENUM('internal','client') NOT NULL DEFAULT 'internal',
    completed_at DATETIME NULL,
    proposed_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_milestones_public_id (public_id),
    KEY idx_milestones_project (project_id, archived_at),
    KEY idx_milestones_target (target_date),
    CONSTRAINT fk_milestones_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_milestones_proposed_by FOREIGN KEY (proposed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_milestones_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_milestones_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    milestone_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    assignee_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    start_date DATE NULL,
    due_date DATE NULL,
    status ENUM('backlog','to_do','in_progress','blocked','in_review','changes_requested','completed','cancelled') NOT NULL DEFAULT 'backlog',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    estimate_minutes INT UNSIGNED NULL,
    review_required TINYINT(1) NOT NULL DEFAULT 1,
    review_status ENUM('not_required','pending','approved','changes_requested') NOT NULL DEFAULT 'not_required',
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tasks_public_id (public_id),
    KEY idx_tasks_project (project_id, status, archived_at),
    KEY idx_tasks_assignee (assignee_id, status),
    KEY idx_tasks_due (due_date),
    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tasks_milestone FOREIGN KEY (milestone_id) REFERENCES milestones (id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "task_id depends on depends_on_task_id". Cycles are rejected by the service inside a locking transaction.
CREATE TABLE task_dependencies (
    task_id BIGINT UNSIGNED NOT NULL,
    depends_on_task_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (task_id, depends_on_task_id),
    KEY idx_task_dep_reverse (depends_on_task_id),
    CONSTRAINT chk_task_dep_not_self CHECK (task_id <> depends_on_task_id),
    CONSTRAINT fk_task_dep_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_dep_dep FOREIGN KEY (depends_on_task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_task_dep_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_task_comments (task_id, id),
    CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE RESTRICT,
    CONSTRAINT fk_task_comments_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_time_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    minutes INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_time_entries_task (task_id),
    CONSTRAINT fk_time_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks are internal working items: the Client role no longer holds tasks.view (tightening, not a data loss).
DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id AND r.slug = 'client'
  JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = 'tasks.view';
