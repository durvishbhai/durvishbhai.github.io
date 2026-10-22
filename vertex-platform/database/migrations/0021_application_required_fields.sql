-- Job applications now always carry a LinkedIn profile, experience and availability (older rows keep NULL).
ALTER TABLE site_job_applications
    ADD COLUMN linkedin_url VARCHAR(300) NULL AFTER portfolio_url,
    ADD COLUMN experience VARCHAR(80) NULL AFTER linkedin_url,
    ADD COLUMN availability VARCHAR(80) NULL AFTER experience;
