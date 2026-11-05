-- Terms & conditions printed at the bottom of quotations and invoices (one line per term). Empty = the standard KIS wording built into the application.
ALTER TABLE business_profile
    ADD COLUMN quotation_terms TEXT NULL,
    ADD COLUMN invoice_terms TEXT NULL;
