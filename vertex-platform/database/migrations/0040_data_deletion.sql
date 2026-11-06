-- Data deletion requests. The requester proves control of the email address (or is signed in), the Owner reviews, and processing records what was deleted, what was
-- anonymised and what had to be kept (and why). Nothing a law requires us to keep is ever deleted by a request.
CREATE TABLE deletion_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(24) NOT NULL,
    requester_name VARCHAR(120) NOT NULL,
    requester_email VARCHAR(190) NOT NULL,
    user_id BIGINT UNSIGNED NULL,                 -- the matching account, if there is one
    reason VARCHAR(1000) NULL,
    status ENUM('awaiting_verification','verified','needs_clarification','approved','rejected','completed','withdrawn') NOT NULL DEFAULT 'awaiting_verification',
    verify_hash CHAR(64) NULL,                    -- SHA-256 of the one-time link sent to the requester's email
    track_hash CHAR(64) NULL,                     -- SHA-256 of the private status link
    verified_at DATETIME NULL,
    verified_method ENUM('email_link','signed_in','owner') NULL,
    decision_note VARCHAR(1000) NULL,             -- what the requester is told (reason for a rejection, what is needed, ...)
    internal_note TEXT NULL,
    result_json MEDIUMTEXT NULL,                  -- the recorded outcome: deleted / anonymised / retained, with the legal reason
    completed_at DATETIME NULL,
    handled_by BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dr_reference (reference),
    UNIQUE KEY uq_dr_verify (verify_hash),
    UNIQUE KEY uq_dr_track (track_hash),
    KEY idx_dr_status (status, created_at),
    KEY idx_dr_email (requester_email),
    CONSTRAINT fk_dr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dr_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deletion_request_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    note VARCHAR(1000) NULL,
    visible_to_requester TINYINT(1) NOT NULL DEFAULT 1,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_dre_request (request_id, id),
    CONSTRAINT fk_dre_request FOREIGN KEY (request_id) REFERENCES deletion_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_dre_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
