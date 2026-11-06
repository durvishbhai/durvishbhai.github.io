-- The client chooses the project START date; the first delivery date is after start + the team's estimated time.
-- preparation_days now holds the NUMBER of units (days, weeks or months) of that estimate.
ALTER TABLE project_delivery_settings
    ADD COLUMN preparation_unit ENUM('days','weeks','months') NOT NULL DEFAULT 'days' AFTER preparation_days,
    ADD COLUMN fixed_start_date DATE NULL AFTER preparation_unit;   -- optional: the team fixes the start date instead of letting the client choose

ALTER TABLE delivery_appointments
    ADD COLUMN project_start_date DATE NULL AFTER appointment_date;
