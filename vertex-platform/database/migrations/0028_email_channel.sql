-- Email copies of notifications: how many delivery attempts were made, and when one succeeded. email_status (already present) says sent / failed.
ALTER TABLE notifications
    ADD COLUMN email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_status,
    ADD COLUMN email_sent_at DATETIME NULL AFTER email_attempts,
    ADD KEY idx_notif_email (email_status, email_attempts, id);
