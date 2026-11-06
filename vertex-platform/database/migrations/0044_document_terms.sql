-- Terms & conditions printed at the bottom of quotations and invoices (one line per term). Empty = the standard KIS wording built into the application.
-- Safe to run twice: the application also adds these columns by itself the first time Business details are saved.
ALTER TABLE business_profile
    ADD COLUMN IF NOT EXISTS quotation_terms TEXT NULL,
    ADD COLUMN IF NOT EXISTS invoice_terms TEXT NULL;
