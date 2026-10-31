-- The official KIS "Website Development & Service Agreement": the filled-in details (parties, projects and values, payment dates, renewal costs) kept with the agreement so the printable PDF is the same every time.
ALTER TABLE agreements ADD COLUMN official_json TEXT NULL AFTER body_sha256;
