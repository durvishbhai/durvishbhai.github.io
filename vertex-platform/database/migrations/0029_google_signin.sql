-- "Continue with Google": the stable Google account id (the "sub" claim) of a client who signs in with Google. Never an email address, never a token.
ALTER TABLE users
    ADD COLUMN google_sub VARCHAR(64) NULL AFTER email_verified_at,
    ADD UNIQUE KEY uq_users_google_sub (google_sub);
