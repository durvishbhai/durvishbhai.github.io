-- Verified business facts supplied by the Owner, public-disclosure approvals, and the record of an Indian lawyer's review of a legal document.
-- Nothing here publishes anything: every fact below starts as NOT approved for public display, and every legal document stays a draft.

CREATE TABLE legal_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    body_hash CHAR(64) NOT NULL,                  -- SHA-256 of the exact text that was reviewed; any later edit makes the review void
    reviewer VARCHAR(160) NOT NULL,               -- name / firm of the lawyer, as recorded by the Owner
    reviewed_on DATE NOT NULL,
    note VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL,
    KEY idx_legal_reviews_doc (document_id, version_no),
    CONSTRAINT fk_legal_reviews_doc FOREIGN KEY (document_id) REFERENCES legal_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_legal_reviews_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facts given by the Owner (INSERT IGNORE: a value the Owner already typed is never overwritten).
INSERT IGNORE INTO compliance_settings (setting_key, value_text, updated_at) VALUES
    ('proprietor_name', 'Leena Sandip Jawale', UTC_TIMESTAMP()),
    ('operator_name', 'Durvish Jawale', UTC_TIMESTAMP()),
    ('udyam_number', 'UDYAM-MH-26-1193430', UTC_TIMESTAMP()),
    ('shop_act_number', '108768302603', UTC_TIMESTAMP()),
    ('contact_email', 'contact@krazyintegratedsolutions.com', UTC_TIMESTAMP()),
    ('whatsapp', '+91 9284347656', UTC_TIMESTAMP()),
    ('grievance_email', 'durvish@krazyintegratedsolutions.com', UTC_TIMESTAMP()),
    ('governing_law', 'India', UTC_TIMESTAMP()),
    ('jurisdiction', 'Pune, Maharashtra', UTC_TIMESTAMP()),
    ('retention_summary', 'Financial and business records that the law requires us to keep are kept for the periods that the applicable Indian laws require. Enquiries that did not become orders, optional profile details, notification history and uploaded reference files are deleted or anonymised when you ask.', UTC_TIMESTAMP());

-- The earlier draft text named a number of years that nobody had confirmed. Unpublished drafts now say "the periods required by applicable law".
UPDATE legal_document_versions
   SET body_html = REPLACE(body_html, 'are kept for {{retention_years}} years or as long as the law requires', 'are kept for the periods required by applicable law')
 WHERE status IN ('draft', 'scheduled');

-- The earlier default of 8 years was a placeholder, not a verified period: remove it unless the Owner has set a value of their own.
DELETE FROM compliance_settings WHERE setting_key = 'retention_years' AND value_text = '8' AND updated_by IS NULL;

-- Legally sensitive checklist items start as "Requires legal review" (the Owner can change them; nothing is marked Complete).
INSERT IGNORE INTO compliance_items (item_key, status, next_action, updated_at) VALUES
    ('privacy_policy', 'legal_review', 'Send to an Indian lawyer, then record the review', UTC_TIMESTAMP()),
    ('terms', 'legal_review', 'Send to an Indian lawyer, then record the review', UTC_TIMESTAMP()),
    ('refund_cancellation', 'legal_review', 'Send to an Indian lawyer, then record the review', UTC_TIMESTAMP()),
    ('cookie_consent', 'legal_review', 'Cookie Policy: lawyer to confirm the categories and consent model', UTC_TIMESTAMP()),
    ('accessibility', 'legal_review', 'Lawyer to confirm the accessibility statement wording', UTC_TIMESTAMP()),
    ('age_children', 'legal_review', 'Lawyer to confirm how minors are treated', UTC_TIMESTAMP()),
    ('data_deletion', 'legal_review', 'Lawyer to confirm which records must be kept and for how long', UTC_TIMESTAMP());
