-- A time the team adds is now a WINDOW (for example 11:00 to 23:00): the visitor chooses their own start and end inside it, in steps of step_minutes.
-- A request locks the range it asked for on that date, so nobody else can book an overlapping range; other times stay open.
ALTER TABLE appointment_slots
    ADD COLUMN step_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER weekdays,
    ADD COLUMN min_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER step_minutes,
    ADD COLUMN max_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER min_minutes;

CREATE TABLE appointment_bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    enquiry_id BIGINT UNSIGNED NULL,
    slot_id BIGINT UNSIGNED NULL,
    meeting_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_booking_date (meeting_date, released_at),
    KEY idx_booking_enquiry (enquiry_id),
    CONSTRAINT fk_booking_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
