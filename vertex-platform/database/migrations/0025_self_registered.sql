-- Client self-registration can now wait for the team's approval (no email needed). A pending account made through the public form is marked so the team can
-- approve it; accounts created by the team (invitations) stay pending until their owner sets a password.
ALTER TABLE users
    ADD COLUMN self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at;
